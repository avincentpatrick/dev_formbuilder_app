<?php

declare(strict_types=1);

namespace App\Jobs\Connectors;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\QueueName;
use App\Enums\UsageMetric;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Maintenance\SweepWebhookRetriesJob;
use App\Jobs\TenantAwareJob;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\Connectors\ConnectionRevokedNotification;
use App\Services\Connectors\ConnectionService;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Entitlements\UsageMeter;
use App\Services\Webhooks\WebhookPayloadArchive;
use App\Support\Connectors\ConnectorDeliveryResult;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Webhooks\RetryLadder;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Pushes one ledger row to its native-connector destination (ADR-0009, H15a) — the
 * {@see DeliverWebhookJob} twin for the connector channel, and its exact behavioural sibling: it never
 * THROWS for a delivery failure (it records state and returns), and the retry ladder lives in the ledger's
 * `next_retry_at` swept by {@see SweepWebhookRetriesJob}, not in job `$tries`/`backoff()`.
 *
 * It runs on the EXISTING `webhooks` queue rather than a new one. A new {@see QueueName} case would force a
 * matching `docker-compose.yml` worker list and `docs/deployment-infrastructure.md` change that
 * `QueuedMailContractTest` asserts byte-for-byte — a real operational cost for two channels that share a
 * table, a quota and a retry sweep and should share a worker pool.
 *
 * Sequence: guard (active subscription, active connection, deliverable status) → on the FIRST attempt only,
 * hard-cap the monthly delivery quota then meter it → hand the envelope to the provider adapter → record
 * success (reset the breaker) or failure (schedule the next retry / dead-letter / trip the breaker). A
 * CREDENTIAL rejection short-circuits all of that: the grant is dead, so the delivery is dead-lettered
 * immediately rather than retried for seven days into a token that will never work again.
 *
 * Metering deliberately shares {@see UsageMetric::WebhookDeliveries} with the webhook channel: it is one
 * outbound-delivery budget over one ledger, not two accounting systems for the same egress.
 */
