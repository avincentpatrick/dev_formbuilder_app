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
 * PROACTIVE, NEVER LAZY. Refreshing inside a delivery attempt would put a second outbound call and a
 * credential write inside the delivery transaction, and would let a provider outage stampede every queued
 * delivery into simultaneous refresh attempts. Here, one sweep touches each due grant once.
 *
 * A grant with no expiry or no refresh token can never be refreshed and is skipped rather than failed —
 * that is the NORMAL case for Slack, whose bot tokens do not expire unless the workspace enables rotation.
 * The path exists for rotation-enabled workspaces and for H16's providers, which expire aggressively.
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

    private function refresh(Connection $connection): bool
    {
        $refreshToken = $connection->refresh_token;

        if ($refreshToken === null) {
            return false; // re-checked after load: the row could have been disconnected mid-sweep
        }

        try {
            $grant = $this->registry->for($connection->provider)->refresh($refreshToken);
        } catch (ConnectorOAuthException $e) {
            $this->markFailed($connection, $e->errorCode);

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
