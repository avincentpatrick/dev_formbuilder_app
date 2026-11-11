<?php

declare(strict_types=1);

namespace App\Services\Explorer;

use App\Enums\ExplorerAggregate;
use App\Enums\ExplorerBucket;
use App\Enums\ExplorerSort;
use App\Support\Explorer\ExplorerDimension;
use App\Support\Explorer\ExplorerField;
use App\Support\Explorer\ExplorerMeasure;
use App\Support\Explorer\ExplorerQuery;
use Carbon\CarbonImmutable;

/**
 * Turns the rows of a grouped query into what a chart draws (ADR-0025 §D4, §D7).
 *
 * The output is one shape for every chart type — CATEGORIES along the first group-by, and SERIES holding
 * one value per category — so the page chooses a chart without re-reading the data. With one group-by a
 * series is a value ("Count of responses", "Average age"); with two, a series is a group of the second.
 *
 * ── Two rules about what is left out, both from ADR-0011 §D11 ───────────────────────────────────────────
 * Past the chart's limit the remaining groups fold into ONE "Other" bucket, and the result says how many
 * groups it stands for. And that bucket carries a value only where the value is additive
 * ({@see ExplorerAggregate::isAdditive()}): the sum of the rest is the sum of the rest, but there is no
 * arithmetic that turns forty averages into the average of the rest. There the value is null and the page
 * prints a dash, which is a stated gap instead of a plausible wrong number.
 *
 * ── A timeline has no holes ─────────────────────────────────────────────────────────────────────────────
 * A day with no responses returns no row. Left as it is, a line would be drawn straight across the gap from
 * the day before to the day after, claiming values that were never there. So a calendar group-by is filled:
 * every period of the range appears, with 0 for an additive value and null for one that has nothing to
 * average.
 */
final class ExplorerResultShaper
{
    /** The second group-by's series cap — the design system's five categorical colours (ADR-0011 §D11). */
    public const int SERIES_LIMIT = 5;

    public const string OTHER_KEY = '__other__';

    public function __construct(private readonly ExplorerLabels $labels) {}

    /**
     * @param  list<array{0: ExplorerDimension, 1: ExplorerField}>  $dimensions
     * @param  list<array{0: ExplorerMeasure, 1: ExplorerField|null}>  $measures
     * @param  list<string>  $measureLabels  one per value, as the page prints it
     * @param  list<object>  $rows  each with `d0`, optionally `d1`, and `m0`…
     * @return array{categories: list<array<string, mixed>>, series: list<array<string, mixed>>, groups: int, hidden_groups: int, hidden_series: int}
     */
    public function shape(ExplorerQuery $query, array $dimensions, array $measures, array $measureLabels, array $rows, string $rangeField): array
    {
        if ($dimensions === []) {
            return ['categories' => [], 'series' => [], 'groups' => 0, 'hidden_groups' => 0, 'hidden_series' => 0];
        }

        return count($dimensions) === 1
            ? $this->oneDimension($query, $dimensions[0], $measures, $measureLabels, $rows, $rangeField)
            : $this->twoDimensions($query, $dimensions, $measures[0][0], $rows, $rangeField);
    }

    /** A value as the page receives it: an integer for a count, a rounded float otherwise, null for none. */
    public function value(ExplorerAggregate $aggregate, mixed $raw): int|float|null
    {
        if ($raw === null) {
            return null;
        }

        if ($aggregate === ExplorerAggregate::Count || $aggregate === ExplorerAggregate::CountDistinct) {
            return (int) $raw;
        }

        return round((float) $raw, 4);
    }

