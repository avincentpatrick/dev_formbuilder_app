<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Rules\ClaimableDomain;
use App\Support\Api\ApiAbilities;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Claim a custom domain for the current tenant (H22a / ADR-0012).
 *
 * `domain` is the ONLY accepted field, and that is a security property rather than tidiness: stancl's
 * Domain model is `$guarded = []`, so a request permitted to carry `verified_at` or `activated_at` and
 * handed to `create()` would let a tenant mark its own hostname live. The service builds its attribute
 * array explicitly and never passes request data through, and CustomDomainClaimTest asserts that a store
 * request carrying those fields still lands pending — belt and braces, because either alone would be
 * enough and neither alone is obviously enough to a future reader.
 *
 * Authorization is on the route ({@see ApiAbilities::MANAGE_DOMAINS} +
 * `can:tenant.settings.manage`), not here, matching every other Group-B request in this namespace.
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
}
