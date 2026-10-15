import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { field, schemaResponse, section } from '../../../public-runtime/__tests__/fixtures';

/*
 * M172 (`R-52f5ad93`, `D133`) — an old version opened read-only from the hub: the testers' "a way to check the older
 * version for history". The page draws the version's snapshot through the builder's preview with every author control
 * withheld, and says plainly that nothing here is saved or changes the form.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        props: ['title'],
        template: '<header><h1>{{ title }}</h1><slot name="breadcrumbs" /><slot name="actions" /></header>',
    },
}));

const VersionView = (await import('./VersionView.vue')).default;

function render() {
    const schema = schemaResponse({
        form: { title: 'Household survey' },
        sections: [section({ key: 'hh', label: 'Household' })],
        fields: [field({ key: 'colour', label: 'Favourite colour', section_key: 'hh', sequence: 0, section_sequence: 0 })],
    });

    return mount(VersionView, {
        props: {
            version: { id: 'v-1', version_number: 1, status: 'superseded', published_at: '2026-06-01T00:00:00+00:00' },
            schema,
            hub_href: '/forms/f1',
            crumbs: [{ label: 'Forms', href: '/forms' }, { label: 'Household survey', href: '/forms/f1' }, { label: 'Version 1' }],
        },
    });
}

describe('forms/VersionView — a published version, read-only', () => {
    it('names the version, says nothing is saved, and links back to the overview', async () => {
        const wrapper = render();
        await flushPromises();

        expect(wrapper.find('h1').text()).toBe('Household survey — version 1');
        const note = wrapper.find('[data-version-view-note]');
        expect(note.text()).toContain('nothing you enter is saved');
        expect(note.find('a').attributes('href')).toBe('/forms/f1');
        wrapper.unmount();
    });

    it('draws the version\'s questions and offers none of the author controls', async () => {
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain('Favourite colour');
        for (const selector of ['[data-preview-grip]', '[data-preview-edit-label]', '[data-preview-add-question]', '[data-preview-add-section]']) {
            expect(wrapper.find(selector).exists(), selector).toBe(false);
        }
        wrapper.unmount();
    });
});
