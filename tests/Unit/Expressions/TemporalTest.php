<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Services\Expressions\Temporal;

/*
|--------------------------------------------------------------------------
| M134 (R-62b638e1) — the strict ISO parse and the integer keys behind chronological ordering.
|--------------------------------------------------------------------------
| The golden corpus (tests/golden/expressions/temporal.json) pins the cross-engine verdicts; this file pins the
| parse itself against an independent calendar — DateTimeImmutable here, Date.UTC in temporal.test.ts — so a wrong
| day count cannot hide behind a vector that happens to compare two nearby dates.
*/

it('parses the three shapes and nothing else', function (): void {
    expect(Temporal::parse('2026-07-11'))->toMatchArray(['kind' => 'date', 'zoned' => false])
        ->and(Temporal::parse('09:30'))->toMatchArray(['kind' => 'time', 'zoned' => false])
        ->and(Temporal::parse('09:30:15'))->toMatchArray(['kind' => 'time', 'zoned' => false])
        ->and(Temporal::parse('2026-07-11T09:30'))->toMatchArray(['kind' => 'datetime', 'zoned' => false])
        ->and(Temporal::parse('2026-07-11T09:30:00+00:00'))->toMatchArray(['kind' => 'datetime', 'zoned' => true])
        ->and(Temporal::parse('2026-07-11T09:30Z'))->toMatchArray(['kind' => 'datetime', 'zoned' => true]);
});

it('refuses every near miss rather than guessing', function (mixed $value): void {
    expect(Temporal::parse($value))->toBeNull();
})->with([
    'a number' => [20260711],
    'a numeric string' => ['2026'],
    'null' => [null],
    'a list' => [['2026-07-11']],
    'a boolean' => [true],
    'a trailing newline' => ["2026-07-11\n"],
    'a leading space' => [' 2026-07-11'],
    'a space for the T' => ['2026-07-11 09:30'],
    'a lower-case t' => ['2026-07-11t09:30'],
    'a lower-case z' => ['2026-07-11T09:30z'],
    'fractional seconds' => ['09:30:00.5'],
    'hour 24' => ['24:00'],
    'minute 60' => ['09:60'],
    'second 60' => ['09:30:60'],
    'an unpadded month' => ['2026-7-11'],
    'a five-digit year' => ['20260-07-11'],
    'year zero' => ['0000-01-01'],
    'month 13' => ['2026-13-01'],
    'day 32' => ['2026-01-32'],
    'February 29th in 2026' => ['2026-02-29'],
    'February 29th in 1900' => ['1900-02-29'],
    'April 31st' => ['2026-04-31'],
    'an offset without minutes' => ['2026-07-11T09:30+08'],
    'an offset of 24 hours' => ['2026-07-11T09:30+24:00'],
    'a date with an offset' => ['2026-07-11+08:00'],
    'a time with an offset' => ['09:30Z'],
]);

it('counts days exactly as an independent calendar does, across every kind of month and leap boundary', function (): void {
    $epoch = new DateTimeImmutable('0001-01-01T00:00:00Z');
    $origin = Temporal::parse('0001-01-01')['key'] ?? 0;
    $checked = 0;

    foreach ([1, 2, 3, 4, 99, 100, 101, 399, 400, 401, 1582, 1600, 1699, 1700, 1899, 1900, 1999, 2000, 2024, 2025, 2026, 2100, 2400, 9999] as $year) {
        foreach (['01-01', '02-28', '02-29', '03-01', '06-30', '12-31'] as $monthDay) {
            $iso = sprintf('%04d-%s', $year, $monthDay);
            $parsed = Temporal::parse($iso);
            $oracle = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));

            if ($oracle === false || $oracle->format('Y-m-d') !== $iso) {
                expect($parsed)->toBeNull("{$iso} is not a calendar date, so it must not parse");

                continue;
            }

            expect($parsed)->not->toBeNull("{$iso} is a calendar date")
                ->and(($parsed['key'] - $origin) / 86400)->toBe((int) $epoch->diff($oracle)->days, $iso);
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(100);
});

it('reads an offset as an instant, and a date as its midnight', function (): void {
    expect(Temporal::parse('2026-07-11T10:00+08:00')['key'] ?? null)->toBe(Temporal::parse('2026-07-11T02:00Z')['key'] ?? null)
        ->and(Temporal::parse('2026-07-10T23:30-03:00')['key'] ?? null)->toBe(Temporal::parse('2026-07-11T02:30:00+00:00')['key'] ?? null)
        ->and(Temporal::parse('2026-07-11')['key'] ?? null)->toBe(Temporal::parse('2026-07-11T00:00')['key'] ?? null);
});

it('orders only comparable pairs — never naive against zoned, never a time against a date', function (): void {
    expect(Temporal::orders('2026-07-11', '2026-07-10', ComparisonOperator::Gt))->toBeTrue()
        ->and(Temporal::orders('2026-07-11T09:00', '2026-07-11', ComparisonOperator::Gt))->toBeTrue()
        ->and(Temporal::orders('09:00', '08:59:59', ComparisonOperator::Gt))->toBeTrue()
        ->and(Temporal::orders('2026-07-11T09:00Z', '2026-07-11T08:00Z', ComparisonOperator::Gt))->toBeTrue()
        // Incomparable pairs are false under EVERY operator, so no ordering of them can hold.
        ->and(collect([ComparisonOperator::Gt, ComparisonOperator::Lt, ComparisonOperator::Gte, ComparisonOperator::Lte])
            ->filter(fn (ComparisonOperator $op): bool => Temporal::orders('2026-07-11', '2026-07-11T00:00:00+00:00', $op)
                || Temporal::orders('09:00', '2026-07-11', $op)
                || Temporal::orders('09:00', '09:00Z', $op))
            ->all())->toBe([]);
});
