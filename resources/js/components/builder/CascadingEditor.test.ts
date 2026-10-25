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

const PSGC = { label: '3Q 2026', counts: { region: 18, province: 120, city: 1642, barangay: 42010 } };

async function mountEditor(
    levels: { key: string; label: string; list?: string }[],
    options: unknown[] = [],
    extra: Record<string, unknown> = {},
): Promise<VueWrapper> {
    vi.stubGlobal('fetch', vi.fn(() => respond({ data: LISTS })));
    const wrapper = mount(CascadingEditor, { props: { levels, options, formId: 'form-1', ...extra } as never });
    await flushPromises();
    return wrapper;
}

const button = (wrapper: VueWrapper, text: string) => wrapper.findAll('button').find((candidate) => candidate.text() === text);

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

        expect(wrapper.text()).toContain('Every level takes its choices from a CSV or Excel file.');
        expect(wrapper.text()).toContain('Region: regions — 17 choices');
        expect(wrapper.text()).toContain('Province: provinces — 82 choices');
        expect(wrapper.find('[aria-label="Option 1 value"]').exists()).toBe(false);
    });

    it('says why a level will not publish: a list not uploaded, or no column named after the level above', async () => {
        const missing = await mountEditor([{ key: 'region', label: 'Region', list: 'regions_2020' }]);
        expect(missing.text()).toContain('regions_2020 is not uploaded to this form.');

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

        expect(wrapper.text()).toContain('Every level must take its choices from a CSV or Excel file, or none may.');
    });

    it('takes a CSV or Excel file, and says beside it how to keep a code like 01 (M184, R-1059e9bd)', async () => {
        const wrapper = await mountEditor([{ key: 'region', label: 'Region' }]);
        const input = wrapper.find('input[type="file"]');
        const described = (input.attributes('aria-describedby') ?? '').split(' ').map((id) => wrapper.find(`#${id}`).text()).join(' ');

        expect(input.attributes('accept')).toContain('.xlsx');
        expect(input.attributes('accept')).toContain('.csv');
        expect(described).toContain('Excel turns a code like 01 into 1: format the code columns as Text before you type them, or use letters.');
    });
});

describe('the built-in Philippine places (M186, R-70c7673a)', () => {
    it('sets the four levels, clears the typed choices and fills in one-choice levels, in one save', async () => {
        const wrapper = await mountEditor([{ key: 'level_1', label: 'Level 1' }], [{ value: 'a', label: 'A', level: 'level_1', parent: null }], { psgc: PSGC });

        expect(wrapper.text()).toContain('from the Philippine Statistics Authority’s 3Q 2026 list');

        await button(wrapper, 'Philippine places (PSGC)')?.trigger('click');

        expect(wrapper.emitted('patch')?.at(-1)).toEqual([
            {
                levels: [
                    { key: 'region', label: 'Region', list: 'psgc:region' },
                    { key: 'province', label: 'Province or district', list: 'psgc:province' },
                    { key: 'city', label: 'City or municipality', list: 'psgc:city' },
                    { key: 'barangay', label: 'Barangay', list: 'psgc:barangay' },
                ],
                options: [],
                skip_single_choice_levels: true,
            },
        ]);
        expect(wrapper.emitted('update:levels')).toBeUndefined();
    });

    it('says what each level holds, offers no upload, and raises no list problem', async () => {
        const wrapper = await mountEditor(
            [
                { key: 'region', label: 'Region', list: 'psgc:region' },
                { key: 'barangay', label: 'Barangay', list: 'psgc:barangay' },
            ],
            [],
            { psgc: PSGC },
        );

        expect(wrapper.text()).toContain('Philippine places — Barangay, 42,010 choices (3Q 2026)');
        expect(wrapper.text()).toContain('Every level takes its choices from the Philippine places.');
        expect(wrapper.text()).not.toContain('is not uploaded to this form');
        expect(wrapper.find('input[type="file"]').exists()).toBe(false);
        expect(wrapper.find('select[aria-label="Level 1 choices from"]').exists()).toBe(false);
    });

    it('lets the places go again, keeping the levels’ names', async () => {
        const wrapper = await mountEditor([{ key: 'region', label: 'Region', list: 'psgc:region' }], [], { psgc: PSGC });

        await button(wrapper, 'Stop using the Philippine places')?.trigger('click');

        expect(wrapper.emitted('update:levels')?.at(-1)).toEqual([[{ key: 'region', label: 'Region' }]]);
    });

    it('says when the places are not on this server yet, and offers the button disabled', async () => {
        const wrapper = await mountEditor([{ key: 'level_1', label: 'Level 1' }], [], { psgc: null });

        expect(wrapper.text()).toContain('The Philippine places are not on this server yet.');
        expect(button(wrapper, 'Philippine places (PSGC)')?.attributes('disabled')).toBeDefined();
    });

    it('turns the one-choice fill-in on and off for any cascade', async () => {
        const wrapper = await mountEditor([{ key: 'level_1', label: 'Level 1' }], [], { skipSingle: false });
        const checkbox = wrapper.findAll('input[type="checkbox"]').find((input) => input.element.closest('label')?.textContent?.includes('Fill in a level that has only one choice'));

        await checkbox?.setValue(true);

        expect(wrapper.emitted('patch')?.at(-1)).toEqual([{ skip_single_choice_levels: true }]);
    });
});
