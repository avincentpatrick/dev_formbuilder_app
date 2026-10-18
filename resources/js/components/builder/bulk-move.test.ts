import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import BuilderCanvas from './BuilderCanvas.vue';
import { fetchMock, jsonResponse, pageProps, requestLog, serverField, serverSection } from './builder-store-fixtures';
import { useBuilderStore } from './useBuilderStore';

/**
 * M177 (`R-4a6f004f`, `D146` A) — the testers' comment (2026-10-11, comment 4): *"Form builder does not all[ow] bulk
 * movement of questions to a section"*. A tick box on every Structure row; shift-click ticks a range; a bar above the
 * list moves every ticked question to a section in ONE order write and ONE undo entry, in the order they stood.
 */
function store() {
    return useBuilderStore(
        pageProps({
            sections: [
                serverSection({ id: 's1', key: 'household', label: 'Household', sequence: 1 }),
                serverSection({ id: 's2', key: 'visit', label: 'Visit', sequence: 2 }),
            ],
            fields: [
                serverField({ id: 'f1', key: 'q1', label: 'Question 1', form_section_id: 's1', sequence: 1 }),
                serverField({ id: 'f2', key: 'q2', label: 'Question 2', form_section_id: 's1', sequence: 2 }),
                serverField({ id: 'f3', key: 'q3', label: 'Question 3', form_section_id: 's1', sequence: 3 }),
                serverField({ id: 'f4', key: 'q4', label: 'Question 4', form_section_id: 's2', sequence: 4 }),
            ],
        }),
    );
}

const where = (s: ReturnType<typeof store>): string[] => s.groups.value.map((g) => `${g.section?.id ?? 'top'}:${g.fields.map((f) => f.key).join(',')}`);
const uid = (s: ReturnType<typeof store>, key: string): string => s.fields.value.find((f) => f.key === key)!.uid;

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('moving several questions at once (M177)', () => {
    it('moves them to the end of the section in the order they stood, with one write and one undo entry', async () => {
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, {}));
        const s = store();

        await s.moveFieldsToSection([uid(s, 'q3'), uid(s, 'q1')], 's2');

        expect(where(s)).toEqual(['top:', 's1:q2', 's2:q4,q1,q3']);
        expect(requestLog(mock).map((r) => `${r.method} ${r.url}`)).toEqual(['POST /forms/form-1/reorder']);
        expect(s.canUndo.value).toBe(true);

        await s.undo();
        await flushPromises();

        expect(where(s)).toEqual(['top:', 's1:q1,q2,q3', 's2:q4']);
        expect(requestLog(mock)).toHaveLength(2);
        expect(s.canUndo.value).toBe(false);
    });

    it('moves them out of every section, to the top level', async () => {
        fetchMock().mockResolvedValue(jsonResponse(200, {}));
        const s = store();

        await s.moveFieldsToSection([uid(s, 'q2'), uid(s, 'q4')], null);

        expect(where(s)).toEqual(['top:q2,q4', 's1:q1,q3', 's2:']);
    });

    it('ticks a range with shift, in the order the rows stand, and clears the ticks after a move', async () => {
        fetchMock().mockResolvedValue(jsonResponse(200, {}));
        const s = store();

        s.setChecked(uid(s, 'q1'), true);
        s.setChecked(uid(s, 'q3'), true, true);
        expect([...s.checkedFields.value].map((u) => s.fields.value.find((f) => f.uid === u)!.key).sort()).toEqual(['q1', 'q2', 'q3']);

        s.setChecked(uid(s, 'q2'), false);
        expect(s.checkedFields.value.size).toBe(2);

        await s.moveFieldsToSection([...s.checkedFields.value], 's2');
        expect(s.checkedFields.value.size).toBe(0);
    });
});

describe('BuilderCanvas — the tick boxes and the bar (M177)', () => {
    it('shows the bar once a question is ticked, and moves the ticked ones to the section chosen', async () => {
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, {}));
        const s = store();
        const wrapper = mount(BuilderCanvas, { props: { store: s, fieldTypeLabels: { short_text: 'Text' } }, attachTo: document.body });

        expect(wrapper.find('[data-bulk-bar]').exists()).toBe(false);
        const ticks = wrapper.findAll('[data-field-tick] input[type="checkbox"]');
        expect(ticks).toHaveLength(4);
        expect(ticks[0].attributes('aria-label') ?? wrapper.find('[data-field-tick]').text()).toContain('Question 1');

        await ticks[0].setValue(true);
        await ticks[2].setValue(true);

        const bar = wrapper.get('[data-bulk-bar]');
        expect(bar.text()).toContain('2 selected');
        await bar.get('select').setValue('s2');
        await bar.findAll('button').find((b) => b.text() === 'Move')!.trigger('click');
        await flushPromises();

        expect(where(s)).toEqual(['top:', 's1:q2', 's2:q4,q1,q3']);
        expect(requestLog(mock).filter((r) => r.url.endsWith('/reorder'))).toHaveLength(1);
        expect(wrapper.find('[data-bulk-bar]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('ticks a range with shift held', async () => {
        const s = store();
        const wrapper = mount(BuilderCanvas, { props: { store: s, fieldTypeLabels: { short_text: 'Text' } }, attachTo: document.body });
        const ticks = () => wrapper.findAll('[data-field-tick]');

        await ticks()[0].get('input').setValue(true);
        await ticks()[3].trigger('pointerdown', { shiftKey: true });
        await ticks()[3].get('input').setValue(true);

        expect(wrapper.get('[data-bulk-bar]').text()).toContain('4 selected');
        wrapper.unmount();
    });
});
