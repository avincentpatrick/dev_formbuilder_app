<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * `round(n, places)` and a number written as text for `concat()` (M180, `R-da02fe03`, `D156`) — one algorithm the
 * TypeScript twin (`resources/public-runtime/engine/rounding.ts`) repeats digit for digit, pinned by
 * `tests/golden/expressions/math-functions.json`.
 *
 * ⛔ NOT PHP's round() AND NOT JavaScript's Math.round(). They disagree with each other — PHP rounds half away from
 * zero, `Math.round` half toward +∞ (-2.5 → -2) — and both round the BINARY value, so 1.005 (stored as
 * 1.00499999…) rounds down to 1.00 where a person expects 1.01. Here a number is first read as the SHORTEST decimal
 * that reads back as exactly that number — what the author typed and what both engines print — found by asking for
 * one significant digit more until the text reads back exactly (never PHP's `precision` setting); the digits of that
 * decimal are then rounded half away from zero, as text. Both engines find the same shortest decimal: a number
 * reads back exactly from at most one decimal of each length, so neither engine's tie rule can choose differently.
 *
 * The same shortest decimal written out in full, never with an exponent, is how `concat()` writes a number — so
 * `concat(0.1 + 0.2)` is "0.30000000000000004" on both engines, where PHP's own string cast says "0.3".
 */
final class Rounding
{
    public const MAX_PLACES = 10;

    /** `value` rounded half away from zero to `places` decimals, on its shortest decimal. NaN stays NaN. */
    public static function round(float $value, int $places): float
    {
        if (is_nan($value) || is_infinite($value)) {
            return NAN;
        }

        [$negative, $scaled] = self::scaled($value, $places);

        return (float) (($negative ? '-' : '').self::placeDecimal($scaled, $places));
    }

    /**
     * `value` rounded the same way and WRITTEN with exactly `places` decimals — "3.50", "34.0", never "-0.00" (M180, a
     * calculation shown as a Number). The text is the point: a float cannot carry a trailing zero.
     */
    public static function fixed(float $value, int $places): string
    {
        [$negative, $scaled] = self::scaled($value, $places);

        return ($negative && trim($scaled, '0') !== '' ? '-' : '').self::placeDecimal($scaled, $places);
    }

    /**
     * |value| × 10^places rounded half away from zero on its shortest decimal, as a digit string, with its sign.
     *
     * @return array{0: bool, 1: string}
     */
    private static function scaled(float $value, int $places): array
    {
        if ($value == 0.0) {
            return [false, '0'];
        }

        [$negative, $digits, $exponent] = self::shortest($value);
        $shift = $exponent + $places; // |value| × 10^places = digits × 10^shift

        if ($shift >= 0) {
            $scaled = $digits.str_repeat('0', $shift);
        } else {
            $keep = strlen($digits) + $shift;

            if ($keep < 0) {
                $scaled = '0';
            } else {
                $head = $keep === 0 ? '0' : substr($digits, 0, $keep);
                $scaled = (int) $digits[$keep] >= 5 ? self::increment($head) : $head;
            }
        }

        return [$negative, $scaled];
    }

    /** A number as plain decimal text — its shortest decimal, never an exponent — the same on both engines. */
    public static function plain(float $value): string
    {
        if ($value == 0.0) {
            return '0';
        }

        [$negative, $digits, $exponent] = self::shortest($value);
        $text = $exponent >= 0 ? $digits.str_repeat('0', $exponent) : self::placeDecimal($digits, -$exponent);

        return ($negative ? '-' : '').$text;
    }

    /**
     * The shortest decimal that reads back as exactly `value`: |value| = digits × 10^exponent, with no trailing zero.
     *
     * @return array{0: bool, 1: string, 2: int} [negative, digits, exponent]
     */
    private static function shortest(float $value): array
    {
        $magnitude = abs($value);
        $text = sprintf('%.16e', $magnitude);

        for ($precision = 1; $precision <= 17; $precision++) {
            $text = sprintf('%.'.($precision - 1).'e', $magnitude);

            if ((float) $text === $magnitude) {
                break;
            }
        }

        [$mantissa, $power] = explode('e', $text);
        $digits = str_replace('.', '', $mantissa);
        $trimmed = rtrim($digits, '0');

        return [$value < 0, $trimmed === '' ? '0' : $trimmed, (int) $power - (strlen($trimmed) - 1)];
    }

    /** "101" with 2 places is "1.01"; "5" with 3 is "0.005"; any digits with 0 places are themselves. */
    private static function placeDecimal(string $digits, int $places): string
    {
        if ($places === 0) {
            return $digits;
        }

        $padded = str_pad($digits, $places + 1, '0', STR_PAD_LEFT);

        return substr($padded, 0, -$places).'.'.substr($padded, -$places);
    }

    /** A digit string plus one, carried. */
    private static function increment(string $digits): string
    {
        $index = strlen($digits) - 1;

        while ($index >= 0 && $digits[$index] === '9') {
            $digits[$index] = '0';
            $index--;
        }

        return $index < 0 ? '1'.$digits : substr_replace($digits, (string) ((int) $digits[$index] + 1), $index, 1);
    }
}
