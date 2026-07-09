<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * The value-coercion primitives (technical-architecture.md §4.3 §6.0) — the normative contract the
 * TypeScript client mirror must reproduce byte-for-byte, and the shared core F3's structured
 * validators reuse. Coercion is VALUE-driven, never schema-driven: numericness is decided by the literal
 * kind + the JSON runtime type of the answer, so every golden vector is self-contained.
 *
 * Ordering inside toBool is load-bearing: the numeric check precedes the string set, so "0"/"0.0"/"00"
 * are all falsy. NUMERIC_RE is anchored with no exponent / no leading + / no bare or trailing dot / no
 * whitespace / no hex — so "" , " 5 ", "1e3", "0x1F", "5.", ".5", "+5", "Infinity" are all NON-numeric
 * (→ NaN in comparisons, → false in gt/lt), the headline divergence from JS `Number()`.
 */
final class Coercion
{
    private const NUMERIC_RE = '/^-?[0-9]+(\.[0-9]+)?$/';

    public static function isEmpty(mixed $value): bool
    {
        return $value === Marker::Absent
            || $value === null
            || $value === ''
            || (is_array($value) && $value === []);
    }

    public static function isNumericLike(mixed $value): bool
    {
        return is_int($value)
            || is_float($value)
            || (is_string($value) && preg_match(self::NUMERIC_RE, $value) === 1);
    }

    public static function toNumber(mixed $value): float
    {
        return self::isNumericLike($value) ? (float) $value : NAN;
    }

    public static function toStr(mixed $value): string
    {
        if ($value === Marker::Absent || $value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Integral floats stringify without a fractional part ("5", not "5.0"); non-integral values
            // never arise in Phase 1 (no arithmetic) and are re-pinned when arithmetic lands (Phase 2).
            if (is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
                return (string) (int) $value;
            }

            return (string) $value;
        }

        // Arrays never reach toStr in a comparison (Eq rule 3 short-circuits). Defensive only.
        return '';
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === Marker::Absent || $value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (self::isNumericLike($value)) {
            return self::toNumber($value) !== 0.0;
        }

        if (is_string($value)) {
            $lower = strtolower($value);

            return $lower !== '' && $lower !== '0' && $lower !== 'false';
        }

        return false;
    }
}
