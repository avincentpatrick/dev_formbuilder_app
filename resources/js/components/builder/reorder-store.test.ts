import { describe, expect, it } from 'vitest';

import { pageProps, serverField, serverSection } from './builder-store-fixtures';
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
