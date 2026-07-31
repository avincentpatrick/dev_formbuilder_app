/**
 * Hidden-field prefill (Increment H7) — the SPA half of `App\Enums\PrefillSource`.
 *
 * A `hidden` field is the one field type nobody fills in, so its value has to arrive from somewhere else.
 * Two sources, and the difference between them is a trust boundary, not a convenience:
 *
 *   - **fixed**  — the authored `default_value`. Seeded here purely so the value EXISTS at render time, which
 *     is what lets a label pipe `${key}` on the very first paint (Doc #26 §3.1's headline case for why
 *     `hidden` is a pipeable source at all). The server writes its own copy over whatever we submit, so this
 *     is a display convenience with no authority; the string is passed through VERBATIM for that reason —
 *     any transformation here would be a formatting decision able to drift from PHP's.
 *   - **url**    — a query parameter. Genuinely untrusted, and the only source whose value survives to storage.
 *
 * Deliberately DOM-free: the caller reads `window.location.search` and hands it in, so this whole module is a
 * pure function of (schema, search) and the store stays unit-testable, as its own docblock requires.
 *
 * ── The over-cap asymmetry, stated so it does not read as a bug later ────────────────────────────────
 * An over-cap URL parameter is DROPPED here, while `StructuralAnswerNormalizer::coerceHidden()` REJECTS an
 * over-cap value with a 422. They cannot disagree, because this function is what decides whether such a
 * value ever enters the answer map: the SPA never submits one, so the server-side fault is reachable only
 * from a direct API post. Dropping is right on this side — a respondent handed a hostile or broken link
 * should get a form that works, not one that cannot be submitted for a reason they cannot see or fix.
 */

import type { AnswerMap, RawField, SchemaResponse } from './types';

/**
 * Mirror of `PrefillSource::MAX_VALUE_BYTES`. Measured in UTF-8 BYTES, not UTF-16 code units, because PHP's
 * `strlen()` counts bytes — `'é'.length` is 1 in JS and 2 in PHP, and a cap that disagreed across the
 * boundary would let the SPA submit a value the server then refuses.
 */
export const MAX_PREFILL_BYTES = 2000;

/** Mirror of `PrefillSource`'s cases. `'none'`/absent is expressed as `null` here. */
export type PrefillSource = 'fixed' | 'url';

const encoder = new TextEncoder();

function byteLength(value: string): number {
    return encoder.encode(value).length;
}

/** The declared source for one field, or null for every non-`hidden` field and every undeclared one. */
export function prefillSourceOf(field: RawField): PrefillSource | null {
    if (field.field_type !== 'hidden') {
        return null;
    }
    const declared = field.config?.prefill_source;
    return declared === 'fixed' || declared === 'url' ? declared : null;
}

/**
 * The query-parameter name a `url`-sourced field reads. Mirror of `PrefillSource::urlParam()`: a blank or
 * absent name falls back to the field's own key.
 */
export function prefillParamOf(field: RawField): string {
    const declared = field.config?.url_param;
    return typeof declared === 'string' && declared.trim() !== '' ? declared.trim() : field.key;
}

/**
 * Every hidden field's prefilled value, keyed by field key. Fields with no source, no value, or an over-cap
 * URL parameter are simply absent — an unanswered hidden field is legal (the publish gate refuses `required`
 * on one precisely so that stays true).
 *
 * @param search the raw `location.search`, leading `?` optional
 */
export function buildPrefill(schema: SchemaResponse, search: string): AnswerMap {
    const params = new URLSearchParams(search);
    const out: AnswerMap = {};

    for (const field of schema.version.schema.fields) {
        const source = prefillSourceOf(field);

        if (source === 'fixed') {
            // Verbatim — see the module docblock. A blank literal is "no value", matching PHP's
            // `applyFixedPrefills()`, which skips null and ''.
            if (field.default_value !== null && field.default_value !== '') {
                out[field.key] = field.default_value;
            }
            continue;
        }

        if (source === 'url') {
            const value = params.get(prefillParamOf(field));
            if (value !== null && value !== '' && byteLength(value) <= MAX_PREFILL_BYTES) {
                out[field.key] = value;
            }
        }
    }

    return out;
}
