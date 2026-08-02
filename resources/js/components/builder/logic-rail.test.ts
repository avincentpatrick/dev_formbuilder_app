import { describe as group, expect, it } from 'vitest';
import { buildRail, formLevelNotices, railHasLogic, type GraphNotice } from './logic-rail';
import type { CanvasGroup, LocalField, LocalSection } from './types';

/*
 * Increment H21d1 — the logic canvas's projection (Doc #27 §8).
 *
 * The rail is AUTHORED order, not the taken path: it is deliberately not built on `StepProjection` /
 * `visibleSteps`, because there are no answers when an author is editing a form. What it does carry from the
 * step model is one static fact — a section whose every field renders nothing can never be a step — and that
 * is here because the author will otherwise never learn why a section they can see never appears.
 */

let uid = 0;

function section(key: string, overrides: Partial<LocalSection> = {}): LocalSection {
    return {
        id: `sec-${key}`,
        uid: `u${++uid}`,
        key,
        label: key.charAt(0).toUpperCase() + key.slice(1),
        description: null,
        is_repeatable: false,
        min_instances: null,
        max_instances: null,
        relevant_expression: null,
        sequence: 0,
        version: null,
        ...overrides,
    };
}

function field(key: string, overrides: Partial<LocalField> = {}): LocalField {
    return {
        id: `fld-${key}`,
        uid: `u${++uid}`,
        form_section_id: null,
        key,
        field_type: 'short_text',
        label: key.charAt(0).toUpperCase() + key.slice(1),
        hint: null,
        placeholder: null,
        is_required: 'optional',
        relevant_expression: null,
        appearance: null,
        config: {},
        default_value: null,
        is_pii: false,
        is_sensitive: false,
        is_queryable: false,
        indexed_data_type: null,
        sequence: 0,
        section_sequence: null,
        version: null,
        validations: [],
        ...overrides,
    };
}

/** `useBuilderStore.groups` always emits the ungrouped bucket first, then sections in `sequence` order. */
function groups(ungrouped: LocalField[], ...rest: CanvasGroup[]): CanvasGroup[] {
    return [{ section: null, fields: ungrouped }, ...rest];
}

group('order and membership', () => {
    it('follows the store’s order, which is §2’s total order', () => {
        const rail = buildRail({
            groups: groups(
                [],
                { section: section('basics'), fields: [field('name')] },
                { section: section('details'), fields: [field('detail')] },
            ),
            notices: [],
        });

        expect(rail.map((node) => node.key)).toEqual(['basics', 'details']);
    });

    it('drops the ungrouped bucket when it is empty, and keeps it when it is not', () => {
        // The bucket is ALWAYS present in `groups` (index 0) and almost always empty; a permanent
        // "Ungrouped" card at the top of every rail is noise on the majority of forms.
        const without = buildRail({ groups: groups([], { section: section('basics'), fields: [] }), notices: [] });
        expect(without.map((node) => node.key)).toEqual(['basics']);

        const with_ = buildRail({
            groups: groups([field('stray')], { section: section('basics'), fields: [] }),
            notices: [],
        });
        expect(with_.map((node) => node.key)).toEqual([null, 'basics']);
        expect(with_[0].label).toBe('Questions before the first section');
        expect(with_[0].uid).toBeNull();
    });

    it('lists ONLY the fields carrying a condition, and counts the rest', () => {
        const rail = buildRail({
            groups: groups([], {
                section: section('basics'),
                fields: [
                    field('name'),
                    field('age', { relevant_expression: "${name} != ''" }),
                    field('note'),
                ],
            }),
            notices: [],
        });

        expect(rail[0].fieldCount).toBe(3);
        expect(rail[0].conditioned.map((f) => f.key)).toEqual(['age']);
    });

    it('treats a whitespace-only expression as no condition, the way the engine does', () => {
        const rail = buildRail({
            groups: groups([], { section: section('basics'), fields: [field('age', { relevant_expression: '   ' })] }),
            notices: [],
        });

        expect(rail[0].conditioned).toEqual([]);
        expect(rail[0].reading.status).toBe('blank');
    });
});

group('conditions', () => {
    it('reads a section’s own condition in the author’s labels, resolving across sections', () => {
        const rail = buildRail({
            groups: groups(
                [],
                { section: section('basics'), fields: [field('age', { label: 'Your age' })] },
                { section: section('adults', { relevant_expression: '${age} > 18' }), fields: [] },
            ),
            notices: [],
        });

        expect(rail[1].reading).toEqual({
            status: 'described',
            prose: 'Shown when Your age is more than 18.',
            references: ['age'],
        });
    });

    it('resolves a SECTION key in a condition, which is what count(${roster}) names', () => {
        // Grammar v2.0 made a section key referenceable and H21a made it actually evaluate. If the label
        // lookup covered fields only, this would read "count of roster" against a raw key forever.
        const rail = buildRail({
            groups: groups(
                [],
                { section: section('roster', { label: 'Household members', is_repeatable: true }), fields: [field('who')] },
                { section: section('summary', { relevant_expression: 'count(${roster}) > 0' }), fields: [] },
            ),
            notices: [],
        });

        expect(rail[1].reading.status === 'described' && rail[1].reading.prose).toBe(
            'Shown when Household members has more than 0 entries.',
        );
    });

    it('keeps the raw expression on the node even when the reading is opaque', () => {
        // The raw text IS the reading in that case, so losing it would leave the node saying nothing at all.
        const rail = buildRail({
            groups: groups([], { section: section('odd', { relevant_expression: '${age} + 1 > 18' }), fields: [] }),
            notices: [],
        });

        expect(rail[0].reading.status).toBe('opaque');
        expect(rail[0].expression).toBe('${age} + 1 > 18');
    });

    it('surfaces a condition that does not parse as invalid, not as a crash', () => {
        const rail = buildRail({
            groups: groups([], { section: section('broken', { relevant_expression: '${age} = = 1' }), fields: [] }),
            notices: [],
        });

        expect(rail[0].reading.status).toBe('invalid');
    });

    it('falls back to the KEY when a label is blank', () => {
        const rail = buildRail({
            groups: groups([], {
                section: section('s', { label: '   ' }),
                fields: [field('age', { label: '  ' }), field('gate', { relevant_expression: '${age} > 1' })],
            }),
            notices: [],
        });

        expect(rail[0].label).toBe('s');
        expect(rail[0].conditioned[0].reading.status === 'described' && rail[0].conditioned[0].reading.prose)
            .toBe('Shown when age is more than 1.');
    });
});

