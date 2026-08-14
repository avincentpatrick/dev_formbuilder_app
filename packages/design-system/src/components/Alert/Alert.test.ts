import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import Alert, { type AlertTone } from './Alert.vue';
import { icons, type IconName } from '../Icon/icons';

/**
 * MdsAlert (DSR §3.7a, J4a) — the in-flow contextual message, and `MdsBanner`'s sibling.
 *
 * The substance of this component is the politeness fork and the fact that it never hides itself. Both are
 * the kind of thing a later refactor "simplifies" without noticing: an alert that always shouts is
 * indistinguishable from a correct one until somebody uses a screen reader, and an alert that hides itself
 * looks better in isolation and quietly takes the decision away from the page.
 */
describe('MdsAlert — the live-region contract', () => {
    it('is polite by default and assertive only when asked', () => {
        // ⭐ BOTH DIRECTIONS. Four admin migrations depend on `alert` (three Vitest suites assert
        // `[role="alert"]` by selector), while every notice that was already true on page load depends on
        // `status`. A component hard-wired to either one breaks half its call sites silently.
        const polite = mount(Alert, { props: { message: 'Rules run against the published form.' } });
        expect(polite.attributes('role')).toBe('status');

        const assertive = mount(Alert, { props: { assertive: true, message: 'That failed.' } });
        expect(assertive.attributes('role')).toBe('alert');
    });

    it('maps each tone to a glyph, and lets a call site override it', () => {
        // ⭐ Mutation target: collapsing the map to a constant leaves colour as the only channel separating
        // an info alert from a danger one, which is the WCAG 1.4.1 failure the map exists to prevent.
        //
        // ⚠️ The icon NAME never reaches the DOM — `MdsIcon` renders one `path` whose `d` comes from the
        // registry, so a `toContain('check')` on the markup would pass or fail for reasons unrelated to the
        // glyph. Comparing against `icons[...]` is the only assertion here that means what it says.
        const expected: Array<[AlertTone, IconName]> = [
            ['info', 'info'],
            ['success', 'check'],
            ['warning', 'alert'],
            ['danger', 'alert'],
        ];

        for (const [tone, icon] of expected) {
            const wrapper = mount(Alert, { props: { tone, message: 'x' } });
            expect(wrapper.get('.mds-alert__icon path').attributes('d')).toBe(icons[icon]);
        }

        // Distinct glyphs, or the map would be satisfied by a registry where every icon is the same path.
        expect(icons.info).not.toBe(icons.alert);
        expect(icons.check).not.toBe(icons.alert);

        const overridden = mount(Alert, { props: { tone: 'warning', icon: 'shield', message: 'x' } });
        expect(overridden.get('.mds-alert__icon path').attributes('d')).toBe(icons.shield);
    });

    it('hides the glyph from assistive tech, because the words already carry the tone', () => {
        const wrapper = mount(Alert, { props: { tone: 'danger', message: 'Something failed.' } });

        expect(wrapper.get('.mds-alert__icon').attributes('aria-hidden')).toBe('true');
    });

    it('renders the title as a paragraph, never a heading', () => {
        // ⭐ An alert cannot know its own heading level, and a wrong one breaks §4.1's heading-order rule.
        // `role="alert"`/`status` both imply `aria-atomic`, so a heading buys nothing here anyway.
        const wrapper = mount(Alert, { props: { title: 'Imported with warnings', message: 'Three rows.' } });

        expect(wrapper.get('.mds-alert__title').element.tagName).toBe('P');
        expect(wrapper.findAll('h1,h2,h3,h4,h5,h6')).toHaveLength(0);
    });

    it('renders title, message and slot body together, in that order', () => {
        const wrapper = mount(Alert, {
            props: { title: 'Imported with warnings', message: 'Three rows could not be matched.' },
            slots: { default: '<ul><li>Row 4</li></ul>' },
        });

        const text = wrapper.get('.mds-alert__body').text();
        expect(text.indexOf('Imported with warnings')).toBeLessThan(text.indexOf('Three rows'));
        expect(text.indexOf('Three rows')).toBeLessThan(text.indexOf('Row 4'));
    });

    it('omits the actions wrapper when no actions are given', () => {
        const without = mount(Alert, { props: { message: 'x' } });
        expect(without.find('.mds-alert__actions').exists()).toBe(false);

        const withActions = mount(Alert, {
            props: { message: 'x' },
            slots: { actions: '<button type="button">Reconnect</button>' },
        });
        expect(withActions.get('.mds-alert__actions button').text()).toBe('Reconnect');
    });
});

