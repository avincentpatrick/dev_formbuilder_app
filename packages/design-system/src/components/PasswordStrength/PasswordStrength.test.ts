import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import PasswordStrength from './PasswordStrength.vue';

/**
 * MdsPasswordStrength (J3b) — the live requirement checklist.
 *
 * Two halves, because two different things can break and only one of them is executable here.
 * The behavioural half is the component's contract with the published policy; the source-text half is a
 * layout guarantee that NOTHING in this repository can execute (see its own describe block).
 */

/** A miniature of what `PasswordPolicy::requirements()` actually ships, including the null row. */
const POLICY = [
    { key: 'min_length', label: '12 characters or more', pattern: '[\\s\\S]{12,}' },
    { key: 'mixed_case', label: 'An upper and a lower case letter', pattern: '(?=[\\s\\S]*\\p{Ll})(?=[\\s\\S]*\\p{Lu})' },
    { key: 'numbers', label: 'A number', pattern: '\\p{N}' },
    { key: 'uncompromised', label: 'Not found in a known data breach', pattern: null },
];

const rowStates = (wrapper: ReturnType<typeof mount>): string[] =>
    wrapper.findAll('.mds-pw__row').map((row) => {
        const state = row.classes().find((c) => c.startsWith('mds-pw__row--'));

        return state?.replace('mds-pw__row--', '') ?? 'unknown';
    });

describe('MdsPasswordStrength — it renders the server\'s list', () => {
    it('ticks exactly the rules the typed password satisfies', () => {
        const wrapper = mount(PasswordStrength, {
            props: { password: 'Abcdefghijkl', requirements: POLICY },
        });

        // 12 chars ✓, mixed case ✓, a number ✗, breach check is the server's.
        expect(rowStates(wrapper)).toEqual(['met', 'met', 'unmet', 'server']);
    });

    it('evaluates the patterns with the `u` flag, so a non-ASCII digit counts exactly as the server counts it', () => {
        // ⭐ THE CASE THAT MAKES THIS FILE ABLE TO FAIL AT ALL. `\p{N}` without the `u` flag is not
        // "match a digit" — it is a syntax error, so a missing flag falls into the catch and every
        // pattern row silently becomes a stated condition. An ASCII-only fixture cannot tell the two
        // apart, which is the drift `PasswordPolicyTest`'s own dataset comment calls out on the PHP side.
        const wrapper = mount(PasswordStrength, {
            props: { password: 'Abcdefghijk٣', requirements: POLICY },
        });

        expect(rowStates(wrapper)).toEqual(['met', 'met', 'met', 'server']);
    });

    it('counts only the rules this browser can decide', () => {
        const wrapper = mount(PasswordStrength, {
            // A 12-character mixed-case-plus-digit literal beside the word `password` is exactly the
            // shape gitleaks' generic-api-key rule looks for, and CI's secret scan flagged this one
            // line of the file. It is a fixture for a password STRENGTH checklist — it has to look
            // like a real password to be worth asserting on. The directive must sit on the SAME line
            // as the match; on the line above it does nothing, which is how the first attempt failed.
            props: { password: 'Abcdefghijk1', requirements: POLICY }, // gitleaks:allow
        });

        // Three of three, not three of four: the breach row is never tickable, so including it in the
        // denominator would announce a checklist that can never reach completion.
        expect(wrapper.find('.mds-pw__status').text()).toBe('3 of 3 password requirements met');
    });

    it('states the breach rule rather than hiding it or ticking it', () => {
        // ⚠️ IF THIS FAILS BECAUSE SOMEBODY GAVE `uncompromised` A PATTERN, DO NOT FIX THE TEST.
        // Evaluating it here would leak the SHA-1 prefix of a password being typed, per keystroke, to a
        // third party from the user's own IP. Hiding the row instead would make the checklist lie by
        // omission. `PasswordPolicyTest` holds the same line on the PHP side.
        const wrapper = mount(PasswordStrength, {
            props: { password: 'Abcdefghijk1!', requirements: POLICY },
        });

        const breach = wrapper.findAll('.mds-pw__row').at(3)!;

        expect(breach.classes()).toContain('mds-pw__row--server');
        expect(breach.text()).toContain('Not found in a known data breach');
    });

    it('degrades an uncompilable pattern to a stated condition instead of a false tick', () => {
        // A malformed pattern must not throw (this renders on every keystroke of a sign-up form) and must
        // not disappear. The dangerous failure is the third one: showing a tick for a rule nothing checked.
        const wrapper = mount(PasswordStrength, {
            props: {
                password: 'anything at all',
                requirements: [{ key: 'broken', label: 'A malformed rule', pattern: '(' }],
            },
        });

        expect(rowStates(wrapper)).toEqual(['server']);
        expect(wrapper.find('.mds-pw__status').text()).toBe('0 of 0 password requirements met');
    });

    it('gives every row a state in words, not only a glyph and a colour', () => {
        // Colour is never the only channel (WCAG 1.4.1) — but the glyph is `aria-hidden`, so without this
        // a screen-reader user hears four requirement labels and cannot tell which one is missing.
        const wrapper = mount(PasswordStrength, {
            props: { password: 'Abcdefghijkl', requirements: POLICY },
        });

        const spoken = wrapper.findAll('.mds-pw__sr').map((node) => node.text());

        expect(spoken).toEqual(['Met:', 'Met:', 'Not yet met:', 'Checked when you submit:']);
    });

    it('exposes the list under a call-site-composable id only when it is given one', () => {
        // `MdsFormField` composes `aria-describedby` from help + error only; the page concatenates this
        // id at the call site rather than the field being widened for one consumer.
        expect(
            mount(PasswordStrength, { props: { password: '', requirements: POLICY, inputId: 'pw-1' } })
                .find('.mds-pw__list')
                .attributes('id'),
        ).toBe('pw-1-strength');

        expect(
            mount(PasswordStrength, { props: { password: '', requirements: POLICY } })
                .find('.mds-pw__list')
                .attributes('id'),
        ).toBeUndefined();
    });

    it('announces politely, and only the summary', () => {
        const wrapper = mount(PasswordStrength, {
            props: { password: 'Abc', requirements: POLICY },
        });

        // `role="status"` is polite. `alert` would interrupt the user on every keystroke that changes the
        // count, in the middle of typing the very field it is describing.
        expect(wrapper.find('.mds-pw__status').attributes('role')).toBe('status');
    });
});

