<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Domain;
use App\Services\Tenancy\CustomDomainService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One custom domain and everything a tenant needs to finish setting it up (H22a / ADR-0012).
 *
 * `status` is DERIVED here rather than stored — the database keeps three nullable timestamps precisely so
 * no column default can be fail-open (see the H22a migration). This is the one place the derivation is
 * turned back into a word, so H22b's UI and any API consumer read the same vocabulary.
 *
 * The verification block is returned on every state, not only while pending, because a tenant that has
 * lost its TXT record needs it back to recover — and it is not a secret: the token lives in public DNS at
 * a name only that zone's controller can write, which is exactly why it is not in AuditRedactor::SECRETS
 * either (ADR-0012 records that decision).
 *
 * @property Domain $resource
 */
final class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $domain = $this->resource;
        $service = app(CustomDomainService::class);
        $token = (string) $domain->verification_token;

        return [
            'domain' => $domain->domain,
            'status' => match (true) {
                $domain->activated_at !== null => 'live',
                $domain->verified_at !== null => 'verified',
                default => 'pending',
            },
            'verification' => [
                'type' => 'TXT',
                'name' => $service->challengeName($domain->domain),
                'value' => $service->expectedValue($token),
            ],
            'verified_at' => $domain->verified_at?->toIso8601String(),
            // Named `activated_at` rather than `live_since` so the API vocabulary matches the column and
            // the artisan command a support conversation will actually reference.
            'activated_at' => $domain->activated_at?->toIso8601String(),
            'last_checked_at' => $domain->verification_checked_at?->toIso8601String(),
            'failure_reason' => $domain->verification_failure_reason?->value,
            // Stated in the payload because it is the single most-asked question about this feature and
            // the answer is counter-intuitive: proving control does NOT put the domain into service.
            'awaiting_operator' => $domain->verified_at !== null && $domain->activated_at === null,
            // H22b — the tenant's chosen respondent-facing host among its live domains. Always false on a
            // domain that is not live; `domains_primary_requires_live_chk` makes that a database property
            // rather than a convention this resource has to restate.
            'is_primary' => (bool) $domain->is_primary,
        ];
    }
}
