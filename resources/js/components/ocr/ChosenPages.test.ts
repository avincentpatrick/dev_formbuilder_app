import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ChosenPages from './ChosenPages.vue';

/**
 * M154 (`R-c84e4f12`) — the pages chosen on the scans page, shown before "Read this scan" so a wrong photo is
 * caught before it is sent. The component only shows them; the page owns the files and their previews.
 */

const pages = [
    { id: 1, name: 'page-1.jpg', size: 2_400_000, previewUrl: 'blob:one' },
    { id: 2, name: 'form.pdf', size: 820_000, previewUrl: null },
];

describe('ChosenPages', () => {
    it('renders nothing until a file is chosen', () => {
        const wrapper = mount(ChosenPages, { props: { pages: [] } });

        expect(wrapper.find('.chosen').exists()).toBe(false);
    });

    it('lists each page in the order it was chosen, with its preview, name and size', () => {
        const wrapper = mount(ChosenPages, { props: { pages } });
        const items = wrapper.findAll('.chosen__item');

        expect(wrapper.text()).toContain('2 pages chosen');
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain('page-1.jpg');
        expect(items[0].text()).toContain('Page 1 · 2.4 MB');
        expect(items[0].find('img').attributes('src')).toBe('blob:one');
        // A file with no preview shows its kind instead of a broken image.
        expect(items[1].find('img').exists()).toBe(false);
        expect(items[1].text()).toContain('PDF');
        expect(items[1].text()).toContain('Page 2 · 820 KB');
    });

    it('says the order does not matter, because the reader puts the pages in printed order', () => {
        const wrapper = mount(ChosenPages, { props: { pages } });

        expect(wrapper.text()).toContain('Any order is fine');
    });

    it('asks the page to remove a file by its id, and offers no removal while sending', async () => {
        const wrapper = mount(ChosenPages, { props: { pages } });

        await wrapper.get('button[aria-label="Remove form.pdf"]').trigger('click');
        expect(wrapper.emitted('remove')).toEqual([[2]]);

        await wrapper.setProps({ disabled: true });
        expect(wrapper.get('button[aria-label="Remove page-1.jpg"]').attributes('disabled')).toBeDefined();
    });
});
