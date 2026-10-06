/**
 * The cascading-select editor's CSV choice lists (M141, `R-f69aab42`, `D95`), through the rendered DOM with `fetch`
 * stubbed at the boundary: a level names a list (never its rows), a level whose list will not publish says why, and
 * once every level names a list the option editor gives way to the lists' sizes.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import CascadingEditor from './CascadingEditor.vue';

const LISTS = [
    { name: 'provinces', file_name: 'provinces.csv', row_count: 82, columns: ['name', 'label', 'region'] },
    { name: 'regions', file_name: 'regions.csv', row_count: 17, columns: ['name', 'label'] },
];

function respond(body: unknown, status = 200): Promise<Response> {
    return Promise.resolve(new Response(JSON.stringify(body), { status }));
}

async function mountEditor(levels: { key: string; label: string; list?: string }[], options: unknown[] = []): Promise<VueWrapper> {
    vi.stubGlobal('fetch', vi.fn(() => respond({ data: LISTS })));
    const wrapper = mount(CascadingEditor, { props: { levels, options, formId: 'form-1' } as never });
    await flushPromises();
    return wrapper;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('a level takes its choices from a CSV list', () => {
    it('offers the form’s lists on each level, and stores the list’s name on the level', async () => {
        const wrapper = await mountEditor([{ key: 'region', label: 'Region' }]);
        const picker = wrapper.find('select[aria-label="Level 1 choices from"]');

        expect(picker.findAll('option').map((option) => option.text())).toEqual(['Typed in below', 'provinces — 82 choices', 'regions — 17 choices']);

        await picker.setValue('regions');

        expect(wrapper.emitted('update:levels')?.at(-1)).toEqual([[{ key: 'region', label: 'Region', list: 'regions' }]]);
    });

    it('gives way to the lists’ sizes once every level names a list, and keeps no typed option', async () => {
        const wrapper = await mountEditor([
            { key: 'region', label: 'Region', list: 'regions' },
            { key: 'province', label: 'Province', list: 'provinces' },
        ]);

        expect(wrapper.text()).toContain('Every level takes its choices from a CSV file.');
        expect(wrapper.text()).toContain('Region: regions — 17 choices');
        expect(wrapper.text()).toContain('Province: provinces — 82 choices');
        expect(wrapper.find('[aria-label="Option 1 value"]').exists()).toBe(false);
    });

    it('says why a level will not publish: a list not uploaded, or no column named after the level above', async () => {
        const missing = await mountEditor([{ key: 'region', label: 'Region', list: 'regions_2020' }]);
        expect(missing.text()).toContain('regions_2020.csv is not uploaded to this form.');

        const noColumn = await mountEditor([
            { key: 'reg', label: 'Region', list: 'regions' },
            { key: 'province', label: 'Province', list: 'provinces' },
        ]);
        expect(noColumn.text()).toContain('provinces has no “reg” column.');
    });

    it('refuses to mix: every level from a list, or none', async () => {
        const wrapper = await mountEditor([
            { key: 'region', label: 'Region', list: 'regions' },
            { key: 'province', label: 'Province' },
        ]);

        expect(wrapper.text()).toContain('Every level must take its choices from a CSV file, or none may.');
    });
});
