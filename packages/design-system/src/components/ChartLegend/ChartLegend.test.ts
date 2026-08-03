import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ChartLegend from './ChartLegend.vue';
import type { ChartLegendItem } from '../../charts/types';

const items: ChartLegendItem[] = [
    { key: 'guest', label: 'Guest', series: 1 },
    { key: 'manual', label: 'Manual', series: 2 },
    { key: 'other', label: 'Other (4 categories)', neutral: true },
];

describe('MdsChartLegend', () => {
    it('is a real list of text labels, not a row of decorated colour chips', () => {
        const wrapper = mount(ChartLegend, { props: { items } });

        expect(wrapper.element.tagName).toBe('UL');
        expect(wrapper.findAll('li').map((li) => li.text())).toEqual([
            'Guest',
            'Manual',
            'Other (4 categories)',
        ]);
        wrapper.unmount();
    });

    it('carries the NON-COLOUR channel too — each swatch draws its own dash pattern', () => {
        // §D12: colour is never the only channel. A legend that showed hue alone would be exactly the
        // place that quietly broke that, because the chart itself does carry the second channel.
        const wrapper = mount(ChartLegend, { props: { items } });

        expect(wrapper.findAll('.mds-chart-legend__swatch').map((s) => s.classes().join(' '))).toEqual([
            'mds-chart-legend__swatch mds-chart-legend__swatch--1',
            'mds-chart-legend__swatch mds-chart-legend__swatch--2',
            'mds-chart-legend__swatch mds-chart-legend__swatch--other',
        ]);
        wrapper.unmount();
    });

    it('keeps the swatches out of the accessibility tree', () => {
        const wrapper = mount(ChartLegend, { props: { items } });

        for (const swatch of wrapper.findAll('.mds-chart-legend__swatch')) {
            expect(swatch.attributes('aria-hidden')).toBe('true');
            expect(swatch.attributes('focusable')).toBe('false');
        }
        wrapper.unmount();
    });

    it('clamps an out-of-range series index rather than emitting a class with no rule behind it', () => {
        // A missing rule means the mark inherits `currentColor` and silently matches its neighbour.
        const wrapper = mount(ChartLegend, {
            props: { items: [{ key: 'x', label: 'Sixth', series: 9 }] },
        });

        expect(wrapper.find('.mds-chart-legend__swatch').classes()).toContain('mds-chart-legend__swatch--5');
        wrapper.unmount();
    });

    it('defaults a series-less item to the first series', () => {
        const wrapper = mount(ChartLegend, { props: { items: [{ key: 'x', label: 'Only' }] } });

        expect(wrapper.find('.mds-chart-legend__swatch').classes()).toContain('mds-chart-legend__swatch--1');
        wrapper.unmount();
    });
});