    /**
     * @param  array{0: ExplorerDimension, 1: ExplorerField}  $dimension
     * @param  list<array{0: ExplorerMeasure, 1: ExplorerField|null}>  $measures
     * @param  list<string>  $measureLabels
     * @param  list<object>  $rows
     * @return array{categories: list<array<string, mixed>>, series: list<array<string, mixed>>, groups: int, hidden_groups: int, hidden_series: int}
     */
    private function oneDimension(ExplorerQuery $query, array $dimension, array $measures, array $measureLabels, array $rows, string $rangeField): array
    {
        /** @var array<string, list<int|float|null>> $byKey */
        $byKey = [];

        foreach ($rows as $row) {
            $key = (string) ($row->d0 ?? '');
            $values = [];

            foreach ($measures as $index => [$measure]) {
                $values[] = $this->value($measure->aggregate, $row->{"m{$index}"} ?? null);
            }

            // A NULL group and an empty-string group are both "no value" and share one bucket.
            $byKey[$key] = isset($byKey[$key]) ? $this->merge($byKey[$key], $values, $measures) : $values;
        }

        $rank = array_map(static fn (array $values): int|float|null => $values[0], $byKey);
        [$shown, $hidden] = $this->order($query, $dimension, array_keys($byKey), $rank, $rangeField);

        $categories = $this->categories($dimension, $shown);
        $series = [];

        foreach ($measures as $index => [$measure]) {
            $values = [];

            foreach ($shown as $key) {
                $values[] = $byKey[$key][$index] ?? $this->blank($measure->aggregate);
            }

            if ($hidden !== []) {
                $values[] = $measure->aggregate->isAdditive()
                    ? $this->total(array_map(static fn (string $key): int|float|null => $byKey[$key][$index], $hidden))
                    : null;
            }

            $series[] = ['key' => "m{$index}", 'label' => $measureLabels[$index], 'other' => false, 'values' => $values];
        }

        if ($hidden !== []) {
            $categories[] = $this->otherCategory(count($hidden));
        }

        return [
            'categories' => $categories,
            'series' => $series,
            'groups' => count($byKey),
            'hidden_groups' => count($hidden),
            'hidden_series' => 0,
        ];
    }

    /**
     * @param  list<array{0: ExplorerDimension, 1: ExplorerField}>  $dimensions
     * @param  list<object>  $rows
     * @return array{categories: list<array<string, mixed>>, series: list<array<string, mixed>>, groups: int, hidden_groups: int, hidden_series: int}
     */
    private function twoDimensions(ExplorerQuery $query, array $dimensions, ExplorerMeasure $measure, array $rows, string $rangeField): array
    {
        $aggregate = $measure->aggregate;
        $additive = $aggregate->isAdditive();

        /** @var array<string, array<string, int|float|null>> $cells */
        $cells = [];
        /** @var array<string, int|float|null> $rowRank */
        $rowRank = [];
        /** @var array<string, int|float|null> $seriesRank */
        $seriesRank = [];

        foreach ($rows as $row) {
            $first = (string) ($row->d0 ?? '');
            $second = (string) ($row->d1 ?? '');
            $value = $this->value($aggregate, $row->m0 ?? null);

            $cells[$first][$second] = $this->combine($cells[$first][$second] ?? null, $value, $additive);
            $rowRank[$first] = $this->combine($rowRank[$first] ?? null, $value, $additive);
            $seriesRank[$second] = $this->combine($seriesRank[$second] ?? null, $value, $additive);
        }

        [$shown, $hidden] = $this->order($query, $dimensions[0], array_keys($rowRank), $rowRank, $rangeField);

        // The series are always the largest five: there are five colours, whatever the chart's own limit.
        uasort($seriesRank, static fn (int|float|null $a, int|float|null $b): int => ($b ?? -INF) <=> ($a ?? -INF));
        $seriesKeys = array_map(strval(...), array_keys($seriesRank));
        $plotted = array_slice($seriesKeys, 0, self::SERIES_LIMIT);
        $folded = array_slice($seriesKeys, self::SERIES_LIMIT);

        if ($dimensions[1][0]->bucket?->isCalendar() === true) {
            sort($plotted);
        }

        $names = $this->labels->resolve($dimensions[1][1], $dimensions[1][0]->bucket, $plotted);
        $blank = $this->blank($aggregate);
        $series = [];

        foreach ($plotted as $seriesKey) {
            $values = [];

            foreach ($shown as $key) {
                $values[] = $cells[$key][$seriesKey] ?? $blank;
            }

            if ($hidden !== []) {
                $values[] = $additive
                    ? $this->total(array_map(static fn (string $key): int|float|null => $cells[$key][$seriesKey] ?? null, $hidden))
                    : null;
            }

            $series[] = ['key' => $seriesKey, 'label' => $names[$seriesKey], 'other' => false, 'values' => $values];
        }

        if ($folded !== [] && $additive) {
            $sum = fn (array $keys): int|float|null => $this->total(array_merge(...array_map(
                static fn (string $key): array => array_map(
                    static fn (string $seriesKey): int|float|null => $cells[$key][$seriesKey] ?? null,
                    $folded,
                ),
                $keys,
            )));

            $values = array_map(static fn (string $key): int|float|null => $sum([$key]) ?? 0, $shown);

            if ($hidden !== []) {
                $values[] = $sum($hidden) ?? 0;
            }

            $series[] = ['key' => self::OTHER_KEY, 'label' => 'Other ('.count($folded).')', 'other' => true, 'values' => $values];
        }

        $categories = $this->categories($dimensions[0], $shown);

        if ($hidden !== []) {
            $categories[] = $this->otherCategory(count($hidden));
        }

        return [
            'categories' => $categories,
            'series' => $series,
            'groups' => count($rowRank),
            'hidden_groups' => count($hidden),
            'hidden_series' => count($folded),
        ];
    }

