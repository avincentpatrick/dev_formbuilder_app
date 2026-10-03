import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ScanPages from './ScanPages.vue';
import type { ScanPage } from './scan-review';

/**
 * M129 — the scanned pages beside the review form. Each zoomable frame must be a focusable, named region (a
 * scrolling region a keyboard cannot reach is an axe failure and a reviewer who cannot pan), a PDF is a link,
 * and a page still waiting for its virus check says so instead of showing a broken image.
 */

const PNG: ScanPage = { number: 1, url: '/forms/f/ocr/scans/s/pages/1', mime: 'image/png', servable: true };

describe('ScanPages', () => {
    it('shows each image page in a focusable, named frame', () => {
        const wrapper = mount(ScanPages, { props: { pages: [PNG, { ...PNG, number: 2, url: '/p/2' }] } });
        const frames = wrapper.findAll('.scan-pages__frame');

        expect(frames).toHaveLength(2);
        expect(frames[0].attributes('role')).toBe('region');
        expect(frames[0].attributes('tabindex')).toBe('0');
        expect(frames[0].attributes('aria-label')).toBe('Page 1 of the scan');
        expect(frames[1].find('img').attributes('src')).toBe('/p/2');
    });

    it('zooms every page in steps, and cannot zoom out past fitting the panel', async () => {
        const wrapper = mount(ScanPages, { props: { pages: [PNG] } });
        const [zoomOut, zoomIn] = wrapper.findAll('button');

        expect(zoomOut.attributes('disabled')).toBeDefined();
        expect(wrapper.find('img').attributes('style')).toContain('width: 100%');

        await zoomIn.trigger('click');
        expect(wrapper.find('img').attributes('style')).toContain('width: 150%');
        expect(wrapper.text()).toContain('150%');
        expect(zoomOut.attributes('disabled')).toBeUndefined();
    });

    it('offers a PDF as a download rather than a frame, with no zoom to offer', () => {
        const wrapper = mount(ScanPages, { props: { pages: [{ ...PNG, mime: 'application/pdf' }] } });

        expect(wrapper.find('.scan-pages__frame').exists()).toBe(false);
        expect(wrapper.find('a[download]').attributes('href')).toBe(PNG.url);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('says a page is still being checked instead of showing a broken image', () => {
        const wrapper = mount(ScanPages, { props: { pages: [{ ...PNG, servable: false }] } });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('Page 1 is being checked for viruses.');
    });
});