describe('MdsAlert — dismissal belongs to the call site', () => {
    it('renders no dismiss control unless asked', () => {
        const wrapper = mount(Alert, { props: { message: 'A standing fact.' } });

        expect(wrapper.find('.mds-alert__dismiss').exists()).toBe(false);
    });

    it('emits once and does NOT remove itself', async () => {
        // ⭐ THE HALF A NAIVE IMPLEMENTATION GETS WRONG. Self-hiding looks tidier and silently takes the
        // decision away from the page — whether a dismissal survives a reload, a navigation or the session
        // is a different answer every time, which is why `MdsToast` draws the same line.
        const wrapper = mount(Alert, {
            props: { message: 'Imported with warnings.', dismissible: true, dismissLabel: 'Dismiss warnings' },
        });

        await wrapper.get('.mds-alert__dismiss').trigger('click');

        expect(wrapper.emitted('dismiss')).toHaveLength(1);
        expect(wrapper.find('.mds-alert').exists()).toBe(true);
        expect(wrapper.get('.mds-alert__dismiss').attributes('aria-label')).toBe('Dismiss warnings');
    });

    it('gives the dismiss control a default accessible name', () => {
        const wrapper = mount(Alert, { props: { message: 'x', dismissible: true } });

        expect(wrapper.get('.mds-alert__dismiss').attributes('aria-label')).toBe('Dismiss');
    });
});

describe('MdsAlert — the colour contract, held in source text', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/Alert/Alert.vue'),
        'utf8',
    );

    // ⚠️ Scoped to the stylesheet, never the whole file — the docblock discusses these token families, and
    // a whole-file negative assertion would match the prose that exists to justify it. `Progress.test.ts`
    // records the four assertions that failed against a correct implementation before this was understood.
    const stylesheet = source.slice(source.indexOf('<style'));

    const block = (selector: string): string =>
        stylesheet.match(new RegExp(`${selector.replace(/\./g, '\\.')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

    it('paints all four tones from the status scale, which is contrast-tested in both themes', () => {
        // The same pairs `MdsBanner` uses, so `theme-overrides.test.ts`'s existing measurements cover this
        // component too. A hand-picked hex here would be unmeasured by anything.
        for (const tone of ['info', 'success', 'warning', 'danger']) {
            expect(block(`.mds-alert--${tone}`)).toMatch(
                new RegExp(`background-color:\\s*var\\(--mds-color-status-${tone}-bg\\)`),
            );
            expect(block(`.mds-alert--${tone}`)).toMatch(
                new RegExp(`color:\\s*var\\(--mds-color-status-${tone}-fg\\)`),
            );
        }
    });

    it('expands the dismiss hit area rather than inflating the glyph', () => {
        // §4.4: 44×44 minimum at every breakpoint. The visual box stays 24px.
        expect(block('.mds-alert__dismiss::before')).toMatch(/min-width:\s*44px/);
        expect(block('.mds-alert__dismiss::before')).toMatch(/min-height:\s*44px/);
        // The pseudo-element is absolutely positioned, so the button must be its containing block — the
        // G11/JR5 defect, in the one place this component could reproduce it.
        expect(block('.mds-alert__dismiss')).toMatch(/position:\s*relative/);
    });

    it('keeps the focus ring on the dismiss control', () => {
        expect(block('.mds-alert__dismiss:focus-visible')).toMatch(
            /outline:\s*2px solid var\(--mds-color-focus-ring\)/,
        );
    });

    it('hides nothing with the clip idiom, so it needs no containing-block guard', () => {
        expect(stylesheet).not.toContain('clip: rect(0 0 0 0)');
    });
});
