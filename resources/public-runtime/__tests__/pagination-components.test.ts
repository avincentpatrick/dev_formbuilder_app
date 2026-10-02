/**
 * Increment M124 (`R-8c517fb6`) — a paginated section through the real component tree: `RuntimeSession` turns
 * pagination on for a stepped form, `StepView` announces and labels each page, `SectionView` keeps the heading
 * on every page and the description on the first, and a saved draft records the section, never the page.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RuntimeSession from '../components/RuntimeSession.vue';
import type { ApiClient } from '../lib/api-client';
import type { Bootstrap, RawField } from '../lib/types';
import { field, schemaResponse, section } from './fixtures';

function fakeClient(overrides: Partial<ApiClient> = {}): ApiClient {
    return {
        fetchSchema: vi.fn(async () => {
            throw new Error('unused in these tests');
        }),
        submit: vi.fn(async () => ({ id: 'sub-1', reference: 'REF-1', status: 'submitted', created: true })),
        saveDraft: vi.fn(async () => ({
            id: 'sub-1',
            completenessPercent: 50,
            resumeToken: 'rt',
            resumeUrl: 'https://acme.test/f/resume/rt',
            expiresAt: '',
        })),
        remint: vi.fn(async () => ({ shareToken: 't2', expiresAt: '', form: { id: 'f', title: 'T' } })),
        token: () => 't1',
        ...overrides,
    };
}

const bootstrap: Bootstrap = {
    shareToken: 't1',
    expiresAt: '',
    formId: 'f',
    formTitle: 'T',
    slug: 's',
    defaultLocale: 'en',
    resumeToken: '',
};

beforeEach(() => {
    window.localStorage.clear();
});

function announced(wrapper: ReturnType<typeof mount>): string {
    return wrapper.find('.runtime__sr-live').text();
}

async function next(wrapper: ReturnType<typeof mount>): Promise<void> {
    await wrapper.findAll('button').filter((b) => b.text() === 'Next')[0].trigger('click');
    await flushPromises();
}

function inHousehold(key: string, sectionSequence: number, extra: Partial<RawField> = {}): RawField {
    return field({ key, label: `Question ${key}`, section_key: 'hh', sequence: sectionSequence, section_sequence: sectionSequence, ...extra });
}

/** "Household": q1 · pb1 · q2 — two pages. */
function twoPages(extra: { pb1?: Partial<RawField>; q1?: Partial<RawField>; saveAndResume?: boolean } = {}) {
    return schemaResponse({
        form: { single_page_mode: false, save_and_resume: extra.saveAndResume ?? false },
        sections: [section({ key: 'hh', label: 'Household', description: 'About the people you live with.', sequence: 0 })],
        fields: [
            inHousehold('q1', 0, extra.q1),
            inHousehold('pb1', 1, { field_type: 'page_break', ...extra.pb1 }),
            inHousehold('q2', 2),
        ],
    });
}

describe('RuntimeSession — a section paginated at its page break', () => {
    it('keeps the heading on every page, the description on the first, and says the section continues', async () => {
        const wrapper = mount(RuntimeSession, { props: { schema: twoPages(), bootstrap, client: fakeClient() } });
        await flushPromises();

        expect(wrapper.text()).toContain('Step 1 of 2: Household');
        expect(wrapper.text()).toContain('About the people you live with.');
        expect(wrapper.text()).toContain('Question q1');
        expect(wrapper.text()).not.toContain('Question q2');

        await next(wrapper);

        expect(wrapper.text()).toContain('Step 2 of 2: Household (continued)');
        expect(wrapper.find('[data-section-heading]').text()).toBe('Household (continued)');
        expect(wrapper.text()).not.toContain('About the people you live with.');
        expect(wrapper.text()).toContain('Question q2');
        expect(announced(wrapper)).toContain('Step 2 of 2: Household (continued)');

        wrapper.unmount();
    });

    it('does not paginate a form shown on one page', async () => {
        const schema = twoPages();
        schema.form.single_page_mode = true;
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client: fakeClient() } });
        await flushPromises();

        expect(wrapper.text()).toContain('Question q1');
        expect(wrapper.text()).toContain('Question q2');
        expect(wrapper.findAll('[data-section-heading]')).toHaveLength(1);

        wrapper.unmount();
    });

    it('saves the section, never the page, from a later page', async () => {
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema: twoPages({ saveAndResume: true }), bootstrap, client } });
        await flushPromises();
        await next(wrapper);
        expect(wrapper.text()).toContain('Step 2 of 2');

        await wrapper.findAll('button').filter((b) => b.text().includes('Save and finish later'))[0].trigger('click');
        await flushPromises();

        expect(client.saveDraft).toHaveBeenCalledWith(expect.objectContaining({ draftCurrentStep: 'hh' }));

        wrapper.unmount();
    });

    it('announces a merge as a change of page, never as a step that no longer applies', async () => {
        // The break applies only while q2 is not "merge" — so answering q2 that way, ON the second page, folds
        // the two pages into one under the respondent. Their question is still in front of them.
        const wrapper = mount(RuntimeSession, {
            props: { schema: twoPages({ pb1: { relevant_expression: "${q2} != 'merge'" } }), bootstrap, client: fakeClient() },
        });
        await flushPromises();
        await next(wrapper);

        // Page two holds exactly one question, so its only input is q2's.
        await wrapper.find('input').setValue('merge');
        await flushPromises();
        await flushPromises();

        expect(announced(wrapper)).toContain('The page you were on is now part of step 1 of 1: Household.');
        expect(announced(wrapper)).not.toContain('no longer applies');
        expect(wrapper.find('.relevance-note').exists()).toBe(false);

        wrapper.unmount();
    });

    it('resumes a draft saved on a section whose first page is now hidden, with no drift notice', async () => {
        const wrapper = mount(RuntimeSession, {
            props: {
                schema: twoPages({ q1: { relevant_expression: "${q2} = 'show'" } }),
                bootstrap,
                client: fakeClient(),
                resume: { uuid: 'draft-uuid-1', locale: null, stepKey: 'hh', completeness: 50, note: null },
            },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Welcome back');
        expect(wrapper.text()).not.toContain('The step you were on no longer applies');
        expect(wrapper.text()).toContain('Question q2');

        wrapper.unmount();
    });
});
