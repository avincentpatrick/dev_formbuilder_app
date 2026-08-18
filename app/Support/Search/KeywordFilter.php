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
     *
     * The `literal-string` types on the callers are the STATIC half of that same argument: PHPStan refuses
     * to pass anything request-derived into these methods at all, so the runtime check below is a backstop
     * rather than the only guard. It is also what lets `whereRaw()`/`orderByRaw()` accept the composed SQL,
     * since a literal-string concatenated with literal-strings stays literal.
     *
     * @param  literal-string  $qualifiedColumn
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
     * @param  literal-string  $qualifiedColumn
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
     * The `ILIKE` twin of {@see apply()}, for entities with no tsvector (Increment J1c). Every token must
     * match at least one of the columns — AND across tokens, OR across columns — which mirrors what
     * `tsQuery()` does with `&` and keeps the two arms' behaviour recognisably the same.
     *
     * ⚠️ THE OUTER CLOSURE GROUP IS LOAD-BEARING FOR EXACTLY THE REASON {@see apply()}'s IS, and the
     * per-token inner group is a SECOND one that matters just as much: without it, `(a OR b) AND (c OR d)`
     * would flatten to `a OR b AND c OR d`, and a two-word member search would return everyone matching
     * either word in either column. Both groups are emitted here, unconditionally, so associativity is a
     * property of this helper rather than of every call site's discipline.
     *
     * ⚠️ `ESCAPE '!'` MUST MATCH {@see SearchTerms::likePatterns()}, which escapes with `!`. Read that
     * method's docblock before changing either — a mismatch silently turns a literal underscore back into
     * a wildcard.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string>  $qualifiedColumns
     * @return Builder<TModel>
     */
    public static function applyLike(Builder $query, SearchTerms $terms, array $qualifiedColumns): Builder
    {
        if ($terms->isEmpty() || $qualifiedColumns === []) {
            return $query;
        }

        foreach ($qualifiedColumns as $column) {
            self::assertColumn($column);
        }

        $patterns = $terms->likePatterns();

        return $query->where(function (Builder $outer) use ($patterns, $qualifiedColumns): void {
            foreach ($patterns as $pattern) {
                $outer->where(function (Builder $inner) use ($pattern, $qualifiedColumns): void {
                    foreach ($qualifiedColumns as $column) {
                        $inner->orWhereRaw("{$column} ILIKE ? ESCAPE '!'", [$pattern]);
                    }
                });
            }
        });
    }

    /**
     * The ORDER BY fragment. Bind `$terms->tsQuery()` as the single parameter.
     *
     * ⚠️ THE REGCONFIG HERE MUST MATCH THE GENERATED COLUMN'S, AND A MISMATCH IS SILENT — BUT SILENT IN THE
     * **CORRECTNESS** DIMENSION, NOT THE PERFORMANCE ONE. Both are the SQL literal `'simple'`, never a bound
     * parameter — `to_tsquery(?, ?)` would need a `::regconfig` cast and would invite a future refactor to
     * make it dynamic.
     *
     * An earlier draft of this note claimed a mismatch would "stop using the GIN index and degrade to a
     * sequential scan, which no functional test can see". All three clauses were wrong, and the correction is
     * worth keeping because the wrong version sends you off to build the wrong guard:
     *
     *   - There is no index to lose. The search vectors carry no GIN at all — see 2026_08_11_000001's
     *     "THERE IS NO GIN INDEX" section for the RLS/leakproof measurement that explains why.
     *   - Even if there were one, a regconfig mismatch would not cost it. An index on a STORED `tsvector`
     *     **column** is matched on the operator and the column; the right-hand side only has to be
     *     rel-independent, and `to_tsquery('english', ?)` is IMMUTABLE and qualifies exactly as `'simple'`
     *     does. (Plan-sensitivity to the regconfig is real for the EXPRESSION-index idiom,
     *     `GIN (to_tsvector('simple', title))` — conflating the two idioms is how the note went wrong.)
     *   - What actually changes is WHICH ROWS COME BACK. `'english'` stems, so a search for "running" looks
     *     for `run` in a vector storing `running`; and it strips stopwords, so "the" lowers to an EMPTY
     *     tsquery, which matches no row at all. A functional case over one such word sees it immediately —
     *     `SearchRegconfigParityTest` is that guard.
     *
     * `SearchIndexUsageTest` exists for a different question entirely: whether a keyword predicate on an
     * RLS-protected table can become an index qual in this deployment, and what bounds the work when it
     * cannot.
     *
     * @param  literal-string  $qualifiedColumn
     * @return literal-string
     */
    public static function rankSql(string $qualifiedColumn): string
    {
        self::assertColumn($qualifiedColumn);

        return "ts_rank('".self::WEIGHTS."', {$qualifiedColumn}, to_tsquery('simple', ?)) DESC";
    }
}
