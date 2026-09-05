/**
 * The TypeScript twin of `app/Services/Submissions/SchemaValueFormatter::displayValue()`
 * (`docs/piping-output-encoding-design.md` §3.2, Increment H6b).
 *
 * ── Why this file is the point of the whole increment ───────────────────────────────────────────────
 * Doc #26 §3.2 says it plainly: a hole PARSER is trivially mirrorable — `template.ts` shipped in H6a and
 * was never the risk. Formatting an ANSWER is where the two engines can disagree, so every branch below
 * is transcribed from the PHP line by line rather than re-derived from what the type "should" render as.
 * Three traps a mirror written from the type instead of from the source gets wrong, each pinned by a
 * golden vector that runs on BOTH engines:
 *
 *   1. The empty guard is the STRICT three-way `=== null || === '' || === []`, not falsiness. `false`,
 *      `0`, `0.0`, `"0"`, `[""]` and `"  "` all fall THROUGH it. `Coercion.isEmpty()` is a different
 *      predicate and is as barred here as `Coercion.toStr()` is.
 *   2. `boolLabel()`'s truthy set is a CLOSED, CASE-SENSITIVE list, so `"yes"` is Yes and `"Yes"` is No.
 *   3. `cascading_select` resolves option labels even though it is not a `hasOptions()` type — the PHP
 *      adds it with an explicit `||`, and dropping that renders raw codes like `"ncr; manila"`.
 *
 * ── Dispatch is on the raw `field_type` string, deliberately ────────────────────────────────────────
 * There is no `FieldType` union anywhere in this codebase (`engine/schema.ts`'s `field_type` is a
 * `string`, and so is the wire's). Minting a 31-case one here to mirror `App\Enums\FieldType` would
 * create a parallel catalog needing forever-sync — a NEW drift surface, in the increment whose whole
 * purpose is closing one. Only nine strings are load-bearing, and each is vector-pinned. The two Sets
 * below follow the house precedent of `schema-mapping.ts`'s `HAS_OPTIONS` and `semantic-validator.ts`'s
 * `MEDIA_FIELD_TYPES`, each commented as a mirror of its PHP predicate.
 *
 * ── Accepted divergences (deliberate, bounded, and not to be "fixed") ───────────────────────────────
 *   • |v| >= 1e21: JavaScript's `toFixed` falls back to exponential notation per spec where PHP's
 *     `%.10F` does not. Hence the `< 1e21` guard in {@link phpString} — it documents where the mirror
 *     stops. No form arithmetic reaches it and no vector may pin a value there.
 *   • A `yes_no` answer of JSON `1.0`: PHP tests `=== 1` (int) and `1.0 === 1` is false, so PHP renders
 *     `No`; JSON `1` and `1.0` are the same JS number, so this renders `Yes`. Unfixable in TypeScript.
 *     Never author a `1.0` yes_no vector.
 *   • Integers beyond 2^53 (PHP keeps 64-bit precision, JS rounds) — unreachable from a form answer.
 *   • `-0` is deliberately NOT special-cased: PHP distinguishes int `0` from float `-0.0` and JS cannot,
 *     so "fixing" one case breaks the other. A clever mirror IS the drift.
 *   • An unknown `field_type` string: PHP's `TemplateSources::fromSnapshot()` fails closed and skips the
 *     field, so its hole renders empty; here it falls to the default branch and stringifies. Reachable
 *     only under deployment skew (a cached PWA bundle meeting a newer snapshot). Closing it costs the
 *     31-string catalog above.
 */

/** Mirror of `FieldType::hasOptions()` — the types carrying an author-defined option list. */
const HAS_OPTIONS = new Set(['single_select', 'multi_select', 'dropdown', 'likert_scale']);

/** Mirror of `FieldType::isGeo()`. */
const GEO_TYPES = new Set(['geopoint', 'geotrace', 'geoshape']);

/**
 * Mirror of `FieldType::isMedia()` — the same five strings `semantic-validator.ts`'s `MEDIA_FIELD_TYPES`
 * holds. On the PHP side this partition is `App\Enums\AnswerEnvelope`, a `match` with no `default` so an
 * unclassified field type is a PHPStan error; there is no equivalent forcing device here, which is why
 * the golden corpus pins each of these families rather than trusting the Set to stay in step.
 */
const MEDIA_TYPES = new Set(['file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature']);

/** Mirror of `boolLabel()`'s truthy strings — CLOSED and CASE-SENSITIVE (PHP's `in_array(…, true)`). */
const TRUTHY_STRINGS = new Set(['1', 'true', 'yes', 'on']);

