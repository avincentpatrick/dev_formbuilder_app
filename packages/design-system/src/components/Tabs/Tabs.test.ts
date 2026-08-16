import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import Tabs, { type TabItem } from './Tabs.vue';

/**
 * MdsTabs (DSR §3.4, J4c).
 *
 * The centre of gravity here is the relationship wiring, because that is the half no other gate can see.
 * The markup this extracted — `ConfigPanel.vue`'s hand-rolled tablist — rendered correctly, passed every
 * visual check and shipped for months with no aria-controls at all, a tabpanel in the tab sequence, and a
 * suppressed focus ring on that panel. An app-tree component gets no Storybook story and therefore no
 * accessibility scan, and the builder's end-to-end specs click every tab without ever asking what any of
 * them points at.
 */

const ITEMS: TabItem[] = [
    { key: 'basics', label: 'Basics' },
    { key: 'validation', label: 'Validation' },
    { key: 'advanced', label: 'Advanced' },
];

function mountTabs(props: Record<string, unknown> = {}) {
    return mount(Tabs, {
        attachTo: document.body,
        props: { items: ITEMS, modelValue: 'basics', ariaLabel: 'Configuration sections', ...props },
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('MdsTabs — structure', () => {
    it('renders one named tablist, one tab per item, and one panel', () => {
        const wrapper = mountTabs();

        const lists = wrapper.findAll('[role="tablist"]');
        expect(lists).toHaveLength(1);
        expect(lists[0]?.attributes('aria-label')).toBe('Configuration sections');

        expect(wrapper.findAll('[role="tab"]')).toHaveLength(3);
        expect(wrapper.findAll('[role="tabpanel"]')).toHaveLength(1);
    });

    it('renders the slot inside the panel', () => {
        const wrapper = mount(Tabs, {
            props: { items: ITEMS, modelValue: 'basics', ariaLabel: 'Sections' },
            slots: { default: '<p class="probe">panel body</p>' },
        });

        expect(wrapper.get('[role="tabpanel"] .probe').text()).toBe('panel body');
    });

    it('renders nothing at all for an empty item set', () => {
        // A tabpanel with no tab to name it has no accessible name, and a tablist with no tabs is not a
        // control. The consumer owns its own empty state — MdsTabNav's rule, and ConfigPanel's `.config__empty`
        // is the consumer honouring it.
        const wrapper = mountTabs({ items: [] });

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.find('[role="tabpanel"]').exists()).toBe(false);
    });

    it('marks exactly one tab selected, by identity rather than per row', () => {
        const wrapper = mountTabs({ modelValue: 'validation' });
        const tabs = wrapper.findAll('[role="tab"]');

        expect(tabs.map((tab) => tab.attributes('aria-selected'))).toEqual(['false', 'true', 'false']);
    });
});

describe('MdsTabs — the relationships, which are the half nothing else checks', () => {
    it('points the selected tab at the panel that actually exists, and leaves the others with none', () => {
        // ⭐ THE SHARP EDGE. Only the selected panel is in the document, so an aria-controls on every tab
        // would point at absent ids for every unselected one — worse than no relationship at all, because a
        // screen reader announces nothing and the user cannot tell. axe downgrades a dangling aria-controls
        // to `incomplete` rather than a violation, so the merge gate stays green over the lazy version.
        const wrapper = mountTabs({ modelValue: 'validation' });
        const tabs = wrapper.findAll('[role="tab"]');
        const panelId = wrapper.get('[role="tabpanel"]').attributes('id');

        expect(panelId).toBeTruthy();
        expect(tabs[1]?.attributes('aria-controls')).toBe(panelId);
        expect(tabs[0]?.attributes('aria-controls')).toBeUndefined();
        expect(tabs[2]?.attributes('aria-controls')).toBeUndefined();
    });

    it('labels the panel with the selected tab, resolving to a real element', () => {
        const wrapper = mountTabs({ modelValue: 'advanced' });
        const labelledBy = wrapper.get('[role="tabpanel"]').attributes('aria-labelledby');

        expect(labelledBy).toBeTruthy();
        expect(document.getElementById(labelledBy as string)?.textContent?.trim()).toBe('Advanced');
    });

    it('keeps the panel out of the tab sequence', () => {
        // The APG puts a panel in the tab sequence only when it holds nothing focusable. This one is
        // designed to hold form controls, so a tabindex would mint a redundant stop in front of them —
        // and `ConfigPanel.vue` had one, plus an outline suppression on the stop it created.
        expect(mountTabs().get('[role="tabpanel"]').attributes('tabindex')).toBeUndefined();
    });
});

describe('MdsTabs — the roving tabindex', () => {
    it('gives the selected tab the only stop', () => {
        const wrapper = mountTabs({ modelValue: 'validation' });

        expect(wrapper.findAll('[role="tab"]').map((tab) => tab.attributes('tabindex'))).toEqual([
            '-1',
            '0',
            '-1',
        ]);
    });

    it('keeps the strip reachable when the model names no item, while selecting nothing', () => {
        // ⭐ The case MdsTabNav resolves the OPPOSITE way, deliberately. Nothing is selected — the component
        // never invents a selection — but exactly one tab must remain reachable by Tab, or an unmatched
        // model takes the whole control out of the keyboard's reach.
        const wrapper = mountTabs({ modelValue: 'nonexistent' });
        const tabs = wrapper.findAll('[role="tab"]');

        expect(tabs.map((tab) => tab.attributes('aria-selected'))).toEqual(['false', 'false', 'false']);
        expect(tabs.map((tab) => tab.attributes('tabindex'))).toEqual(['0', '-1', '-1']);
        expect(wrapper.get('[role="tabpanel"]').attributes('aria-labelledby')).toBeUndefined();
    });
});

describe('MdsTabs — keyboard', () => {
    it('moves and selects on the arrows, wrapping in both directions', async () => {
        const wrapper = mountTabs({ modelValue: 'basics' });
        const tabs = wrapper.findAll('[role="tab"]');

        await tabs[0]?.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['validation']);

        await tabs[0]?.trigger('keydown', { key: 'ArrowLeft' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['advanced']);

        await tabs[2]?.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['basics']);
    });

    it('jumps to the ends on Home and End', async () => {
        const wrapper = mountTabs({ modelValue: 'validation' });
        const tabs = wrapper.findAll('[role="tab"]');

        await tabs[1]?.trigger('keydown', { key: 'Home' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['basics']);

        await tabs[1]?.trigger('keydown', { key: 'End' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['advanced']);
    });

    it('moves DOM focus with the selection, not only the model', async () => {
        // Automatic activation is two things at once, and a version that emitted without focusing would
        // pass every assertion above while leaving the keyboard user's focus on the tab they just left.
        const wrapper = mountTabs({ modelValue: 'basics' });
        const tabs = wrapper.findAll('[role="tab"]');

        await tabs[0]?.trigger('keydown', { key: 'ArrowRight' });
        expect(document.activeElement).toBe(tabs[1]?.element);

        await tabs[1]?.trigger('keydown', { key: 'End' });
        expect(document.activeElement).toBe(tabs[2]?.element);
    });

    it('consumes only the keys it handles', async () => {
        // An unconditional preventDefault swallows Tab, Enter and every printable character the browser is
        // meant to act on — including the Tab that is a tablist's only way out.
        const wrapper = mountTabs();
        const tab = wrapper.findAll('[role="tab"]')[0];

        const handled = new KeyboardEvent('keydown', { key: 'ArrowRight', cancelable: true });
        tab?.element.dispatchEvent(handled);
        expect(handled.defaultPrevented).toBe(true);

        for (const key of ['Tab', 'Enter', ' ', 'a']) {
            const event = new KeyboardEvent('keydown', { key, cancelable: true });
            tab?.element.dispatchEvent(event);
            expect(event.defaultPrevented, `${key} must reach the browser`).toBe(false);
        }
    });

    it('selects on click', async () => {
        const wrapper = mountTabs();

        await wrapper.findAll('[role="tab"]')[2]?.trigger('click');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['advanced']);
    });
});

describe('MdsTabs — the selected indicator is a non-text UI component', () => {
    /**
     * Source text is the only place this can be held: happy-dom computes no layout and resolves no custom
     * properties, and axe checks no border contrast at all. Three increments have now paid for the same
     * substitution — J2a on MdsTabNav, J4a on the personalization accent bar, and `ConfigPanel.vue` was
     * still carrying it on this strip when J4c arrived.
     *
     * The needles are bare token names rather than full references, so `token-references.test.ts` does not
     * read this file's assertions as real usages.
     */
    const SOURCE = readFileSync(join(process.cwd(), 'packages/design-system/src/components/Tabs/Tabs.vue'), 'utf8');
    const selectedRule = /\.mds-tabs__tab\.is-selected\s*\{([^}]*)\}/.exec(SOURCE);

    it('draws the underline with the paired foreground token, never the fill', () => {
        expect(selectedRule, 'the selected-tab rule must exist to be checked').not.toBeNull();

        const block = selectedRule?.[1] ?? '';
        expect(block).toContain('--mds-color-action-primary-fg');
        expect(block).not.toContain('--mds-color-action-primary-bg');
    });

    it('carries a second, non-colour channel', () => {
        // WCAG 1.4.1: the selected state must not be conveyed by colour alone. The weight change is what
        // survives greyscale, and aria-selected is what survives having no sight at all.
        expect(selectedRule?.[1] ?? '').toContain('font-weight');
    });
});