group('the never-a-step marker', () => {
    it('marks a section holding only fields the respondent never sees', () => {
        // H21c's keyer-only "Reference fields" shape from the authoring side. No condition the author writes
        // will make this section appear, and nothing else on the page says so.
        const rail = buildRail({
            groups: groups([], {
                section: section('refs'),
                fields: [field('utm', { field_type: 'hidden' }), field('total', { field_type: 'calculated' })],
            }),
            notices: [],
        });

        expect(rail[0].neverAStep).toBe(true);
    });

    it('does NOT mark a section with one visible field among hidden ones', () => {
        const rail = buildRail({
            groups: groups([], {
                section: section('mixed'),
                fields: [field('utm', { field_type: 'hidden' }), field('name')],
            }),
            notices: [],
        });

        expect(rail[0].neverAStep).toBe(false);
    });

    it('does NOT mark an EMPTY section, because there is nothing to conclude yet', () => {
        // `every()` is vacuously true on an empty list — the accident this guards. Telling an author that a
        // section they are halfway through building can never be a step would be false the moment they
        // dropped a question into it.
        const rail = buildRail({ groups: groups([], { section: section('new'), fields: [] }), notices: [] });

        expect(rail[0].neverAStep).toBe(false);
    });
});

group('notices', () => {
    const forward: GraphNotice = {
        kind: 'forward_reference',
        nodes: ['early', 'late_field'],
        message: 'This depends on “late_field”, which comes later in the form.',
    };
    const cycle: GraphNotice = {
        kind: 'cycle',
        nodes: ['alpha', 'beta'],
        message: 'Circular condition — “alpha” ⇄ “beta” depend on each other.',
    };
    const empty: GraphNotice = {
        kind: 'empty_at_open',
        nodes: [],
        message: 'This form shows no questions at all until something is answered.',
    };

    it('attaches a notice to the section it names', () => {
        const rail = buildRail({
            groups: groups(
                [],
                { section: section('early', { relevant_expression: "${late_field} = 'y'" }), fields: [] },
                { section: section('late'), fields: [field('late_field')] },
            ),
            notices: [forward],
        });

        expect(rail[0].notices).toEqual([forward]);
        expect(rail[1].notices).toEqual([]);
    });

    it('attaches a notice to a FIELD it names, not only to sections', () => {
        const rail = buildRail({
            groups: groups([], {
                section: section('late'),
                fields: [field('late_field', { relevant_expression: "${early} = 'y'" })],
            }),
            notices: [forward],
        });

        expect(rail[0].conditioned[0].notices).toEqual([forward]);
    });

    it('shows a cycle under EVERY member, because a cycle is a property of the set', () => {
        const rail = buildRail({
            groups: groups(
                [],
                { section: section('alpha', { relevant_expression: "${y} = '1'" }), fields: [] },
                { section: section('beta', { relevant_expression: "${x} = '1'" }), fields: [] },
            ),
            notices: [cycle],
        });

        expect(rail[0].notices).toEqual([cycle]);
        expect(rail[1].notices).toEqual([cycle]);
    });

    it('keeps a form-level notice OFF every node, and hands it back separately', () => {
        // `empty_at_open` names no node on purpose: emptiness is a property of the graph, and pinning it to
        // an arbitrary member of the set of conditions jointly responsible would point the author at the
        // wrong one.
        const rail = buildRail({
            groups: groups([], { section: section('gated', { relevant_expression: "${gate} = 'y'" }), fields: [] }),
            notices: [empty],
        });

        expect(rail[0].notices).toEqual([]);
        expect(formLevelNotices([empty, forward])).toEqual([empty]);
    });

    it('ignores a notice naming a node that is no longer there', () => {
        // Routine, not exotic: the sidecar answers for the last SAVED draft, so a section deleted since the
        // fetch is named by a notice with nowhere to go. It must vanish quietly, not throw.
        const rail = buildRail({
            groups: groups([], { section: section('basics'), fields: [] }),
            notices: [{ kind: 'cycle', nodes: ['deleted_one'], message: 'gone' }],
        });

        expect(rail[0].notices).toEqual([]);
    });
});

group('railHasLogic', () => {
    it('is false for a form with no conditions anywhere', () => {
        const rail = buildRail({
            groups: groups([], { section: section('basics'), fields: [field('name')] }),
            notices: [],
        });

        expect(railHasLogic(rail)).toBe(false);
    });

    it('is true for a condition on a section OR on a field', () => {
        const onSection = buildRail({
            groups: groups([], { section: section('s', { relevant_expression: '${a} > 1' }), fields: [] }),
            notices: [],
        });
        const onField = buildRail({
            groups: groups([], { section: section('s'), fields: [field('a', { relevant_expression: '${b} > 1' })] }),
            notices: [],
        });

        expect(railHasLogic(onSection)).toBe(true);
        expect(railHasLogic(onField)).toBe(true);
    });
});
