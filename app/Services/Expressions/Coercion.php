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
            // Integral floats stringify without a fractional part ("5", not "5.0"). Non-integral arithmetic
            // results (grammar v2.0) fall through to PHP's default float formatting; cross-engine byte-parity
            // for a non-integral float→string is NOT pinned (the documented checksum caveat), so such values
            // are compared as NUMBERS in the golden `computed` block, never stringified into a vector.
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
            $number = self::toNumber($value);

            // NaN (only reachable from a grammar-v2.0 arithmetic result over empty/non-numeric operands) is
            // falsy; every real numeric value is truthy iff non-zero.
            return ! is_nan($number) && $number !== 0.0;
        }

        if (is_string($value)) {
            $lower = strtolower($value);

            return $lower !== '' && $lower !== '0' && $lower !== 'false';
        }

        return false;
    }

    /**
     * `M124` — the STRICT reading of whatever a yes/no answer is compared with. The answer itself is a boolean
     * by the time it is compared (Stage 2 writes one, and Stage 3 reads a string answer through
     * {@see yesNoAnswer()}), and the other side means yes or no only if it says so: `yes`, `true` and `1` read
     * as true and `no`, `false` and `0` as false, trimmed and lowercased, so `Yes` and ` NO ` do too. The
     * numbers 1 and 0 read the same way, because a numeric-looking rule value lowers to a Number literal.
     * Everything else — `maybe`, `y`, `''`, a boolean, null, absent — is null, which equals no answer at all.
     *
     * Deliberately NOT {@see yesNoAnswer()}'s table, which reads every other non-empty string as yes: right
     * for an answer a client sent, wrong for a comparison an author wrote, where `= 'maybe'` must not hold for
     * a Yes. The TypeScript twin must trim and lowercase exactly as PHP does, not as JavaScript does.
     */
    public static function yesNoLiteral(mixed $value): ?bool
    {
        if (is_int($value) || is_float($value)) {
            return match ((float) $value) {
                1.0 => true,
                0.0 => false,
                default => null,
            };
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'yes', 'true', '1' => true,
            'no', 'false', '0' => false,
            default => null,
        };
    }

    /**
     * `M124` — a yes/no ANSWER as a boolean: the table Stage 2's `StructuralAnswerNormalizer` has always
     * applied, moved here so Stage 3 can apply the same one to an answer that never passed through Stage 2 —
     * the browser's, which arrives as the string its control emits. A string is false only when, trimmed and
     * lowercased, it is '', '0', 'false' or 'no', and every other string is yes; any other value reads through
     * {@see toBool()}. It is not a superset of toBool: '0.0' is false there and yes here, and ' 0 ' the reverse.
     */
    public static function yesNoAnswer(mixed $value): bool
    {
        if (is_string($value)) {
            $lower = strtolower(trim($value));

            return ! in_array($lower, ['', '0', 'false', 'no'], true);
        }

        return self::toBool($value);
    }
}
