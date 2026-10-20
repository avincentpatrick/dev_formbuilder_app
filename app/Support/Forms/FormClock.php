<?php

declare(strict_types=1);

namespace App\Support\Forms;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

/**
 * The clock `today()` and `now()` read, stamped in the form's own time zone (M180, `D153`, `R-9fb95b45`'s clock half) —
 * the server half of `isoClock()` in `resources/public-runtime/lib/schema-mapping.ts`.
 *
 * Both engines read `today()` as the clock's first ten characters, so the zone it is stamped in IS the calendar day:
 * stamped in UTC, as it was, a respondent in Manila met yesterday's date until 08:00. The clock was UTC so the browser
 * and the server agree — and they still do, because both now stamp the same zone, the form's (`forms.timezone`, set
 * on the form's Schedule; new forms start on Asia/Manila). Only the offset changes; the instant never does, so an
 * ordering against `now()` is unmoved.
 */
final class FormClock
{
    /** The ISO-8601 clock for `$instant` (the present moment when null) in `$timezone`; UTC for a missing or unknown zone. */
    public static function at(?string $instant, ?string $timezone): string
    {
        $at = $instant === null ? CarbonImmutable::now() : CarbonImmutable::parse($instant);

        return $at->setTimezone(self::zone($timezone))->toIso8601String();
    }

    private static function zone(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }
}
