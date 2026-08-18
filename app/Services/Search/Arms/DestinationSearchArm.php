<?php

declare(strict_types=1);

namespace App\Services\Search\Arms;

use App\Enums\SearchEntity;
use App\Models\User;
use App\Services\Search\SearchArm;
use App\Services\Search\SearchArmResult;
use App\Support\Search\DestinationCatalog;
use App\Support\Search\SearchTerms;

/**
 * Pages and settings the user can navigate to (Increment J1c) — PRD §3.7's "settings".
 *
 * The only arm with no database query at all: its corpus is {@see DestinationCatalog}'s authored rows,
 * matched in PHP. That is why `allowed()` is unconditionally true — every authenticated tenant user can
 * reach **Dashboard and Settings**, so refusing the arm would be a lie. **The ROWS are what get filtered**,
 * and a row the user cannot reach is absent, which satisfies the same disclosure rule the query-backed arms
 * follow.
 *
 * ⚠️ THAT SENTENCE USED TO NAME A THIRD PAGE — "Dashboard, Settings and Notifications" — AND IT WAS FALSE.
 * `/notifications` is a JSON endpoint with no Inertia page at all, so the row backing the claim was itself
 * the defect J2d removed (see {@see DestinationCatalog}). `allowed(): true` stays correct on the surviving
 * two, but the justification is corrected rather than left standing on a row that no longer exists: a
 * docblock arguing from a deleted premise is how the next author reintroduces it.
 *
 * ⚠️ `count()` AND `search()` SHARE `matched()` FOR THE SAME REASON THE OTHER ARMS SHARE A BUILDER. There
 * is no database here, so the count could trivially have been `count(self::ROWS)` — and that would report
 * destinations the user cannot reach, which is precisely the count-leak `SearchCountLeakTest` exists to
 * prevent. Structure it the same way as the arms that need it, so nobody has to notice.
 */
final readonly class DestinationSearchArm implements SearchArm
{
    public function __construct(private DestinationCatalog $catalog) {}

    public function entity(): SearchEntity
    {
        return SearchEntity::Destination;
    }

    public function allowed(User $user): bool
    {
        return true;
    }

    public function search(User $user, SearchTerms $terms, int $limit): SearchArmResult
    {
        $rows = array_map(
            static fn (array $row): array => [
                'id' => $row['key'],
                'title' => $row['label'],
                'subtitle' => $row['url'],
                'url' => $row['url'],
            ],
            $this->matched($user, $terms)
        );

        return SearchArmResult::fromOverfetch($this->entity(), array_slice($rows, 0, $limit + 1), $limit);
    }

    public function count(User $user, SearchTerms $terms): int
    {
        return count($this->matched($user, $terms));
    }

    /**
     * The single source of both the rows and the count.
     *
     * Matching mirrors `KeywordFilter::applyLike()`'s semantics deliberately — AND across tokens, OR across
     * the label and the keyword list — so "audit log" narrows the same way here as a two-word member search
     * does against name and email. Tokens come from `SearchTerms`, so the sanitiser is shared and this arm
     * inherits the clamping and the hostile-input handling for free.
     *
     * @return list<array{key: string, label: string, url: string, keywords: string}>
     */
    private function matched(User $user, SearchTerms $terms): array
    {
        if ($terms->isEmpty()) {
            return [];
        }

        // The patterns carry `%` and the `!` escapes that SQL needs; strip them back to bare tokens for a
        // PHP substring match rather than re-deriving the token list, so the two paths cannot drift.
        $tokens = array_map(
            static fn (string $pattern): string => str_replace(['!!', '!%', '!_'], ['!', '%', '_'], trim($pattern, '%')),
            $terms->likePatterns()
        );

        return array_values(array_filter(
            $this->catalog->visibleTo($user),
            static function (array $row) use ($tokens): bool {
                $haystack = mb_strtolower($row['label'].' '.$row['keywords']);

                foreach ($tokens as $token) {
                    if (! str_contains($haystack, $token)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }
}
