/**
 * `round(n, places)` and a number written as text for `concat()` (M180, `R-da02fe03`, `D156`) — the mirror of PHP
 * `App\Services\Expressions\Rounding`, pinned by `tests/golden/expressions/math-functions.json`.
 *
 * ⛔ NOT Math.round() AND NOT PHP's round(). They disagree — `Math.round` rounds half toward +∞ (-2.5 → -2), PHP half
 * away from zero — and both round the BINARY value, so 1.005 (stored as 1.00499999…) rounds down to 1.00. Here a
 * number is first read as the SHORTEST decimal that reads back as exactly that number (`toExponential` with one
 * significant digit more each time), then its digits are rounded half away from zero, as text. A number reads back
 * exactly from at most one decimal of each length, so this engine and PHP find the same digits.
 */

export const MAX_PLACES = 10;

/** `value` rounded half away from zero to `places` decimals, on its shortest decimal. NaN stays NaN. */
export function roundHalfAway(value: number, places: number): number {
    if (!Number.isFinite(value)) {
        return Number.NaN;
    }

    const [negative, scaled] = scaledDigits(value, places);

    return Number((negative ? '-' : '') + placeDecimal(scaled, places));
}

/** `value` rounded the same way and WRITTEN with exactly `places` decimals — "3.50", "34.0", never "-0.00". Mirrors PHP `Rounding::fixed()`. */
export function fixedDecimal(value: number, places: number): string {
    const [negative, scaled] = scaledDigits(value, places);

    return (negative && /[1-9]/.test(scaled) ? '-' : '') + placeDecimal(scaled, places);
}

/** |value| × 10^places rounded half away from zero on its shortest decimal, as a digit string, with its sign. */
function scaledDigits(value: number, places: number): [boolean, string] {
    if (value === 0) {
        return [false, '0'];
    }

    const [negative, digits, exponent] = shortest(value);
    const shift = exponent + places; // |value| × 10^places = digits × 10^shift
    let scaled: string;

    if (shift >= 0) {
        scaled = digits + '0'.repeat(shift);
    } else {
        const keep = digits.length + shift;

        if (keep < 0) {
            scaled = '0';
        } else {
            const head = keep === 0 ? '0' : digits.slice(0, keep);
            scaled = Number(digits[keep]) >= 5 ? increment(head) : head;
        }
    }

    return [negative, scaled];
}

/** A number as plain decimal text — its shortest decimal, never an exponent — the same on both engines. */
export function plainDecimal(value: number): string {
    if (value === 0) {
        return '0';
    }

    const [negative, digits, exponent] = shortest(value);
    const text = exponent >= 0 ? digits + '0'.repeat(exponent) : placeDecimal(digits, -exponent);

    return (negative ? '-' : '') + text;
}

/** The shortest decimal that reads back as exactly `value`: |value| = digits × 10^exponent, with no trailing zero. */
function shortest(value: number): [boolean, string, number] {
    const magnitude = Math.abs(value);
    let text = magnitude.toExponential(16);

    for (let precision = 1; precision <= 17; precision++) {
        text = magnitude.toExponential(precision - 1);

        if (Number(text) === magnitude) {
            break;
        }
    }

    const [mantissa, power] = text.split('e');
    const digits = mantissa.replace('.', '');
    const trimmed = digits.replace(/0+$/, '');
    const kept = trimmed === '' ? '0' : trimmed;

    return [value < 0, kept, Number(power) - (kept.length - 1)];
}

/** "101" with 2 places is "1.01"; "5" with 3 is "0.005"; any digits with 0 places are themselves. */
function placeDecimal(digits: string, places: number): string {
    if (places === 0) {
        return digits;
    }

    const padded = digits.padStart(places + 1, '0');

    return padded.slice(0, -places) + '.' + padded.slice(-places);
}

/** A digit string plus one, carried. */
function increment(digits: string): string {
    const chars = digits.split('');
    let index = chars.length - 1;

    while (index >= 0 && chars[index] === '9') {
        chars[index] = '0';
        index--;
    }

    if (index < 0) {
        return '1' + chars.join('');
    }

    chars[index] = String(Number(chars[index]) + 1);

    return chars.join('');
}
