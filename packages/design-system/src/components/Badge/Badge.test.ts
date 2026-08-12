/**
 * `MdsBadge` behaviour (DSR §3.8). Added by JR2, which introduced the `dot` prop and found that the
 * component had no test of its own at all — `status-variant.test.ts` covers the enum→descriptor
 * lookup and nothing had ever asserted what the component renders.
 *
 * The `dot` cases matter more than they look. The disc is `aria-hidden` decoration, so a regression
 * that rendered it wrongly is invisible to the two gates that see this component: the Storybook axe
 * run checks violations (a hidden element has none) and the e2e contrast sweep never looks at it.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Badge from './Badge.vue';

describe('MdsBadge', () => {
    it('renders the label as text and nothing else by default', () => {
        const w = mount(Badge, { props: { label: 'Active' } });

        expect(w.text()).toBe('Active');
        expect(w.classes()).toContain('mds-badge');
        expect(w.classes()).toContain('mds-badge--neutral');
        expect(w.find('.mds-badge__dot').exists()).toBe(false);
        expect(w.find('.mds-badge__icon').exists()).toBe(false);
    });

    it('renders the status disc when `dot` is set, and hides it from assistive tech', () => {
        const w = mount(Badge, { props: { label: 'Live', variant: 'success', dot: true } });

        const dot = w.find('.mds-badge__dot');
        expect(dot.exists()).toBe(true);
        expect(dot.attributes('aria-hidden')).toBe('true');

        // The word still carries the meaning — the disc never becomes the signifier (WCAG 1.4.1),
        // and the accessible name is unchanged by its presence.
        expect(w.text()).toBe('Live');
    });

    it('suppresses the disc when an icon is also supplied, so the two never sit side by side', () => {
        const w = mount(Badge, { props: { label: 'Active', icon: 'check', dot: true } });

        expect(w.find('.mds-badge__dot').exists()).toBe(false);
        expect(w.find('svg.mds-icon').exists()).toBe(true);
    });

    it('paints the disc with currentColor so it can never drift from its own label', () => {
        // Read as SOURCE, not as a computed style: happy-dom lays nothing out and resolves no custom
        // properties, so `getComputedStyle(...).backgroundColor` here would return '' and the
        // assertion would pass whatever the CSS said. The contract worth pinning is that the rule
        // does not name a status token, because a named one can fall out of step with the variant
        // the label beside it is using — `currentColor` cannot.
        const source = readFileSync(join(process.cwd(), 'packages/design-system/src/components/Badge/Badge.vue'), 'utf8');
        const rule = source.match(/\.mds-badge__dot\s*\{([^}]*)\}/);

        expect(rule, '.mds-badge__dot rule not found — was it renamed?').not.toBeNull();
        expect(rule![1]).toContain('background-color: currentColor');
        expect(rule![1]).not.toMatch(/var\(--mds-color-status-/);
    });
});
