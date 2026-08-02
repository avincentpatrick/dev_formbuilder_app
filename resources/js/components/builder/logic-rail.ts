/**
 * The logic canvas's projection: the builder's draft → an ordered rail of nodes with their conditions
 * (Increment H21d1, Doc #27 §8). Pure — no Vue, no fetch, no DOM — so the whole shape is testable without
 * mounting anything.
 *
 * ── IT IS A RAIL, NOT A FLOW CHART, AND THAT IS §2 RATHER THAN A SIMPLIFICATION ─────────────────────
 * The step graph is a TOTAL ORDER FILTERED BY RELEVANCE. There is no edge table, no successor column, no
 * `next_step_id` anywhere in this system: nodes are sections in `sequence` order, the edges between them are
 * implied by that order and are not editable, and the only editable object is a predicate hanging off a node
 * or off a field inside it. So there is no arrow to draw from section 3 to section 7 — a skip over four
 * sections is four conditions — and the surface must SAY that rather than imply otherwise by drawing
 * something it cannot honour.
 *
 * ── AUTHORED ORDER, NOT THE TAKEN PATH ──────────────────────────────────────────────────────────────
 * Deliberately NOT built on `StepProjection` / `visibleSteps`. Those answer "what would a respondent walk,
 * given these answers", and there are no answers here — the author is editing a form, not filling one. A
 * simulator ("show me this as someone who answered X") is the residue `docs/feature-backlog.md` keeps after
 * H21d1, and drawing one set of nodes while meaning the other would be the worst of both.
 *
 * One static fact from the step model IS carried, because it explains an absence the author will otherwise
 * find baffling: a section whose every field renders nothing can never be a step at all. See
 * {@link RailNode.neverAStep}.
 */

import { rendersNothing } from '../../../public-runtime/engine';
import { describe, type ConditionReading, type LabelLookup } from './condition-describer';
import type { CanvasGroup, LocalField, LocalSection, Uid } from './types';

/** A notice as `GET /forms/{form}/graph` ships it (`App\Support\Forms\GraphNotice::toArray()`). */
export interface GraphNotice {
    kind: 'forward_reference' | 'cycle' | 'empty_at_open';
    nodes: string[];
    message: string;
}

/** A field inside a node that carries a condition of its own. */
export interface RailField {
    uid: Uid;
    key: string;
    label: string;
    expression: string;
    reading: ConditionReading;
    notices: GraphNotice[];
}

export interface RailNode {
    /** null for the ungrouped bucket, which has no section row to select or edit. */
    uid: Uid | null;
    /** The section key, or null for the ungrouped bucket — which is `__lead__` to the runtime, not a row. */
    key: string | null;
    label: string;
    /** The section's own `relevant_expression`, '' when it has none. */
    expression: string;
    reading: ConditionReading;
    isRepeatable: boolean;
    minInstances: number | null;
    maxInstances: number | null;
    /** How many fields the node holds in total, conditioned or not. */
    fieldCount: number;
    /** Only the fields carrying a condition — the rest are structure, not logic. */
    conditioned: RailField[];
    /**
     * True when the node can never be a step, because every field in it renders nothing to a respondent
     * (`hidden` / `calculated` / `page_break`). This is H21c's keyer-only "Reference fields" shape seen from
     * the authoring side, and it is worth saying out loud: the author can see the section in Structure and
     * will never see it in the form, and no condition they write will change that.
     *
     * An EMPTY node is not marked — there is nothing to conclude about a section with no fields yet, and
     * saying "never a step" about one an author is halfway through building would be false as soon as they
     * dropped a question into it.
     */
    neverAStep: boolean;
    notices: GraphNotice[];
}

export interface RailInput {
    groups: CanvasGroup[];
    notices: GraphNotice[];
}

/**
 * Build the rail. Notices are matched to nodes BY KEY, which is why a node's own key is carried even though
 * selection uses the uid: the server names sections and fields the only way it can across a wire, and the
 * uid is a client invention with no server-side existence.
 */
