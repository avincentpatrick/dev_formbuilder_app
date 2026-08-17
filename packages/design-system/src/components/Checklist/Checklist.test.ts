import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { markRaw } from 'vue';
import Checklist from './Checklist.vue';
import type { ChecklistItem } from './Checklist.vue';

/**
 * MdsChecklist (DSR §3.13, J5b) — the getting-started list.
 *
 * The same two-kinds-of-assertion split as `Progress.test.ts` and `PasswordStrength.test.ts`, forced by the
 * same fact: happy-dom computes no layout and resolves no custom properties, so anything about COLOUR or
 * SIZE has to be read out of the component's own source text or it would pass whatever the stylesheet said.
 */
const items: ChecklistItem[] = [
    { key: 'create_form', label: 'Create your first form', description: 'From a template, or blank.', done: true },
    { key: 'publish_form', label: 'Publish it', done: true },
    { key: 'first_response', label: 'Collect your first response', done: false, href: '/forms' },
    { key: 'invite_teammate', label: 'Invite a teammate', done: false, href: '/members' },
];

describe('MdsChecklist — what it renders', () => {
    it('renders one row per item, in the order given', () => {
        const list = mount(Checklist, { props: { items } });
        const rows = list.findAll('.mds-checklist__row');

        expect(rows).toHaveLength(4);
        expect(rows[0].text()).toContain('Create your first form');
        expect(rows[3].text()).toContain('Invite a teammate');
    });

    it('carries role="list", because list-style: none strips list semantics in WebKit', () => {
        // ⭐ MUTATION TARGET, and the one whose loss is invisible in every browser the gates run in. Without
        // it VoiceOver announces four steps with no sense of how many there are — on the one surface whose
        // entire subject is how many there are. `MdsBreadcrumb` carries the identical attribute.
        expect(mount(Checklist, { props: { items } }).get('ul').attributes('role')).toBe('list');
    });

    it('states each row’s state in words, not only in a glyph and a colour', () => {
        // ⭐ WCAG 1.4.1. The check glyph is `aria-hidden`, and the done row's only other signal is its
        // colour — so deleting this span leaves a screen-reader user unable to tell a finished step from an
        // unfinished one, while every visual gate stays green.
        const list = mount(Checklist, { props: { items } });
        const rows = list.findAll('.mds-checklist__row');

        expect(rows[0].get('.mds-checklist__sr').text()).toBe('Done:');
        expect(rows[2].get('.mds-checklist__sr').text()).toBe('To do:');
    });

    it('marks a done row with the check glyph and a pending row with the ring', () => {
        const list = mount(Checklist, { props: { items } });
        const rows = list.findAll('.mds-checklist__row');

        expect(rows[0].find('.mds-checklist__mark--pending').exists()).toBe(false);
        expect(rows[0].get('.mds-checklist__mark').attributes('aria-hidden')).toBe('true');
        expect(rows[2].get('.mds-checklist__mark--pending').exists()).toBe(true);
    });
});

describe('MdsChecklist — the href contract', () => {
    it('links a row that has a destination', () => {
        const list = mount(Checklist, { props: { items } });

        expect(list.findAll('.mds-checklist__link').map((link) => link.attributes('href'))).toEqual([
            '/forms',
            '/members',
        ]);
    });

    it('renders a row with no destination as text, keeping its place in the list', () => {
        // ⭐ BOTH ROUTES TO A MISSING HREF END HERE, AND THE COMPONENT DELIBERATELY CANNOT TELL THEM APART: a
        // done step has nothing left to do, and a refused destination must not be offered as a link that
        // bounces. What matters is what it must NOT do — drop the row. Dropping it would make one workspace
        // show a different NUMBER of steps per role, which is `MdsBreadcrumb`'s argument for keeping a
        // refused crumb as text.
        const refused: ChecklistItem[] = items.map(({ href: _href, ...item }) => item);
        const list = mount(Checklist, { props: { items: refused } });

        expect(list.findAll('.mds-checklist__row')).toHaveLength(4);
        expect(list.find('.mds-checklist__link').exists()).toBe(false);
        expect(list.findAll('.mds-checklist__label')).toHaveLength(4);
    });

    it('renders links through the caller’s own link component', () => {
        // ⭐ The `MdsBreadcrumb` contract: the package imports nothing from the application's router, and an
        // Inertia app passes its `Link` so a row is a client visit rather than a document load. A regression
        // to a hard-coded anchor passes every other assertion in this file.
        // `markRaw` because a component definition handed in as a prop is otherwise wrapped in a reactive
        // proxy, which Vue warns about. Not a component defect — the app passes Inertia's `Link`, which is
        // already raw — but a test that prints a warning trains the next reader to ignore warnings.
        const Stub = markRaw({
            name: 'LinkStub',
            props: ['href'],
            template: '<a data-stub :href="href"><slot /></a>',
        });
        const list = mount(Checklist, { props: { items, linkComponent: Stub } });

        expect(list.findAll('[data-stub]')).toHaveLength(2);
    });
});

