<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FormStatus;
use Carbon\CarbonImmutable;

/**
 * The forms list's counted facet chips (JR3) — Live / Draft / Closing soon.
 *
 * ⚠️ THESE RUN OVER THE PRESENTED ROWS, IN PHP, AND THAT IS THE DESIGN RATHER THAN A SHORTCUT. Three
 * consequences follow, all of them wanted:
 *
 *   1. **Zero extra queries.** The page has no pagination, so every visible row is already in memory;
 *      a SQL facet would cost a second pass over the same set to learn what is already known.
 *   2. **The counts describe the CURRENT SEARCH.** Chips are computed after `?q` has been applied, so
 *      "Live 4" means four live forms among the ones you are looking at — not four in the tenant. A
 *      chip that counts rows the list is not showing is a chip that argues with the list beneath it.
 *   3. **"Live" is a DERIVED state, not `forms.status`.** A published form whose close time has passed
 *      is not live, and a draft with no schedule still reports `acceptance: 'open'` because
 *      {@see FormSchedule::acceptance()} knows nothing about publication. So `live` is the conjunction
 *      of both, and reading either half alone is the way to get this wrong.
 *
 * ⚠️ ADDING A FACET HERE MEANS WIDENING `ListEmptyReason::for()`'s SECOND ARGUMENT — see the
 * `empty_reason` line in the tenant FormController's `index()`. A filter the empty state cannot see
 * tells a 200-form tenant it has no forms, which is verbatim the defect J1e existed to fix.
 */
final class FormListFacets
{
    public const string LIVE = 'live';

    public const string DRAFT = 'draft';

    public const string CLOSING_SOON = 'closing_soon';

    /** A close time inside this window puts a form in the "Closing soon" chip. */
    private const int CLOSING_SOON_DAYS = 7;

    /**
     * The chosen facet, or null for "All". Anything unrecognised — including an array, which is why the
     * type check is not merely defensive — falls back to null rather than 500ing or filtering to zero.
     */
    public static function parse(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, [self::LIVE, self::DRAFT, self::CLOSING_SOON], true)
            ? $raw
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{value: ?string, label: string, count: int}>
     */
    public static function counts(array $rows): array
    {
        $now = CarbonImmutable::now();

        return [
            ['value' => null, 'label' => 'All', 'count' => count($rows)],
            ['value' => self::LIVE, 'label' => 'Live', 'count' => self::countMatching($rows, self::LIVE, $now)],
            ['value' => self::DRAFT, 'label' => 'Draft', 'count' => self::countMatching($rows, self::DRAFT, $now)],
            ['value' => self::CLOSING_SOON, 'label' => 'Closing soon', 'count' => self::countMatching($rows, self::CLOSING_SOON, $now)],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function apply(array $rows, ?string $facet): array
    {
        if ($facet === null) {
            return $rows;
        }

        $now = CarbonImmutable::now();

        return array_values(array_filter($rows, fn (array $row): bool => self::matches($row, $facet, $now)));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function countMatching(array $rows, string $facet, CarbonImmutable $now): int
    {
        return count(array_filter($rows, fn (array $row): bool => self::matches($row, $facet, $now)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function matches(array $row, string $facet, CarbonImmutable $now): bool
    {
        $schedule = is_array($row['schedule'] ?? null) ? $row['schedule'] : [];
        $acceptance = is_string($schedule['acceptance'] ?? null) ? $schedule['acceptance'] : null;
        $isPublished = ($row['status'] ?? null) === FormStatus::Published->value;

        return match ($facet) {
            self::LIVE => $isPublished && $acceptance === 'open',
            self::DRAFT => ($row['status'] ?? null) === FormStatus::Draft->value,
            // Deliberately narrower than "has a close time": a form already closed, or not yet open, or
            // full, is not closing soon — it has already stopped or has not started.
            self::CLOSING_SOON => $isPublished
                && $acceptance === 'open'
                && self::closesWithin($schedule['closes_at'] ?? null, $now),
            default => false,
        };
    }

    private static function closesWithin(mixed $closesAt, CarbonImmutable $now): bool
    {
        if (! is_string($closesAt)) {
            return false;
        }

        return CarbonImmutable::parse($closesAt)->lessThanOrEqualTo($now->addDays(self::CLOSING_SOON_DAYS));
    }
}
