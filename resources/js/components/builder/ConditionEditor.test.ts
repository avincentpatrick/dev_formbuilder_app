/**
 * The condition editor through the rendered DOM (Increment H21d2).
 *
 * The load-bearing group is the FIRST one. Doc #27 §8 specifies that a non-representable expression renders
 * read-only and is never rewritten, and §9 asks for that as an explicit test — so it is asserted the only
 * way that means anything: mount over every shape the editor will ever meet, including every canonical
 * string it can itself produce, and require that NOTHING is emitted. A test that only checked the opaque
 * case would pass against a component that quietly re-printed every describable condition on open.
 *
 * No store double is needed: this component takes none. `ConfigPanel` owns the catalogue and the
 * `store.touch()` call, which is what keeps the write on the existing optimistic-concurrency path.
 */

import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe as group, expect, it } from 'vitest';

import ConditionEditor from './ConditionEditor.vue';
import type { ConditionCatalogue } from './types';

const fixture: { name: string; text: string }[] = JSON.parse(
    readFileSync(join(process.cwd(), 'tests', 'fixtures', 'condition-serializer.json'), 'utf-8'),
);

const catalogue: ConditionCatalogue = {
    fields: [
        { key: 'age', label: 'Your age', numeric: true, options: [] },
        {
            key: 'tier',
            label: 'Membership tier',
            numeric: false,
            options: [
                { value: 'gold', label: 'Gold' },
                { value: 'silver', label: 'Silver' },
            ],
        },
        { key: 'notes', label: 'Notes', numeric: false, options: [] },
        { key: 'end', label: 'End date', numeric: false, options: [] },
        { key: 'start', label: 'Start date', numeric: false, options: [] },
        { key: 'colours', label: 'Colours', numeric: false, options: [{ value: 'red', label: 'Red' }] },
        { key: 'surname', label: 'Surname', numeric: false, options: [] },
        { key: 'note', label: 'A note', numeric: false, options: [] },
        { key: 'balance', label: 'Balance', numeric: true, options: [] },
        { key: 'score', label: 'Score', numeric: true, options: [] },
        { key: 'a', label: 'A', numeric: true, options: [] },
        { key: 'b', label: 'B', numeric: true, options: [] },
        { key: 'c', label: 'C', numeric: true, options: [] },
        { key: 'd', label: 'D', numeric: true, options: [] },
        { key: 'n', label: 'N', numeric: true, options: [] },
    ],
    repeatables: [{ key: 'roster', label: 'Household members' }],
};

function mountEditor(expression: string | null) {
    return mount(ConditionEditor, { props: { expression, catalogue, legend: 'Show this question only when…' } });
}

function control(wrapper: ReturnType<typeof mountEditor>, label: string) {
    return wrapper.get(`[aria-label="${label}"]`);
}

/** Text-labelled buttons ("Add condition"); an ICON button carries its name in `aria-label` instead. */
function button(wrapper: ReturnType<typeof mountEditor>, text: string) {
    const found = wrapper
        .findAll('button')
        .find((candidate) => candidate.text() === text || candidate.attributes('aria-label') === text);
    if (found === undefined) throw new Error(`no button labelled «${text}»`);

    return found;
}

/** Every emitted value, flattened — the assertion is almost always about the LAST one, or about none. */
function emitted(wrapper: ReturnType<typeof mountEditor>): (string | null)[] {
    return ((wrapper.emitted('update:expression') as [string | null][] | undefined) ?? []).map(([value]) => value);
}