describe('MdsChecklist — the meter', () => {
    it('counts done rows against the total, in the unit a person counts in', () => {
        const list = mount(Checklist, { props: { items } });
        const bar = list.get('[role="progressbar"]');

        expect(bar.attributes('aria-valuenow')).toBe('2');
        expect(bar.attributes('aria-valuemax')).toBe('4');
        // ⭐ Not "50%". `aria-valuenow="2"` against `aria-valuemax="4"` is announced as a percentage unless
        // `aria-valuetext` says otherwise, and half of a four-item to-do list is not a thing anybody counts.
        expect(bar.attributes('aria-valuetext')).toBe('2 of 4');
    });

    it('names the meter with something other than the card’s own title', () => {
        // ⭐ `MdsProgress` renders its label unconditionally — by design, so "a bar alone is not sufficient"
        // cannot be opted out of. Passing the card title into it as well would print the same words twice.
        const list = mount(Checklist, { props: { items, label: 'Getting started' } });

        expect(list.get('.mds-checklist__title').text()).toBe('Getting started');
        expect(list.get('.mds-progress__label').text()).not.toBe('Getting started');
    });

    it('takes the region’s accessible name from the visible title', () => {
        const list = mount(Checklist, { props: { items, label: 'Set-up' } });
        const labelledBy = list.get('section').attributes('aria-labelledby');

        expect(labelledBy).toBeTruthy();
        expect(list.get(`#${labelledBy}`).text()).toBe('Set-up');
    });

    it('gives two checklists in the same app distinct title ids', () => {
        // ⚠️ The two MUST share one app: `useId()` counts per app instance, so two separate `mount()` calls
        // each restart the counter and produce the SAME id — a test written that way fails against a correct
        // implementation. The `Progress.test.ts` precedent, restated because it is easy to get wrong.
        const both = mount(
            {
                components: { Checklist },
                template: '<div><Checklist :items="items" /><Checklist :items="items" label="Other" /></div>',
                data: () => ({ items }),
            },
            { global: { components: { Checklist } } },
        );

        const [first, second] = both.findAll('section');

        expect(first.attributes('aria-labelledby')).toBeTruthy();
        expect(first.attributes('aria-labelledby')).not.toBe(second.attributes('aria-labelledby'));
    });
});

describe('MdsChecklist — dismissal, and what it does not do', () => {
    it('renders no dismiss control unless asked', () => {
        expect(mount(Checklist, { props: { items } }).find('.mds-checklist__dismiss').exists()).toBe(false);
    });

    it('emits dismiss and does NOT hide itself', () => {
        // ⭐ The `MdsAlert` / `MdsToast` contract: the component never owns the `v-if`. Whether a dismissal
        // survives a reload is a page decision every time — here the page answers it with a column on the
        // membership row, and a component that hid itself would make that answer unobservable.
        const list = mount(Checklist, { props: { items, dismissible: true } });

        list.get('.mds-checklist__dismiss').trigger('click');

        expect(list.emitted('dismiss')).toHaveLength(1);
        expect(list.find('.mds-checklist__list').exists()).toBe(true);
    });

    it('gives the dismiss control an accessible name, since it is a glyph', () => {
        const list = mount(Checklist, { props: { items, dismissible: true } });

        expect(list.get('.mds-checklist__dismiss').attributes('aria-label')).toBe(
            'Hide the getting-started checklist',
        );
    });

    it('renders nothing at all for an empty list', () => {
        // A checklist with no steps is not an empty state, it is a bug at the call site — and an empty
        // bordered card with a 0-of-0 meter is a worse rendering of it than nothing.
        expect(mount(Checklist, { props: { items: [] } }).find('section').exists()).toBe(false);
    });
});

