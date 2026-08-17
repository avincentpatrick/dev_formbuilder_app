import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import Combobox, { type ComboboxOption } from './Combobox.vue';

/**
 * MdsCombobox (DSR §3.4.1 / §4.5, J4c).
 *
 * ⚠️ THESE ASSERTIONS EXIST BECAUSE THE ACCESSIBILITY GATE CANNOT MAKE THEM. axe does not RESOLVE
 * `aria-activedescendant` or `aria-controls`, so a relationship pointing at an id that is not in the
 * document passes every scan while announcing nothing to the reader it was written for. The Storybook job
 * being green says nothing about the half of this component that matters most.
 */

const OPTIONS: ComboboxOption[] = [
    { key: 'form:f1', label: 'Clinic Intake', group: 'Forms' },
    { key: 'form:f2', label: 'Clinic Referral', group: 'Forms' },
    { key: 'member:m1', label: 'Ada Lovelace', group: 'Members' },
    // No group: a synthetic trailing row, which is the palette's "see all" shape and the reason the API
    // takes a FLAT list with an optional group rather than a nested one.
    { key: 'see-all', label: 'See all results for “clinic”' },
];

function mountCombobox(props: Record<string, unknown> = {}, slots: Record<string, string> = {}) {
    return mount(Combobox, {
        attachTo: document.body,
        props: {
            modelValue: 'clinic',
            options: OPTIONS,
            label: 'Search this workspace',
            listboxLabel: 'Search results',
            ...props,
        },
        slots,
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('MdsCombobox — omitted, never dangling', () => {
    it('omits both relationships when there is no listbox to point at', () => {
        const wrapper = mountCombobox({ options: [] });
        const input = wrapper.get('[role="combobox"]');

        expect(input.attributes('aria-controls')).toBeUndefined();
        expect(input.attributes('aria-activedescendant')).toBeUndefined();
        expect(input.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('points aria-controls at the listbox that actually exists', () => {
        const wrapper = mountCombobox();

        expect(wrapper.get('[role="combobox"]').attributes('aria-controls')).toBe(
            wrapper.get('[role="listbox"]').attributes('id'),
        );
        expect(wrapper.get('[role="combobox"]').attributes('aria-expanded')).toBe('true');
    });

    it('points aria-activedescendant at a real option, in the document', () => {
        const wrapper = mountCombobox();
        const active = wrapper.get('[role="combobox"]').attributes('aria-activedescendant');

        expect(active).toBeTruthy();
        const target = document.getElementById(String(active));
        expect(target).not.toBeNull();
        expect(target?.getAttribute('role')).toBe('option');
    });

    it('drops the active descendant rather than dangling it when the list empties', async () => {
        const wrapper = mountCombobox();
        expect(wrapper.get('[role="combobox"]').attributes('aria-activedescendant')).toBeTruthy();

        await wrapper.setProps({ options: [] });
        expect(wrapper.get('[role="combobox"]').attributes('aria-activedescendant')).toBeUndefined();
    });
});

describe('MdsCombobox — listbox structure', () => {
    it('renders options as divs with no tabindex, never as buttons', () => {
        const wrapper = mountCombobox();
        const options = wrapper.findAll('[role="option"]');

        expect(options).toHaveLength(4);

        for (const option of options) {
            // A button with role=option trips axe's nested-interactive AND breaks aria-activedescendant.
            expect(option.element.tagName).toBe('DIV');
            // Rows are not tabbable by design — DOM focus never leaves the input.
            expect(option.attributes('tabindex')).toBeUndefined();
        }

        // An li inside a listbox fails aria-required-children, so the grouping is divs too.
        expect(wrapper.find('[role="listbox"] li').exists()).toBe(false);
    });

    it('wraps consecutive options of one group and leaves an ungrouped row a direct child', () => {
        const wrapper = mountCombobox();
        const groups = wrapper.findAll('[role="group"]');

        expect(groups.map((group) => group.attributes('aria-label'))).toEqual(['Forms', 'Members']);
        expect(groups[0]?.findAll('[role="option"]')).toHaveLength(2);

        // ⭐ The trailing row must NOT be inside a group. A role=group demands an accessible name, and
        // inventing one for a synthetic row puts a heading in the reader's ear that is not on the screen.
        const listbox = wrapper.get('[role="listbox"]');
        const directRows = listbox.element.querySelectorAll(':scope > [role="option"]');
        expect(directRows).toHaveLength(1);
        expect(directRows[0]?.textContent?.trim()).toContain('See all results');
    });

    it('keeps every option id agreeing with its position in the flat list', () => {
        // ⭐ THE REASON THE API TAKES A FLAT LIST. Grouping is a rendering run; the index that the id, the
        // active descendant and the selection all use is the ORIGINAL one. A nested structure would carry a
        // second index whose only job is to agree with the first.
        const wrapper = mountCombobox();
        const ids = wrapper.findAll('[role="option"]').map((option) => option.attributes('id') ?? '');
        const suffixes = ids.map((id) => Number(id.slice(id.lastIndexOf('-') + 1)));

        expect(suffixes).toEqual([0, 1, 2, 3]);
    });

    it('renders the option slot, falling back to the label', () => {
        const plain = mountCombobox();
        expect(plain.findAll('[role="option"]')[0]?.text()).toBe('Clinic Intake');

        const slotted = mountCombobox({}, { option: '<span class="probe">{{ params.option.key }}</span>' });
        expect(slotted.find('.probe').exists()).toBe(true);
    });

    it('renders the empty slot instead of a listbox when there is nothing to show', () => {
        const wrapper = mountCombobox({ options: [] }, { empty: '<p class="none">No results.</p>' });

        expect(wrapper.get('.none').text()).toBe('No results.');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });
});

describe('MdsCombobox — keyboard, with DOM focus pinned to the input', () => {
    const active = (wrapper: ReturnType<typeof mountCombobox>) =>
        wrapper.get('[role="combobox"]').attributes('aria-activedescendant');

    it('moves and wraps on the arrows without moving focus', async () => {
        const wrapper = mountCombobox();
        const input = wrapper.get('[role="combobox"]');
        (input.element as HTMLInputElement).focus();

        const first = active(wrapper);
        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(active(wrapper)).not.toBe(first);

        // Four options, so three more downs return to the start.
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(active(wrapper)).toBe(first);

        await input.trigger('keydown', { key: 'ArrowUp' });
        expect(active(wrapper)).not.toBe(first);

        expect(document.activeElement).toBe(input.element);
    });

    it('jumps to the ends on Home and End', async () => {
        const wrapper = mountCombobox();
        const input = wrapper.get('[role="combobox"]');

        await input.trigger('keydown', { key: 'End' });
        expect(wrapper.findAll('[role="option"]')[3]?.attributes('aria-selected')).toBe('true');

        await input.trigger('keydown', { key: 'Home' });
        expect(wrapper.findAll('[role="option"]')[0]?.attributes('aria-selected')).toBe('true');
    });

    it('selects the highlighted option on Enter, and submits the raw query when there is none', async () => {
        const wrapper = mountCombobox();
        const input = wrapper.get('[role="combobox"]');

        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('select')?.at(-1)?.[0]).toMatchObject({ key: 'form:f2' });
        expect(wrapper.emitted('submit')).toBeUndefined();

        const bare = mountCombobox({ options: [] });
        await bare.get('[role="combobox"]').trigger('keydown', { key: 'Enter' });
        expect(bare.emitted('submit')?.at(-1)).toEqual(['clinic']);
        expect(bare.emitted('select')).toBeUndefined();
    });

    it('selects on click, from the row the pointer actually landed on', async () => {
        const wrapper = mountCombobox();

        await wrapper.findAll('[role="option"]')[2]?.trigger('click');
        expect(wrapper.emitted('select')?.at(-1)?.[0]).toMatchObject({ key: 'member:m1' });
    });

    it('does NOT consume Escape, because the surface that owns it is the dialog around this component', async () => {
        // ⭐ A DECISION, NOT AN OMISSION. The one consumer sits inside MdsModal, whose Escape is the
        // dismissal; a listbox that collapsed on the first press would make closing the dialog take two.
        // MdsTooltip and MdsMenu each solve their own Escape differently and for their own reasons — the
        // three must not be "aligned".
        const wrapper = mountCombobox();
        const event = new KeyboardEvent('keydown', { key: 'Escape', cancelable: true, bubbles: true });

        wrapper.get('[role="combobox"]').element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(wrapper.find('[role="listbox"]').exists()).toBe(true);
    });

    it('leaves every other key to the browser', async () => {
        const wrapper = mountCombobox();

        for (const key of ['Tab', 'a', ' ', 'ArrowLeft']) {
            const event = new KeyboardEvent('keydown', { key, cancelable: true });
            wrapper.get('[role="combobox"]').element.dispatchEvent(event);
            expect(event.defaultPrevented, `${key} must reach the browser`).toBe(false);
        }
    });

    it('re-highlights the first row when the query changes', async () => {
        const wrapper = mountCombobox();
        const input = wrapper.get('[role="combobox"]');

        await input.trigger('keydown', { key: 'End' });
        expect(wrapper.findAll('[role="option"]')[3]?.attributes('aria-selected')).toBe('true');

        (input.element as HTMLInputElement).value = 'clinics';
        await input.trigger('input');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['clinics']);
        expect(wrapper.findAll('[role="option"]')[0]?.attributes('aria-selected')).toBe('true');
    });
});

describe('MdsCombobox — the label and the live region', () => {
    it('associates a real label with the input', () => {
        const wrapper = mountCombobox();
        const label = wrapper.get('label');

        expect(label.text()).toBe('Search this workspace');
        expect(label.attributes('for')).toBe(wrapper.get('[role="combobox"]').attributes('id'));
    });

    it('accepts a caller-supplied input id, so a dialog can aim its initial focus', () => {
        const wrapper = mountCombobox({ inputId: 'palette-input' });

        expect(wrapper.get('[role="combobox"]').attributes('id')).toBe('palette-input');
        expect(wrapper.get('label').attributes('for')).toBe('palette-input');
    });

    it('announces the CONSUMER’s wording, never a count of its own', () => {
        // DSR §3.4.1 makes zero-result copy one string with one code path. A component computing its own
        // count would branch it, and would also be wrong wherever a synthetic row is in the list.
        const wrapper = mountCombobox({ status: '3 results' });

        expect(wrapper.get('[role="status"]').text()).toBe('3 results');
    });
});

describe('MdsCombobox — the containing-block guard, which no other gate can see', () => {
    /**
     * The design system must hold ZERO instances of the clip-rect-without-a-positioned-ancestor shape
     * (`clipped-node-containment.test.ts` asserts it). This component is where the palette's clipping MOVED
     * to, which is what lets that file leave the app-tree exception list — so the guarantee has to hold
     * here. Asserted directly as well, because the tree-wide scan reports a list rather than a reason.
     */
    const SOURCE = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/Combobox/Combobox.vue'),
        'utf8',
    );

    it('positions its own root, so a clipped child cannot escape to the document', () => {
        const root = /\.mds-combobox\s*\{([^}]*)\}/.exec(SOURCE)?.[1] ?? '';

        expect(root).toContain('position: relative');
    });
});
