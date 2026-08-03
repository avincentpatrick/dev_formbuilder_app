<?php

declare(strict_types=1);

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFormSelection;
use App\Enums\AnalyticsGranularity;
use App\Exceptions\Analytics\InvalidAnalyticsQueryException;
use App\Support\Analytics\AnalyticsQuery;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| ADR-0011 §D7's three bounds, enforced in the constructor so "bounded" is a property of the TYPE rather
| than a rule every call site remembers -- and §D8's round-trip, which the saved-view contract rests on.
| Unit: no container, no DB.
*/

function query(array $overrides = []): AnalyticsQuery
{
    return new AnalyticsQuery(...array_merge([
        'from' => CarbonImmutable::parse('2026-03-01', 'UTC'),
        'to' => CarbonImmutable::parse('2026-03-07', 'UTC'),
    ], $overrides));
}

it('counts an inclusive span, so a single day is one', function (): void {
    // Off-by-one here would let a 367-day range through, or reject a legitimate 366-day one.
    expect(query(['to' => CarbonImmutable::parse('2026-03-01', 'UTC')])->spanInDays())->toBe(1)
        ->and(query()->spanInDays())->toBe(7);
});

it('refuses a range longer than 366 days', function (): void {
    expect(fn () => query(['to' => CarbonImmutable::parse('2027-03-02', 'UTC')]))
        ->toThrow(InvalidAnalyticsQueryException::class);

    // A full leap year is the boundary and must be ACCEPTED, or the cap silently reads 365.
    expect(query([
        'from' => CarbonImmutable::parse('2024-01-01', 'UTC'),
        'to' => CarbonImmutable::parse('2024-12-31', 'UTC'),
    ])->spanInDays())->toBe(366);
});

it('refuses an inverted range', function (): void {
    expect(fn () => query([
        'from' => CarbonImmutable::parse('2026-03-07', 'UTC'),
        'to' => CarbonImmutable::parse('2026-03-01', 'UTC'),
    ]))->toThrow(InvalidAnalyticsQueryException::class);
});

it('refuses an unknown timezone before it can reach date_trunc', function (): void {
    // The zone is bound into date_trunc's third argument. An unvalidated one is a PostgreSQL runtime error
    // rather than a usable message, which is the whole reason this check is here and not only in a request.
    expect(fn () => query(['timezone' => 'Mars/Olympus']))
        ->toThrow(InvalidAnalyticsQueryException::class);

    expect(query(['timezone' => 'Asia/Manila'])->timezone)->toBe('Asia/Manila');
});

it('caps an explicit form list and refuses an empty one', function (): void {
    // The cap is not decorative: ResourceGrantResolver's docblock records PostgreSQL's 65535 bind-parameter
    // limit, which is why that class returns subqueries rather than materialized id arrays.
    $ids = array_map(static fn (int $i): string => "form-{$i}", range(1, AnalyticsQuery::MAX_FORM_IDS + 1));

    expect(fn () => query(['selection' => AnalyticsFormSelection::Forms, 'formIds' => $ids]))
        ->toThrow(InvalidAnalyticsQueryException::class);

    expect(fn () => query(['selection' => AnalyticsFormSelection::Forms, 'formIds' => []]))
        ->toThrow(InvalidAnalyticsQueryException::class);
});

it('refuses a scope-node selection with no node', function (): void {
    expect(fn () => query(['selection' => AnalyticsFormSelection::ScopeNode]))
        ->toThrow(InvalidAnalyticsQueryException::class);
});

it('bounds the breakdown cardinality', function (): void {
    expect(fn () => query(['topN' => 0]))->toThrow(InvalidAnalyticsQueryException::class)
        ->and(fn () => query(['topN' => AnalyticsQuery::MAX_TOP_N + 1]))->toThrow(InvalidAnalyticsQueryException::class);

    // The default matches §D11's five-series categorical cap, so an ordinary breakdown never needs Other.
    expect(query()->topN)->toBe(5);
});

