/**
 * The TypeScript half of H21a's fifth R3 mirror pair (Doc #27 §9, amendment A6).
 *
 * This file and `tests/Unit/Forms/StepProjectionTest.php` drive the SAME fixture —
 * `tests/fixtures/step-projection.json` — through the guest store's `visibleSteps` and through PHP's
 * `App\Support\Forms\StepProjection`. Each side runs its OWN engine over the fixture's expressions and
 * answers, so a difference in the resulting step list is a PROJECTION difference and nothing else: the two
 * evaluators are already held equal by the golden corpus.
 *
 * The fixture is deliberately NOT under `tests/golden/`. It carries no `grammar_version` key and no manifest,
 * so it moves neither the 296-site count nor the 114-vector total, and it is not a fifth corpus — §9 rejected
 * a `tests/golden/steps/` corpus because the language-neutral vector shape cannot carry a section list,
 * per-section sequences, a repeat topology or a field→section map. A purpose-built fixture carries all four.
 *
 * `process.cwd()` rather than `import.meta.url` arithmetic: Vitest's cwd is its config's directory (the repo
 * root), and the G11 CI failure recorded in PROGRESS was exactly a five-level `import.meta.url` directory
 * walk losing the `file:` scheme under happy-dom's `URL` global.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { createFormRuntime } from '../composables/useFormRuntime';
import type { AnswerMap } from '../lib/types';
import { field, schemaResponse, section } from './fixtures';

type FixtureSection = { key: string; sequence: number; repeatable?: boolean; min?: number; max?: number; relevant?: string };
type FixtureField = { key: string; section?: string; field_type?: string; sequence: number; relevant?: string };
type FixtureCase = {
    name: string;
    note?: string;
    sections?: FixtureSection[];
    fields?: FixtureField[];
    answers?: AnswerMap;
    expected_steps: string[];
};

const cases: FixtureCase[] = JSON.parse(
    readFileSync(join(process.cwd(), 'tests', 'fixtures', 'step-projection.json'), 'utf-8'),
);

function runtimeFor(testCase: FixtureCase) {
    const schema = schemaResponse({
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
        // The store builds `visibleSteps` either way, but multi-step is the mode the projection describes.
        form: { single_page_mode: false },
    });

    return createFormRuntime(schema, { initialAnswers: testCase.answers ?? {} });
}

describe('step projection parity', () => {
    it.each(cases.map((c) => [c.name, c] as const))('projects %s the way PHP does', (_name, testCase) => {
        const runtime = runtimeFor(testCase);

        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(testCase.expected_steps);
    });

    it('reads a non-trivial fixture with unique case names', () => {
        // Anti-vacuity, the `PdfFieldRoleTest` shape: a fixture that failed to load, or an `it.each` that
        // silently collapsed to zero rows, would make every assertion above pass by never running.
        expect(cases.length).toBeGreaterThanOrEqual(16);
        expect(new Set(cases.map((c) => c.name)).size).toBe(cases.length);

        // At least one case must expect an EMPTY list and at least one a MULTI-step list, or a projection
        // that always returned [] — or always returned every section — would satisfy the whole file.
        expect(cases.some((c) => c.expected_steps.length === 0)).toBe(true);
        expect(cases.some((c) => c.expected_steps.length > 1)).toBe(true);
    });
});
