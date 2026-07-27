<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\ConnectorProviderKey;

/**
 * The verified claims of an OAuth `state` token (ADR-0009 §D3, H15a): which tenant the callback may write
 * into, which user started the flow (recorded as `connections.connected_by` and used as the audit actor),
 * and which provider the token is valid for.
 *
 * Only ever constructed by {@see ConnectorOAuthStateService::verify()} — i.e. after the signature has been
 * checked — so a value of this type in the request means "these claims were signed by us and have not
 * expired," which is exactly the guarantee the pre-RLS middleware acts on.
 */
final readonly class ConnectorOAuthState
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public ConnectorProviderKey $provider,
        public int $expiresAt,
    ) {}
}
