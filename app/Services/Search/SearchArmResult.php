<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SearchEntity;

/**
 * One arm's rows plus whether it had more (Increment J1b).
 *
 * `hasMore` is derived by asking for `limit + 1` rows and discarding the extra, rather than by comparing
 * against `count()`. That keeps the "is there more" signal free on the suggest path, where running a real
 * `COUNT(*)` over a GIN bitmap on every keystroke would be the wrong shape regardless of what it discloses.
 */
final readonly class SearchArmResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public SearchEntity $entity,
        public array $rows,
        public bool $hasMore,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  exactly `limit + 1` long at most
     */
    public static function fromOverfetch(SearchEntity $entity, array $rows, int $limit): self
    {
        return new self($entity, array_slice($rows, 0, $limit), count($rows) > $limit);
    }
}