/** Shared, so the non-choice path allocates no map at all. */
const NO_LABELS: ReadonlyMap<string, string> = new Map();

const isPhpScalar = (v: unknown): v is string | number | boolean =>
    typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean';

const isPlainObject = (v: unknown): v is Record<string, unknown> =>
    typeof v === 'object' && v !== null && !Array.isArray(v);

/**
 * PHP `is_array()` over a `json_decode($json, true)` document. A JSON OBJECT decodes to a PHP array too,
 * so `implode()` walks its VALUES — `Array.isArray({})` is false and would take the scalar branch
 * instead, emitting the JSON text. Returns the values in insertion order, or null when not array-like.
 */
function phpList(value: unknown): unknown[] | null {
    if (Array.isArray(value)) {
        return value;
    }

    return isPlainObject(value) ? Object.values(value) : null;
}

/**
 * The pinned scalar→string rule — the twin of `SchemaValueFormatter::scalarString()` (Doc #26 amendment
 * A7). NOT `String(v)`, and explicitly not `Coercion.toStr()`.
 *
 *     value        String()                  PHP (string)          here
 *     true         "true"                    "1"                   "1"
 *     false        "false"                   ""                    ""
 *     0.1 + 0.2    "0.30000000000000004"     "0.3"                 "0.3"
 *     1 / 3        "0.3333333333333333"      "0.33333333333333"    "0.3333333333"
 *     1e15         "1000000000000000"        "1.0E+15"             "1000000000000000"
 *     0.00001      "0.00001"                 "1.0E-5"              "0.00001"
 *
 * Both divergent branches are reachable: the expression evaluator passes a native bool straight through
 * (so a `calculated` field holding `${age} >= 18` stores one), and any `calculated` field doing division
 * holds a non-integral float. H6a's only float vector was `3.5` — exactly representable in binary, which
 * is why it concealed all of this.
 */
function phpString(value: string | number | boolean): string {
    if (typeof value === 'boolean') {
        return value ? '1' : '';
    }

    if (typeof value === 'number') {
        if (!Number.isFinite(value)) {
            return '';
        }

        return Number.isInteger(value) && Math.abs(value) < 1e21
            ? value.toFixed(0)
            : value.toFixed(10).replace(/0+$/, '').replace(/\.$/, '');
    }

    return value;
}

/**
 * PHP `json_encode()` with DEFAULT flags — the delta from `JSON.stringify` is exactly two rules: `/`
 * escapes to `\/`, and every non-ASCII code unit escapes to `\uXXXX`. Both escape `"`, `\` and C0
 * identically, and U+007F is raw on both sides (hence the U+0080 floor).
 */
