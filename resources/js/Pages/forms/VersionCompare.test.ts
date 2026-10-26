import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { Comparison } from './version-compare';

/*
 * M189 (`version-diff-ui`, `D169`) — the Compare page: two versions side by side, each changed setting in the builder's
 * words, the marks §5 asked for, and either side changed from the page.
 */

const mocks = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: mocks.visit },
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        props: ['title'],
        template: '<header><h1>{{ title }}</h1><slot name="breadcrumbs" /><slot name="actions" /></header>',
    },
}));

const VersionCompare = (await import('./VersionCompare.vue')).default;

const comparison: Comparison = {
    sections: [{ key: 'hh', label: 'Household', status: 'same', changes: [], marks: [] }],
    questions: [
        {
            key: 'name', label: 'Name', field_type: 'short_text', section_key: 'hh', status: 'changed',
            changes: [{ setting: 'is_required', before: 'optional', after: 'required' }], breaking: 'required', marks: ['comparability'],
        },
        { key: 'age', label: 'Age', field_type: 'integer', section_key: 'hh', status: 'same', changes: [], breaking: null, marks: [] },
        { key: 'notes', label: 'Notes', field_type: 'short_text', section_key: 'hh', status: 'added', changes: [], breaking: null, marks: [] },
    ],
};

function render(overrides: Partial<{ comparison: Comparison }> = {}) {
    return mount(VersionCompare, {
        props: {
            form: { id: 'f1', title: 'Household survey' },
            from: { id: 'v-1', version_number: 1, status: 'published', name: 'Version 1 (live)', published_at: '2026-06-01T00:00:00+00:00' },
            to: { id: 'v-2', version_number: 2, status: 'draft', name: 'Draft', published_at: null },
            versions: [
                { id: 'v-2', name: 'Draft' },
                { id: 'v-1', name: 'Version 1 (live)' },
            ],
            comparison,
            labels: { from: { name: 'Name', age: 'Age' }, to: { name: 'Name', age: 'Age', notes: 'Notes' } },
            words: {
                enums: { required_modes: [{ value: 'optional', label: 'Optional' }, { value: 'required', label: 'Required' }], indexed_data_types: [], validation_rule_types: [], comparison_operators: [] } as never,
                type_labels: { short_text: 'Short text', integer: 'Number' },
            },
            compare_base: '/forms/f1/compare',
            hub_href: '/forms/f1',
            crumbs: [{ label: 'Forms', href: '/forms' }, { label: 'Household survey', href: '/forms/f1' }, { label: 'Compare versions' }],
            ...overrides,
        },
    });
}

describe('forms/VersionCompare — two versions, setting by setting', () => {
    it('names both sides and sums up, and lists only what differs until asked', () => {
        const wrapper = render();

        expect(wrapper.find('h1').text()).toBe('Household survey — compare versions');
        expect(wrapper.find('[data-compare-summary]').text()).toBe('Version 1 (live) compared with Draft. Questions: 1 changed, 1 added and 1 unchanged.');
        expect(wrapper.findAll('[data-compare-question]').map((q) => q.attributes('data-compare-question'))).toEqual(['name', 'notes']);
        wrapper.unmount();
    });

    it('draws a changed question as Setting · From · To in the builder’s words, with its mark and why', () => {
        const wrapper = render();
        const name = wrapper.find('[data-compare-question="name"]');

        expect(name.findAll('thead th').map((th) => th.text())).toEqual(['Setting', 'Version 1 (live)', 'Draft']);
        expect(name.findAll('tbody tr').map((tr) => tr.findAll('th, td').map((cell) => cell.text()))).toEqual([['Required', 'Optional', 'Required']]);
        expect(name.text()).toContain('Answers may not compare across versions');
        expect(name.text()).toContain('it became required');
        expect(wrapper.find('[data-compare-question="notes"]').text()).toContain('Not in Version 1 (live).');
        wrapper.unmount();
    });

    it('shows unchanged questions on request, and only marked ones on the other switch', async () => {
        const wrapper = render();

        await wrapper.find('[data-compare-show-unchanged] input').setValue(true);
        expect(wrapper.findAll('[data-compare-question]')).toHaveLength(3);

        await wrapper.find('[data-compare-only-marked] input').setValue(true);
        expect(wrapper.findAll('[data-compare-question]').map((q) => q.attributes('data-compare-question'))).toEqual(['name']);
        wrapper.unmount();
    });

    it('opens another pair when either side is changed, and swaps them', async () => {
        const wrapper = render();

        await wrapper.find('#compare-to').setValue('v-1');
        expect(mocks.visit).toHaveBeenLastCalledWith('/forms/f1/compare/v-1/v-1');
        await wrapper.find('[data-compare-swap]').trigger('click');
        expect(mocks.visit).toHaveBeenLastCalledWith('/forms/f1/compare/v-2/v-1');
        wrapper.unmount();
    });

    it('says so when nothing differs', () => {
        const wrapper = render({ comparison: { sections: comparison.sections, questions: [comparison.questions[1]] } });

        expect(wrapper.text()).toContain('No differences');
        wrapper.unmount();
    });
});
