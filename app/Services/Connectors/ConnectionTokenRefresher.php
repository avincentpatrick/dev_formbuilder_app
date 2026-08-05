<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectionStatus;
use App\Exceptions\Connectors\ConnectorOAuthException;
use App\Exceptions\Connectors\UnknownConnectorProviderException;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Connectors\ConnectionRevokedNotification;
use App\Support\Connectors\ConnectorRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Renews the tenant's OAuth grants ahead of their expiry (ADR-0009 §D6, H15a). Runs from the scheduled
 * cross-tenant sweep, under one tenant's context at a time.
 *
 * PROACTIVE FIRST, WITH ONE NARROW LAZY GUARD — amended in H16a; ADR-0009 §D6 is amended with it.
 *
 * §D6 originally read "proactive, never lazy", for two good reasons that still hold: a refresh inside a
 * delivery attempt puts a second outbound call and a credential write in the delivery path, and a provider
 * outage could stampede every queued delivery into simultaneous refresh attempts. It also named the condition
 * that would overturn it — "a provider whose tokens expire faster than the sweep interval, which would force a
 * lazy pre-flight refresh after all." **Google Sheets (H16a) is that provider**: its access tokens live about
 * an hour against an hourly sweep, so a grant minted just after one sweep and delivered against before the
 * next can be dead on arrival. Retuning the sweep does not fix it — one missed run reopens the window.
 *
 * So {@see sweep()} remains the mechanism and {@see ensureFresh()} is a GUARD ON TOP OF IT, not a second
 * policy. It refreshes only a grant that is already expired or within `delivery_refresh_lead_seconds` (120s,
 * versus the sweep's 7200s), so a healthy connection never reaches it and the stampede §D6 warned about now
 * requires the sweep to have failed first rather than being one outage away permanently.
 *
 * A grant with no expiry or no refresh token can never be refreshed and is skipped rather than failed —
 * that is the NORMAL case for Slack, whose bot tokens do not expire unless the workspace enables rotation.
 *
 * A refusal is terminal, not retried: `invalid_grant` means the tenant (or their admin) removed our app, and
 * hammering it changes nothing. The connection is marked dead — which clears the tokens and pauses its
 * rules — and the owner is told once, because only a human re-running the OAuth flow can fix it.
 */
final class ConnectionTokenRefresher
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ConnectionService $connections,
    ) {}

    /** @return int the number of grants refreshed successfully */
    public function sweep(Carbon $now): int
    {
        $leadSeconds = (int) config('connectors.refresh_lead_seconds', 7200);
        $refreshed = 0;

        $due = Connection::query()
            ->where('status', ConnectionStatus::Active->value)
            ->whereNotNull('refresh_token')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $now->copy()->addSeconds($leadSeconds))
            ->get();

        foreach ($due as $connection) {
            if ($this->refresh($connection)) {
                $refreshed++;
            }
        }

        return $refreshed;
    }

    /**
     * The delivery path's pre-flight guard (H16a): renew this ONE grant if it is already expired or about to
     * be, otherwise do nothing at all.
     *
     * Returns whether a refresh was performed — `false` covers both "not due" and "the refresh was refused",
     * which the caller cannot conflate because a refusal also flips `$connection->status` away from Active
     * (through the same {@see markFailed()} path the sweep uses, so the owner is notified exactly once and by
     * one code path). Callers therefore re-read the status rather than trusting this return value.
     *
     * Deliberately reuses {@see refresh()} rather than reimplementing the grant application: a second place
     * that writes a credential and decides when one is dead is precisely how the two would drift.
     */
    public function ensureFresh(Connection $connection, Carbon $now): bool
    {
        $leadSeconds = (int) config('connectors.delivery_refresh_lead_seconds', 120);

        // `needsRefresh()` already encodes "no refresh token or no expiry ⇒ never due", which is what makes
        // this a no-op for Slack rather than something Slack has to opt out of.
        if ($connection->status !== ConnectionStatus::Active || ! $connection->needsRefresh($now, $leadSeconds)) {
            return false;
        }

        return $this->refresh($connection);
    }

    private function refresh(Connection $connection): bool
    {
        $refreshToken = $connection->refresh_token;

        if ($refreshToken === null) {
            return false; // re-checked after load: the row could have been disconnected mid-sweep
        }

        try {
            $grant = $this->registry->for($connection->provider)->refresh($refreshToken);
        } catch (ConnectorOAuthException $e) {
            // TERMINAL ONLY (H16a). `invalid_grant` means the tenant removed our app and no retry can change
            // that, so the grant is marked dead. A TIMEOUT means nothing about the credential — and killing a
            // connection over one would clear both tokens, pause every rule on it and require a human to
            // re-run OAuth, all because Google was briefly unreachable. This distinction was unreachable in
            // H15a (Slack's tokens never expire, so nothing refreshed) and is load-bearing from H16a on,
            // because the delivery path now pre-flights a refresh on every Google send.
            if ($e->terminal) {
                $this->markFailed($connection, $e->errorCode);
            }

            return false;
        } catch (UnknownConnectorProviderException) {
            // The provider was disabled in config (an outage, a pulled app registration). Leave the grant
            // untouched and alive — this is our configuration state, not the tenant's credential going bad.
            return false;
        }

        $this->connections->applyRefreshedGrant($connection, $grant);

        return true;
    }

    private function markFailed(Connection $connection, string $errorCode): void
    {
        $providerLabel = $connection->provider->label();
        $accountLabel = $connection->external_account_label;

        $this->connections->markDead($connection, ConnectionStatus::RefreshFailed, $errorCode);

        $this->notifyOwner((string) $connection->tenant_id, $providerLabel, $accountLabel);
    }

    /**
     * Best-effort owner notification, on the H3 queued-mail substrate via an on-demand notifiable (the
     * `DeliverWebhookJob::notifyAutoDisabled()` recipe). A missing tenant/owner/email never fails the sweep.
     */
    private function notifyOwner(string $tenantId, string $providerLabel, string $accountLabel): void
    {
        $ownerId = Tenant::query()->whereKey($tenantId)->value('owner_user_id'); // RLS-exempt central table

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
}
