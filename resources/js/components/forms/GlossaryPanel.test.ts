/**
 * The Glossary section (M245, `R-d0bbb7bb`), through the rendered DOM with `fetch` stubbed at the boundary: the terms
 * are the server's answer, read when the settings open; an added, changed or removed term is shown in the server's
 * order and announced; a refusal shows the server's own sentence; and the builder is told each time.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import GlossaryPanel from './GlossaryPanel.vue';
import { sortGlossary } from './glossary';
import { glossaryKey } from '@/components/builder/glossary';

const TWG = { id: '0192e2e0-0000-7000-8000-0000000000a1', term: 'TWG', definition: 'The technical working group.' };
const BHW = { id: '0192e2e0-0000-7000-8000-0000000000a2', term: 'barangay health worker', definition: 'A volunteer.' };

function respond(body: unknown, status = 200): Promise<Response> {
    return Promise.resolve(new Response(body === null ? null : JSON.stringify(body), { status }));
}

function buttonNamed(wrapper: VueWrapper, name: string) {
    const found = wrapper.findAll('button').find((b) => b.text() === name || b.attributes('aria-label') === name);
    if (found === undefined) throw new Error(`no button named «${name}»`);
    return found;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('the Glossary section', () => {
    it('reads the terms when the settings open, and says what marking a word does', async () => {
        const fetchMock = vi.fn(() => respond({ data: [BHW, TWG] }));
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(GlossaryPanel, { props: { open: true, formId: 'form-1' } });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/forms/form-1/glossary', expect.objectContaining({ method: 'GET' }));
        expect(wrapper.findAll('[data-glossary-row]').map((row) => row.text())).toEqual([
            expect.stringContaining('barangay health worker'),
            expect.stringContaining('TWG'),
        ]);
        expect(wrapper.text()).toContain('mark a word as [[word]]');
        expect(wrapper.text()).toContain("Shown in the form's default language only.");
    });

    it('adds a term in its place, clears the form, and tells the builder', async () => {
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(() => respond({ data: [TWG] }))
            .mockImplementationOnce(() => respond({ data: BHW }, 201));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(GlossaryPanel, { props: { open: true, formId: 'form-1' } });
        await flushPromises();

        await wrapper.get('[data-glossary-new-term] input, input[data-glossary-new-term]').setValue('barangay health worker');
        await wrapper.get('[data-glossary-new-definition] textarea, textarea[data-glossary-new-definition]').setValue('A volunteer.');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(fetchMock).toHaveBeenLastCalledWith(
            '/forms/form-1/glossary',
            expect.objectContaining({ method: 'POST', body: JSON.stringify({ term: 'barangay health worker', definition: 'A volunteer.' }) }),
        );
        expect(wrapper.findAll('[data-glossary-row]').map((row) => row.text().split('A ')[0])).toHaveLength(2);
        expect(wrapper.findAll('[data-glossary-row]')[0].text()).toContain('barangay health worker');
        expect(wrapper.get('[role="status"]').text()).toBe('Added barangay health worker.');
        expect(wrapper.emitted('changed')?.[0]?.[0]).toEqual([BHW, TWG]);
    });

    it('changes a term in place and removes one', async () => {
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(() => respond({ data: [TWG] }))
            .mockImplementationOnce(() => respond({ data: { ...TWG, definition: 'Changed.' } }))
            .mockImplementationOnce(() => respond(null, 204));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(GlossaryPanel, { props: { open: true, formId: 'form-1' } });
        await flushPromises();

        await buttonNamed(wrapper, 'Change TWG').trigger('click');
        await wrapper.get('[data-glossary-row] textarea').setValue('Changed.');
        await buttonNamed(wrapper, 'Save').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenNthCalledWith(2, `/forms/form-1/glossary/${TWG.id}`, expect.objectContaining({ method: 'PATCH' }));
        expect(wrapper.get('[data-glossary-row]').text()).toContain('Changed.');

        await buttonNamed(wrapper, 'Remove TWG').trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith(`/forms/form-1/glossary/${TWG.id}`, expect.objectContaining({ method: 'DELETE' }));
        expect(wrapper.find('[data-glossary-row]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No terms yet.');
    });

    it('shows the server’s own sentence when it refuses a term', async () => {
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(() => respond({ data: [TWG] }))
            .mockImplementationOnce(() => respond({ message: 'x', errors: { term: ['This glossary already has “TWG”.'] } }, 422));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(GlossaryPanel, { props: { open: true, formId: 'form-1' } });
        await flushPromises();

        await wrapper.get('input').setValue('twg');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('This glossary already has “TWG”.');
        expect(wrapper.emitted('changed')).toBeUndefined();
    });
});

describe('sortGlossary', () => {
    it('orders by the matching key, then the term, byte-wise, as the server does', () => {
        const rows = [{ term: 'b' }, { term: '9' }, { term: 'B' }, { term: '10' }, { term: 'a' }];

        expect(sortGlossary(rows, glossaryKey).map((row) => row.term)).toEqual(['10', '9', 'a', 'B', 'b']);
    });
});
