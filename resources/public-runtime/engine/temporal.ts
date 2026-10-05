/**
 * Chronological ordering of ISO-8601 dates, times and date-times (Increment M134, `R-62b638e1`; `D71`, `D89`) —
 * the mirror of `app/Services/Expressions/Temporal.php`, reached ONLY from the evaluator's `numericCompare()` once
 * either operand has failed to be a number. A numeric-like string never parses here and an ISO value is never
 * numeric-like, so every pre-existing vector is untouched. `tests/golden/expressions/temporal.json` and
 * `tests/golden/validation/temporal.json` hold the two engines together.
 *
 * NOT `coercion.ts`: that is the byte-for-byte contract, and a date is not a number. Equality is untouched.
 *
 * Strict shapes, strings only: `YYYY-MM-DD`, `HH:MM[:SS]`, `YYYY-MM-DDTHH:MM[:SS]` with an optional `Z` or
 * `±HH:MM`; calendar-valid, years 0001-9999. A date with a date and a naive date-time with a naive date-time
 * compare (a date reads as its midnight); a time with a time; zoned with zoned, as instants. A naive value never
 * compares with a zoned one (`D89`), so no answer orders against `now()`.
 *
 * Integer arithmetic only — never `new Date(str)`, which reads a naive string as LOCAL time. The keys stay far
 * below 2^53. JavaScript's `$` (no `m` flag) is the end of input, so these patterns match PHP's `\A…\z`.
 */

import type { MaybeAbsent } from './coercion';

type Ordering = 'gt' | 'lt' | 'gte' | 'lte';

export interface TemporalValue {
    kind: 'date' | 'time' | 'datetime';
    zoned: boolean;
    key: number;
}

const DATE = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/;
const TIME = /^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/;
const DATETIME = /^([0-9]{4})-([0-9]{2})-([0-9]{2})T([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?(Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])?$/;

const SECONDS_PER_DAY = 86400;

/** Does `left op right` hold chronologically? False whenever either side is not a temporal value, or the two cannot be compared. */
export function ordersTemporally(left: MaybeAbsent, right: MaybeAbsent, op: Ordering): boolean {
    const a = parseTemporal(left);
    const b = parseTemporal(right);

    if (a === null || b === null || !comparable(a, b)) {
        return false;
    }

    switch (op) {
        case 'gt':
            return a.key > b.key;
        case 'lt':
            return a.key < b.key;
        case 'gte':
            return a.key >= b.key;
        case 'lte':
            return a.key <= b.key;
    }
}

/** A strict parse — null for anything that is not exactly one of the three shapes. Mirrors Temporal::parse. */
export function parseTemporal(value: MaybeAbsent): TemporalValue | null {
    if (typeof value !== 'string') {
        return null;
    }

    let m = DATE.exec(value);
    if (m !== null) {
        const days = daysFrom(Number(m[1]), Number(m[2]), Number(m[3]));

        return days === null ? null : { kind: 'date', zoned: false, key: days * SECONDS_PER_DAY };
    }

    m = TIME.exec(value);
    if (m !== null) {
        return { kind: 'time', zoned: false, key: secondsOfDay(m[1], m[2], m[3]) };
    }

    m = DATETIME.exec(value);
    if (m !== null) {
        const days = daysFrom(Number(m[1]), Number(m[2]), Number(m[3]));

        if (days === null) {
            return null;
        }

        const offset = m[7];
        const key = days * SECONDS_PER_DAY + secondsOfDay(m[4], m[5], m[6]);

        return { kind: 'datetime', zoned: offset !== undefined, key: key - offsetSeconds(offset) };
    }

    return null;
}

function comparable(a: TemporalValue, b: TemporalValue): boolean {
    if (a.kind === 'time' || b.kind === 'time') {
        return a.kind === b.kind;
    }

    return a.zoned === b.zoned;
}

/** Days from a fixed origin (0000-03-01) for a calendar-valid date, else null. */
function daysFrom(year: number, month: number, day: number): number | null {
    if (year < 1 || month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month)) {
        return null;
    }

    const shift = month <= 2 ? 1 : 0;
    const y = year - shift;
    const m = month + 12 * shift - 3;

    return 365 * y + Math.floor(y / 4) - Math.floor(y / 100) + Math.floor(y / 400) + Math.floor((153 * m + 2) / 5) + day - 1;
}

function daysInMonth(year: number, month: number): number {
    if (month === 2) {
        return year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0) ? 29 : 28;
    }

    return [4, 6, 9, 11].includes(month) ? 30 : 31;
}

function secondsOfDay(hours: string, minutes: string, seconds: string | undefined): number {
    return Number(hours) * 3600 + Number(minutes) * 60 + Number(seconds ?? '0');
}

function offsetSeconds(offset: string | undefined): number {
    if (offset === undefined || offset === 'Z') {
        return 0;
    }

    const magnitude = Number(offset.slice(1, 3)) * 3600 + Number(offset.slice(4, 6)) * 60;

    return offset[0] === '-' ? -magnitude : magnitude;
}
