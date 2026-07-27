<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\AuditEvent;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Enums\ConnectorSubscriptionStatus;
use App\Models\Connection;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use App\Support\Connectors\ConnectorGrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates, refreshes and tears down the platform's OAuth grants (ADR-0009, H15a) — the orchestration behind
 * the callback controller, the refresh sweep and the disconnect route, keeping all three thin.
 *
 * Every mutation writes an audit row under the `connection` alias, whose `access_token`/`refresh_token`
 * {@see AuditRedactor} strips unconditionally (ADR-0009 §D10). The audit snapshot deliberately INCLUDES the
 * tokens for exactly that reason: the redactor is what removes them, and it also records that it did — a
 * snapshot that quietly omitted them would leave no evidence the ledger ever saw a credential-bearing write.
 */
final class ConnectionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Store a freshly-granted credential, updating the tenant's existing grant for the same workspace in
     * place rather than accumulating duplicates (the partial unique on
     * `(tenant_id, provider, external_account_id) WHERE deleted_at IS NULL` is the DB-side guarantee).
     *
     * Re-connecting also RESTORES a previously disconnected grant: the tenant is standing in front of the
     * provider's consent screen having just re-authorized us, so refusing to reuse the soft-deleted row would
     * only produce a unique-violation on a workflow the tenant explicitly asked for.
     */
    public function upsertFromGrant(
        ConnectorProviderKey $provider,
        ConnectorGrant $grant,
        string $tenantId,
        ?string $userId,
    ): Connection {
        return DB::transaction(function () use ($provider, $grant, $tenantId, $userId): Connection {
            $connection = Connection::withTrashed()
                ->where('provider', $provider->value)
                ->where('external_account_id', $grant->externalAccountId)
                ->first();

            $isNew = $connection === null;
            $old = $isNew ? null : $this->auditValues($connection);

            $connection ??= new Connection;

            $connection->forceFill([
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'external_account_id' => $grant->externalAccountId,
                'external_account_label' => $grant->externalAccountLabel,
                'scopes' => $grant->scopes,
                'access_token' => $grant->accessToken,
                'refresh_token' => $grant->refreshToken,
                'token_expires_at' => $grant->expiresAt,
                'status' => ConnectionStatus::Active,
                'last_error' => null,
                'last_error_at' => null,
                'connected_by' => $userId,
                'deleted_at' => null,
            ])->save();

            $this->audit->record(
                $isNew ? AuditEvent::Created : AuditEvent::Updated,
                'connection',
                (string) $connection->getKey(),
                $old,
                $this->auditValues($connection),
            );

            return $connection;
        });
    }

    /**
     * Apply a refreshed grant (ADR-0009 §D6). A provider that returns no new refresh token expects the stored
     * one to be reused, so a null is PRESERVED rather than written — nulling it would silently convert a
     * rotating grant into an unrefreshable one on its first successful refresh.
     *
     * Not audited: a successful machine refresh changes no tenant-visible configuration, and auditing every
     * sweep would bury the connect/disconnect events that matter in noise. `last_refreshed_at` is the record.
     */
    public function applyRefreshedGrant(Connection $connection, ConnectorGrant $grant): Connection
    {
        $connection->forceFill([
            'access_token' => $grant->accessToken,
            'refresh_token' => $grant->refreshToken ?? $connection->refresh_token,
            'token_expires_at' => $grant->expiresAt,
            'status' => ConnectionStatus::Active,
            'last_refreshed_at' => Carbon::now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        return $connection;
    }

    /**
     * Mark a grant dead and stop its subscriptions delivering (ADR-0009 §D6/§D7). Used for both a rejected
     * refresh (`refresh_failed`) and a provider rejecting the credential mid-delivery (`revoked`).
     *
     * The tokens are cleared in both cases: a credential the provider has disowned is not worth holding, and
     * ADR-0009's custody rules say the safest state for a dead token is "gone".
     */
    public function markDead(Connection $connection, ConnectionStatus $status, string $errorCode): Connection
    {
        return DB::transaction(function () use ($connection, $status, $errorCode): Connection {
            $old = $this->auditValues($connection);

            $connection->forceFill([
                'access_token' => '',
                'refresh_token' => null,
                'status' => $status,
                'last_error' => mb_substr($errorCode, 0, 255),
                'last_error_at' => Carbon::now(),
            ])->save();

            $this->pauseSubscriptions($connection);

            $this->audit->record(
                AuditEvent::Updated,
                'connection',
                (string) $connection->getKey(),
                $old,
                $this->auditValues($connection),
            );

            return $connection;
        });
    }

    /**
     * Tenant-initiated disconnect (ADR-0009 §D7). Local destruction is UNCONDITIONAL and happens here; the
     * caller may attempt a best-effort provider revoke first, but its outcome never gates this — inverting
     * that order would leave a live token in the database precisely when the provider is unreachable, which
     * is when a tenant is most likely to be disconnecting.
     *
     * Soft delete, so the shared delivery ledger keeps its history: an outbound-delivery record is
     * operational history, not credential material.
     */
    public function disconnect(Connection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            $old = $this->auditValues($connection);

            $connection->forceFill([
                'access_token' => '',
                'refresh_token' => null,
                'status' => ConnectionStatus::Revoked,
            ])->save();

            $this->pauseSubscriptions($connection);

            $connection->delete(); // soft-delete

            $this->audit->record(
                AuditEvent::Deleted,
                'connection',
                (string) $connection->getKey(),
                $old,
                null,
            );
        });
    }

    /**
     * A dead grant cannot deliver, so its rules are paused rather than left Active and silently failing. They
     * are NOT deleted: re-connecting the same workspace restores the grant, and the tenant's routing should
     * survive that round trip.
     */
    private function pauseSubscriptions(Connection $connection): void
    {
        $connection->subscriptions()
            ->where('status', ConnectorSubscriptionStatus::Active->value)
            ->update(['status' => ConnectorSubscriptionStatus::Paused->value]);
    }

    /**
     * The audit snapshot. Includes both tokens deliberately — {@see AuditRedactor} strips them under the
     * `connection` alias and records the strip in `redacted_fields`, so the plaintext never reaches the ledger
     * while the fact that a credential-bearing write happened does.
     *
     * @return array<string, mixed>
     */
    private function auditValues(Connection $connection): array
    {
        return [
            'provider' => $connection->provider->value,
            'external_account_id' => $connection->external_account_id,
            'external_account_label' => $connection->external_account_label,
            'scopes' => $connection->scopes,
            'status' => $connection->status->value,
            'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
        ];
    }
}
