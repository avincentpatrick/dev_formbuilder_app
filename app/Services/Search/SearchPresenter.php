<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SearchEntity;
use App\Models\User;
use App\Support\Search\SearchTerms;

/**
 * The `/search` page's props (Increment J1b).
 *
 * Follows the shape every list surface in this app emits — `data` / `filters.applied` / `can` /
 * `empty_reason` — so the page reads like `audit/Index.vue` and `feedback/Index.vue` rather than inventing a
 * fourth convention. `meta` is deliberately absent: see {@see SearchService::SINGLE_ENTITY_LIMIT}.
 */
final readonly class SearchPresenter
{
    public function __construct(private SearchService $search) {}

    /**
     * @return array<string, mixed>
     */
    public function index(User $user, SearchTerms $terms, ?SearchEntity $only): array
    {
        $permitted = $this->search->permitted($user);
        $limit = $only !== null ? SearchService::SINGLE_ENTITY_LIMIT : SearchService::PER_ENTITY_PREVIEW;

        $results = $this->search->results($user, $terms, $only, $limit);
        $counts = $this->search->counts($user, $terms, $only);

        return [
            'data' => array_map(fn (SearchArmResult $r): array => [
                'entity' => $r->entity->value,
                'label' => $r->entity->label(),
                'items' => $r->rows,
                'has_more' => $r->hasMore,
            ], $results),
            'counts' => $counts,
            'filters' => [
                // Only the arms this user may actually use. Advertising a Forms filter to a Reviewer who
                // would get an empty list from it is the same disclosure as a `0` count, one click later.
                'entities' => array_map(static fn (SearchEntity $e): array => [
                    'value' => $e->value,
                    'label' => $e->label(),
                ], $permitted),
                'applied' => [
                    'q' => $terms->raw(),
                    'entity' => $only?->value,
                ],
            ],
            'limits' => [
                'per_entity' => SearchService::PER_ENTITY_PREVIEW,
                'single_entity' => SearchService::SINGLE_ENTITY_LIMIT,
            ],
            'empty_reason' => $this->emptyReason($terms, $permitted, $results),
        ];
    }

    /**
     * The ⌘K palette's payload (Increment J1d) — plain JSON, never an Inertia response.
     *
     * ⚠️ NO `counts` KEY, AND THAT IS A DELIBERATE DIFFERENCE FROM {@see index()}. `hasMore` already comes
     * free from the arms' `limit + 1` overfetch, so the palette gets its "there is more" signal without a
     * second query — whereas `counts()` runs a real `COUNT(*)` per arm, which is the wrong shape on a path
     * that fires on every debounce tick. Omitting it also keeps the count-disclosure question off the hot
     * path entirely rather than answering it twice.
     *
     * Everything else is inherited unchanged: the same arms, the same gates, the same absent-not-zero rule
     * for a refused arm. **This endpoint adds no authorization surface** — it is `index()` with a smaller
     * limit and a smaller envelope, which is why it reuses `SearchRequest` rather than minting a second
     * contract that could drift from it.
     *
     * @return array<string, mixed>
     */
    public function suggest(User $user, SearchTerms $terms): array
    {
        $results = $this->search->results($user, $terms, null, SearchService::PER_ENTITY_PREVIEW);

        return [
            'q' => $terms->raw(),
            'groups' => array_map(fn (SearchArmResult $r): array => [
                'entity' => $r->entity->value,
                'label' => $r->entity->label(),
                'items' => $r->rows,
                'has_more' => $r->hasMore,
            ], $results),
            // Always present, even with no query, so the client never has to construct a URL itself and the
            // "See all results" option has one source of truth.
            'see_all_url' => '/search?q='.rawurlencode((string) $terms->raw()),
        ];
    }

    /**
     * One prop, one meaning, computed on the server — the rule `AuditLogPresenter` records.
     *
     * ⚠️ `no_permitted_scopes` IS UNREACHABLE FOR EVERY SEEDED ROLE TODAY (all five hold `submissions.view`)
     * and it exists anyway. Without it, a future narrow role — a "Data Correction" seat with no read
     * permissions — would be told "nothing matched", which is false and sends them looking for a data bug
     * instead of an access one.
     *
     * ⚠️ AND `no_matches` MUST RENDER THE SAME COPY WHETHER NOTHING MATCHED OR EVERYTHING THAT MATCHED IS
     * INVISIBLE TO THIS USER. That is why there is no fourth case here: a distinct "some results are hidden
     * from you" reason IS the disclosure, and one code path is the only way to guarantee the two are
     * indistinguishable.
     *
     * @param  list<SearchEntity>  $permitted
     * @param  list<SearchArmResult>  $results
     */
    private function emptyReason(SearchTerms $terms, array $permitted, array $results): ?string
    {
        if ($terms->isEmpty()) {
            return 'no_query';
        }

        if ($permitted === []) {
            return 'no_permitted_scopes';
        }

        foreach ($results as $result) {
            if ($result->rows !== []) {
                return null;
            }
        }

        return 'no_matches';
    }
}
