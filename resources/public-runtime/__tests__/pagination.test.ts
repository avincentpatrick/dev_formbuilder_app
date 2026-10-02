/**
 * Increment M124 (`R-8c517fb6`; `D57`, amended 2026-10-01) — page breaks paginate a stepped form.
 *
 * Until M124 a `page_break` was a page on paper and an ODK group boundary on export, and nothing at all on screen.
 * The store now cuts each non-repeatable section, and the leading section-less block, into pages at every page
 * break that currently applies — when, and only when, a caller turns `paginateAtPageBreaks` on. The guest runtime
 * and the builder preview do so for a stepped form; the staff encode page does not, by decision.
 *
 * What these cases pin, beyond the split itself, is IDENTITY: which key a page carries, what survives a break
 * appearing or disappearing, what a saved draft records, and what the step-change record tells a component.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { nextTick, ref } from 'vue';
import { describe, expect, it } from 'vitest';

import { createFormRuntime, LEAD_STEP_KEY, stepTitle, type RuntimeOptions } from '../composables/useFormRuntime';
import type { AnswerMap, RawField } from '../lib/types';
import { field, schemaResponse, section } from './fixtures';

/** A field of section `hh`, positioned by its section sequence. */
function inHousehold(key: string, sectionSequence: number, extra: Partial<RawField> = {}): RawField {
    return field({ key, section_key: 'hh', sequence: sectionSequence, section_sequence: sectionSequence, ...extra });
}

/** Section `hh` ("Household"): q1 · pb1 · q2 · q3 · pb2 · q4 — three pages — then section `end`: w1. */
function household(overrides: { pb1?: Partial<RawField>; q1?: Partial<RawField>; q4?: Partial<RawField> } = {}) {
    return schemaResponse({
        form: { single_page_mode: false },
        sections: [
            section({ key: 'hh', label: 'Household', description: 'About the people you live with.', sequence: 0 }),
            section({ key: 'end', label: 'Wrap up', sequence: 1 }),
        ],
        fields: [
            inHousehold('q1', 0, overrides.q1),
            inHousehold('pb1', 1, { field_type: 'page_break', ...overrides.pb1 }),
            inHousehold('q2', 2),
            inHousehold('q3', 3),
            inHousehold('pb2', 4, { field_type: 'page_break' }),
            inHousehold('q4', 5, overrides.q4),
            field({ key: 'w1', section_key: 'end', sequence: 6, section_sequence: 0 }),
        ],
    });
}

function paged(schema = household(), opts: RuntimeOptions = {}) {
    return createFormRuntime(schema, { paginateAtPageBreaks: true, ...opts });
}

function keys(runtime: ReturnType<typeof createFormRuntime>): string[] {
    return runtime.visibleSteps.value.map((s) => s.key);
}

describe('paginateAtPageBreaks — off unless a caller turns it on', () => {
    it('leaves every section one step by default, exactly as before', () => {
        const runtime = createFormRuntime(household());

        expect(keys(runtime)).toEqual(['hh', 'end']);
        expect(runtime.visibleSteps.value[0].fieldKeys).toEqual(['q1', 'q2', 'q3', 'q4']);
        expect(runtime.visibleSteps.value.every((s) => s.continuation === false)).toBe(true);
    });
});

