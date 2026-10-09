import { describe, expect, it } from 'vitest';

import { createFormRuntime } from '../../../public-runtime/composables/useFormRuntime';
import { fetchMock, jsonResponse, pageProps, requestLog, serverField, serverSection } from './builder-store-fixtures';
import { projectDraft } from './draft-snapshot';
import { useBuilderStore } from './useBuilderStore';
import type { ServerField } from './types';

/**
 * M139 (`R-c0857303`) — keyboard grab mode moves a question through every PLACE it can sit, not past its neighbouring
 * question. The real-browser probe that filed this grabbed a section's only question and found it could not move: an
 * empty section has no neighbour to step past. The pointer could drop into it all along (`useCanvasReorder.ts`).
 */
function store(fields: Array<Partial<ServerField>>, sectionIds: string[] = ['s1', 's2']) {
    return useBuilderStore(
        pageProps({
            sections: sectionIds.map((id, i) => serverSection({ id, key: `section_${id}`, label: `Section ${id}`, sequence: i + 1 })),
            fields: fields.map((f, i) => serverField({ id: `f${i + 1}`, key: `q${i + 1}`, label: `Question ${i + 1}`, sequence: i + 1, ...f })),
        }),
    );
}

function where(s: ReturnType<typeof store>): string[] {
    return s.groups.value.map((g) => `${g.section?.id ?? 'top'}:${g.fields.map((f) => f.key).join(',')}`);
}

describe('keyboard grab steps through places, crossing sections (M139)', () => {
    it('walks a top-level question into an empty section and on into the next, and back out', () => {
        const s = store([{ form_section_id: null }]);
        const uid = s.fields.value[0].uid;

        expect(s.stepFieldAcross(uid, 1)).toBe(true);
        expect(where(s)).toEqual(['top:', 's1:q1', 's2:']);
        expect(s.stepFieldAcross(uid, 1)).toBe(true);
        expect(where(s)).toEqual(['top:', 's1:', 's2:q1']);
        // The last place there is.
        expect(s.stepFieldAcross(uid, 1)).toBe(false);

        expect(s.stepFieldAcross(uid, -1)).toBe(true);
        expect(s.stepFieldAcross(uid, -1)).toBe(true);
        expect(where(s)).toEqual(['top:q1', 's1:', 's2:']);
        expect(s.stepFieldAcross(uid, -1)).toBe(false);
    });

    it('still steps one place at a time inside a section, then across its edge', () => {
        const s = store([{ form_section_id: 's1' }, { form_section_id: 's1' }]);
        const first = s.fields.value.find((f) => f.key === 'q1')!.uid;

        expect(s.stepFieldAcross(first, 1)).toBe(true);
        expect(where(s)).toEqual(['top:', 's1:q2,q1', 's2:']);
        expect(s.stepFieldAcross(first, 1)).toBe(true);
        expect(where(s)).toEqual(['top:', 's1:q2', 's2:q1']);
    });

    it('moves a form\'s only question out of its section, which had no neighbour to pass before', () => {
        const s = store([{ form_section_id: 's1' }], ['s1']);
        const uid = s.fields.value[0].uid;

        expect(s.stepFieldAcross(uid, -1)).toBe(true);
        expect(where(s)).toEqual(['top:q1', 's1:']);
        expect(s.stepFieldAcross(uid, 1)).toBe(true);
        expect(where(s)).toEqual(['top:', 's1:q1']);
    });

    it('has nowhere to go with one question and no section', () => {
        const s = store([{ form_section_id: null }], []);
        const uid = s.fields.value[0].uid;

        expect(s.stepFieldAcross(uid, 1)).toBe(false);
        expect(s.stepFieldAcross(uid, -1)).toBe(false);
    });
});

/**
 * M151 (`R-250b57eb`) — an XLSForm import stores each question's place within its section as `section_sequence`, and the
 * engine orders a section by it before `sequence` (as Print blank and the export do). A move that renumbered only
 * `sequence` reordered the builder's list and left the order a respondent, the paper and the preview see where the import
 * put it. The import assigns both numbers in one pass, so they agree, and a move clears them all.
 */
describe('a move reaches the order the engine reads, on an imported form (M151)', () => {
    /** Two imported sections: q1-q3 in s1 and q4-q5 in s2, each numbered within its section. */
    function imported(): ReturnType<typeof store> {
        return store(
            [
                { form_section_id: 's1', section_sequence: 0 },
                { form_section_id: 's1', section_sequence: 1 },
                { form_section_id: 's1', section_sequence: 2 },
                { form_section_id: 's2', section_sequence: 0 },
                { form_section_id: 's2', section_sequence: 1 },
            ],
            ['s1', 's2'],
        );
    }

    function engineOrder(s: ReturnType<typeof store>): string[][] {
        const { schema } = projectDraft({
            form: { id: 'form-1', title: 'Imported', description: null, default_locale: 'en', supported_locales: ['en'] },
            version: { id: 'v-1', version_number: 1 },
            sections: s.sections.value,
            fields: s.fields.value,
        });

        return createFormRuntime(schema).visibleSteps.value.map((step) => step.fieldKeys);
    }

    function uidOf(s: ReturnType<typeof store>, key: string): string {
        return s.fields.value.find((f) => f.key === key)!.uid;
    }

    it('starts in the order the import gave it', () => {
        expect(engineOrder(imported())).toEqual([
            ['q1', 'q2', 'q3'],
            ['q4', 'q5'],
        ]);
    });

    it('reorders the engine when a question is dragged within its section', () => {
        const s = imported();

        s.placeField(uidOf(s, 'q3'), 's1', 0);

        expect(where(s)).toEqual(['top:', 's1:q3,q1,q2', 's2:q4,q5']);
        expect(engineOrder(s)).toEqual([
            ['q3', 'q1', 'q2'],
            ['q4', 'q5'],
        ]);
    });

    it('puts a question dragged into another section where it was dropped', () => {
        const s = imported();

        s.placeField(uidOf(s, 'q3'), 's2', 0);

        expect(engineOrder(s)).toEqual([
            ['q1', 'q2'],
            ['q3', 'q4', 'q5'],
        ]);
    });

    it('moves a question to the end of another section from the settings pane, and saves the order it shows', async () => {
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, {}));
        const s = imported();

        await s.moveFieldToSection(uidOf(s, 'q1'), 's2');

        expect(engineOrder(s)).toEqual([
            ['q2', 'q3'],
            ['q4', 'q5', 'q1'],
        ]);
        const sent = requestLog(mock).find((r) => r.url.endsWith('/reorder'))!.body as { fields: Array<{ section_sequence: number | null }> };
        expect(sent.fields.map((f) => f.section_sequence)).toEqual([null, null, null, null, null]);
    });
});