group('a condition it cannot represent is read-only and is NEVER rewritten', () => {
    const shapes: (string | null)[] = [
        null,
        '',
        '   ',
        ...fixture.map((c) => c.text),
        // The three states H21d1's rail can draw that this editor must not touch.
        '${age} + 1 > 18',
        "not(${age} > 18 and ${tier} = 'gold')",
        '${age} = = 1',
        // …and two shapes that are describable but NOT written the way the printer would write them:
        // extra spacing and redundant parentheses. Opening one must not tidy it up.
        '${age}   >   18',
        '(${age} > 18)',
    ];

    it.each(shapes.map((s) => [JSON.stringify(s), s] as const))('mounting over %s emits nothing', (_label, expression) => {
        const wrapper = mountEditor(expression);

        expect(wrapper.emitted('update:expression')).toBeUndefined();
    });

    it('does not mount the structured rows over an opaque or an invalid condition', () => {
        for (const expression of ['${age} + 1 > 18', '${age} = = 1']) {
            const wrapper = mountEditor(expression);

            expect(wrapper.find('[aria-label="Condition 1 subject"]').exists()).toBe(false);
            expect(wrapper.get('textarea').element.value).toBe(expression);
        }
    });

    it('mounts the rows over a describable one, so the case above is a fallback and not what it always does', () => {
        // Anti-vacuity. Without this, a component that rendered a textarea unconditionally would satisfy
        // every assertion in this group.
        const wrapper = mountEditor('${age} > 18');

        expect(wrapper.find('[aria-label="Condition 1 subject"]').exists()).toBe(true);
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('keeps an opaque condition EDITABLE — read-only constrains the rows, not the author', () => {
        const wrapper = mountEditor('${age} + 1 > 18');

        expect(wrapper.get('textarea').attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain('never rewritten');
    });

    it('writes raw text back verbatim, without canonicalising it', async () => {
        const wrapper = mountEditor('${age} + 1 > 18');
        await wrapper.get('textarea').setValue('${age}  +  2   >   18');

        expect(emitted(wrapper)).toEqual(['${age}  +  2   >   18']);
    });

    it('says a condition that does not parse will be refused at publish', () => {
        expect(mountEditor('${age} = = 1').text()).toContain('publishing will refuse it');
    });
});

group('loading a describable condition into rows', () => {
    it('reads a chain into one row per clause', () => {
        const wrapper = mountEditor("${age} > 18 and ${tier} = 'gold'");

        expect((control(wrapper, 'Group 1. match').element as HTMLSelectElement).value).toBe('and');
        expect((control(wrapper, 'Condition 1 subject').element as HTMLSelectElement).value).toBe('field:age');
        expect((control(wrapper, 'Condition 1 operator').element as HTMLSelectElement).value).toBe('gt');
        expect((control(wrapper, 'Condition 1 value').element as HTMLInputElement).value).toBe('18');
        expect((control(wrapper, 'Condition 2 subject').element as HTMLSelectElement).value).toBe('field:tier');
        expect((control(wrapper, 'Condition 2 value').element as HTMLInputElement).value).toBe('gold');
    });

    it('shows the same sentence the logic rail shows', () => {
        // One reading, one source. If these two ever diverge, one of them is lying about the same text.
        expect(mountEditor('${age} > 18').text()).toContain('Shown when Your age is more than 18.');
    });

    it('nests a group inside a group, with its own ordinal path', () => {
        const wrapper = mountEditor("(${age} > 18 or ${age} < 5) and ${tier} = 'gold'");

        expect((control(wrapper, 'Group 1. match').element as HTMLSelectElement).value).toBe('and');
        expect((control(wrapper, 'Group 1. match').element as HTMLSelectElement).value).toBe('and');
        expect((control(wrapper, 'Condition 1.1 subject').element as HTMLSelectElement).value).toBe('field:age');
        expect((control(wrapper, 'Condition 1.2 operator').element as HTMLSelectElement).value).toBe('lt');
        expect((control(wrapper, 'Condition 2 subject').element as HTMLSelectElement).value).toBe('field:tier');
    });

    it('reads a count row against a repeat section', () => {
        const wrapper = mountEditor('count(${roster}) > 0');

        expect((control(wrapper, 'Condition 1 subject').element as HTMLSelectElement).value).toBe('count:roster');
        expect((control(wrapper, 'Condition 1 operator').element as HTMLSelectElement).value).toBe('gt');
        expect((control(wrapper, 'Condition 1 entry count').element as HTMLInputElement).value).toBe('0');
    });

    it('offers a membership row the field’s own choices rather than a text box', () => {
        const wrapper = mountEditor("selected(${colours}, 'red')");
        const option = control(wrapper, 'Condition 1 option');

        expect(option.element.tagName).toBe('SELECT');
        expect((option.element as HTMLSelectElement).value).toBe('red');
        expect((control(wrapper, 'Condition 1 operator').element as HTMLSelectElement).value).toBe('includes');
    });

    it('falls back to a text box when the named field has no choices', () => {
        // Anti-vacuity for the case above, and the real case of a `selected()` against a key the catalogue
        // does not carry at all.
        expect(control(mountEditor("selected(${notes}, 'x')"), 'Condition 1 option').element.tagName).toBe('INPUT');
    });

    it('reads an emptiness test as its own operator, with no value control', () => {
        const wrapper = mountEditor("${notes} = ''");

        expect((control(wrapper, 'Condition 1 operator').element as HTMLSelectElement).value).toBe('blank');
        expect(wrapper.find('[aria-label="Condition 1 value"]').exists()).toBe(false);
    });

    it('reads a reversed comparison without swapping it back', () => {
        // The describable set does not constrain operand order, so neither may this. A row that rendered
        // `18 < ${age}` as `${age} > 18` would be the silent rewrite §8 calls the disqualifier.
        const wrapper = mountEditor('18 < ${age}');

        expect((control(wrapper, 'Condition 1 subject').element as HTMLSelectElement).value).toBe('literal');
        expect((control(wrapper, 'Condition 1 subject value').element as HTMLInputElement).value).toBe('18');
        expect((control(wrapper, 'Condition 1 compared with').element as HTMLSelectElement).value).toBe('field:age');
    });
});

group('editing writes canonical text, once', () => {
    it('emits exactly one canonical string per change', async () => {
        const wrapper = mountEditor('${age} > 18');
        await control(wrapper, 'Condition 1 value').setValue('21');

        expect(emitted(wrapper)).toEqual(['${age} > 21']);
    });

    it('changes the shape of the row when the operator changes', async () => {
        const wrapper = mountEditor('${age} > 18');
        await control(wrapper, 'Condition 1 operator').setValue('blank');

        expect(emitted(wrapper)).toEqual(["${age} = ''"]);
    });

    it('flips a membership row to its negation', async () => {
        const wrapper = mountEditor("selected(${colours}, 'red')");
        await control(wrapper, 'Condition 1 operator').setValue('excludes');

        expect(emitted(wrapper)).toEqual(["not(selected(${colours}, 'red'))"]);
    });

    it('keeps a nested group’s parentheses when a leaf inside it changes', async () => {
        const wrapper = mountEditor("(${age} > 18 or ${age} < 5) and ${tier} = 'gold'");
        await control(wrapper, 'Condition 1.2 value').setValue('6');

        expect(emitted(wrapper)).toEqual(["(${age} > 18 or ${age} < 6) and ${tier} = 'gold'"]);
    });

    it('switches the whole group between all-of and any-of', async () => {
        const wrapper = mountEditor("${age} > 18 and ${tier} = 'gold'");
        await control(wrapper, 'Group 1. match').setValue('or');

        expect(emitted(wrapper)).toEqual(["${age} > 18 or ${tier} = 'gold'"]);
    });

    it('emits NOTHING when a control is set to the value it already had', async () => {
        // Idempotence at the seam. Without it, every re-selection would bump the row's `version` and burn
        // an undo entry for a change that is not one.
        const wrapper = mountEditor('${age} > 18');
        await control(wrapper, 'Condition 1 operator').setValue('gt');

        expect(wrapper.emitted('update:expression')).toBeUndefined();
    });

    it('writes null — not an empty string — when the last row is removed', async () => {
        const wrapper = mountEditor('${age} > 18');
        await button(wrapper, 'Remove condition 1').trigger('click');

        expect(emitted(wrapper)).toEqual([null]);
    });

    it('holds a half-built row on screen and writes nothing until it is finished', async () => {
        const wrapper = mountEditor('${age} > 18');
        await button(wrapper, 'Add condition').trigger('click');

        expect(wrapper.find('[aria-label="Condition 2 subject"]').exists()).toBe(true);
        expect(wrapper.emitted('update:expression')).toBeUndefined();
        expect(wrapper.text()).toContain('Finish every condition');

        // …and it writes as soon as it IS finished, so the silence above is a wait and not a refusal.
        await control(wrapper, 'Condition 2 subject').setValue('field:tier');
        await control(wrapper, 'Condition 2 value').setValue('gold');

        expect(emitted(wrapper)).toEqual(["${age} > 18 and ${tier} = 'gold'"]);
    });

    it('adds a nested group carrying the OPPOSITE operator, which is the only one that says anything new', async () => {
        const wrapper = mountEditor('${age} > 18');
        await button(wrapper, 'Add group').trigger('click');

        expect((control(wrapper, 'Group 2. match').element as HTMLSelectElement).value).toBe('or');
        // An empty group is ignored rather than written, so nothing has changed yet.
        expect(wrapper.emitted('update:expression')).toBeUndefined();
    });

    it('defaults a new fixed value to a NUMBER under an ordering operator and to TEXT under equality', async () => {
        // Ordering is numeric-only in both engines (Doc #27 amendment A1), and `${code} = '7'` is a
        // different question from `${code} = 7` (Eq rule 4 vs rule 5) — so this is semantics, not display.
        const numeric = mountEditor("${notes} = 'x'");
        await control(numeric, 'Condition 1 operator').setValue('gt');
        await control(numeric, 'Condition 1 value').setValue('7');
        expect(emitted(numeric)).toEqual(['${notes} > 7']);

        const textual = mountEditor("${notes} = 'x'");
        await control(textual, 'Condition 1 value').setValue('7');
        expect(emitted(textual)).toEqual(["${notes} = '7'"]);
    });
});

group('a value the grammar cannot express is refused, not mangled', () => {
    it('shows the refusal and writes nothing', async () => {
        const wrapper = mountEditor("${notes} = 'x'");
        await control(wrapper, 'Condition 1 value').setValue('it\'s "fine"');

        expect(wrapper.get('[role="alert"]').text()).toContain('both a straight apostrophe and a double quote');
        expect(wrapper.emitted('update:expression')).toBeUndefined();
    });

    it('clears the refusal and writes as soon as the value becomes expressible', async () => {
        const wrapper = mountEditor("${notes} = 'x'");
        await control(wrapper, 'Condition 1 value').setValue('it\'s "fine"');
        await control(wrapper, 'Condition 1 value').setValue("it's fine");

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(emitted(wrapper)).toEqual(['${notes} = "it\'s fine"']);
    });
});

group('the escape hatch between the two modes', () => {
    it('opens the raw text of a describable condition without changing it', async () => {
        const wrapper = mountEditor('${age} > 18');
        await button(wrapper, 'Edit as text').trigger('click');

        expect(wrapper.get('textarea').element.value).toBe('${age} > 18');
        expect(wrapper.emitted('update:expression')).toBeUndefined();
    });

    it('comes back to the rows carrying whatever the author typed', async () => {
        const wrapper = mountEditor('${age} > 18');
        await button(wrapper, 'Edit as text').trigger('click');
        await wrapper.get('textarea').setValue("${tier} = 'gold'");
        await wrapper.setProps({ expression: "${tier} = 'gold'" });
        await button(wrapper, 'Use the condition builder').trigger('click');

        expect((control(wrapper, 'Condition 1 subject').element as HTMLSelectElement).value).toBe('field:tier');
        expect(emitted(wrapper)).toEqual(["${tier} = 'gold'"]);
    });

    it('offers no way back while the text is not representable', async () => {
        const wrapper = mountEditor('${age} > 18');
        await button(wrapper, 'Edit as text').trigger('click');
        await wrapper.get('textarea').setValue('${age} + 1 > 18');
        await wrapper.setProps({ expression: '${age} + 1 > 18' });

        expect(wrapper.findAll('button').some((b) => b.text() === 'Use the condition builder')).toBe(false);
    });
});

group('an outside change is adopted, not fought', () => {
    it('re-seeds the rows when the prop changes underneath it (undo, or a resolved conflict)', async () => {
        const wrapper = mountEditor('${age} > 18');
        await wrapper.setProps({ expression: "${tier} = 'gold'" });

        expect((control(wrapper, 'Condition 1 subject').element as HTMLSelectElement).value).toBe('field:tier');
        expect(wrapper.emitted('update:expression')).toBeUndefined();
    });

    it('does not re-seed on the echo of its own write', async () => {
        // The parent writes what this component emitted straight back into the prop. Re-seeding there would
        // be harmless today and a lost keystroke the moment the round trip is not instantaneous.
        const wrapper = mountEditor('${age} > 18');
        await control(wrapper, 'Condition 1 value').setValue('21');
        await wrapper.setProps({ expression: '${age} > 21' });

        expect(emitted(wrapper)).toEqual(['${age} > 21']);
        expect((control(wrapper, 'Condition 1 value').element as HTMLInputElement).value).toBe('21');
    });
});
