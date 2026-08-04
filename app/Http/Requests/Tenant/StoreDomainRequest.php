<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Rules\ClaimableDomain;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Claim a custom domain from the admin UI (Increment H22b / ADR-0012).
 *
 * The session-authed twin of {@see \App\Http\Requests\Api\V1\StoreDomainRequest}, and deliberately a
 * SEPARATE class rather than a reuse of it: the `Api\V1` namespace is versioned, and a web surface that
 * imported from it would make an API version bump a UI change. What must not diverge is the RULE, and it
 * does not — both compose the one {@see ClaimableDomain}, which is also the predicate
 * `InitializeTenancyByPublicHost` routes by. If the claim rule and the routing rule
 * ever drift, the drift is silent, which is why there is exactly one of them.
 *
 * `domain` is the only accepted field, and that is a security property rather than tidiness: stancl's
 * Domain model is `$guarded = []`, so a request permitted to carry `verified_at`, `activated_at` or
 * `is_primary` and handed to `create()` would let a tenant mark its own hostname live.
 *
 * Authorization is on the route (`can:tenant.settings.manage` + `feature:custom_domain`) — there is no
 * `ability:` here, because that is Sanctum token-scope middleware and a session request carries no token.
 */
final class StoreDomainRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', new ClaimableDomain],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        // The field renders as "Domain" on the page, so the validator's messages should say so rather than
        // leak the wire name.
        return ['domain' => 'domain'];
    }
}
