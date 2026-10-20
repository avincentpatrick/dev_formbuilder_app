/**
 * A question's Default value, filled in when a form opens fresh (M180, `R-20784770`, `D148`, `D154`).
 *
 * The value was always stored and always sent — `default_value`, with `default_value_is_expression` for a formula —
 * but nothing put it into the answers (only a hidden question's FIXED value, `prefill.ts`). Here each one is worked out
 * ONCE (`D154`, ODK's dynamic default): a typed value as written, a formula over the clock and the defaults filled in
 * before it, in the order the form shows its questions — so a formula naming a later question reads it as blank. After
 * that it is the respondent's answer, to keep or change; a value that keeps updating is what a calculation is for.
 *
 * Shaped for the question's control (a number as a number, a choice only from its own options, a multiple choice's
 * space-separated values as a list, a date or time trimmed to what its input takes); a default that does not fit is
 * left out rather than shown wrong. A question that takes no default — `TAKES_NO_DEFAULT` — gets none; the builder hides
 * its box by the same list. The server fills nothing: a default is the respondent's to clear, so only the browser
 * seeds it (`useFormRuntime`'s `seedDefaults`), and only on a fresh form.
 */

import { EvaluationContext, makeExpressionEvaluator, type Answers } from '../engine';
import { isNumericLike, toNumber, toStr, yesNoLiteral, type EngineValue } from '../engine/coercion';
import { parseTemporal } from '../engine/temporal';
import type { AnswerMap, RawField, SchemaResponse } from './types';

/** The question types a default means nothing for — or, for a hidden question, means its fixed prefill (`prefill.ts`). */
export const TAKES_NO_DEFAULT: ReadonlySet<string> = new Set([
    'note', 'page_break', 'calculated', 'hidden',
    'file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature',
    'matrix', 'likert_matrix', 'cascading_select', 'geopoint', 'geotrace', 'geoshape',
]);

const SINGLE_CHOICES = new Set(['single_select', 'dropdown', 'likert_scale']);

export interface Defaults {
    /** Defaults of the questions outside a repeating section. */
    top: AnswerMap;
    /** Each repeating section's member defaults, filled into every new entry. */
    members: Record<string, Record<string, EngineValue>>;
}

export function buildDefaults(schema: SchemaResponse, now: string | null): Defaults {
    const { sections, fields } = schema.version.schema;
    const sectionSequence = new Map(sections.map((section) => [section.key, section.sequence]));
    const repeats = new Set(sections.filter((section) => section.is_repeatable).map((section) => section.key));
    const position = (field: RawField): [number, number] => [field.section_key === null ? -1 : (sectionSequence.get(field.section_key) ?? -1), field.sequence];
    const ordered = [...fields].sort((a, b) => {
        const [as, af] = position(a);
        const [bs, bf] = position(b);

        return as - bs || af - bf;
    });

    const evaluator = makeExpressionEvaluator();
    const top: AnswerMap = {};
    const members: Record<string, Record<string, EngineValue>> = {};

    for (const field of ordered) {
        const raw = field.default_value;
        if (raw === null || raw === '' || TAKES_NO_DEFAULT.has(field.field_type)) {
            continue;
        }

        let value: unknown = raw;
        if (field.default_value_is_expression) {
            try {
                value = evaluator.evaluate(raw, new EvaluationContext({ ...top } as Answers, undefined, now));
            } catch {
                continue; // a formula that does not parse fills nothing in
            }
        }

        const shaped = shape(field, value);
        if (shaped === null) {
            continue;
        }

        const repeat = field.section_key !== null && repeats.has(field.section_key) ? field.section_key : null;
        if (repeat === null) {
            top[field.key] = shaped;
        } else {
            (members[repeat] ??= {})[field.key] = shaped;
        }
    }

    return { top, members };
}

/** The value as the question's control holds it, or null when it does not fit that question. */
function shape(field: RawField, value: unknown): EngineValue | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    switch (field.field_type) {
        case 'integer':
        case 'decimal':
            if (typeof value === 'number') {
                return Number.isFinite(value) ? value : null;
            }

            return typeof value === 'string' && isNumericLike(value) ? toNumber(value) : null;
        case 'multi_select': {
            const picked = Array.isArray(value) ? value.map((item) => toStr(item as EngineValue)) : toStr(value as EngineValue).split(/\s+/).filter((item) => item !== '');
            const allowed = optionValues(field);
            const kept = allowed === null ? picked : picked.filter((item) => allowed.has(item));

            return kept.length > 0 ? kept : null;
        }
        case 'yes_no': {
            const meaning = typeof value === 'boolean' ? value : yesNoLiteral(value as EngineValue);

            return meaning === null ? null : meaning ? 'yes' : 'no';
        }
        case 'date': {
            const date = toStr(value as EngineValue).slice(0, 10);

            return parseTemporal(date)?.kind === 'date' ? date : null;
        }
        case 'time': {
            const text = toStr(value as EngineValue);
            const time = text[10] === 'T' ? text.slice(11, 16) : text.slice(0, 5);

            return parseTemporal(time)?.kind === 'time' ? time : null;
        }
        case 'datetime': {
            const text = toStr(value as EngineValue);
            const local = parseTemporal(text)?.kind === 'date' ? `${text}T00:00` : text.slice(0, 16);

            return parseTemporal(local)?.kind === 'datetime' ? local : null;
        }
        default: {
            if (typeof value === 'object') {
                return null;
            }

            const text = toStr(value as EngineValue);
            if (SINGLE_CHOICES.has(field.field_type)) {
                const allowed = optionValues(field);

                return allowed === null || allowed.has(text) ? text : null;
            }

            return text === '' ? null : text;
        }
    }
}

/** A choice question's own option values, or null when its list comes from elsewhere (or is not typed here). */
function optionValues(field: RawField): Set<string> | null {
    const options = field.config?.options;
    if (field.config?.options_source != null || !Array.isArray(options) || options.length === 0) {
        return null;
    }

    return new Set(options.map((option) => String(option.value)));
}