it('converts local dates to a half-open UTC instant pair', function (): void {
    // Half-open, not BETWEEN 23:59:59 -- which loses sub-second rows in the final second, the bug already
    // live in ReconcileTenantUsageJob:59.
    $utc = query();

    expect($utc->fromUtc()->toIso8601String())->toBe('2026-03-01T00:00:00+00:00')
        ->and($utc->toUtcExclusive()->toIso8601String())->toBe('2026-03-08T00:00:00+00:00');

    // Manila is UTC+8, so its local midnight is the previous day at 16:00 UTC.
    $manila = query(['timezone' => 'Asia/Manila']);

    expect($manila->fromUtc()->toIso8601String())->toBe('2026-02-28T16:00:00+00:00')
        ->and($manila->toUtcExclusive()->toIso8601String())->toBe('2026-03-07T16:00:00+00:00');
});

it('shifts the prior period by exactly the range length, carrying everything else', function (): void {
    $prior = query(['axis' => AnalyticsAxis::Source, 'topN' => 9])->priorPeriod();

    expect($prior->from->toDateString())->toBe('2026-02-22')
        ->and($prior->to->toDateString())->toBe('2026-02-28')
        ->and($prior->spanInDays())->toBe(7)
        // Like-for-like: a comparison against a differently-filtered period would not be a comparison.
        ->and($prior->axis)->toBe(AnalyticsAxis::Source)
        ->and($prior->topN)->toBe(9);
});

it('round-trips through the stored definition shape', function (): void {
    // §D8 stores a DECLARATION resolved at read time, so this round-trip is the saved-view contract itself.
    $original = query([
        'timezone' => 'Asia/Manila',
        'granularity' => AnalyticsGranularity::Month,
        'selection' => AnalyticsFormSelection::Forms,
        'formIds' => ['a', 'b'],
        'axis' => AnalyticsAxis::Status,
        'statuses' => ['submitted'],
        'sources' => ['guest'],
        'locales' => ['en'],
        'topN' => 7,
    ]);

    $restored = AnalyticsQuery::fromArray($original->toArray());

    expect($restored->toArray())->toBe($original->toArray())
        // Dates are stored LOCAL beside their zone, so re-reading a view a year later reproduces the same
        // buckets rather than the same UTC boundaries.
        ->and($restored->from->toDateString())->toBe('2026-03-01')
        ->and($restored->timezone)->toBe('Asia/Manila');
});

it('degrades a definition from another schema version to a stated refusal', function (): void {
    // The reason the version tag exists: without it, §D8's read-time resolution cannot tell "written by an
    // older build" from "corrupt", and would guess.
    $payload = query()->toArray();
    $payload['v'] = 99;

    expect(fn () => AnalyticsQuery::fromArray($payload))
        ->toThrow(InvalidAnalyticsQueryException::class);
});

it('refuses an unknown enum value in a stored definition rather than throwing a ValueError', function (): void {
    // Every enum lookup is tryFrom-with-refusal, because this reads PERSISTED data: a value written by a
    // build that knew a case this one does not must produce §D8's stated refusal, not an uncaught ValueError.
    $payload = query()->toArray();
    $payload['axis'] = 'campaign';

    expect(fn () => AnalyticsQuery::fromArray($payload))
        ->toThrow(InvalidAnalyticsQueryException::class);
});

it('refuses a malformed date rather than rolling it over', function (): void {
    // createFromFormat, not parse: "2026-13-45" must be a refusal, not silently January 2027.
    $payload = query()->toArray();
    $payload['from'] = '2026-13-45';

    expect(fn () => AnalyticsQuery::fromArray($payload))
        ->toThrow(InvalidAnalyticsQueryException::class);
});

it('carries a machine-readable reason on every refusal', function (): void {
    // An API error body and H24b both branch on this rather than parsing prose.
    try {
        query(['to' => CarbonImmutable::parse('2027-03-02', 'UTC')]);
        expect(false)->toBeTrue('expected a refusal');
    } catch (InvalidAnalyticsQueryException $e) {
        expect($e->reason())->toBe('range_too_long');
    }
});
