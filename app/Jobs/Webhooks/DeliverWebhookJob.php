<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Enums\QueueName;
use App\Enums\UsageMetric;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEndpointStatus;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Jobs\Entitlements\ReconcileTenantUsageJob;
use App\Jobs\Maintenance\SweepWebhookRetriesJob;
use App\Jobs\TenantAwareJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\Webhooks\WebhookAutoDisabledNotification;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Entitlements\UsageMeter;
use App\Services\Webhooks\WebhookPayloadArchive;
use App\Support\Branding\BrandPalette;
use App\Support\Webhooks\OutboundUrlGuard;
use App\Support\Webhooks\RetryLadder;
use App\Support\Webhooks\WebhookSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Delivers one {@see WebhookDelivery} to its endpoint over HTTP (H13a). Runs on the `webhooks` queue, so it
 * inherits the per-tenant fairness ceiling (ADR-0007 §D9). The retry ladder lives in the delivery's
 * `next_retry_at` column swept by {@see SweepWebhookRetriesJob} — NOT via job
 * $tries/backoff() (webhook-integration-design.md §1): a customer endpoint down for hours is a business
 * condition, not a job defect. This job therefore never THROWS for a delivery failure — it records state and
 * returns; the sweep re-dispatches a due `failed` delivery.
 *
 * Sequence: guard (active endpoint, deliverable status) → on the FIRST attempt only, hard-cap the monthly
 * `webhook_deliveries` quota (over → dead-letter, not sent) then meter it → re-validate the URL against the
 * SSRF blocklist (DNS-rebinding defense) → POST with the HMAC signature, no redirect-follow, a bounded
 * timeout → record success (reset the breaker) or failure (schedule the next retry / dead-letter / trip the
 * breaker at the consecutive-failure threshold).
 *
 * Idle-in-transaction note: the base wraps this in one tenant transaction, so the ≤10s POST runs inside it.
 * Mitigated by holding no row locks (plain saves, no lockForUpdate) so concurrency is bounded by worker count.
 */