    /**
     * The order of the first group-by, and which groups fall past the limit.
     *
     * @param  array{0: ExplorerDimension, 1: ExplorerField}  $dimension
     * @param  list<string|int>  $keys
     * @param  array<string|int, int|float|null>  $rank
     * @return array{0: list<string>, 1: list<string>} shown, hidden
     */
    private function order(ExplorerQuery $query, array $dimension, array $keys, array $rank, string $rangeField): array
    {
        $keys = array_map(strval(...), $keys);
        $bucket = $dimension[0]->bucket;

        if ($bucket === ExplorerBucket::Weekday) {
            return [array_map(strval(...), range(1, 7)), []];
        }

        if ($bucket === ExplorerBucket::Hour) {
            return [array_map(strval(...), range(0, 23)), []];
        }

        if ($bucket !== null) {
            return [$this->periods($query, $bucket, $keys, $dimension[1]->key === $rangeField), []];
        }

        if ($query->sort === ExplorerSort::Label) {
            $names = $this->labels->resolve($dimension[1], null, $keys);
            usort($keys, static fn (string $a, string $b): int => strnatcasecmp($names[$a], $names[$b]));
        } else {
            $direction = $query->sort === ExplorerSort::ValueAscending ? 1 : -1;

            // Groups with no value sort last in either direction: "smallest first" means the smallest
            // value there is, not the groups that have none.
            usort($keys, static function (string $a, string $b) use ($rank, $direction): int {
                $left = $rank[$a] ?? null;
                $right = $rank[$b] ?? null;

                if ($left === null || $right === null) {
                    return ($left === null ? 1 : 0) <=> ($right === null ? 1 : 0);
                }

                // Equal values keep a stable order, with the no-value group after the named ones.
                return ($left <=> $right) * $direction ?: [$a === '', $a] <=> [$b === '', $b];
            });
        }

        return [array_slice($keys, 0, $query->limit), array_slice($keys, $query->limit)];
    }

