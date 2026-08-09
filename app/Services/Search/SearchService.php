<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SearchEntity;
use App\Models\User;
use App\Support\Search\SearchTerms;

/**
 * Fans a query out across the permitted arms and collects their results (Increment J1b).
 *
 * ⚠️ THE FAN-OUT IS PER-ARM AND THE UNION HAPPENS IN PHP, NOT IN SQL, AND THAT IS THE SECURITY DESIGN.
 * A `UNION` across `forms`, `submissions` and `users` in one statement would have to carry ONE `WHERE` for
 * all three, and the three visibility rules genuinely differ — forms key on `forms.edit.any` + Editor grants,
 * submissions on `dashboard.org.view` + any-capacity grants + a respondent clause. Every arm therefore
 * applies its own gate and its own row predicate to its own table, and only the ROWS are merged. There is no
 * query in this codebase that reads across the searchable tables at once.
 */
final readonly class SearchService
{
    /** Rows per arm on the grouped "everything" view. Small on purpose — it is a preview, not a report. */
    public const int PER_ENTITY_PREVIEW = 5;

    /**
     * Rows on the single-entity view.
     *
     * ⚠️ THIS IS A CAP, NOT A PAGE SIZE — there is no paginator behind it, and the UI says so out loud.
     * Grouped results cannot be paginated coherently ("page 2 of what?" across four arms with four different
     * predicates), and a per-arm paginator is a bigger surface than J1b should carry. Pagination on the
     * single-entity view is filed rather than half-built; what must never happen is a silent truncation, so
     * the presenter emits `has_more` and the page renders it.
     */
    public const int SINGLE_ENTITY_LIMIT = 50;

    /** @param list<SearchArm> $arms display order */
    public function __construct(private array $arms) {}

    /**
     * @return list<SearchEntity> the arms this user may use at all
     */
    public function permitted(User $user): array
    {
        return array_values(array_map(
            static fn (SearchArm $arm): SearchEntity => $arm->entity(),
            array_filter($this->arms, static fn (SearchArm $arm): bool => $arm->allowed($user)),
        ));
    }

    /**
     * @return list<SearchArmResult> in display order; refused arms are ABSENT, never present-and-empty
     */
    public function results(User $user, SearchTerms $terms, ?SearchEntity $only, int $limit): array
    {
        if ($terms->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($this->arms as $arm) {
            if (! $arm->allowed($user)) {
                continue;
            }
            if ($only !== null && $arm->entity() !== $only) {
                continue;
            }

            $results[] = $arm->search($user, $terms, $limit);
        }

        return $results;
    }

    /**
     * Visible totals, keyed by entity value.
     *
     * ⚠️ A REFUSED ARM HAS NO KEY HERE — never a `0`. The distinction is the whole disclosure story: `0` is a
     * CLAIM ("there are none of these"), while an absent key is the truth ("you cannot ask that question").
     * A Reviewer seeing "Forms (0)" would learn the shape of a surface they are refused; seeing no Forms
     * section at all tells them nothing about the tenant.
     *
     * @return array<string, int>
     */
    public function counts(User $user, SearchTerms $terms, ?SearchEntity $only): array
    {
        if ($terms->isEmpty()) {
            return [];
        }

        $counts = [];

        foreach ($this->arms as $arm) {
            if (! $arm->allowed($user)) {
                continue;
            }
            if ($only !== null && $arm->entity() !== $only) {
                continue;
            }

            $counts[$arm->entity()->value] = $arm->count($user, $terms);
        }

        return $counts;
    }
}
