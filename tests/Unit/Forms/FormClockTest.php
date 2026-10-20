<?php

declare(strict_types=1);

use App\Support\Forms\FormClock;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| M180 (`D153`, `R-9fb95b45`'s clock half) — "Today" is the form's calendar day.
|--------------------------------------------------------------------------
| Both engines read `today()` as the first ten characters of the clock they are handed, so the clock is stamped in the
| form's own time zone — on the server here, in the browser by `isoClock()` — and the two still agree, which is the
| reason the clock was UTC before. Before 08:00 in Manila the UTC day is still yesterday.
*/

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('stamps an instant in the form\'s zone, so the date part is that zone\'s calendar day', function (): void {
    expect(FormClock::at('2026-10-20T23:30:00+00:00', 'Asia/Manila'))->toBe('2026-10-21T07:30:00+08:00')
        ->and(FormClock::at('2026-07-11T09:30:00+00:00', 'America/New_York'))->toBe('2026-07-11T05:30:00-04:00')
        ->and(FormClock::at('2026-07-11T09:30:00+00:00', 'UTC'))->toBe('2026-07-11T09:30:00+00:00');
});

it('stamps the present moment when no instant is given', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20T23:30:00+00:00'));

    expect(FormClock::at(null, 'Asia/Manila'))->toBe('2026-10-21T07:30:00+08:00');
});

it('falls back to UTC for a missing or unknown zone', function (?string $zone): void {
    expect(FormClock::at('2026-10-20T23:30:00+08:00', $zone))->toBe('2026-10-20T15:30:00+00:00');
})->with([
    'none' => [null],
    'empty' => [''],
    'unknown' => ['Mars/Olympus_Mons'],
]);