export function buildRail({ groups, notices }: RailInput): RailNode[] {
    const labels = labelLookup(groups);
    const byNode = indexNotices(notices);

    return groups
        // The ungrouped bucket is always present in `groups` (index 0) and is almost always empty. Drop it
        // when it is: a permanent "Ungrouped" card at the top of every rail is noise on the majority of
        // forms, and a rail that starts with the author's first real section reads as their form.
        .filter((group) => group.section !== null || group.fields.length > 0)
        .map((group) => toNode(group, labels, byNode));
}

function toNode(group: CanvasGroup, labels: LabelLookup, byNode: Map<string, GraphNotice[]>): RailNode {
    const section = group.section;
    const expression = section?.relevant_expression ?? '';

    return {
        uid: section?.uid ?? null,
        key: section?.key ?? null,
        // The ungrouped bucket is `__lead__` to the runtime (Doc #27 §5.3) and a synthetic step with no row
        // behind it. An author never named it, so it gets a plain description rather than a fake key.
        label: section === null ? 'Questions before the first section' : sectionLabel(section),
        expression,
        reading: describe(expression, labels),
        isRepeatable: section?.is_repeatable ?? false,
        minInstances: section?.min_instances ?? null,
        maxInstances: section?.max_instances ?? null,
        fieldCount: group.fields.length,
        conditioned: group.fields.filter(hasCondition).map((field) => toRailField(field, labels, byNode)),
        neverAStep: group.fields.length > 0 && group.fields.every((field) => rendersNothing(field.field_type)),
        notices: section === null ? [] : (byNode.get(section.key) ?? []),
    };
}

function toRailField(field: LocalField, labels: LabelLookup, byNode: Map<string, GraphNotice[]>): RailField {
    const expression = field.relevant_expression ?? '';

    return {
        uid: field.uid,
        key: field.key,
        label: fieldLabel(field),
        expression,
        reading: describe(expression, labels),
        notices: byNode.get(field.key) ?? [],
    };
}

function hasCondition(field: LocalField): boolean {
    return (field.relevant_expression ?? '').trim() !== '';
}

/**
 * `key → label` over every field AND section, because a condition can name either: `count(${roster})`
 * references a section key, which grammar v2.0 added and H21a made actually work.
 *
 * Fields are indexed AFTER sections, and the asymmetry is deliberate rather than arbitrary. Section and
 * field keys are NOT globally unique per version — two independent per-table composite indexes, verified in
 * H21a's amendment A7 — so a collision is reachable, and if one happens the label an author is most likely
 * to recognise is the question's. Nothing behavioural rests on it: this map only decides which words a
 * sentence uses.
 */
function labelLookup(groups: CanvasGroup[]): LabelLookup {
    const labels: LabelLookup = {};

    groups.forEach((group) => {
        if (group.section !== null) labels[group.section.key] = sectionLabel(group.section);
    });
    groups.forEach((group) => {
        group.fields.forEach((field) => (labels[field.key] = fieldLabel(field)));
    });

    return labels;
}

/** Notices indexed by every node they name — a cycle names all of its members, so it appears under each. */
function indexNotices(notices: GraphNotice[]): Map<string, GraphNotice[]> {
    const byNode = new Map<string, GraphNotice[]>();

    notices.forEach((notice) => {
        notice.nodes.forEach((key) => {
            const existing = byNode.get(key);
            if (existing) existing.push(notice);
            else byNode.set(key, [notice]);
        });
    });

    return byNode;
}

/** Form-level notices — the ones naming no node at all, which is `empty_at_open`. */
export function formLevelNotices(notices: GraphNotice[]): GraphNotice[] {
    return notices.filter((notice) => notice.nodes.length === 0);
}

/** Whether the rail has anything to say about conditions at all — drives the empty state. */
export function railHasLogic(rail: RailNode[]): boolean {
    return rail.some((node) => node.reading.status !== 'blank' || node.conditioned.length > 0);
}

function sectionLabel(section: LocalSection): string {
    return section.label.trim() === '' ? section.key : section.label.trim();
}

function fieldLabel(field: LocalField): string {
    return field.label.trim() === '' ? field.key : field.label.trim();
}
