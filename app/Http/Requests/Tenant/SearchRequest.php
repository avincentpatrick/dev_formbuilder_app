<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\SearchEntity;
use App\Support\Search\SearchTerms;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /search`'s query string (Increment J1b).
 *
 * ⚠️ EVERY RULE IS `sometimes` AND NOTHING IS `required` — the reason `AuditLogFilterRequest` records, and it
 * bites harder here: a bare `/search` with no query string is the NORMAL first visit (the nav field links
 * straight to it), and a 422 on an Inertia GET redirects "back", which on a cold visit is nowhere at all.
 *
 * ⚠️ `entity` IS A LOOSE STRING, DELIBERATELY NOT `Rule::enum(SearchEntity::class)`. A bookmarked or
 * hand-edited `?entity=widgets` must render an ordinary empty page, not a validation failure — and once
 * J1c/J1d add arms, a link shared between two users with different permissions would otherwise 422 for
 * whichever of them cannot use that arm. `entity()` resolves unknown values to `null`, i.e. "all".
 */
final class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization here is ENTIRELY per-arm, inside SearchService: each arm answers `allowed()` from an
        // existing permission and then applies its own row predicate. A route- or request-level gate would be
        // either too wide (letting a Reviewer's request reach an arm they may not use) or too narrow
        // (refusing a Viewer the submissions arm they legitimately hold).
        return true;
    }

    /**
     * ⚠️ NO `max:` ON EITHER FIELD, AND ITS ABSENCE IS THE POINT — a first draft had `max:200` on `q` and it
     * contradicted this class's own docblock. A 1,400-character query is not a validation failure to report;
     * it is a query to CLAMP, which {@see SearchTerms::parse()} does at exactly
     * {@see SearchTerms::MAX_RAW_LENGTH}. With the rule in place a long paste 422'd and redirected "back" —
     * i.e. nowhere on a cold visit — which is the precise failure the rule was supposed to be protecting
     * against. Every bound in this feature belongs in the sanitiser, where it degrades instead of refusing.
     *
     * `string` is the only real constraint left: it rejects `?q[]=x`, which would otherwise reach
     * `mb_substr()` as an array.
     *
     * ⚠️ AND `nullable` IS LOAD-BEARING NEXT TO `sometimes`, WHICH IS NOT OBVIOUS. Laravel's global
     * `TrimStrings` and `ConvertEmptyStringsToNull` middleware run BEFORE validation, so `?q=%20%20%20`
     * arrives as `q => null`: the key is PRESENT (so `sometimes` does not skip it) and the value is null (so
     * `string` fails). Without `nullable`, every whitespace-only search — including one produced by a user
     * clearing the box and pressing Enter — 422s and redirects "back" to nowhere. Found by a test, not by
     * reading; a unit-level `validator()` call passes because it never runs the middleware.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string'],
            'entity' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function terms(): SearchTerms
    {
        $raw = $this->query('q');

        return SearchTerms::parse(is_string($raw) ? $raw : null);
    }

    public function entity(): ?SearchEntity
    {
        $raw = $this->query('entity');

        return is_string($raw) ? SearchEntity::tryFrom($raw) : null;
    }
}
