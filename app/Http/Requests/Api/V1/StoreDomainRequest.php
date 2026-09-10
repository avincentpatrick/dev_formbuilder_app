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
 * array explicitly and never passes request data through, and CustomDomainApiTest asserts that a store
 * request carrying those fields still lands pending — belt and braces, because either alone would be
 * enough and neither alone is obviously enough to a future reader.
 *
 * ⚠️ M92 — THE NAME WAS CORRECTED, NOT THE CLAIM. This used to cite a file that has never existed in any
 * commit. The assertion it describes is real and lives in `tests/Feature/Api/CustomDomainApiTest.php` as
 * *"it ignores verification fields smuggled into the claim body"*, which posts both fields and asserts
 * `pending` with both timestamps null on the unscoped row. A pointer at coverage that does not exist is
 * strictly worse than no pointer, which is what `scripts/test-pointer-lint.php` now refuses.
 *
 * ⚠️ Written as a PATH rather than as a fully-qualified `@see` deliberately: Pint's
 * `fully_qualified_strict_types` rewrites one into a real `use` statement, which would put a test-class
 * import in production code. Measured in M88.
 *
 * ⚠️ And the OLD name is deliberately not quoted here. The gate reads identifiers, not intent, so a
 * correction note naming the corpse re-arms the gate against itself — which is what the one standing
 * EXEMPTION exists for, and eight more of those would make the list a list of false statements. Git
 * carries the provenance.
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