describe('paginateAtPageBreaks — where a page starts and what it is called', () => {
    it('splits a section at each break, keying a later page by the break that opens it', () => {
        const runtime = paged();

        expect(keys(runtime)).toEqual(['hh', 'hh#pb1', 'hh#pb2', 'end']);
        expect(runtime.visibleSteps.value.map((s) => s.fieldKeys)).toEqual([['q1'], ['q2', 'q3'], ['q4'], ['w1']]);
        expect(runtime.visibleSteps.value.map((s) => s.sectionKey)).toEqual(['hh', 'hh', 'hh', 'end']);
        expect(runtime.visibleSteps.value.map((s) => s.continuation)).toEqual([false, true, true, false]);
        expect(runtime.visibleSteps.value.map(stepTitle)).toEqual([
            'Household',
            'Household (continued)',
            'Household (continued)',
            'Wrap up',
        ]);
    });

    it('splits the section-less lead block the same way', () => {
        const runtime = paged(
            schemaResponse({
                form: { single_page_mode: false },
                fields: [
                    field({ key: 'a', sequence: 0 }),
                    field({ key: 'cut', field_type: 'page_break', sequence: 1 }),
                    field({ key: 'b', sequence: 2 }),
                ],
            }),
        );

        expect(keys(runtime)).toEqual([LEAD_STEP_KEY, `${LEAD_STEP_KEY}#cut`]);
        expect(runtime.visibleSteps.value.map((s) => s.sectionKey)).toEqual([null, null]);
    });

    it('opens no empty page for a leading, a doubled or a trailing break', () => {
        const runtime = paged(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [section({ key: 's', sequence: 0 })],
                fields: [
                    field({ key: 'lead_break', section_key: 's', field_type: 'page_break', sequence: 0, section_sequence: 0 }),
                    field({ key: 'x', section_key: 's', sequence: 1, section_sequence: 1 }),
                    field({ key: 'first_of_two', section_key: 's', field_type: 'page_break', sequence: 2, section_sequence: 2 }),
                    field({ key: 'second_of_two', section_key: 's', field_type: 'page_break', sequence: 3, section_sequence: 3 }),
                    field({ key: 'y', section_key: 's', sequence: 4, section_sequence: 4 }),
                    field({ key: 'tail_break', section_key: 's', field_type: 'page_break', sequence: 5, section_sequence: 5 }),
                ],
            }),
        );

        // The first page holding a question keeps the bare key, even behind a leading break; the next is named
        // by the break immediately before it.
        expect(keys(runtime)).toEqual(['s', 's#second_of_two']);
        expect(runtime.visibleSteps.value.map((s) => s.fieldKeys)).toEqual([['x'], ['y']]);
    });

    it('never splits a repeatable section — its instances are what the respondent pages through', () => {
        const runtime = paged(
            schemaResponse({
                form: { single_page_mode: false },
                sections: [section({ key: 'members', is_repeatable: true, min_instances: 0, sequence: 0 })],
                fields: [
                    field({ key: 'm1', section_key: 'members', sequence: 0, section_sequence: 0 }),
                    field({ key: 'cut', section_key: 'members', field_type: 'page_break', sequence: 1, section_sequence: 1 }),
                    field({ key: 'm2', section_key: 'members', sequence: 2, section_sequence: 2 }),
                ],
            }),
        );

        expect(keys(runtime)).toEqual(['members']);
        expect(runtime.visibleSteps.value[0].isRepeat).toBe(true);
    });
});

describe('paginateAtPageBreaks — a break obeys its own condition', () => {
    it('does not cut at a break hidden by its condition, and cuts as soon as it applies', () => {
        const runtime = paged(household({ pb1: { relevant_expression: "${w1} = 'split'" } }));

        expect(keys(runtime)).toEqual(['hh', 'hh#pb2', 'end']);
        expect(runtime.visibleSteps.value[0].fieldKeys).toEqual(['q1', 'q2', 'q3']);

        runtime.setAnswer('w1', 'split');

        expect(keys(runtime)).toEqual(['hh', 'hh#pb1', 'hh#pb2', 'end']);
    });

    it('keeps the bare key on a first page its conditions empty, and lands the block key on the first page shown', () => {
        // q1 is the whole first page and is gated off, so the block's first VISIBLE page is `hh#pb1` — which
        // carries the description, and which a cursor naming the block resolves to, EXACTLY.
        const runtime = paged(household({ q1: { relevant_expression: "${w1} = 'show'" } }));

        expect(keys(runtime)).toEqual(['hh#pb1', 'hh#pb2', 'end']);
        expect(runtime.visibleSteps.value.map((s) => s.continuation)).toEqual([false, true, false]);

        runtime.goToStep('end');
        expect(runtime.goToStep('hh')).toBe('exact');
        expect(runtime.currentStepKey.value).toBe('hh#pb1');
    });

    it('follows a live switch of the option through a ref, without a new runtime', () => {
        const on = ref(true);
        const runtime = createFormRuntime(household(), { paginateAtPageBreaks: on });
        expect(keys(runtime)).toEqual(['hh', 'hh#pb1', 'hh#pb2', 'end']);

        on.value = false;

        expect(keys(runtime)).toEqual(['hh', 'end']);
    });
});

describe('paginateAtPageBreaks — navigation, errors and resume work per page', () => {
    it('blocks Next only for the questions on the page, and files their errors under it', () => {
        const runtime = paged(household({ q4: { is_required: 'required' } }));

        expect(runtime.attemptNext().advanced).toBe(true);
        expect(runtime.currentStepKey.value).toBe('hh#pb1');
        expect(runtime.attemptNext().advanced).toBe(true);
        expect(runtime.currentStepKey.value).toBe('hh#pb2');

        expect(runtime.attemptNext()).toEqual({ advanced: false, errorCount: 1 });
        runtime.markSubmitAttempted();
        expect(runtime.erroredItems.value).toEqual([{ address: 'q4', label: 'q4', stepKey: 'hh#pb2' }]);
    });

    it('records the block, never the page, for a saved draft', () => {
        const runtime = paged();

        runtime.goToStep('hh#pb2');

        expect(runtime.currentStepKey.value).toBe('hh#pb2');
        expect(runtime.resumeStepKey.value).toBe('hh');
    });

    it('records the lead block for any page of it', () => {
        const runtime = paged(
            schemaResponse({
                form: { single_page_mode: false },
                fields: [
                    field({ key: 'a', sequence: 0 }),
                    field({ key: 'cut', field_type: 'page_break', sequence: 1 }),
                    field({ key: 'b', sequence: 2 }),
                ],
            }),
        );

        runtime.goToStep(`${LEAD_STEP_KEY}#cut`);

        expect(runtime.resumeStepKey.value).toBe(LEAD_STEP_KEY);
    });
});

