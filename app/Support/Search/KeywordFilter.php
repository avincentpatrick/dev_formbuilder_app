<?php

declare(strict_types=1);

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The one place a full-text predicate is attached to a query (Increment J1b).
 *
 * ⚠️ THE CLOSURE GROUP IS THE ENTIRE POINT AND MUST NOT BE "SIMPLIFIED" AWAY.
 * Callers compose this onto builders that already carry an OR. `Submission::scopeVisibleTo()` is the live
 * one — it adds `(granted forms OR I am the respondent)` — and `(A OR B) AND C` is not `A OR (B AND C)`.
 * Flattened, a reviewer searching a keyword would additionally receive every submission they ever authored,
 * of any status, on any form: the search would silently return rows the inbox refuses.
 *
 * Because the group is emitted HERE, unconditionally, associativity is a property of this helper rather than
 * of every call site's discipline. That is the same conclusion `Submission::scopeVisibleTo()` reaches from
 * the other direction, in its own words: "correctness that depends on how you were called is worth one
 * closure to make unconditional."
 *
 * It is also a no-op on empty terms, so `->tap(fn ($q) => KeywordFilter::apply($q, $terms, ...))` is safe on a
 * bare list-page visit with no `?q=` at all and no call site needs its own `when()`.
 */
final class KeywordFilter
{
    /**
     * The `{D,C,B,A}` weight array, spelled out rather than left to `ts_rank`'s default so that changing it is
     * a visible diff rather than an invisible behaviour change. These ARE the defaults today.
     */
    private const string WEIGHTS = '{0.1, 0.2, 0.4, 1.0}';

    /**
     * A qualified column is code-authored, never request data — but it is interpolated into SQL, so it is
     * validated anyway. The check is what lets a reader confirm there is no injection surface here without
     * having to audit every call site, and it is the same posture `TenantIsolation` takes for identifiers.
     */
    private static function assertColumn(string $qualifiedColumn): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $qualifiedColumn) !== 1) {
            throw new InvalidArgumentException(
                "Refusing to build a search predicate on the non-identifier [{$qualifiedColumn}]."
            );
        }
    }

    /**
     * Add `column @@ to_tsquery('simple', ?)`, always inside its own `where(closure)` group.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, SearchTerms $terms, string $qualifiedColumn): Builder
    {
        if ($terms->isEmpty()) {
            return $query;
        }

        self::assertColumn($qualifiedColumn);

        return $query->where(function (Builder $inner) use ($terms, $qualifiedColumn): void {
            $inner->whereRaw(
                "{$qualifiedColumn} @@ to_tsquery('simple', ?)",
                [$terms->tsQuery()]
            );
        });
    }

    /**
     * The ORDER BY fragment. Bind `$terms->tsQuery()` as the single parameter.
     *
     * ⚠️ THE REGCONFIG HERE MUST MATCH THE GENERATED COLUMN'S, AND A MISMATCH IS SILENT. Both are the SQL
     * literal `'simple'`, never a bound parameter — `to_tsquery(?, ?)` would need a `::regconfig` cast and
     * would invite a future refactor to make it dynamic. If the two ever diverge the query still returns
     * rows; it just stops using the GIN index and degrades to a sequential scan, which no functional test can
     * see. `SearchIndexUsageTest` reads the actual plan for exactly this reason.
     */
    public static function rankSql(string $qualifiedColumn): string
    {
        self::assertColumn($qualifiedColumn);

        return "ts_rank('".self::WEIGHTS."', {$qualifiedColumn}, to_tsquery('simple', ?)) DESC";
    }
}
