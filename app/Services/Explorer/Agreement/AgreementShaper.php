<?php

declare(strict_types=1);

namespace App\Services\Explorer\Agreement;

use App\Support\Explorer\ExplorerAgreement;

/**
 * Turns an agreement list's counts into its lines, their status and their order (M250, ADR-0025 §D10). Pure: it reads
 * no database, so every rule below is pinned by a unit test with the numbers written out.
 *
 * ── What an item's share counts (`D234` answer 1) ───────────────────────────────────────────────────────
 * Those who gave a VIEW are the responses with at least one answer to the item that is not left out. The share is the
 * part of them who gave the item's MOST COMMON answer — unless the list names answers that count together as agreeing
 * (Agree and Strongly agree) and this item offers one of them, when it is the part who gave any of those. A question
 * that takes several answers counts the people who ticked its most common choice. A tie for most common goes to the
 * answer the author listed first.
 *
 * ── Whole numbers decide, and the share shown is rounded down ───────────────────────────────────────────
 * An item is on the short list when `agreeing × 100 ≥ threshold × views` and at least the minimum number gave a view:
 * no float ever decides a status. The share printed is rounded DOWN to one decimal, so an item to discuss can never
 * read "75.0%" beside a 75% threshold.
 *
 * ── The order a meeting reads it in ─────────────────────────────────────────────────────────────────────
 * The short list, highest share first; then the items to discuss, LOWEST share first — "starting with the questions on
 * which people disagree the most"; then the items too few people gave a view on. Shares are compared by cross-
 * multiplying, never by their rounded value, and a tie keeps the form's own order.
 */
final class AgreementShaper
{
    public const string SHORT_LIST = 'short_list';

    public const string DISCUSS = 'discuss';

    public const string TOO_FEW = 'too_few';

    private const array RANK = [self::SHORT_LIST => 0, self::DISCUSS => 1, self::TOO_FEW => 2];

    /**
     * @param  list<AgreementItem>  $items
     * @param  list<array{n: int, value: string|null, whole: bool, responses: int, views: int, agreeing: int}>  $rows  per item (`whole`) and per item and answer
     * @return array<string, mixed>
     */
    public function shape(ExplorerAgreement $spec, array $items, array $rows): array
    {
        $leaveOut = array_fill_keys($spec->leaveOut, true);
        $agreeing = array_fill_keys($spec->agreeing, true);

        /** @var array<int, array{values: array<string, int>, responses: int, views: int, agreeing: int}> $counts */
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['n']] ??= ['values' => [], 'responses' => 0, 'views' => 0, 'agreeing' => 0];

            if ($row['whole']) {
                $counts[$row['n']]['responses'] = $row['responses'];
                $counts[$row['n']]['views'] = $row['views'];
                $counts[$row['n']]['agreeing'] = $row['agreeing'];
            } elseif ($row['value'] !== null) {
                $counts[$row['n']]['values'][$row['value']] = $row['responses'];
            }
        }

        $lines = [];
        $labels = [];

        foreach ($items as $item) {
            $count = $counts[$item->position] ?? ['values' => [], 'responses' => 0, 'views' => 0, 'agreeing' => 0];
            $labels += $item->options;
            $lines[] = $this->line($item, $count, $leaveOut, $agreeing, $spec);
        }

        usort($lines, static function (array $a, array $b): int {
            $rank = self::RANK[$a['status']] <=> self::RANK[$b['status']];

            if ($rank !== 0) {
                return $rank;
            }

            $share = match ($a['status']) {
                self::SHORT_LIST => ($b['agreeing'] * $a['views']) <=> ($a['agreeing'] * $b['views']),
                self::DISCUSS => ($a['agreeing'] * $b['views']) <=> ($b['agreeing'] * $a['views']),
                default => 0,
            };

            return $share !== 0 ? $share : $a['position'] <=> $b['position'];
        });

        $summary = ['items' => count($lines), self::SHORT_LIST => 0, self::DISCUSS => 0, self::TOO_FEW => 0];
        foreach ($lines as $line) {
            $summary[$line['status']]++;
        }

        return [
            'threshold' => $spec->threshold,
            'min_views' => $spec->minViews,
            'leave_out' => self::named($spec->leaveOut, $labels),
            'agreeing' => self::named($spec->agreeing, $labels),
            'summary' => $summary,
            'items' => $lines,
        ];
    }

    /**
     * @param  array{values: array<string, int>, responses: int, views: int, agreeing: int}  $count
     * @param  array<string, true>  $leaveOut
     * @param  array<string, true>  $agreeing
     * @return array<string, mixed>
     */
    private function line(AgreementItem $item, array $count, array $leaveOut, array $agreeing, ExplorerAgreement $spec): array
    {
        $order = array_flip(array_map('strval', array_keys($item->options)));
        $values = $count['values'];

        // The author's answers in the author's order, then anything else given, most given first.
        $answers = [];
        foreach ($item->options as $value => $label) {
            $answers[(string) $value] = $values[(string) $value] ?? 0;
        }
        $extra = array_diff_key($values, $answers);
        uksort($extra, static fn (int|string $a, int|string $b): int => [$extra[$b], (string) $a] <=> [$extra[$a], (string) $b]);
        $answers += $extra;

        $offered = array_keys($answers);
        $marked = array_values(array_filter($offered, static fn (int|string $value): bool => isset($agreeing[(string) $value])));

        if ($marked !== []) {
            $basis = 'agreeing';
            $counted = array_map('strval', $marked);
            $agree = $count['agreeing'];
        } else {
            $basis = 'most_common';
            $top = null;

            foreach ($answers as $value => $given) {
                $value = (string) $value;

                if ($given === 0 || isset($leaveOut[$value])) {
                    continue;
                }

                if ($top === null || $given > $answers[$top] || ($given === $answers[$top] && ($order[$value] ?? PHP_INT_MAX) < ($order[$top] ?? PHP_INT_MAX))) {
                    $top = $value;
                }
            }

            $counted = $top === null ? [] : [$top];
            $agree = $top === null ? 0 : $answers[$top];
        }

        $views = $count['views'];

        $status = match (true) {
            $views < $spec->minViews => self::TOO_FEW,
            $agree * 100 >= $spec->threshold * $views => self::SHORT_LIST,
            default => self::DISCUSS,
        };

        $countedSet = array_fill_keys($counted, true);

        return [
            'key' => $item->key,
            'pick' => $item->pick,
            'label' => $item->label,
            'question' => $item->question,
            'column' => $item->columnLabel,
            'section' => $item->section,
            'position' => $item->position,
            'multiple' => $item->multiple,
            'responses' => $count['responses'],
            'views' => $views,
            'left_out' => $count['responses'] - $views,
            'answers' => array_map(static fn (int|string $value, int $given): array => [
                'value' => (string) $value,
                'label' => $item->options[(string) $value] ?? (string) $value,
                'responses' => $given,
                'left_out' => isset($leaveOut[(string) $value]),
                'counted' => isset($countedSet[(string) $value]),
            ], array_keys($answers), array_values($answers)),
            'basis' => $basis,
            'counted' => array_map(static fn (string $value): array => [
                'value' => $value,
                'label' => $item->options[$value] ?? $value,
            ], $counted),
            'agreeing' => $agree,
            // Rounded DOWN to one decimal, so an item below the threshold never prints at it.
            'share' => $views === 0 ? null : intdiv($agree * 1000, $views) / 10,
            'status' => $status,
        ];
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function named(array $values, array $labels): array
    {
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => $labels[$value] ?? $value,
        ], $values);
    }
}