    /**
     * Every period a timeline must show, in date order.
     *
     * When the field grouped by is the one the chart's range applies to, the periods are the range's own.
     * Any other date — "reviewed", or a date a question collected — can fall outside the range, so its
     * periods run from the first one seen to the last.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function periods(ExplorerQuery $query, ExplorerBucket $bucket, array $keys, bool $isRangeField): array
    {
        $dated = array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));
        sort($dated);

        if ($isRangeField) {
            $from = $query->from;
            $to = $query->to;
        } elseif ($dated !== []) {
            $from = CarbonImmutable::parse($dated[0], 'UTC');
            $to = CarbonImmutable::parse($dated[count($dated) - 1], 'UTC');
        } else {
            return in_array('', $keys, true) ? [''] : [];
        }

        $periods = [];
        $cursor = $this->floor($from, $bucket);

        while ($cursor->lessThanOrEqualTo($to) && count($periods) <= ExplorerQuery::MAX_PERIODS) {
            $periods[] = $cursor->toDateString();
            $cursor = $this->next($cursor, $bucket);
        }

        // Too long a span to fill honestly: show the periods that have data and leave the gaps to the table.
        if (count($periods) > ExplorerQuery::MAX_PERIODS) {
            $periods = array_slice($dated, 0, ExplorerQuery::MAX_PERIODS);
        }

        // Rows with no date at all sit after the timeline, never inside it.
        return in_array('', $keys, true) ? [...$periods, ''] : $periods;
    }

    private function floor(CarbonImmutable $date, ExplorerBucket $bucket): CarbonImmutable
    {
        return match ($bucket) {
            // ISO weeks, Monday first — what `date_trunc('week', …)` returns.
            ExplorerBucket::Week => $date->startOfWeek(CarbonImmutable::MONDAY),
            ExplorerBucket::Month => $date->startOfMonth(),
            ExplorerBucket::Quarter => $date->firstOfQuarter(),
            ExplorerBucket::Year => $date->startOfYear(),
            default => $date,
        };
    }

    private function next(CarbonImmutable $date, ExplorerBucket $bucket): CarbonImmutable
    {
        return match ($bucket) {
            ExplorerBucket::Week => $date->addWeek(),
            ExplorerBucket::Month => $date->addMonthNoOverflow(),
            ExplorerBucket::Quarter => $date->addMonthsNoOverflow(3),
            ExplorerBucket::Year => $date->addYearNoOverflow(),
            default => $date->addDay(),
        };
    }

    /**
     * @param  array{0: ExplorerDimension, 1: ExplorerField}  $dimension
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string, other: bool}>
     */
    private function categories(array $dimension, array $keys): array
    {
        $names = $this->labels->resolve($dimension[1], $dimension[0]->bucket, $keys);

        return array_map(
            static fn (string $key): array => ['key' => $key, 'label' => $names[$key], 'other' => false],
            $keys,
        );
    }

    /** @return array{key: string, label: string, other: bool} */
    private function otherCategory(int $groups): array
    {
        return ['key' => self::OTHER_KEY, 'label' => "Other ({$groups})", 'other' => true];
    }

    public function measureLabel(ExplorerMeasure $measure, ?ExplorerField $field, string $rowNoun): string
    {
        if ($measure->aggregate === ExplorerAggregate::Count || $field === null) {
            return $rowNoun === '' ? 'Count' : 'Count of '.$rowNoun;
        }

        return $measure->aggregate->label().' of '.$field->label;
    }

    /**
     * What a group with no rows holds — an empty period of a timeline, or a pairing of two group-bys that
     * never occurred. Nothing happened, so a count or a sum is 0; there is nothing to average, so null.
     */
    private function blank(ExplorerAggregate $aggregate): ?int
    {
        return $aggregate->isAdditive() ? 0 : null;
    }

    /**
     * @param  list<int|float|null>  $current
     * @param  list<int|float|null>  $incoming
     * @param  list<array{0: ExplorerMeasure, 1: ExplorerField|null}>  $measures
     * @return list<int|float|null>
     */
    private function merge(array $current, array $incoming, array $measures): array
    {
        $out = [];

        foreach ($measures as $index => [$measure]) {
            $out[] = $this->combine($current[$index] ?? null, $incoming[$index] ?? null, $measure->aggregate->isAdditive());
        }

        return $out;
    }

    /** Add two additive values; for the rest keep the larger, which is only ever used to rank groups. */
    private function combine(int|float|null $current, int|float|null $incoming, bool $additive): int|float|null
    {
        if ($current === null || $incoming === null) {
            return $current ?? $incoming;
        }

        return $additive ? $current + $incoming : max($current, $incoming);
    }

    /** @param  list<int|float|null>  $values */
    private function total(array $values): int|float|null
    {
        $present = array_filter($values, static fn (int|float|null $value): bool => $value !== null);

        return $present === [] ? null : array_sum($present);
    }
}
