/**
 * M185 (`R-0a7275ce`, `D129`) — a repeatable section's Layout: cards (stored as nothing) or a table, and, when a question the
 * section holds cannot be a table column, which one keeps it as cards.
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import RepeatLayoutSelect from './RepeatLayoutSelect.vue';
import type { LocalField, PaletteGroup } from './types';

const PALETTE = [
    { category: 'text', label: 'Text', icon: 'text', types: [{ value: 'long_text', label: 'Long text' }, { value: 'short_text', label: 'Short text' }] },
] as unknown as PaletteGroup[];

const member = (key: string, type: string, label = key): LocalField => ({ key, field_type: type, label, config: {} }) as unknown as LocalField;

describe('RepeatLayoutSelect', () => {
    it('offers cards and a table, and stores the cards as nothing', async () => {
        const wrapper = mount(RepeatLayoutSelect, { props: { layout: null, members: [], palette: PALETTE } });
        const select = wrapper.get('select');

        expect(select.findAll('option').map((o) => o.text())).toEqual(['A card per entry', 'A table, a row per entry']);
        expect((select.element as HTMLSelectElement).value).toBe('cards');

        await select.setValue('table');
        await select.setValue('cards');
        expect(wrapper.emitted('update:layout')).toEqual([['table'], [null]]);
    });

    it('names the question that keeps a table section as cards, by its label and its type', () => {
        const wrapper = mount(RepeatLayoutSelect, {
            props: { layout: 'table', members: [member('name', 'short_text', 'Name'), member('notes', 'long_text', 'Notes')], palette: PALETTE },
        });

        expect(wrapper.get('[data-repeat-layout-blocker]').text()).toBe(
            'Shown as cards: “Notes” (Long text) cannot be a table column. A table holds text, numbers, dates and choices.',
        );
    });

    it('says nothing when every question fits, or the section is cards anyway', () => {
        expect(mount(RepeatLayoutSelect, { props: { layout: 'table', members: [member('name', 'short_text')], palette: PALETTE } }).find('[data-repeat-layout-blocker]').exists()).toBe(false);
        expect(mount(RepeatLayoutSelect, { props: { layout: null, members: [member('notes', 'long_text')], palette: PALETTE } }).find('[data-repeat-layout-blocker]').exists()).toBe(false);
    });
});