describe('MdsChecklist — the contracts that live in source text', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/Checklist/Checklist.vue'),
        'utf8',
    );

    /**
     * ⚠️ SCOPED TO THE STYLESHEET, AND THEN TO A DECLARATION BLOCK, BECAUSE A WHOLE-FILE SCAN MATCHES THE
     * COMMENT EXPLAINING THE RULE. That has fired six times in this repository — most recently in J4c1,
     * where a guard against a CSS declaration matched the comment justifying it. The durable fix is
     * scoping, not rewording.
     */
    const stylesheet = source.slice(source.indexOf('<style'));

    /**
     * ⚠️ THE STYLESHEET WITH ITS COMMENTS REMOVED, AND THIS EXISTS BECAUSE THE FIRST VERSION OF THIS SUITE
     * FAILED AGAINST A CORRECT IMPLEMENTATION — SEVENTH OCCURRENCE OF *NAME THE THING, NEVER QUOTE IT* IN
     * THIS REPOSITORY, AND THE THIRD WHERE THE NOTE EXPLAINING A RULE IS WHAT BROKE IT.
     *
     * The overflow guard below scans for a declaration this component must never carry. A CSS comment was
     * then added telling the next author not to wrap the component in a box carrying that same
     * declaration — and the guard matched the warning. Rewording the comment would "fix" it until the next
     * person needed to write the words down again, so the fix is the one `token-references.test.ts` and
     * J4c1 both landed on: **scope the scan to the region the contract actually lives in.** Declarations
     * are the contract; prose about declarations is not.
     */
    const declarations = stylesheet.replace(/\/\*[\s\S]*?\*\//g, '');

    const block = (selector: string): string =>
        declarations.match(new RegExp(`${selector.replace(/\./g, '\\.')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

    it('colours a done row with an -fg token, never a -bg one', () => {
        // ⭐ DSR §3.4's standing rule for any coloured rule, edge or indicator. The project has paid for this
        // substitution three times (J2a's tab strip, J4a's accent bar, J4c1's underline at 2.41:1 in teal
        // dark). A "tidy" to the `-bg` half must redden here, because no gate in the repo can measure it.
        expect(block('.mds-checklist__row--done')).toMatch(/color:\s*var\(--mds-color-status-success-fg\)/);
        expect(declarations).not.toContain('status-success-bg');
    });

    it('establishes its own containing block for the clipped state words', () => {
        // ⭐ The fourth instance of a defect no gate here can execute: `position: absolute` + the clip idiom
        // resolves against the nearest POSITIONED ancestor, so without this the four 1px nodes are parked
        // against whatever is positioned further up and can extend the DOCUMENT's scroll box.
        // `clipped-node-containment.test.ts` holds the whole tree to it; this states the reason locally, so
        // it travels with the component.
        expect(block('.mds-checklist')).toMatch(/position:\s*relative/);
        expect(declarations).toContain('clip: rect(0 0 0 0)');
    });

    it('sizes the pending ring in pixels, to match the glyph that replaces it', () => {
        // ⭐ `MdsIcon`'s `sm` is a fixed 16×16 box. A ring sized in `em` resolves against `body-sm` and comes
        // out narrower, so every label would shift sideways the moment a row ticks over — on rows that tick
        // over while somebody is looking at them. The `MdsPasswordStrength` finding, inherited deliberately.
        expect(block('.mds-checklist__mark--pending')).toMatch(/width:\s*16px/);
        expect(block('.mds-checklist__mark--pending')).toMatch(/height:\s*16px/);
    });

    it('expands the dismiss control’s hit area rather than inflating its glyph', () => {
        // WCAG 2.5.8 — the visual box is 24px; `MdsAlert` and `MdsToast` use the identical construction.
        expect(block('.mds-checklist__dismiss::before')).toMatch(/min-width:\s*44px/);
        expect(block('.mds-checklist__dismiss::before')).toMatch(/min-height:\s*44px/);
    });

    it('does not scroll: neither axis is given an overflow of its own', () => {
        // ⭐ J4c1's finding, fifth instance of its class: `overflow-x: auto` with the other axis unset
        // COERCES `overflow-y` to `auto` (CSS Overflow 3), and one negative margin or one 1px border then
        // mints a scroll container nothing in this repository can see — happy-dom lays nothing out, the e2e
        // assertion reads a document box pinned flat by the app shell, and axe stays silent about a region
        // whose children are all focusable. A column of rows has no reason to want either axis.
        expect(declarations).not.toMatch(/overflow(-x|-y)?:\s*(auto|scroll)/);
    });
});