function phpJsonEncode(value: unknown): string {
    const json = JSON.stringify(value);

    if (json === undefined) {
        return 'null';   // JS-only: `undefined` has no PHP counterpart
    }

    return json
        .replace(/\//g, '\\/')
        .replace(/[^\x00-\x7f]/g, (c) => `\\u${c.charCodeAt(0).toString(16).padStart(4, '0')}`);
}

/** `SchemaValueFormatter::scalar()` — a non-scalar falls back to its JSON text. */
function scalar(value: unknown): string {
    return isPhpScalar(value) ? phpString(value) : phpJsonEncode(value);
}

/** `boolLabel()`. Never write `Boolean(answer)` or `.toLowerCase()` here — see trap 2 in the header. */
function boolLabel(answer: unknown): string {
    const truthy = answer === true || answer === 1 || (typeof answer === 'string' && TRUTHY_STRINGS.has(answer));

    return truthy ? 'Yes' : 'No';
}

/**
 * One option's `label_translations` variant for `locale`, or null when there is none to use. Mirrors
 * `resolveText()` — a missing, non-string OR BLANK variant is no variant at all — which Doc #26 §4 makes
 * the normative locale resolver, so the two engines agree by construction.
 */
function variant(option: Record<string, unknown>, locale: string | undefined): string | null {
    if (locale === undefined || !isPlainObject(option.label_translations)) {
        return null;
    }

    const value = option.label_translations[locale];

    return typeof value === 'string' && value !== '' ? value : null;
}

/**
 * `optionLabels()` — value ⇒ label, keyed by the STRINGIFIED option value so an integer answer finds an
 * integer-valued option. A `Map`, never an object literal: PHP's `$labels[$k] ?? $k` is a plain miss for
 * any key, where `{}['constructor']` returns a function, and the answer is respondent-controlled.
 *
 * Two fallbacks at two levels, deliberately different and mirroring the PHP exactly: the LOCALE variant
 * falls back when missing/non-string/blank; the BASE label falls back to the value on NULL ONLY, so an
 * author's explicit `"label": ""` still wins.
 */
function optionLabels(config: Record<string, unknown>, locale: string | undefined): ReadonlyMap<string, string> {
    const raw = phpList(config.options ?? []);

    if (raw === null) {
        return NO_LABELS;
    }

    const map = new Map<string, string>();

    for (const option of raw) {
        // PHP skips a non-array entry, and `isset($option['value'])` is null-blind — so a `{"value": null}`
        // entry is dropped too.
        if (!isPlainObject(option) || option.value === undefined || option.value === null) {
            continue;
        }

        const value = scalar(option.value);
        const base = option.label === undefined || option.label === null ? value : scalar(option.label);

        // A duplicate value: LAST wins, as with plain array-key assignment in PHP.
        map.set(value, variant(option, locale) ?? base);
    }

    return map;
}

/** `coord()` — fixed 7 decimals, trailing zeros trimmed, with the `''`/`'-0'` → `'0'` clamp. */
function coord(n: unknown): string {
    // The `.` blocks the zero-strip, so "100.0000000" trims to "100", not to "1".
    const s = Number(n).toFixed(7).replace(/0+$/, '').replace(/\.$/, '');

    return s === '' || s === '-0' ? '0' : s;
}

/** PHP 8 `is_numeric()`: surrounding whitespace and exponents allowed, hex not, booleans never. */
const PHP_NUMERIC = /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/;
const phpIsNumeric = (v: unknown): boolean =>
    typeof v === 'number' ? Number.isFinite(v) : typeof v === 'string' && PHP_NUMERIC.test(v);

/**
 * `formatGeo()`. Unreachable through a published hole — geo is `PipingEligibility::Excluded` — but
 * `displayValue()` is a SHARED formatter, and an incomplete twin fails invisibly the moment anything
 * reuses it (an offline "review your answers" screen is the obvious candidate). Mirrored so the twin
 * stays a twin, and so `formatting.json` needs no `engines` key at all.
 */
function formatGeo(type: string, answer: unknown): string {
    const envelope = isPlainObject(answer) ? answer : null;
    const coordinates = envelope === null ? null : phpList(envelope.coordinates);

    if (coordinates === null) {
        return '';
    }

    if (type === 'geopoint') {
        const lon = coordinates[0] ?? null;   // storage is lon-FIRST
        const lat = coordinates[1] ?? null;

        if (!phpIsNumeric(lon) || !phpIsNumeric(lat)) {
            return '';
        }

        // …display is lat-FIRST. The inversion is deliberate in the PHP and easy to mirror backwards.
        let text = `${coord(lat)}, ${coord(lon)}`;
        const accuracy = envelope?.accuracy ?? null;

        if (phpIsNumeric(accuracy)) {
            text += ` (±${coord(accuracy)} m)`;
        }

        return text;
    }

    // geotrace = the position list; geoshape = the first (outer) ring.
    const positions = type === 'geoshape' ? phpList(coordinates[0] ?? []) : coordinates;
    const count = positions === null ? 0 : positions.length;
    const noun = type === 'geoshape' ? 'Area' : 'Line';

    return `${noun} — ${count} ${count === 1 ? 'point' : 'points'}`;
}

/**
 * The keys of a decoded JSON object in the order PHP's `sort($keys, SORT_STRING)` produces (M74).
 *
 * ⛔ A BARE `.sort()` IS WRONG HERE, AND IT WAS MEASURED RATHER THAN REASONED. PHP compares UTF-8
 * BYTES, which is CODE POINT order. JavaScript's default comparator compares UTF-16 CODE UNITS, where
 * an astral character is a surrogate pair at U+D800-DBFF and therefore sorts BEFORE every BMP
 * character from U+E000 upward. Probed both ways against the PHP: a key set of ASCII, Tagalog
 * diacritics and one emoji AGREED — and concealed the whole question — while a set pairing a fullwidth
 * `＃` (U+FF03) with an emoji DISAGREED. Comparing by code point makes the two identical on both sets.
 *
 * ⚠️ Sorting at all is not cosmetic: PHP's `json_decode` keeps insertion order and turns `"10"` into an
 * INT key, while `Object.keys()` hoists integer-like keys to the front in numeric order. A Likert grid
 * with rows `1`, `2`, `3` is the ordinary case, so unsorted twins diverge on the most likely input.
 */
function sortedKeys(map: Record<string, unknown>): string[] {
    return Object.keys(map).sort((a, b) => {
        const left = Array.from(a);
        const right = Array.from(b);
        const shared = Math.min(left.length, right.length);

        for (let i = 0; i < shared; i += 1) {
            const x = left[i]!.codePointAt(0)!;
            const y = right[i]!.codePointAt(0)!;

            if (x !== y) {
                return x < y ? -1 : 1;
            }
        }

        return left.length - right.length;
    });
}

/**
 * `formatMedia()` — the files, named, joined with `'; '`. Rungs are `name`, then `mime`, then the
 * literal `Attached file`; `id` is deliberately not one. See the PHP for why each.
 */
function formatMedia(answer: unknown): string {
    if (!Array.isArray(answer)) {
        return '';
    }

    return answer
        .filter((ref) => isPlainObject(ref))
        .map((ref) => {
            const name = (ref as Record<string, unknown>).name;
            const mime = (ref as Record<string, unknown>).mime;

            if (typeof name === 'string' && name !== '') {
                return name;
            }

            return typeof mime === 'string' && mime !== '' ? mime : 'Attached file';
        })
        .join('; ');
}

/** `formatGrid()` — `{row:{col:cell}}` → `row: col=cell, col=cell; row: …`. Malformed → `''`. */
function formatGrid(answer: unknown): string {
    if (!isPlainObject(answer)) {
        return '';
    }

    const rows: string[] = [];

    for (const rowKey of sortedKeys(answer)) {
        const cells = answer[rowKey];

        if (!isPlainObject(cells)) {
            continue;
        }

        const pairs = sortedKeys(cells).map((colKey) => `${colKey}=${scalar(cells[colKey])}`);

        if (pairs.length > 0) {
            rows.push(`${rowKey}: ${pairs.join(', ')}`);
        }
    }

    return rows.join('; ');
}

/** `formatScoreGrid()` — `{row:score}` → `row=score; row=score`. Malformed → `''`. */
function formatScoreGrid(answer: unknown): string {
    if (!isPlainObject(answer)) {
        return '';
    }

    return sortedKeys(answer).map((rowKey) => `${rowKey}=${scalar(answer[rowKey])}`).join('; ');
}

/**
 * Format one stored answer for display. Branch ORDER is the contract, not a style choice — the empty
 * guard precedes `yes_no` (so an unanswered yes/no is blank, not "No"), and every OBJECT-VALUED family
 * precedes the array join (which would otherwise walk the envelope's VALUES, `json_encode` each
 * non-scalar one and drop the keys entirely).
 *
 * @param type    the raw `field_type` string, as it appears in the frozen snapshot
 * @param answer  the stored answer, or null/undefined when there is none
 * @param config  the field's raw `config` (holds `options` for choice types) — NOT a render-model
 *                projection, which has already dropped what this reads
 * @param locale  resolves choice-option `label_translations` (amendment A8); omit for the base label
 */
export function displayValue(
    type: string,
    answer: unknown,
    config: Record<string, unknown>,
    locale?: string,
): string {
    const list = phpList(answer);

    // The STRICT three-way guard. `undefined` is TypeScript's absent, which the PHP caller normalises to
    // null before it ever reaches here. An empty JSON object is covered by the length check, because
    // `json_decode($json, true)` turns `{}` into `[]` and PHP's `=== []` catches it.
    if (answer === null || answer === undefined || answer === '' || (list !== null && list.length === 0)) {
        return '';
    }

    if (type === 'yes_no') {
        return boolLabel(answer);
    }

    if (GEO_TYPES.has(type)) {
        return formatGeo(type, answer);
    }

    // M74 — the three families added after this formatter was written, each of which reached the join
    // below and rendered machine noise. `likert_matrix` is the one that produced no JSON at all: its
    // leaves are scalars, so the join emitted `4; 5; 3` with every row label dropped.
    if (MEDIA_TYPES.has(type)) {
        return formatMedia(answer);
    }

    if (type === 'matrix') {
        return formatGrid(answer);
    }

    if (type === 'likert_matrix') {
        return formatScoreGrid(answer);
    }

    // The `||` is load-bearing: `cascading_select` is NOT a `hasOptions()` type.
    const labels = HAS_OPTIONS.has(type) || type === 'cascading_select' ? optionLabels(config, locale) : NO_LABELS;

    if (list !== null) {
        // Separator `'; '`, submitted order preserved — no sort, no dedupe, no empty filtering — with a
        // per-element fallback to the raw value on a label miss.
        return list.map((v) => {
            const s = scalar(v);

            return labels.get(s) ?? s;
        }).join('; ');
    }

    const s = scalar(answer);

    return labels.get(s) ?? s;
}
