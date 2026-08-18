<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Tenant\AuditLogFilterRequest;
use App\Http\Requests\Tenant\SearchRequest;
use App\Support\Search\SearchTerms;
use Illuminate\Http\Request;

/**
 * Reads `?q=` off a list page's query string (Increment J1e).
 *
 * Five of the six row-lists reach their presenter through a bare `Request` rather than a FormRequest, so
 * this is where their keyword arrives. The sixth, the audit log, already had
 * {@see AuditLogFilterRequest} and parses `q` there instead — same
 * {@see SearchTerms::parse()}, same rules, one extra `sometimes|nullable|string`.
 *
 * ⚠️ NO FORMREQUEST WAS ADDED FOR THE OTHER FIVE, AND THAT IS A DECISION. `q` has exactly one validation
 * rule worth having — "is it a string" — and the `is_string()` check below IS that rule, applied at the only
 * place the value is read. Introducing five FormRequests to express it would add five files whose rule sets
 * are otherwise empty, and would put a 422 in the path of a GET that must never produce one. Everything
 * else this feature could validate is a BOUND, and every bound lives in `SearchTerms::parse()`, where it
 * clamps instead of refusing — see {@see SearchRequest::rules()} for why a `max:` on a
 * search box is the bug rather than the guard.
 *
 * ⚠️ THE `is_string()` GUARD IS LOAD-BEARING, NOT A TYPE FORMALITY. `?q[]=x` arrives as an ARRAY, and
 * without it that array reaches `mb_substr()` and 500s the page. This is the same
 * `AuditLogFilterRequest::stringOrNull()` idiom, and the same reason.
 */
trait ReadsKeywordFilter
{
    protected function keyword(Request $request): SearchTerms
    {
        $raw = $request->query('q');

        return SearchTerms::parse(is_string($raw) ? $raw : null);
    }
}
