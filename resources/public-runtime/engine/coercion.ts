/**
 * The value-coercion primitives — the TypeScript mirror of `app/Services/Expressions/Coercion.php`
 * (technical-architecture.md §4.3 §6.0). This is the normative §6 contract reproduced byte-for-byte so the
 * client-side (SPA) engine reaches the same verdict as the PHP authority; the shared golden-vector suite
 * (`tests/golden/**`) is run through BOTH engines to make any drift a CI failure (Risk R3).
 *
 * Coercion is VALUE-driven, never schema-driven: numericness is decided by the literal kind + the runtime
 * type of the answer, so every golden vector is self-contained. Ordering inside `toBool` is load-bearing:
 * the numeric check precedes the string set, so "0"/"0.0"/"00" are all falsy. `NUMERIC_RE` is anchored with
 * no exponent / no leading + / no bare or trailing dot / no whitespace / no hex — so "", " 5 ", "1e3",
 * "0x1F", "5.", ".5", "+5", "Infinity" are all NON-numeric (→ NaN in comparisons, → false in gt/lt), the
 * headline divergence from JS `Number()` that this module deliberately closes.
 */

/**
 * The internal "field is unanswered" sentinel — the mirror of PHP `Marker::Absent`. A missing key or unset
 * `.` self-reference evaluates to this; it is normalised to `null` only at the public value boundary.
 */
export const ABSENT: unique symbol = Symbol('absent');
export type Absent = typeof ABSENT;

/** Every value the engine can hold at runtime (answers + literals + the absent sentinel). */
export type EngineValue = string | number | boolean | null | EngineValue[];
export type MaybeAbsent = EngineValue | Absent;

/** Anchored; NO `u` flag; no exponent / no leading + / no bare or trailing dot / no whitespace / no hex. */
const NUMERIC_RE = /^-?[0-9]+(\.[0-9]+)?$/;

export function isEmpty(value: MaybeAbsent): boolean {
    return (
        value === ABSENT ||
        value === null ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

export function isNumericLike(value: MaybeAbsent): boolean {
    // A PHP int/float ⇒ a JS `number` (booleans are excluded, as `is_int`/`is_float` exclude PHP bools).
    return (
        typeof value === 'number' ||
        (typeof value === 'string' && NUMERIC_RE.test(value))
    );
}

export function toNumber(value: MaybeAbsent): number {
    return isNumericLike(value) ? Number(value) : NaN;
}

export function toStr(value: MaybeAbsent): string {
    if (value === ABSENT || value === null) {
        return '';
    }

    if (typeof value === 'boolean') {
        return value ? '1' : '';
    }

    if (typeof value === 'string') {
        return value;
    }

    if (typeof value === 'number') {
        // Integral finite values stringify without a fractional part ("5", not "5.0"), mirroring PHP's
        // int-cast branch. Non-integral arithmetic results (grammar v2.0) fall through to JS default
        // formatting; cross-engine byte-parity for a non-integral float→string is NOT pinned (the
        // documented caveat), so such values are compared as NUMBERS in the golden `computed` block.
        if (Number.isFinite(value) && Math.floor(value) === value && Math.abs(value) < 1e15) {
            return String(Math.trunc(value));
        }

        return String(value);
    }

    // Arrays never reach toStr in a comparison (Eq rule 3 short-circuits). Defensive only.
    return '';
}

export function toBool(value: MaybeAbsent): boolean {
    if (typeof value === 'boolean') {
        return value;
    }

    if (value === ABSENT || value === null) {
        return false;
    }

    if (Array.isArray(value)) {
        return value.length !== 0;
    }

    if (isNumericLike(value)) {
        const n = toNumber(value);

        // NaN (only reachable from a grammar-v2.0 arithmetic result over empty/non-numeric operands) is
        // falsy; every real numeric value is truthy iff non-zero.
        return !Number.isNaN(n) && n !== 0;
    }

    if (typeof value === 'string') {
        const lower = value.toLowerCase();

        return lower !== '' && lower !== '0' && lower !== 'false';
    }

    return false;
}

/**
 * PHP `trim()`'s default set — space, `\t`, `\n`, `\r`, NUL and `\x0B` — and nothing else. JavaScript's own
 * `.trim()` is NOT this: it strips NBSP and the other Unicode spaces and keeps NUL, the reverse of PHP on both.
 */
const PHP_TRIM_RE = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;

function phpTrim(value: string): string {
    return value.replace(PHP_TRIM_RE, '');
}

/** PHP 8's `strtolower()` is ASCII-only; `.toLowerCase()` folds every script. */
function asciiLower(value: string): string {
    return value.replace(/[A-Z]/g, (letter) => String.fromCharCode(letter.charCodeAt(0) + 32));
}

/**
 * `M124` — the mirror of `Coercion::yesNoLiteral()`: the STRICT reading of whatever a yes/no answer is
 * compared with. `yes`, `true` and `1` read as true and `no`, `false` and `0` as false, trimmed and
 * lowercased as PHP does it; the numbers 1 and 0 likewise; everything else — `maybe`, `''`, a boolean, null,
 * absent — is null, which equals no answer. Deliberately not {@link yesNoAnswer}'s permissive table.
 */
export function yesNoLiteral(value: MaybeAbsent): boolean | null {
    if (typeof value === 'number') {
        return value === 1 ? true : value === 0 ? false : null;
    }

    if (typeof value !== 'string') {
        return null;
    }

    switch (asciiLower(phpTrim(value))) {
        case 'yes':
        case 'true':
        case '1':
            return true;
        case 'no':
        case 'false':
        case '0':
            return false;
        default:
            return null;
    }
}

/**
 * `M124` — the mirror of `Coercion::yesNoAnswer()`: a yes/no ANSWER as a boolean, by the table the server's
 * Stage 2 applies. The browser runs no Stage 2, so its Yes/No control's `'yes'`/`'no'` reach the engine as
 * strings; Stage 3 reads them through this. A string is false only when, trimmed and lowercased, it is '',
 * '0', 'false' or 'no'; any other value reads through {@link toBool}.
 */
export function yesNoAnswer(value: MaybeAbsent): boolean {
    if (typeof value === 'string') {
        return !['', '0', 'false', 'no'].includes(asciiLower(phpTrim(value)));
    }

    return toBool(value);
}