#[Queue(QueueName::Webhooks)]
final class DeliverWebhookJob extends TenantAwareJob
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $deliveryId,
        // A manual redeliver (H13b) re-runs SSRF + re-signs + re-attempts on a fresh ladder, but does NOT
        // re-consume the monthly delivery quota — the event was already metered on its first send, mirroring
        // how automatic retries never re-meter. Scalar so the job-payload gate stays green.
        public readonly bool $skipMeter = false,
    ) {}

    protected function handleForTenant(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null) {
            return; // deleted between enqueue and run
        }

        $endpoint = $delivery->endpoint;

        // Deliver only a pending/failed delivery to an active endpoint. A soft-deleted endpoint won't load.
        if ($endpoint === null
            || $endpoint->status !== WebhookEndpointStatus::Active
            || ! in_array($delivery->status, [WebhookDeliveryStatus::Pending, WebhookDeliveryStatus::Failed], true)) {
            return;
        }

        // First attempt only: hard-cap the monthly delivery quota, then meter. Retries neither re-check nor
        // re-meter (a delivery is one metered unit regardless of how many attempts it takes); nor does a
        // manual redeliver ($skipMeter — H13b), which re-sends an already-metered delivery.
        if ($delivery->attempt_count === 0 && ! $this->skipMeter) {
            if (! app(QuotaGuard::class)->hasRateQuotaRemaining(UsageMetric::WebhookDeliveries)) {
                $this->deadLetterForQuota($delivery);

                return;
            }

            app(UsageMeter::class)->increment(UsageMetric::WebhookDeliveries);
        }

        $this->attempt($delivery, $endpoint);
    }

    private function attempt(WebhookDelivery $delivery, WebhookEndpoint $endpoint): void
    {
        $attemptNumber = $delivery->attempt_count + 1;

        // Delivery-time SSRF re-validation (webhook-integration-design.md §2.2): a host public at save time
        // can be repointed internally before a retry fires.
        try {
            app(OutboundUrlGuard::class)->assertPublic($endpoint->url);
        } catch (BlockedWebhookUrlException $e) {
            $this->finishFailed($delivery, $endpoint, $attemptNumber, null, '['.$e->reason.'] '.$e->getMessage(), null, null);

            return;
        }

        // Read the full envelope back from archival storage when the inline payload was off-loaded (H13b),
        // so the signed body is always the real payload, not the trimmed marker.
        $payload = app(WebhookPayloadArchive::class)->read($delivery);
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $timestamp = (string) ($payload['occurred_at'] ?? Carbon::now()->toIso8601String());
        // Signs with the current secret and, during a rotation grace window, the previous one too (H13b).
        $signature = app(WebhookSigner::class)->signatureHeaderFor($endpoint, $timestamp, $rawBody);
        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                WebhookSigner::SIGNATURE_HEADER => $signature,
                WebhookSigner::TIMESTAMP_HEADER => $timestamp,
                WebhookSigner::EVENT_ID_HEADER => $delivery->event_id,
                'User-Agent' => 'FormBuilder-Webhooks/1',
            ])
                ->withOptions(['allow_redirects' => false]) // never follow a 3xx into an internal host
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->withBody($rawBody, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            // Timeout / connection refused / transport error — a failed attempt with no response.
            $this->finishFailed($delivery, $endpoint, $attemptNumber, null, $this->excerpt('[transport_error] '.$e->getMessage()), $this->elapsedMs($startedAt), $signature);

            return;
        }

        $ms = $this->elapsedMs($startedAt);

        if ($response->successful()) { // any 2xx within the timeout
            $this->finishSucceeded($delivery, $endpoint, $attemptNumber, $response->status(), $this->excerpt($response->body()), $ms, $signature);

            return;
        }

        $this->finishFailed($delivery, $endpoint, $attemptNumber, $response->status(), $this->excerpt($response->body()), $ms, $signature);
    }

    private function finishSucceeded(WebhookDelivery $delivery, WebhookEndpoint $endpoint, int $attempt, int $status, string $excerpt, int $ms, string $signature): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Succeeded,
            'attempt_count' => $attempt,
            'last_attempted_at' => Carbon::now(),
            'next_retry_at' => null,
            'response_status_code' => $status,
            'response_body_excerpt' => $excerpt,
            'response_time_ms' => $ms,
            'signature' => $signature,
        ])->save();

        $endpoint->forceFill([
            'consecutive_failure_count' => 0,
            'last_success_at' => Carbon::now(),
        ])->save();
    }

    private function finishFailed(WebhookDelivery $delivery, WebhookEndpoint $endpoint, int $attempt, ?int $status, string $excerpt, ?int $ms, ?string $signature): void
    {
        // The ladder is shared with the connector channel (H15a) so the two cannot drift — same config key,
        // same exhaustion rule, one ledger table.
        $nextRetryAt = RetryLadder::nextRetryAt($attempt, $delivery->max_attempts);
        $deliveryStatus = $nextRetryAt === null
            ? WebhookDeliveryStatus::DeadLettered
            : WebhookDeliveryStatus::Failed;

        $delivery->forceFill([
            'status' => $deliveryStatus,
            'attempt_count' => $attempt,
            'last_attempted_at' => Carbon::now(),
            'next_retry_at' => $nextRetryAt,
            'response_status_code' => $status,
            'response_body_excerpt' => $excerpt,
            'response_time_ms' => $ms,
            'signature' => $signature ?? $delivery->signature,
        ])->save();

        // Circuit breaker: trip after N consecutive failures.
        $failures = $endpoint->consecutive_failure_count + 1;
        $attrs = ['consecutive_failure_count' => $failures, 'last_failure_at' => Carbon::now()];

        if ($failures >= (int) config('webhooks.breaker_threshold', 20)) {
            $attrs['status'] = WebhookEndpointStatus::Paused;
            $attrs['disabled_reason'] = 'too_many_failures';
        }

        $endpoint->forceFill($attrs)->save();

        // The breaker just tripped (Active → Paused this attempt — the job guard only reaches here for an
        // Active endpoint, so this fires exactly once per trip): tell the tenant owner (H13b).
        if (($attrs['status'] ?? null) === WebhookEndpointStatus::Paused) {
            $this->notifyAutoDisabled($endpoint, $failures);
        }
    }

    /**
     * Notify the tenant owner that the circuit breaker auto-paused this endpoint (H13b). Scalar-only, on-demand
     * notifiable — the {@see ReconcileTenantUsageJob} overage recipe. Best-effort: a
     * missing tenant/owner/email is swallowed so a notification hiccup never disrupts delivery accounting.
     */
    private function notifyAutoDisabled(WebhookEndpoint $endpoint, int $failures): void
    {
        $ownerId = Tenant::query()->whereKey($this->tenantId)->value('owner_user_id'); // RLS-exempt central table

        if (! is_string($ownerId) || $ownerId === '') {
            return;
        }

        $email = User::query()->whereKey($ownerId)->value('email');

        if (! is_string($email) || $email === '') {
            return;
        }

        // Inside handleForTenant(), where the GUC is live — the queued notification itself runs with no
        // tenant context at all, so a brand read there would fail closed (H23a4).
        Notification::route('mail', $email)
            ->notify(
                (new WebhookAutoDisabledNotification($endpoint->name, $endpoint->url, $failures))
                    ->withBrand(BrandPalette::forTenantId($this->tenantId))
            );
    }

    private function deadLetterForQuota(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::DeadLettered,
            'next_retry_at' => null,
            'response_body_excerpt' => '[quota_exceeded] Monthly webhook-delivery quota reached; this delivery was not sent.',
        ])->save();
    }

    /** Truncate a response body to the configured excerpt size, stripping invalid UTF-8 so `text` accepts it. */
    private function excerpt(string $body): string
    {
        $clean = mb_convert_encoding($body, 'UTF-8', 'UTF-8');

        return mb_substr($clean, 0, (int) config('webhooks.response_excerpt_bytes', 2000));
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['queue' => QueueName::Webhooks->value, 'delivery_id' => $this->deliveryId];
    }
}
