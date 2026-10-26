import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { ENUMS, serverField } from './builder-store-fixtures';
import FieldHistory from './FieldHistory.vue';
import type { DiffWords } from './conflict-diff';
import type { FieldHistoryEntry, FieldHistoryPage, LocalField } from './types';
import type { BuilderStore } from './useBuilderStore';

/**
 * M188 (`R-3894369d`, `D167`, `D168`) — the History tab: each change in the panel's words, who and when; Put back shows the
 * setting now and as it will be before anything is saved; a type change and a move say what to do instead. The store is
 * a double: its own Put back is `put-back-store.test.ts`'s.
 */

const WORDS: DiffWords = { enums: ENUMS, labels: {}, typeLabel: (value) => ({ short_text: 'Short text', long_text: 'Long text' })[value] ?? value };

function entry(overrides: Partial<FieldHistoryEntry> = {}): FieldHistoryEntry {
    return {
        id: 'e1', kind: 'edited', at: '2026-10-26T01:00:00Z', from: '2026-10-26T00:59:00Z', by: 'Ana Reyes',
        field_key: 'occupation', field_type: 'short_text',
        changes: [{ setting: 'label', before: 'Short text', after: 'Occupation' }],
        put_back_of_at: null, can_put_back: true, reason: null, ...overrides,
    };
}

function field(): LocalField {
    return { ...serverField(), uid: 'u1' } as LocalField;
}

function mountHistory(pages: (FieldHistoryPage | null)[], putBack = vi.fn(() => Promise.resolve(true))) {
    const historyFor = vi.fn();
    for (const page of pages) historyFor.mockResolvedValueOnce(page);
    historyFor.mockResolvedValue(pages[pages.length - 1] ?? null);
    const store = { historyFor, putBack } as unknown as BuilderStore;
    const wrapper = mount(FieldHistory, {
        props: { store, field: field(), words: WORDS },
        global: { stubs: { teleport: true } },
        attachTo: document.body,
    });

    return { wrapper, historyFor, putBack };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('FieldHistory', () => {
    it('lists each change in the panel’s words, with who made it', async () => {
        const { wrapper } = mountHistory([{ data: [entry(), entry({ id: 'e0', by: null, changes: [{ setting: 'is_required', before: 'optional', after: 'required' }] })], next: null }]);
        await flushPromises();

        const lines = wrapper.findAll('[data-history-change]').map((li) => li.text().replace(/\s+/g, ' '));
        expect(lines).toEqual(['Label: Short text → changed to Occupation', 'Required: Optional → changed to Required']);
        expect(wrapper.text()).toContain('Ana Reyes');
        expect(wrapper.text()).toContain('A former member');
    });

    it('shows the setting now and as it will be, and saves only on confirm', async () => {
        const putBack = vi.fn(() => Promise.resolve(true));
        const { wrapper } = mountHistory([{ data: [entry()], next: null }], putBack);
        await flushPromises();

        await wrapper.get('[data-put-back]').trigger('click');
        const row = wrapper.get('[data-put-back-row]').findAll('th, td').map((cell) => cell.text());
        expect(row).toEqual(['Label', 'Occupation', 'Short text']);
        expect(putBack).not.toHaveBeenCalled();

        await wrapper.get('[data-put-back-confirm]').trigger('click');
        await flushPromises();
        expect(putBack).toHaveBeenCalledWith('u1', entry().changes, 'e1');
        expect(wrapper.get('[data-history-outcome="done"]').text()).toContain('Undo reverses it');
    });

    it('says what to do instead of Put back for a move, a type change and a change made as another type', async () => {
        const { wrapper } = mountHistory([{
            data: [
                entry({ id: 'm', kind: 'moved', can_put_back: false, reason: 'moved', changes: [{ setting: 'position', before: { section: null, position: 2 }, after: { section: 'Household', position: 1 } }] }),
                entry({ id: 't', kind: 'type_changed', can_put_back: false, reason: 'type_changed', changes: [{ setting: 'field_type', before: 'long_text', after: 'short_text' }] }),
                entry({ id: 'o', field_type: 'long_text' }),
            ],
            next: null,
        }]);
        await flushPromises();

        const refusals = wrapper.findAll('[data-history-refusal]').map((p) => p.text());
        expect(wrapper.findAll('[data-put-back]')).toHaveLength(0);
        expect(refusals[0]).toContain('drag the question back');
        expect(refusals[1]).toContain('choose the type again');
        expect(refusals[2]).toContain('Made while this was a Long text question');
        expect(wrapper.text()).toContain('Place: Not in a section, place 2 → changed to “Household”, place 1');
    });

    it('says when nothing is kept yet, and when the history could not be read', async () => {
        const empty = mountHistory([{ data: [], next: null }]);
        await flushPromises();
        expect(empty.wrapper.text()).toContain('No changes kept yet');

        const failed = mountHistory([null]);
        await flushPromises();
        expect(failed.wrapper.text()).toContain('The history could not be loaded');
    });

    it('reads older changes behind the cursor, after the ones shown', async () => {
        const { wrapper, historyFor } = mountHistory([{ data: [entry()], next: 'e1' }, { data: [entry({ id: 'e0' })], next: null }]);
        await flushPromises();

        await wrapper.findAll('button').find((b) => b.text() === 'Show older changes')!.trigger('click');
        await flushPromises();

        expect(historyFor).toHaveBeenLastCalledWith('u1', 'e1');
        expect(wrapper.findAll('[data-history-kind]')).toHaveLength(2);
    });
});
