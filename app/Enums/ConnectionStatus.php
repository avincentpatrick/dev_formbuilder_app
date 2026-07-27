<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Connection;
use App\Services\Connectors\ConnectionTokenRefresher;

/**
 * The lifecycle state of a {@see Connection} — the platform's OAuth grant on a tenant's third-party
 * workspace (ADR-0009, H15a). The `connections_status_check` CHECK is generated from {@see values()}.
 *
 *   - {@see Active}        — the credential works; subscriptions on it deliver.
 *   - {@see RefreshFailed} — the provider rejected a token refresh ({@see ConnectionTokenRefresher}), so the
 *     grant is dead through no action of ours. Deliveries stop; the tenant owner is notified once and must
 *     re-connect. Distinct from {@see Revoked} so the UI can say *why* (H15b).
 *   - {@see Revoked}       — the credential is gone: either the tenant disconnected (ADR-0009 §D7 — local
 *     tokens are destroyed unconditionally, provider revocation is best-effort) or a delivery attempt was
 *     rejected with a credential error (`invalid_auth`/`token_revoked`), which is the provider telling us
 *     the grant no longer exists.
 *
 * Only {@see Active} connections deliver; the fan-out and the delivery job both filter on it.
 */
enum ConnectionStatus: string
{
    case Active = 'active';
    case RefreshFailed = 'refresh_failed';
    case Revoked = 'revoked';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
