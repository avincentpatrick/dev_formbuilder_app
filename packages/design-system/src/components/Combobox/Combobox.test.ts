import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import Combobox, { type ComboboxOption } from './Combobox.vue';
import { scrollTopToReveal } from './scroll-reveal';

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

    it('re-highlights a valid row when a SHORTER list replaces a longer one', async () => {
        // ⭐ THE ADVERSARIAL FINDING. A consumer whose list arrives asynchronously — the palette's does,
        // 250ms behind the keystroke — can have the reader arrow down the OLD list while a shorter new one
        // is in flight. Clamping only on activation left the index past the end, so the relationship went
        // absent (honestly) and the listbox sat there with NOTHING highlighted until the reader moved.
        // DSR §3.4.1 records the behaviour as auto-highlight the first row on every non-empty list.
        const wrapper = mountCombobox();
        const input = wrapper.get('[role="combobox"]');

        await input.trigger('keydown', { key: 'End' });
        expect(wrapper.findAll('[role="option"]')[3]?.attributes('aria-selected')).toBe('true');

        await wrapper.setProps({ options: OPTIONS.slice(0, 2) });

        const rows = wrapper.findAll('[role="option"]');
        expect(rows).toHaveLength(2);
        expect(rows[0]?.attributes('aria-selected'), 'something must be highlighted').toBe('true');
        expect(wrapper.get('[role="combobox"]').attributes('aria-activedescendant')).toBe(
            rows[0]?.attributes('id'),
        );
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

/**
 * M20 — the highlight must stay inside the 22rem box.
 *
 * ⚠️ THE ARITHMETIC IS TESTED DIRECTLY AND THE WIRING IS TESTED SEPARATELY, BECAUSE happy-dom COMPUTES NO
 * LAYOUT. Every box in this environment is 0, so a mounted "did it scroll?" assertion would pass whatever
 * the component did — which is the same reason `scroll-reveal.ts` exists as a module at all. The mounted
 * case below therefore asserts only what happy-dom CAN answer: that the watcher fires, finds the element
 * `aria-activedescendant` names, and reads the container. The numbers are asserted on the pure function.
 */
describe('MdsCombobox — scrollTopToReveal, the arithmetic behind the highlight', () => {
    // A 352px band (22rem at the default root size) over a list of 48px rows, which is the palette's
    // real geometry rather than a convenient one.
    const BAND = 352;

    it('returns null when the option is already fully inside the band, so the reader is never fought', () => {
        expect(
            scrollTopToReveal({ scrollTop: 0, viewportHeight: BAND, optionTop: 48, optionHeight: 48 }),
        ).toBeNull();
    });

    it('aligns an option above the band to the TOP, moving by the least amount that works', () => {
        expect(
            scrollTopToReveal({ scrollTop: 200, viewportHeight: BAND, optionTop: 96, optionHeight: 48 }),
        ).toBe(96);
    });

    it('aligns an option below the band to the BOTTOM, not to the top', () => {
        // The seventh row (index 6) of a 48px list starts at 288 and ends at 336 — visible. The EIGHTH
        // ends at 384, which is 32px past a 352px band, so the list moves exactly 32px and no further.
        expect(
            scrollTopToReveal({ scrollTop: 0, viewportHeight: BAND, optionTop: 336, optionHeight: 48 }),
        ).toBe(32);
    });

    it('never returns a negative scrollTop', () => {
        expect(
            scrollTopToReveal({ scrollTop: 40, viewportHeight: BAND, optionTop: -20, optionHeight: 48 }),
        ).toBe(0);
    });

    /**
     * ⚠️ THIS IS THE CASE THAT KEEPS THE UNIT SUITE HONEST RATHER THAN A DEFENSIVE BRANCH. happy-dom
     * reports 0 for every box, and so does a real browser inside a `display: none` ancestor. Without the
     * guard both would compute `optionTop + optionHeight - 0` and slam the list to the bottom — and in
     * happy-dom that wrong answer would be indistinguishable from "nothing happened".
     */
    it('declines to move a container it cannot measure', () => {
        expect(
            scrollTopToReveal({ scrollTop: 0, viewportHeight: 0, optionTop: 0, optionHeight: 0 }),
        ).toBeNull();
    });

    it('shows the START of an option taller than the band, rather than oscillating between its edges', () => {
        expect(
            scrollTopToReveal({ scrollTop: 500, viewportHeight: BAND, optionTop: 120, optionHeight: 400 }),
        ).toBe(120);

        // Already aligned to its start — nothing to do, and no write.
        expect(
            scrollTopToReveal({ scrollTop: 120, viewportHeight: BAND, optionTop: 120, optionHeight: 400 }),
        ).toBeNull();
    });
});

describe('MdsCombobox — the reveal is wired to the highlight, not to focus', () => {
    /**
     * The three decisions that make this necessary are all in the component ON PURPOSE, so a source-text
     * assertion is what stops a later author "simplifying" one of them away and silently re-opening the
     * WCAG 2.4.7 failure: the list scrolls, the rows carry no tabindex, and the arrows are prevented.
     */
    const SOURCE = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/Combobox/Combobox.vue'),
        'utf8',
    );

    it('still scrolls its list, so the reveal is not dead code', () => {
        const list = /\.mds-combobox__list\s*\{([^}]*)\}/.exec(SOURCE)?.[1] ?? '';

        expect(list).toContain('overflow-y: auto');
        expect(list).toContain('max-height');
    });

    it('reveals on a POST-flush watcher, or it measures the row that was highlighted a moment ago', () => {
        // A pre-flush watcher runs before Vue patches the DOM. It would scroll to the previous row every
        // time — consistently, which reads as "the fix does not work" rather than as a timing bug.
        // ⚠️ SLICED RATHER THAN PATTERN-MATCHED, AND THE FIRST TWO VERSIONS OF THIS LINE WERE REGEXES
        // THAT DID NOT WORK. The text between the two anchors contains a paren of its own — the options
        // getter's arrow — so a negated-paren class stopped dead at it. Reading the call site as a plain
        // substring is both shorter and impossible to get subtly wrong.
        const watchCall = SOURCE.slice(SOURCE.indexOf('watch([activeIndex'), SOURCE.length);

        expect(watchCall.slice(0, 160)).toContain("flush: 'post'");
    });

    it('never reaches for scrollIntoView, which would be free to scroll the page as well', () => {
        // `block: 'nearest'` walks EVERY scrollable ancestor, and this listbox sits inside a dialog inside
        // the page. M17 and M19 were both about a page gaining scroll it should not have.
        //
        // ⚠️ MATCHED AS A CALL, NOT AS A SUBSTRING, AND THE FIRST VERSION OF THIS TEST GOT IT WRONG.
        // The component's docblock NAMES the API it rejects, so a bare `toContain` fails on the very
        // comment explaining why the code is correct — this repository's standing "name the thing,
        // never quote it" lesson, arriving for once inside a gate rather than inside a document.
        expect(SOURCE).not.toContain('.scrollIntoView(');
    });

    it('looks the option up by the id aria-activedescendant names, so the two cannot disagree', async () => {
        const wrapper = mountCombobox();
        const list = wrapper.get('[role="listbox"]').element as HTMLElement;

        // happy-dom cannot lay this out, so the assertion is about IDENTITY, not about pixels: after an
        // arrow press the id the input announces must resolve to a real element inside the list.
        await wrapper.get('input').trigger('keydown', { key: 'ArrowDown' });

        const announced = wrapper.get('input').attributes('aria-activedescendant');

        expect(announced).toBeDefined();
        expect(list.querySelector(`[id="${announced}"]`)).not.toBeNull();
    });

    it('survives a list that is replaced under the highlight without throwing', async () => {
        const wrapper = mountCombobox();

        await wrapper.get('input').trigger('keydown', { key: 'End' });
        // The palette's real race: a shorter list lands 250ms behind the keystroke.
        await wrapper.setProps({ options: OPTIONS.slice(0, 1) });

        expect(wrapper.get('input').attributes('aria-activedescendant')).toBeDefined();
    });
});
