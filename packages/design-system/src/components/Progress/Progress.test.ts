import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import Progress from './Progress.vue';

/**
 * MdsProgress (DSR §3.9, J4a) — the determinate bar.
 *
 * Two kinds of assertion live here and the split is forced rather than stylistic. The ARIA contract is
 * mountable, so it is mounted. The COLOUR contract is not: happy-dom computes no layout and resolves no
 * custom properties, so `getComputedStyle(...).backgroundColor` returns an empty string and a mounted
 * assertion about the fill would pass whatever the stylesheet said. Those are read out of the component's
 * own source text, which is the idiom `SegmentedControl.test.ts` established and `PasswordStrength.test.ts`
 * followed.
 */
describe('MdsProgress — the ARIA contract', () => {
    it('reports the caller’s own value, not a percentage', () => {
        // ⭐ `resources/js/Pages/forms/index.test.ts:238` asserts `aria-valuenow === '58'` for a form with 58
        // of 100 responses used. The number a screen reader hears is the count, and `aria-valuemax` is the
        // cap — a component that helpfully normalised to 0..100 would silently rewrite both.
        const bar = mount(Progress, { props: { label: 'Capacity', value: 58, max: 100 } });
        const track = bar.get('[role="progressbar"]');

        expect(track.attributes('aria-valuenow')).toBe('58');
        expect(track.attributes('aria-valuemin')).toBe('0');
        expect(track.attributes('aria-valuemax')).toBe('100');
    });

    it('clamps a value outside the range instead of painting past the track', () => {
        // ⭐ Mutation target: a pass-through would let `aria-valuenow` exceed `aria-valuemax`, which is an
        // invalid ARIA state, and would compute a fill wider than 100%.
        const over = mount(Progress, { props: { label: 'Capacity', value: 150, max: 100 } });
        expect(over.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('100');

        const under = mount(Progress, { props: { label: 'Capacity', value: -5, max: 100 } });
        expect(under.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('0');
    });

    it('treats a non-finite value as nothing done, rather than rendering NaN', () => {
        // A division by a zero cap at the call site arrives here as Infinity or NaN. Both would reach the
        // DOM as the literal string, and `width: NaN%` is an invalid declaration the browser drops — so the
        // bar would look 0% while announcing garbage.
        //
        // ⚠️ Infinity reads 0, NOT the maximum, and that is deliberate rather than an oversight in the
        // clamp. Clamping it to `max` would render a full bar and announce "complete" for what is always a
        // caller bug; 0 says "nothing measured yet", which is the honest reading of an unknown.
        const nan = mount(Progress, { props: { label: 'Capacity', value: Number.NaN, max: 100 } });
        expect(nan.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('0');

        const infinite = mount(Progress, { props: { label: 'Capacity', value: Number.POSITIVE_INFINITY } });
        expect(infinite.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('0');
    });

    it('cannot divide by a zero maximum', () => {
        const bar = mount(Progress, { props: { label: 'Capacity', value: 3, max: 0 } });

        expect(bar.get('[role="progressbar"]').attributes('style')).toContain('--progress-fill: 0%');
        expect(bar.get('.mds-progress__value').text()).toBe('0%');
    });

    it('takes its accessible name from the visible label, so the two cannot drift', () => {
        const bar = mount(Progress, { props: { label: 'Capacity', value: 58 } });
        const labelledBy = bar.get('[role="progressbar"]').attributes('aria-labelledby');

        expect(labelledBy).toBeTruthy();
        expect(bar.get(`#${labelledBy}`).text()).toBe('Capacity');
        // WCAG 2.5.3 is about the name CONTAINING the visible text; here they are the same node, so there
        // is nothing to keep in sync. A regression to `aria-label` would break exactly this assertion.
        expect(bar.get('[role="progressbar"]').attributes('aria-label')).toBeUndefined();
    });

    it('gives two bars in the same app distinct label ids', () => {
        // ⭐ A `useId()` collapsed to a constant would point every bar's `aria-labelledby` at the first
        // label in the document — every meter on `/forms` would announce the same form's name.
        //
        // ⚠️ The two bars MUST share one app. `useId()` counts per app instance, so two separate `mount()`
        // calls each start the counter afresh and produce the SAME id — a test written that way fails
        // against a correct implementation and tells you nothing about the one thing it exists to catch.
        const both = mount(
            {
                components: { Progress },
                template:
                    '<div><Progress label="Capacity" :value="1" /><Progress label="Storage" :value="2" /></div>',
            },
            { global: { components: { Progress } } },
        );

        const [first, second] = both.findAll('[role="progressbar"]');

        expect(first.attributes('aria-labelledby')).toBeTruthy();
        expect(first.attributes('aria-labelledby')).not.toBe(second.attributes('aria-labelledby'));
    });

    it('renders the numeric value for every prop combination, because a bar alone is not sufficient', () => {
        // ⭐ THE ASSERTION THAT HOLDS §3.9. The component has no `labelHidden` prop and the value span has
        // no `v-if` — mutate it to `v-if="valueText"` and this reddens. Without it the rule is a sentence
        // in a document rather than a property of the API.
        const derived = mount(Progress, { props: { label: 'Export', value: 40, max: 80 } });
        expect(derived.get('.mds-progress__value').text()).toBe('50%');
        expect(derived.get('[role="progressbar"]').attributes('aria-valuetext')).toBe('50%');

        const explicit = mount(Progress, {
            props: { label: 'Capacity', value: 58, max: 250, valueText: '58 / 250' },
        });
        expect(explicit.get('.mds-progress__value').text()).toBe('58 / 250');
        // ⭐ The announced text and the seen text are one string. A percentage announced over a count is
        // the defect `aria-valuetext` exists to prevent.
        expect(explicit.get('[role="progressbar"]').attributes('aria-valuetext')).toBe('58 / 250');
    });

    it('carries the fill width as a component-local custom property', () => {
        // The computed width is unreachable here (happy-dom resolves no custom properties), so this pins
        // the number that CSS consumes rather than the geometry it produces.
        const bar = mount(Progress, { props: { label: 'Capacity', value: 58, max: 100 } });

        expect(bar.get('[role="progressbar"]').attributes('style')).toContain('--progress-fill: 58%');
    });

    it('defaults to the sm size and the default tone', () => {
        const bar = mount(Progress, { props: { label: 'Capacity', value: 1 } });

        expect(bar.classes()).toContain('mds-progress--sm');
        expect(bar.get('.mds-progress__fill').classes()).toContain('mds-progress__fill--default');
    });
});

describe('MdsProgress — the colour contract, held in source text', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/Progress/Progress.vue'),
        'utf8',
    );

    /**
     * ⚠️ EVERY NEGATIVE ASSERTION BELOW RUNS AGAINST THE STYLESHEET, NEVER THE WHOLE FILE, AND THE FIRST
     * DRAFT OF THIS SUITE PROVED WHY.
     *
     * Three of these guards forbid a token name. The component's docblock explains each of those rules —
     * and therefore spells the forbidden name — so a whole-file `not.toContain` matched the prose that
     * exists to justify the guard. Four assertions failed against a correct implementation, and the only
     * way to "fix" them at the file level would have been to delete the explanation.
     *
     * This is the same defect gitleaks caught three times on this branch, where a tracker entry describing
     * a secret-scanner match reproduced the match by quoting it. The general form: a guard that scans text
     * must scan the region the contract lives in, or documenting the contract violates it.
     */
    const stylesheet = source.slice(source.indexOf('<style'));

    /** The declaration block of a scoped-CSS rule, by selector. */
    const block = (selector: string): string =>
        stylesheet.match(new RegExp(`${selector.replace(/\./g, '\\.')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

    it('fills with an -fg token, never a -bg one', () => {
        // ⭐ DSR §3.4's standing rule for any coloured rule, edge or indicator. A `-bg` token guarantees
        // only that text placed ON it is readable — it says nothing about the fill against its own track,
        // and the meter this component replaces measured 3.95:1 in dark for exactly that reason. A "tidy"
        // back to `action-primary-bg` must redden here, because no gate in the repo can measure it.
        expect(block('.mds-progress__fill--default')).toMatch(
            /background-color:\s*var\(--mds-color-action-primary-fg\)/,
        );
        expect(block('.mds-progress__fill--warning')).toMatch(
            /background-color:\s*var\(--mds-color-status-warning-fg\)/,
        );
        expect(stylesheet).not.toContain('--mds-color-action-primary-bg');
    });

    it('runs the track on the sunken surface the meter it replaces used', () => {
        expect(block('.mds-progress__track')).toMatch(/background-color:\s*var\(--mds-color-bg-sunken\)/);
        expect(block('.mds-progress__track')).toMatch(/border-radius:\s*var\(--mds-radius-full\)/);
    });

    it('keeps its fill variable unprefixed, so the token gate ignores it', () => {
        // ⭐ `token-references.test.ts` scans every file for prefixed `var()` references and fails on any
        // name that is not a real token. It never evaluates a fallback, so giving this component's fill
        // variable the design-system prefix would red-light the whole package for a value that is not a
        // token and was never meant to be.
        //
        // ⚠️ AND THIS COMMENT IS NOT ALLOWED TO SPELL THE PREFIXED NAME, WHICH IS THE THIRD TIME THIS EXACT
        // DEFECT HAS FIRED IN THIS REPOSITORY AND THE FIRST TIME IN THIS GATE. The first draft explained the
        // rule by quoting the forbidden spelling — and the scanner found it here, in the sentence warning
        // against it. That scanner strips BLOCK comments before matching, so the component's own docblock
        // may discuss it freely; a line comment like this one is scanned as source. gitleaks did the same
        // thing twice on `PROGRESS_ARCHIVE.md`, where a lesson about a secret-scanner match reproduced the
        // match. The general rule, stronger than "add a directive": name the thing, never quote it.
        expect(stylesheet).toContain('var(--progress-fill, 0%)');
        expect(stylesheet).not.toMatch(new RegExp(`--${'mds'}-progress-`));
        // Nothing here may ASSIGN an --mds-* property either; those belong to the token build.
        expect(stylesheet.replace(/var\([^)]*\)/g, '')).not.toMatch(/--mds-[\w-]+\s*:/);
    });

    it('hides nothing with the clip idiom, so it needs no containing-block guard', () => {
        // The tree-wide `clipped-node-containment.test.ts` requires `packages/**` offenders to be exactly
        // []. Stated locally so the reason travels with the component rather than living only in a
        // whole-tree gate that names no cause.
        expect(stylesheet).not.toContain('clip: rect(0 0 0 0)');
    });

    it('stops animating the fill under reduced motion', () => {
        expect(stylesheet).toMatch(/@media\s*\(prefers-reduced-motion:\s*reduce\)/);
    });
});
