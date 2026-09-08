import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

import FormField from './FormField.vue';

/**
 * Increment M87 — the dangling `label[for]` a self-labelling group produces, and the scan that finds
 * the next one.
 *
 * ⛔ THE DEFECT, AS IT WAS FOUND. `MdsFormField` renders `<label :for="fieldId">` and hands `id` to
 * its slot so the page author binds it onto the input. `MdsSegmentedControl` cannot take it: it is a
 * `<fieldset>` with a visually-hidden `<legend>` and has no `id` prop at all. Both role pickers in
 * `resources/js/Pages/members/Index.vue` wrapped one anyway, so each rendered a `<label>` pointing at
 * an element that does not exist, beside a `<legend>` carrying the same word — a dangling association
 * and two competing name sources, on a control a workspace admin uses to change somebody's role.
 *
 * ⚠️ NOTHING IN THE STACK REPORTED IT, AND THAT IS THE INTERESTING PART. axe has no rule for a
 * `label[for]` matching nothing (it checks the converse — a control with no label). happy-dom computes
 * no layout, so the visual duplication is invisible to a unit test. Storybook's axe gate never mounts
 * an app page. `SheetsRuleFields.vue` had already met the same construction and left a comment about
 * it, which is a warning where a rule was needed: a comment guards the file it is in.
 *
 * ⚠️ SO THERE ARE TWO CASES HERE AND THE SECOND IS THE DURABLE ONE. The mounted pair pins the
 * component's behaviour; the source scan pins every CALL SITE, including ones written after this.
 */
describe('MdsFormField — a self-labelling group gets a span, not a dangling label', () => {
    it('associates its label with the slotted control by default', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email' },
            slots: { default: '<input data-test="control" />' },
        });

        const label = wrapper.find('label');
        expect(label.exists()).toBe(true);
        expect(label.attributes('for')).toBeTruthy();
        expect(label.text()).toContain('Email');
    });

    it('renders no label element at all when the control labels itself', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Role', groupLabel: true },
            slots: { default: '<fieldset><legend>Role</legend></fieldset>' },
        });

        // ⛔ ASSERTED AS AN ABSENCE, NOT AS "the for attribute is undefined". A `<label>` with no `for`
        // wraps its control implicitly — which this one does not do either, since the group is a
        // sibling — so the only honest assertion is that no `<label>` is emitted.
        expect(wrapper.find('label').exists()).toBe(false);
        expect(wrapper.find('.mds-field__label').text()).toContain('Role');
    });

    it('keeps the live error region in group mode, which is why the wrapper is kept at all', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Role', groupLabel: true, error: 'Pick a role.' },
            slots: { default: '<fieldset><legend>Role</legend></fieldset>' },
        });

        const region = wrapper.find('.mds-field__error');
        expect(region.attributes('aria-live')).toBe('polite');
        expect(region.text()).toContain('Pick a role.');
    });
});

describe('MdsFormField — no call site wraps a self-labelling group without saying so', () => {
    /** Components that render their own `<fieldset>`/`<legend>` and take no `id`. */
    const SELF_LABELLING = ['MdsSegmentedControl'];

    const ROOTS = ['resources/js', 'packages/design-system/src'];

    function vueFiles(dir: string): string[] {
        const out: string[] = [];
        for (const entry of readdirSync(dir)) {
            const full = join(dir, entry);
            if (statSync(full).isDirectory()) {
                out.push(...vueFiles(full));
            } else if (entry.endsWith('.vue')) {
                out.push(full);
            }
        }

        return out;
    }

    const files = ROOTS.flatMap((root) => vueFiles(join(process.cwd(), root)));

    it('scans a non-empty corpus, so the case below cannot pass blind', () => {
        // ⛔ THE FLOOR. A recursive walk that returns nothing makes the assertion below vacuous and
        // green — the exact shape this repository has paid for in three separate gates.
        expect(files.length).toBeGreaterThan(100);
        expect(files.some((f) => f.replace(/\\/g, '/').endsWith('resources/js/Pages/members/Index.vue'))).toBe(true);
    });

    it('marks every MdsFormField that wraps one with group-label', () => {
        const offenders: string[] = [];

        for (const file of files) {
            const source = readFileSync(file, 'utf8');

            // Each `<MdsFormField …>` opening tag through to its matching close, non-greedily.
            for (const block of source.matchAll(/<MdsFormField\b([^>]*)>([\s\S]*?)<\/MdsFormField>/g)) {
                const [, attrs, body] = block;

                if (! SELF_LABELLING.some((tag) => body.includes(`<${tag}`))) {
                    continue;
                }

                if (! /(^|\s)(group-label|:group-label|groupLabel)(\s|=|$)/.test(attrs)) {
                    offenders.push(file.replace(/\\/g, '/').split('/').slice(-3).join('/'));
                }
            }
        }

        expect(offenders).toEqual([]);
    });
});