#[Queue(QueueName::Webhooks)]
final class DeliverConnectorMessageJob extends TenantAwareJob
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $deliveryId,
        // Reserved for a future manual redeliver (the webhook channel's H13b affordance): re-send an
        // already-metered delivery without re-consuming the monthly quota. Scalar so the payload gate stays green.
        public readonly bool $skipMeter = false,
    ) {}

    protected function handleForTenant(): void
    {
        $delivery = WebhookDelivery::query()->with('subscription.grant')->find($this->deliveryId);

        if ($delivery === null) {
            return; // deleted between enqueue and run
        }

        $subscription = $delivery->subscription;
        $connection = $subscription?->grant;

        // Deliver only a pending/failed row through an active rule on a live grant. A soft-deleted
        // subscription or connection won't load, which is the same refusal by another route.
        if ($subscription === null
            || $connection === null
            || $subscription->status !== ConnectorSubscriptionStatus::Active
            || $connection->status !== ConnectionStatus::Active
            || ! in_array($delivery->status, [WebhookDeliveryStatus::Pending, WebhookDeliveryStatus::Failed], true)) {
            return;
        }

        // First attempt only: hard-cap the monthly delivery quota, then meter. Retries neither re-check nor
        // re-meter — a delivery is one metered unit regardless of how many attempts it takes.
        if ($delivery->attempt_count === 0 && ! $this->skipMeter) {
            if (! app(QuotaGuard::class)->hasRateQuotaRemaining(UsageMetric::WebhookDeliveries)) {
                $this->deadLetterForQuota($delivery);

                return;
            }

            app(UsageMeter::class)->increment(UsageMetric::WebhookDeliveries);
        }

        $this->attempt($delivery, $subscription, $connection);
    }

    private function attempt(WebhookDelivery $delivery, ConnectionSubscription $subscription, Connection $connection): void
    {
        $attemptNumber = $delivery->attempt_count + 1;
        $startedAt = microtime(true);

        // Read through the archive seam so an off-loaded envelope still delivers in full (H13b); a connector
        // envelope is well under the threshold today, so this is the inline payload.
        $payload = app(WebhookPayloadArchive::class)->read($delivery);

        $provider = app(ConnectorRegistry::class)->for($connection->provider);
        $result = $provider->deliver($connection, $subscription, $payload);

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($result->delivered) {
            $this->finishSucceeded($delivery, $subscription, $attemptNumber, $result, $elapsedMs);

            return;
        }

        if ($result->credentialRejected) {
            $this->finishCredentialRejected($delivery, $connection, $attemptNumber, $result, $elapsedMs);

            return;
        }

        $this->finishFailed($delivery, $subscription, $attemptNumber, $result, $elapsedMs);
    }

    private function finishSucceeded(WebhookDelivery $delivery, ConnectionSubscription $subscription, int $attempt, ConnectorDeliveryResult $result, int $ms): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Succeeded,
            'attempt_count' => $attempt,
            'last_attempted_at' => Carbon::now(),
            'next_retry_at' => null,
            'response_status_code' => $result->responseStatus,
            'response_body_excerpt' => $result->responseExcerpt,
            'response_time_ms' => $ms,
        ])->save();

        $subscription->forceFill([
            'consecutive_failure_count' => 0,
            'last_success_at' => Carbon::now(),
        ])->save();
    }

    private function finishFailed(WebhookDelivery $delivery, ConnectionSubscription $subscription, int $attempt, ConnectorDeliveryResult $result, int $ms): void
    {
        $nextRetryAt = RetryLadder::nextRetryAt($attempt, $delivery->max_attempts);

        $delivery->forceFill([
            'status' => $nextRetryAt === null ? WebhookDeliveryStatus::DeadLettered : WebhookDeliveryStatus::Failed,
            'attempt_count' => $attempt,
            'last_attempted_at' => Carbon::now(),
            'next_retry_at' => $nextRetryAt,
            'response_status_code' => $result->responseStatus,
            'response_body_excerpt' => $result->responseExcerpt,
            'response_time_ms' => $ms,
        ])->save();

        // Circuit breaker: pause the rule after N consecutive failures, mirroring the webhook endpoint's.
        $failures = $subscription->consecutive_failure_count + 1;
        $attrs = ['consecutive_failure_count' => $failures, 'last_failure_at' => Carbon::now()];

        if ($failures >= (int) config('webhooks.breaker_threshold', 20)) {
            $attrs['status'] = ConnectorSubscriptionStatus::Paused;
        }

        $subscription->forceFill($attrs)->save();
    }

    /**
     * The provider disowned the credential (ADR-0009 §D6/§D7). Terminal by construction: mark the grant dead
     * (which also pauses its rules and clears the tokens), dead-letter this delivery rather than scheduling
     * retries that can only fail identically, and tell the tenant owner once so a human can re-connect.
     */
    private function finishCredentialRejected(WebhookDelivery $delivery, Connection $connection, int $attempt, ConnectorDeliveryResult $result, int $ms): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::DeadLettered,
            'attempt_count' => $attempt,
            'last_attempted_at' => Carbon::now(),
            'next_retry_at' => null,
            'response_status_code' => $result->responseStatus,
            'response_body_excerpt' => $result->responseExcerpt,
            'response_time_ms' => $ms,
        ])->save();

        $providerLabel = $connection->provider->label();
        $accountLabel = $connection->external_account_label;

        app(ConnectionService::class)->markDead($connection, ConnectionStatus::Revoked, $result->responseExcerpt);

        $this->notifyOwner($providerLabel, $accountLabel);
    }

    /**
     * Tell the tenant owner the grant is gone. Scalar-only, on-demand notifiable — the
     * {@see DeliverWebhookJob::notifyAutoDisabled()} recipe. Best-effort: a missing tenant/owner/email is
     * swallowed so a notification hiccup never disrupts delivery accounting.
     */
    private function notifyOwner(string $providerLabel, string $accountLabel): void
    {
        $ownerId = Tenant::query()->whereKey($this->tenantId)->value('owner_user_id'); // RLS-exempt central table

        if (! is_string($ownerId) || $ownerId === '') {
            return;
        }

        $email = User::query()->whereKey($ownerId)->value('email');

        if (! is_string($email) || $email === '') {
            return;
        }

        Notification::route('mail', $email)
            ->notify(new ConnectionRevokedNotification($providerLabel, $accountLabel));
    }

    private function deadLetterForQuota(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::DeadLettered,
            'next_retry_at' => null,
            'response_body_excerpt' => '[quota_exceeded] Monthly delivery quota reached; this message was not sent.',
        ])->save();
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['queue' => QueueName::Webhooks->value, 'delivery_id' => $this->deliveryId];
    }
}
