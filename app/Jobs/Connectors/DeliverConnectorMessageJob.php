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
use App\Notifications\Connectors\ConnectorRulePausedNotification;
use App\Services\Connectors\ConnectionPresenter;
use App\Services\Connectors\ConnectionService;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Entitlements\UsageMeter;
use App\Services\Webhooks\WebhookPayloadArchive;
use App\Support\Branding\BrandPalette;
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
            || ! in_array($delivery->status, [WebhookDeliveryStatus::Pending, WebhookDeliveryStatus::Failed], true)) {
            return;
        }

        // ⚠️⚠️ THE GRANT IS CHECKED BEFORE THE RULE, AND THE ORDER IS THE WHOLE POINT (M6). Both used to sit
        // in the guard above, which returns without touching the row — and `markDead()` PAUSES EVERY RULE ON
        // THE CONNECTION, so the paused-rule arm always fired first and the dead-grant arm was unreachable in
        // practice. The delivery then kept its `failed` status, its `next_retry_at` and its `attempt_count`,
        // so `WebhookRetrySweeper`'s `attempt_count < max_attempts` predicate stayed true and it re-dispatched
        // the same row every five minutes for as long as it existed. Nothing surfaced it because the
        // pre-flight refresh used to catch the revocation ONE ATTEMPT EARLIER and dead-letter it there; M6
        // moved that refresh into its own job, which would have left the silent loop as the only path.
        //
        // The two conditions are not the same kind of thing, which is why they now answer differently:
        //   • a DEAD GRANT is terminal — only a human re-running OAuth fixes it, so the delivery is settled;
        //   • a PAUSED RULE is reversible — an admin un-pauses it and the queued deliveries resume, which is
        //     exactly what makes `paused` worth distinguishing from `disabled`, so it stays silent.
        if ($connection->status !== ConnectionStatus::Active) {
            $this->attempt($delivery, $subscription, $connection);

            return;
        }

        if ($subscription->status !== ConnectorSubscriptionStatus::Active) {
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

        if ($connection->status !== ConnectionStatus::Active) {
            // ⚠️ M6 MOVED WHAT REACHES THIS, AND IT WOULD HAVE BECOME DEAD CODE IF LEFT ALONE. It used to be
            // the pre-flight `ensureFresh()` marking the grant dead mid-attempt; that no longer happens here,
            // and `handleForTenant()`'s own guard would have caught a dead connection first — by RETURNING
            // SILENTLY, which leaves the delivery `failed` with a `next_retry_at` and no attempt consumed, so
            // the sweep re-dispatches it forever. The guard there now routes a dead CONNECTION here instead,
            // which preserves exactly the outcome the pre-flight used to produce.
            //
            // Nothing was sent, so this is not a delivery failure to retry — and the owner has already been
            // told once by whichever path marked the grant dead ({@see RefreshOneConnectionJob} now), so
            // re-notifying from here would tell them twice.
            $this->finishBlocked(
                $delivery,
                $subscription,
                $attemptNumber,
                ConnectorDeliveryResult::blocked(null, '[grant_expired] The connection needs to be reconnected before this rule can deliver.'),
                (int) round((microtime(true) - $startedAt) * 1000),
                notify: false,
            );

            return;
        }

        // PRE-FLIGHT REFRESH (H16a) — ADR-0009 §D6's own named revisit trigger, fired. §D6 made refresh
        // proactive-and-scheduled precisely to keep a second outbound call out of the delivery path, and that
        // still holds for Slack, whose bot tokens do not expire at all. Google's do, in about an hour, against
        // an HOURLY sweep: a grant minted just after a sweep and delivered against before the next one is
        // dead on arrival, and retuning the sweep cannot close a window that one missed run reopens.
        //
        // It is a GUARD, not a policy: `ensureFresh()` returns immediately unless the token is already expired
        // or inside `connectors.delivery_refresh_lead_seconds` (120s), so a healthy connection is still
        // renewed by the sweep and never reaches this call. The stampede §D6 warns about therefore needs the
        // sweep to have failed first, rather than being one provider outage away at all times.
        //
        // ⚠️⚠️ AMENDED IN M6: IT HANDS THE ROTATION OFF INSTEAD OF PERFORMING IT. H16a's reasoning above is
        // unchanged and still right — a grant minted just after a sweep IS dead on arrival without a
        // pre-flight — but performing the refresh HERE put an IRREVERSIBLE provider-side effect inside a
        // transaction with three more outbound calls after it. Airtable invalidates the previous refresh
        // token on every renewal, so any later throw (or the 60s `$timeout`) rolled our write back while the
        // provider stayed rotated, and the next sweep got `invalid_grant` — terminal, killing the whole
        // connection. {@see RefreshOneConnectionJob} carries the rotation in a transaction of its own; this
        // delivery defers one ladder step and arrives with a token that is real.
        if ($connection->needsRefresh(Carbon::now(), (int) config('connectors.delivery_refresh_lead_seconds', 120))) {
            RefreshOneConnectionJob::dispatch($this->tenantId, (string) $connection->getKey());

            $this->finishDeferredForRefresh($delivery, $attemptNumber, (int) round((microtime(true) - $startedAt) * 1000));

            return;
        }

        $provider = app(ConnectorRegistry::class)->for($connection->provider);

        // M5. The one thing this attempt knows that the adapter cannot work out for itself: whether the
        // PREVIOUS attempt issued a write and never learned its outcome. Read as a boolean rather than passed
        // as the timestamp, because the adapter's question is "may the destination already hold this?" and
        // nothing it does depends on when.
        $result = $provider->deliver($connection, $subscription, $payload, $delivery->unconfirmed_write_at !== null);

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($result->delivered) {
            $this->finishSucceeded($delivery, $subscription, $attemptNumber, $result, $elapsedMs);

            return;
        }

        if ($result->credentialRejected) {
            $this->finishCredentialRejected($delivery, $connection, $attemptNumber, $result, $elapsedMs);

            return;
        }

        if ($result->blocked) {
            $this->finishBlocked($delivery, $subscription, $attemptNumber, $result, $elapsedMs);

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
            // M5. Cleared wherever the outcome is SETTLED, so the flag can only ever describe the attempt
            // before this one. A stale one would make a LATER retry skip a write that was never issued.
            'unconfirmed_write_at' => null,
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
            // M5. The ONLY path that sets it, and it is set from the result rather than from the absence of a
            // status: a `blocked` outcome also carries a null status and must not arm the next attempt's probe.
            'unconfirmed_write_at' => $result->writeUnconfirmed ? Carbon::now() : null,
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
     * The DESTINATION is unusable and the credential is fine (H16a) — column drift, a deleted spreadsheet, a
     * file this grant cannot reach.
     *
     * Terminal for this RULE and nothing wider: the subscription is paused, this delivery is dead-lettered
     * rather than retried, and the connection plus every other rule on it is left running. Pausing rather than
     * disabling is deliberate — `disabled` is the admin's word (see {@see ConnectorSubscriptionStatus}), and
     * overwriting it here would lose the distinction between "we stopped this" and "you stopped this".
     *
     * The owner is told once, on the same edge-triggered recipe the breaker and the revoked-grant path use:
     * only a human can re-map the columns, and a paused rule with no notification is a rule that silently
     * stopped working. `notify: false` is for the one caller that has ALREADY notified — the pre-flight
     * refresh, which marks the grant dead through the refresher's own path.
     */
    private function finishBlocked(
        WebhookDelivery $delivery,
        ConnectionSubscription $subscription,
        int $attempt,
        ConnectorDeliveryResult $result,
        int $ms,
        bool $notify = true,
    ): void {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::DeadLettered,
            'attempt_count' => $attempt,
            'last_attempted_at' => Carbon::now(),
            'next_retry_at' => null,
            'response_status_code' => $result->responseStatus,
            'response_body_excerpt' => $result->responseExcerpt,
            'response_time_ms' => $ms,
            'unconfirmed_write_at' => null, // M5 — terminal, so nothing downstream may act on it
        ])->save();

        $alreadyPaused = $subscription->status === ConnectorSubscriptionStatus::Paused;

        $subscription->forceFill([
            'status' => ConnectorSubscriptionStatus::Paused,
            'last_failure_at' => Carbon::now(),
        ])->save();

        // Edge-triggered: a rule that is already paused has already told them. Without this, every queued
        // delivery for a drifted sheet would email the owner — the exact "no bell, no polling" over-notification
        // H17 argued against, arrived at from the other direction.
        if ($notify && ! $alreadyPaused) {
            $this->notifyBlocked($subscription, $result->responseExcerpt);
        }
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
            'unconfirmed_write_at' => null, // M5 — terminal, so nothing downstream may act on it
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

        // Inside handleForTenant(), where the GUC is live (H23a4) — see BrandPalette.
        Notification::route('mail', $email)
            ->notify(
                (new ConnectionRevokedNotification($providerLabel, $accountLabel))
                    ->withBrand(BrandPalette::forTenantId($this->tenantId))
            );
    }

    /**
     * Tell the tenant owner one RULE is paused and why (H16a) — same on-demand-notifiable, best-effort recipe
     * as {@see notifyOwner()}, different message, because "reconnect your Google account" would be wrong
     * advice for a spreadsheet that grew a column.
     */
    private function notifyBlocked(ConnectionSubscription $subscription, string $reason): void
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
            ->notify(new ConnectorRulePausedNotification((string) $subscription->name, $reason));
    }

    /**
     * The grant needs renewing, so this attempt stands aside for one ladder step (M6).
     *
     * ⚠️ IT IS NOT A FAILURE AND MUST NOT BE COUNTED AS ONE. `consecutive_failure_count` drives the circuit
     * breaker that pauses a RULE after 20 consecutive failures, and the rule is not the thing that is wrong
     * here — the credential is, briefly, and by the time the ladder comes round it will have been renewed by
     * a job whose only work is renewing it. Counting deferrals would let an ordinary hourly token expiry pause
     * a healthy rule, which is the H16a classification trap in a third costume.
     *
     * `attempt_count` IS incremented, deliberately: without it a grant that never refreshes would sit at the
     * ladder's 1-minute first step forever instead of walking to a dead letter, and an endless quiet retry is
     * worse than a loud one. The excerpt is ours and carries a `[marker]`, so
     * {@see ConnectionPresenter::pausedReasons()} can surface it.
     */
    private function finishDeferredForRefresh(WebhookDelivery $delivery, int $attempt, int $ms): void
    {
        $nextRetryAt = RetryLadder::nextRetryAt($attempt, $delivery->max_attempts);

        $delivery->forceFill([
            'status' => $nextRetryAt === null ? WebhookDeliveryStatus::DeadLettered : WebhookDeliveryStatus::Failed,
            'attempt_count' => $attempt,
            'last_attempted_at' => Carbon::now(),
            'next_retry_at' => $nextRetryAt,
            'response_status_code' => null,
            'response_body_excerpt' => '[token_refreshing] The connection’s access token is being renewed; this message will be sent on the next attempt.',
            'response_time_ms' => $ms,
            'unconfirmed_write_at' => null, // M5 — nothing was sent, so nothing is unconfirmed
        ])->save();
    }

    private function deadLetterForQuota(WebhookDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::DeadLettered,
            'next_retry_at' => null,
            'response_body_excerpt' => '[quota_exceeded] Monthly delivery quota reached; this message was not sent.',
            'unconfirmed_write_at' => null, // M5 — terminal, so nothing downstream may act on it
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