describe('paginateAtPageBreaks — what the step-change record says when pages move', () => {
    it('keeps the respondent on their questions when their page merges, and reports nothing left behind', async () => {
        const runtime = paged(household({ pb1: { relevant_expression: "${w1} = 'split'" } }), {
            initialAnswers: { w1: 'split' },
        });
        runtime.goToStep('hh#pb1');
        runtime.setAnswer('q2', 'answered on the page that merges');
        await nextTick();

        runtime.setAnswer('w1', 'no');
        await nextTick();

        const change = runtime.lastStepChange.value;
        expect(runtime.currentStepKey.value).toBe('hh');
        expect(change?.rescuedFrom).toBe('hh#pb1');
        expect(change?.rescuedTo).toBe('hh');
        expect(change?.rescueReason).toBe('repaginated');
        expect(change?.removed).toEqual(['hh#pb1']);
        expect(change?.removedWithAnswers).toEqual([]);
    });

    it('reports a page whose questions stop applying, with its answers, under its section', async () => {
        const runtime = paged(household({ q4: { relevant_expression: "${q1} != 'hide'" } }));
        runtime.setAnswer('q4', 'kept on the device');
        await nextTick();

        runtime.setAnswer('q1', 'hide');
        await nextTick();

        const change = runtime.lastStepChange.value;
        expect(change?.removed).toEqual(['hh#pb2']);
        expect(change?.removedWithAnswers).toEqual(['hh#pb2']);
        expect(change?.groupOf['hh#pb2']).toBe('hh');
        expect(change?.rescueReason).toBeNull();
    });

    it('says why the respondent moved when their page stops applying', async () => {
        const runtime = paged(household({ q4: { relevant_expression: "${q1} != 'hide'" } }));
        runtime.goToStep('hh#pb2');
        await nextTick();

        runtime.setAnswer('q1', 'hide');
        await nextTick();

        expect(runtime.lastStepChange.value?.rescueReason).toBe('irrelevant');
        expect(runtime.currentStepKey.value).toBe('hh#pb1');
    });
});

type FixtureSection = { key: string; sequence: number; repeatable?: boolean; min?: number; max?: number; relevant?: string };
type FixtureField = { key: string; section?: string; field_type?: string; sequence: number; relevant?: string };
type FixtureCase = { name: string; sections?: FixtureSection[]; fields?: FixtureField[]; answers?: AnswerMap; expected_steps: string[] };

/**
 * ⛔ PAGINATION IS TYPESCRIPT-ONLY, AND THIS IS WHAT KEEPS THE PHP TWIN HONEST ABOUT IT. `StepProjection.php`
 * never splits, because every one of its consumers asks a SECTION-level question (is the form screened out, is a
 * section empty at open). Pages divide the same filtered fields, and a block is visible exactly when one of its
 * pages is — so with pagination ON, the de-duplicated blocks of the paginated steps must still be the fixture's
 * expected steps, case for case. A split that ever changed which SECTIONS show would be an R3 break.
 */
describe('paginating never changes which sections show', () => {
    const cases: FixtureCase[] = JSON.parse(
        readFileSync(join(process.cwd(), 'tests', 'fixtures', 'step-projection.json'), 'utf-8'),
    );

    it.each(cases.map((c) => [c.name, c] as const))('keeps %s section-for-section', (_name, testCase) => {
        const runtime = paged(
            schemaResponse({
                form: { single_page_mode: false },
                sections: (testCase.sections ?? []).map((s) =>
                    section({
                        key: s.key,
                        sequence: s.sequence,
                        is_repeatable: s.repeatable ?? false,
                        min_instances: s.min ?? null,
                        max_instances: s.max ?? null,
                        relevant_expression: s.relevant ?? null,
                    }),
                ),
                fields: (testCase.fields ?? []).map((f) =>
                    field({
                        key: f.key,
                        section_key: f.section ?? null,
                        field_type: f.field_type ?? 'short_text',
                        sequence: f.sequence,
                        relevant_expression: f.relevant ?? null,
                    }),
                ),
            }),
            { initialAnswers: testCase.answers ?? {} },
        );

        const blocks = runtime.visibleSteps.value.map((s) => s.sectionKey ?? LEAD_STEP_KEY);
        expect([...new Set(blocks)]).toEqual(testCase.expected_steps);
    });

    it('reads a non-trivial fixture', () => {
        expect(cases.length).toBeGreaterThanOrEqual(16);
    });
});
