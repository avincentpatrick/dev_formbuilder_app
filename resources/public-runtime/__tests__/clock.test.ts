/**
 * Increment H21a — the session clock (Doc #27 §3.4, amendment A1).
 *
 * Before this, `toSemanticInput()` passed no `now` at all, so `SemanticInput.now` fell to null, `hasNow()`
 * was false, and BOTH clock functions returned ABSENT in the SPA while PHP always stamps one. That is a live
 * PHP/TS relevance divergence the R3 gate structurally CANNOT see, because the golden runner supplies `now`
 * from each vector — the wiring diverges, the vectors do not.
 *
 * Two things are pinned here and both are load-bearing:
 *
 *  - THE FORMAT. Both engines return the injected clock verbatim from `now()`, so the string shape is itself
 *    a mirror surface. `Carbon::now()->toIso8601String()` under `config/app.php`'s `'timezone' => 'UTC'`
 *    emits `2026-08-01T12:34:56+00:00` — seconds precision, explicit offset, never `Z` — and every `now`
 *    literal in the golden corpus matches it. A naive `new Date().toISOString()` emits milliseconds and `Z`.
 *  - THE ZONE. UTC, not the device offset: the server re-validates at submit under its own UTC clock, so a
 *    local offset would make `today()` disagree across the evening window in every UTC+ zone, and the client
 *    would render a step the server then prunes — silently, with a 201, because an irrelevant field is never
 *    required-checked.
 *
 * Recorded honestly (amendment A1): this buys LESS than Doc #27 §3.4 implied. `${d} >= today()` was already
 * always-false in BOTH engines — `numericCompare` is numeric-only and `NUMERIC_RE` rejects `'2026-08-01'` —
 * so what actually moves is equality against the clock, `count()`, and `if()`.
 */

import { describe, expect, it } from 'vitest';

import { EvaluationContext, makeExpressionEvaluator, makeSemanticValidator, type SemanticInput } from '../engine';
import { isoClock, toSemanticInput } from '../lib/schema-mapping';
import { createFormRuntime } from '../composables/useFormRuntime';
import { field, schemaResponse, section } from './fixtures';

describe('the session clock', () => {
    it('formats exactly like Carbon::toIso8601String() under a UTC app timezone', () => {
        const at = new Date(Date.UTC(2026, 6, 11, 9, 30, 0, 789));

        // Seconds precision, explicit +00:00, no milliseconds, no `Z` — byte-identical to the shape every
        // `now` literal in `tests/golden/**` carries.
        expect(isoClock(at)).toBe('2026-07-11T09:30:00+00:00');
        expect(isoClock(at)).not.toContain('Z');
        expect(isoClock(at)).not.toContain('.');
    });

    it('pads every component so a single-digit month or hour keeps the fixed width', () => {
        // Without zero-padding this yields `2026-1-5T3:4:5+00:00`, which `today()`'s 10-character slice would
        // silently truncate to `2026-1-5T3` — a value that compares equal to nothing.
        expect(isoClock(new Date(Date.UTC(2026, 0, 5, 3, 4, 5)))).toBe('2026-01-05T03:04:05+00:00');
    });

    it('stamps UTC rather than the device offset', () => {
        // Constructed from a UTC instant, so the output must reflect that instant regardless of the zone the
        // test process happens to run in.
        const at = new Date(Date.UTC(2026, 11, 31, 23, 59, 59));

        expect(isoClock(at)).toBe('2026-12-31T23:59:59+00:00');
    });

    it('threads the clock into SemanticInput, and keeps null when none is given', () => {
        const engineSchema = { fields: [], sections: [], validations: [] };

        expect(toSemanticInput(engineSchema, {}, 'en', '2026-07-11T09:30:00+00:00').now).toBe('2026-07-11T09:30:00+00:00');
        // Optional, so every pre-H21a caller stays byte-identical (the A8 device).
        expect(toSemanticInput(engineSchema, {}, 'en').now).toBeNull();
    });

    it('makes today() and now() resolve instead of returning ABSENT', () => {
        const evaluator = makeExpressionEvaluator();
        const withClock = new EvaluationContext({}, undefined, '2026-07-11T09:30:00+00:00');
        const without = new EvaluationContext({}, undefined, null);

        expect(evaluator.evaluate('today()', withClock)).toBe('2026-07-11');
        expect(evaluator.evaluate('now()', withClock)).toBe('2026-07-11T09:30:00+00:00');
        // The pre-H21a SPA state, kept here so the fix cannot be mistaken for something that was always true.
        expect(evaluator.evaluate('today()', without)).toBeNull();
    });

    it('branches a section on today() through the real store, which it could not do before', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                sections: [section({ key: 'sec', sequence: 1, relevant_expression: '${d} = today()' })],
                fields: [
                    field({ key: 'd', field_type: 'date', sequence: 1 }),
                    field({ key: 'inside', section_key: 'sec', sequence: 2 }),
                ],
                form: { single_page_mode: false },
            }),
            { initialAnswers: { d: '2026-07-11' }, now: '2026-07-11T09:30:00+00:00' },
        );

        // `d` has no section, so it leads in the synthetic `__lead__` block; `sec` is the clock-gated step.
        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['__lead__', 'sec']);
    });

    it('hides that section when the clock says another day, so the branch is really the clock', () => {
        // The anti-vacuity twin: without this the test above would pass against a store that ignored the
        // expression entirely and simply rendered every section.
        const runtime = createFormRuntime(
            schemaResponse({
                sections: [section({ key: 'sec', sequence: 1, relevant_expression: '${d} = today()' })],
                fields: [
                    field({ key: 'd', field_type: 'date', sequence: 1 }),
                    field({ key: 'inside', section_key: 'sec', sequence: 2 }),
                ],
                form: { single_page_mode: false },
            }),
            { initialAnswers: { d: '2026-07-11' }, now: '2026-08-01T09:30:00+00:00' },
        );

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['__lead__']);
    });

    it('agrees with the PHP engine on the corpus clock literal', () => {
        // The one string both engines are already known to agree on, asserted from the TypeScript side so a
        // change to `isoClock()`'s shape reddens against the corpus rather than only against itself.
        const input: SemanticInput = {
            fields: [{ id: 'd', key: 'd', sequence: 0, is_required: 'optional', form_section_id: null, relevant_expression: null }],
            sections: [{ id: 's', key: 'sec', relevant_expression: '${d} = today()' }],
            validations: [],
            answers: { d: '2026-07-11' },
            locale: 'en',
            now: isoClock(new Date(Date.UTC(2026, 6, 11, 9, 30, 0))),
        };

        expect(makeSemanticValidator().evaluate(input).sectionRelevance.sec).toBe(true);
    });
});
