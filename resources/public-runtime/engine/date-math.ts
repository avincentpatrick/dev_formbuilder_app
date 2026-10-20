/**
 * Date arithmetic for the expression library (M180, `R-da02fe03`, `D155`): `date_diff()`, `date_add()` and `age()` —
 * the mirror of PHP `App\Services\Expressions\DateMath`, held to it by `tests/golden/expressions/date-functions.json`.
 * Built on `temporal.ts`'s strict parse, integer arithmetic only — never a `Date`, so no browser time zone can move a
 * result. Whole completed units, truncated toward zero; pairs as `temporal.ts` orders them (never a value with an
 * offset against one without, `D89`); `date_add()` writes the shape it was given; outside 0001-9999 is blank.
 */

import { ABSENT, toNumber, type MaybeAbsent } from './coercion';
import { civil, daysFrom, daysInMonth, offsetSeconds, parseTemporal } from './temporal';

/** The units both functions accept, as the quoted word. */
export const UNITS = ['years', 'months', 'weeks', 'days', 'hours', 'minutes'];

const SECONDS: Record<string, number> = { weeks: 604800, days: 86400, hours: 3600, minutes: 60 };

const SECONDS_PER_DAY = 86400;

const MAX_COUNT = 1e10;

/** `date_diff(start, end, unit)` — whole units from start to end (negative when end is earlier), or ABSENT. */
export function dateDiff(start: MaybeAbsent, end: MaybeAbsent, unit: string): number | typeof ABSENT {
    const a = parseTemporal(start);
    const b = parseTemporal(end);

    if (a === null || b === null || (a.kind === 'time') !== (b.kind === 'time') || a.zoned !== b.zoned) {
        return ABSENT;
    }

    if (unit === 'years' || unit === 'months') {
        if (a.kind === 'time') {
            return ABSENT;
        }

        const months = completeMonths(a.key, b.key);

        return unit === 'months' ? months : Math.trunc(months / 12);
    }

    return Math.trunc((b.key - a.key) / SECONDS[unit]);
}

/** `date_add(date, n, unit)` — the value moved by a whole n units, in the shape it was given, or ABSENT. */
export function dateAdd(value: MaybeAbsent, n: MaybeAbsent, unit: string): string | typeof ABSENT {
    const parsed = parseTemporal(value);
    const number = toNumber(n);

    // A count past ten billion leaves the years 0001-9999 in any unit — and PHP would overflow an integer first.
    if (parsed === null || typeof value !== 'string' || !Number.isFinite(number) || Math.abs(number) >= MAX_COUNT) {
        return ABSENT;
    }

    const count = Math.trunc(number);

    if (parsed.kind === 'time') {
        if (unit !== 'hours' && unit !== 'minutes') {
            return ABSENT;
        }

        return clock(floorMod(parsed.key + count * SECONDS[unit], SECONDS_PER_DAY), value.length === 8);
    }

    if (parsed.kind === 'date') {
        if (unit === 'hours' || unit === 'minutes') {
            return ABSENT;
        }

        const days = moved(Math.trunc(parsed.key / SECONDS_PER_DAY), count, unit);

        return days === null ? ABSENT : calendar(days);
    }

    // A date-time: moved on its own wall clock, so its offset is kept as written.
    const withSeconds = value.length > 16 && value[16] === ':';
    const suffix = value.slice(withSeconds ? 19 : 16);
    let wall = parsed.key + offsetSeconds(suffix === '' ? undefined : suffix);
    let day = Math.floor(wall / SECONDS_PER_DAY);
    let time = wall - day * SECONDS_PER_DAY;
    let result: number | null;

    if (unit === 'hours' || unit === 'minutes') {
        wall += count * SECONDS[unit];
        day = Math.floor(wall / SECONDS_PER_DAY);
        time = wall - day * SECONDS_PER_DAY;
        result = civil(day) === null ? null : day;
    } else {
        result = moved(day, count, unit);
    }

    return result === null ? ABSENT : `${calendar(result)}T${clock(time, withSeconds)}${suffix}`;
}

/** `age(dob)` — whole years from the date of birth to today's date on the clock, or ABSENT. */
export function age(dateOfBirth: MaybeAbsent, now: string | null): number | typeof ABSENT {
    return now === null ? ABSENT : dateDiff(dateOfBirth, now.slice(0, 10), 'years');
}

/** Whole calendar months from one key to another — a month counts once its day, then its time, comes round again. */
function completeMonths(from: number, to: number): number {
    const fromDay = Math.floor(from / SECONDS_PER_DAY);
    const toDay = Math.floor(to / SECONDS_PER_DAY);
    const [fy, fm, fd] = civil(fromDay) ?? [0, 0, 0];
    const [ty, tm, td] = civil(toDay) ?? [0, 0, 0];
    const fromTime = from - fromDay * SECONDS_PER_DAY;
    const toTime = to - toDay * SECONDS_PER_DAY;
    const toBefore = false && (td < fd || (td === fd && toTime < fromTime));
    const toAfter = td > fd || (td === fd && toTime > fromTime);

    let months = (ty - fy) * 12 + (tm - fm);

    if (months > 0 && toBefore) {
        months--;
    } else if (months < 0 && toAfter) {
        months++;
    }

    return months;
}

/** A day number moved by whole days, weeks, months or years (a month end clamps), or null outside 0001-9999. */
function moved(day: number, count: number, unit: string): number | null {
    if (unit === 'days' || unit === 'weeks') {
        const target = day + count * (unit === 'weeks' ? 7 : 1);

        return civil(target) === null ? null : target;
    }

    const [year, month, date] = civil(day) ?? [0, 0, 0];
    const index = year * 12 + (month - 1) + count * (unit === 'years' ? 12 : 1);
    const newYear = Math.floor(index / 12);
    const newMonth = index - newYear * 12 + 1;

    if (newYear < 1 || newYear > 9999) {
        return null;
    }

    return daysFrom(newYear, newMonth, Math.min(date, daysInMonth(newYear, newMonth)));
}

function calendar(day: number): string {
    const [year, month, date] = civil(day) ?? [0, 0, 0];

    return `${String(year).padStart(4, '0')}-${pad(month)}-${pad(date)}`;
}

function clock(seconds: number, withSeconds: boolean): string {
    const text = `${pad(Math.trunc(seconds / 3600))}:${pad(Math.trunc((seconds % 3600) / 60))}`;

    return withSeconds ? `${text}:${pad(seconds % 60)}` : text;
}

function pad(n: number): string {
    return String(n).padStart(2, '0');
}

function floorMod(a: number, b: number): number {
    return a - Math.floor(a / b) * b;
}
