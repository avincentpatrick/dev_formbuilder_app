<?php

declare(strict_types=1);

namespace App\Jobs\Automations;

use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationRunStatus;
use App\Enums\QueueName;
use App\Enums\UsageMetric;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Jobs\TenantAwareJob;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\FormAutomationRun;
use App\Services\Automations\FormAutomationWebhookPayload;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Entitlements\UsageMeter;
use App\Support\Webhooks\OutboundUrlGuard;
use App\Support\Webhooks\RetryLadder;
use App\Support\Webhooks\WebhookSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Sends one response's answers to one web-address automation (M132, `R-b7bc5149`, `D83`), on the `webhooks` queue
 * beside the workspace's own webhooks and under the same rules: the monthly `webhook_deliveries` quota is checked and
 * metered on the first attempt only, the address is re-checked against the private-network guard before EVERY attempt
 * (a host public when saved can be repointed), redirects are never followed, and the body is signed with the
 * automation's secret in the workspace webhooks' headers — the {@see DeliverWebhookJob} sequence.
 *
 * ── A FAILED ATTEMPT IS RECORDED AND RELEASED, NEVER THROWN ─────────────────────────────────────────────────
 * The body runs inside the tenant transaction (`TenantAwareJob::handle()`), so a throw would roll back the very row
 * that says the attempt failed, and `failed()` is final and only logs. So a failure is written to the run and the job
 * is `release()`d on the shared {@see RetryLadder} — the `ReadOcrScanJob` shape. After
 * {@see MAX_ATTEMPTS} the run is `failed` and nothing more is tried. The ladder's first four gaps (1, 5, 30 and 120
 * minutes) fit inside the queue's six-hour retry window, which is fixed when the job is first dispatched.
 *
 * The POST runs inside the tenant transaction, as `DeliverWebhookJob`'s does, holding no row lock: concurrency is
 * bounded by the worker count.
 */
#[Queue(QueueName::Webhooks)]
final class DeliverFormAutomationWebhookJob extends TenantAwareJob
{
    /** Attempts in all before a run is given up: the first send and four retries. */
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $runId,
    ) {}

    protected function handleForTenant(): void
    {
        $run = FormAutomationRun::query()->with('automation.form')->find($this->runId);

        if ($run === null || ! in_array($run->status, [FormAutomationRunStatus::Pending, FormAutomationRunStatus::Retrying], true)) {
            return; // deleted with its automation, or already finished
        }

        $automation = $run->automation;

        if ($automation === null || $automation->action !== FormAutomationAction::Webhook || $automation->url === null || $automation->secret === null) {
            return;
        }

        if (! $automation->enabled) {
            $this->finish($run, FormAutomationRunStatus::Skipped, null, 'disabled');

            return;
        }

        if ($run->attempt_count === 0) {
            if (! app(QuotaGuard::class)->hasRateQuotaRemaining(UsageMetric::WebhookDeliveries)) {
                $this->finish($run, FormAutomationRunStatus::Skipped, null, 'quota_exceeded');

                return;
            }

            app(UsageMeter::class)->increment(UsageMetric::WebhookDeliveries);
        }

        $payload = app(FormAutomationWebhookPayload::class)->build($run, $automation);

        if ($payload === null) {
            $this->finish($run, FormAutomationRunStatus::Failed, null, 'submission_missing');

            return;
        }

        try {
            app(OutboundUrlGuard::class)->assertPublic($automation->url);
        } catch (BlockedWebhookUrlException) {
            $this->attemptFailed($run, null, 'blocked_url');

            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $timestamp = (string) $payload['occurred_at'];

        try {
            $response = Http::withHeaders([
                WebhookSigner::SIGNATURE_HEADER => app(WebhookSigner::class)->signatureHeader($automation->secret, $timestamp, $body),
                WebhookSigner::TIMESTAMP_HEADER => $timestamp,
                WebhookSigner::EVENT_ID_HEADER => $run->event_id,
                'User-Agent' => 'FormBuilder-Automations/1',
            ])
                ->withOptions(['allow_redirects' => false]) // never follow a 3xx into an internal host
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->withBody($body, 'application/json')
                ->post($automation->url);
        } catch (ConnectionException) {
            $this->attemptFailed($run, null, 'transport_error');

            return;
        }

        if ($response->successful()) {
            $this->finish($run, FormAutomationRunStatus::Succeeded, $response->status(), null, attempted: true);

            return;
        }

        $this->attemptFailed($run, $response->status(), 'http_'.$response->status());
    }

    /** Record a failed attempt; schedule the next one, or give up after the last. */
    private function attemptFailed(FormAutomationRun $run, ?int $status, string $code): void
    {
        $attempt = $run->attempt_count + 1;
        $next = RetryLadder::nextRetryAt($attempt, self::MAX_ATTEMPTS);

        $run->forceFill([
            'status' => $next === null ? FormAutomationRunStatus::Failed : FormAutomationRunStatus::Retrying,
            'attempt_count' => $attempt,
            'response_status' => $status,
            'error_code' => $code,
            'last_attempted_at' => Carbon::now(),
        ])->save();

        if ($next !== null) {
            $this->release(max(1, (int) Carbon::now()->diffInSeconds($next, true)));
        }
    }

    private function finish(FormAutomationRun $run, FormAutomationRunStatus $status, ?int $responseStatus, ?string $code, bool $attempted = false): void
    {
        $run->forceFill([
            'status' => $status,
            'attempt_count' => $run->attempt_count + ($attempted ? 1 : 0),
            'response_status' => $responseStatus,
            'error_code' => $code,
            'last_attempted_at' => $attempted ? Carbon::now() : $run->last_attempted_at,
        ])->save();
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['form_automation_run_id' => $this->runId];
    }
}
