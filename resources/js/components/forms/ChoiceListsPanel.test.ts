/**
 * The Choice lists section (M141, `R-f69aab42`, `D95`), through the rendered DOM with `fetch` stubbed at the boundary:
 * the lists are the server's answer, read when the settings open; an upload that replaces a list replaces it on screen;
 * and a refusal shows the server's own sentence.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import ChoiceListsPanel from './ChoiceListsPanel.vue';

const REGIONS = { name: 'regions', file_name: 'regions.csv', row_count: 17, columns: ['name', 'label'] };

function respond(body: unknown, status = 200): Promise<Response> {
    return Promise.resolve(new Response(body === null ? null : JSON.stringify(body), { status }));
}

function choose(wrapper: VueWrapper, file: File) {
    const input = wrapper.find('input[type="file"]');
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    return input.trigger('change');
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('the Choice lists section', () => {
    it('reads the lists when the settings open, and names each one’s size and columns', async () => {
        const fetchMock = vi.fn(() => respond({ data: [REGIONS] }));
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(ChoiceListsPanel, { props: { open: true, formId: 'form-1' } });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/forms/form-1/choice-lists', expect.objectContaining({ method: 'GET' }));
        expect(wrapper.text()).toContain('regions');
        expect(wrapper.text()).toContain('17 choices · columns: name, label');
    });

    it('replaces a list whose file is uploaded again, and removes one', async () => {
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => respond({ data: [REGIONS] }))
            .mockImplementationOnce(() => respond({ data: { ...REGIONS, row_count: 18 } }, 201))
            .mockImplementationOnce(() => respond(null, 204));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(ChoiceListsPanel, { props: { open: true, formId: 'form-1' } });
        await flushPromises();

        await choose(wrapper, new File(['name,label\n'], 'regions.csv', { type: 'text/csv' }));
        await flushPromises();

        expect(wrapper.findAll('.choice-lists__item')).toHaveLength(1);
        expect(wrapper.text()).toContain('Uploaded regions: 18 choices.');

        await wrapper.find('button[aria-label="Remove regions"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenLastCalledWith('/forms/form-1/choice-lists/regions', expect.objectContaining({ method: 'DELETE' }));
        expect(wrapper.text()).toContain('No choice lists yet.');
    });

    it('shows the server’s own reason when a file is refused', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockImplementationOnce(() => respond({ data: [] }))
            .mockImplementationOnce(() => respond({ errors: { file: ['“POB” is on rows 2 and 3. Each name must be a code used once in the file.'] } }, 422)));
        const wrapper = mount(ChoiceListsPanel, { props: { open: true, formId: 'form-1' } });
        await flushPromises();

        await choose(wrapper, new File(['x'], 'barangays.csv', { type: 'text/csv' }));
        await flushPromises();

        expect(wrapper.text()).toContain('“POB” is on rows 2 and 3. Each name must be a code used once in the file.');
    });
});