/**
 * ⚠️ THE CONTAINING-BLOCK GUARD — THE THIRD TIME THIS REPOSITORY HAS PAID FOR THIS EXACT DEFECT.
 *
 * `.mds-pw__sr` and `.mds-pw__status` are the `position: absolute` + `clip: rect(0 0 0 0)` visually-hidden
 * pattern. Absolute positioning resolves against the nearest POSITIONED ancestor, so without
 * `position: relative` on `.mds-pw` those nodes are laid out against whatever is positioned further up —
 * and no scroll container between this component and the document can clip them. A 1px hidden node parked
 * past a viewport edge extends the DOCUMENT's scrollable box.
 *
 * G11 found it horizontally on `MdsDataTable`; JR5 found it vertically on `MdsSegmentedControl`, where a
 * hidden `<legend>` sat 73px below the workspace and the page gained 73px of real scroll.
 *
 * ⚠️ SOURCE TEXT BECAUSE NOTHING HERE CAN EXECUTE IT. happy-dom computes no layout, so a mounted
 * assertion would pass whatever the CSS said; the e2e overflow assertion reads
 * `documentElement.scrollWidth`, which `.app-shell { overflow-x: clip }` pins flat; axe has no rule for
 * it. The idiom is `SegmentedControl.test.ts`'s, deliberately — a second instance of the same guard is
 * what makes it a pattern rather than a one-off.
 */
describe('MdsPasswordStrength — the visually-hidden nodes stay inside the component', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/PasswordStrength/PasswordStrength.vue'),
        'utf8',
    );

    /** The declaration block of a scoped-CSS rule, by selector. */
    const block = (selector: string): string =>
        source.match(new RegExp(`${selector.replace(/\./g, '\\.')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

    it('positions the container, so it is the containing block for both of them', () => {
        expect(block('.mds-pw')).toMatch(/position:\s*relative/);
    });

    it('still hides them the accessible way', () => {
        // `display: none` would remove the row states and the live summary from the accessibility tree
        // entirely — which is the whole reason those nodes exist. Matched directly rather than through
        // `block()`: the two share one selector group, and feeding a multi-selector string through that
        // helper's dot-escaping would corrupt the pattern rather than fail loudly.
        const hidden = source.match(/\.mds-pw__sr,\s*\.mds-pw__status\s*\{([^}]*)\}/)?.[1] ?? '';

        expect(hidden, 'the shared visually-hidden rule was not found at all').not.toBe('');
        expect(hidden).toMatch(/position:\s*absolute/);
        expect(hidden).toMatch(/clip:\s*rect\(0 0 0 0\)/);
        expect(hidden).not.toMatch(/display:\s*none/);
    });
});
