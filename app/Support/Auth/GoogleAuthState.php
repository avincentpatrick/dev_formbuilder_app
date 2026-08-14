<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * A verified Google sign-in `state`, decoded (Increment J3c2).
 *
 * ⚠️ `tenantId` IS NULLABLE AND THAT IS THE CENTRAL-HOST ARM, NOT A MISSING VALUE. A sign-in begun on the
 * central host belongs to no workspace — exactly like a central-host registration, which produces an
 * account with no membership — so the callback forks on this being null (ADR-0017 §D12). The connector
 * equivalent has no such case, because a connector is always a tenant's.
 *
 * ⚠️ AND THERE IS NO `uid`, unlike the connector state. That token names the user who STARTED the flow,
 * because a connector is attributed to them; here the user is the OUTCOME of the flow and is not known
 * until Google answers. A `uid` claim would be a value nobody could fill honestly at mint time.
 */
final readonly class GoogleAuthState
{
    public function __construct(
        public ?string $tenantId,
        public string $stateId,
        public int $expiresAt,
    ) {}

    public function isCentral(): bool
    {
        return $this->tenantId === null;
    }
}
