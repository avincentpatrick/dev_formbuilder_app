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
 * Authorization is on the route ({@see ApiAbilities::MANAGE_DOMAINS} +
 * `can:tenant.settings.manage`), not here, matching every other Group-B request in this namespace.
 */
final class StoreDomainRequest extends FormRequest
{
    // ⛔ M92 — THIS CLASS DOCBLOCK IS PUBLISHED API DOCUMENTATION, WHICH IS WHY THIS NOTE IS DOWN HERE.
    // Scramble emits a FormRequest's class docblock verbatim as the `description` of its schema in
    // `openapi.json`, so an internal maintenance note written up there ships to integrators and reddens
    // the Contract job as drift. Three paragraphs of gate archaeology were written above and caught
    // exactly that way; anything that is not about the request CONTRACT belongs inside the class body.
    //
    // What those paragraphs said, kept where it costs nothing:
    //   · The test name above was corrected — the one it used to carry has never existed in any commit.
    //     The assertion is real: `tests/Feature/Api/CustomDomainApiTest.php`, "it ignores verification
    //     fields smuggled into the claim body", which posts both fields and asserts `pending` with both
    //     timestamps null on the unscoped row.
    //   · It is written as a PATH rather than a fully-qualified `@see` because Pint's
    //     `fully_qualified_strict_types` rewrites one into a real `use`, putting a test-class import in
    //     production code (measured in M88).
    //   · The old name is deliberately not quoted anywhere in this file: `scripts/test-pointer-lint.php`
    //     reads identifiers rather than intent, so naming the corpse re-arms the gate against the very
    //     comment that fixed it.

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
