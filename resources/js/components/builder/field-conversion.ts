/**
 * The pure half of changing a question's type (Increment M123, `B5b`): how the dialog lists the targets the server
 * offers, and how one plan reads in plain words. No store, no fetch — the dialog renders what this returns.
 *
 * ⚠️ NOTHING HERE RE-LISTS THE FIELD TYPES. The server decides which conversions exist (`FieldTypeConversion`) and in
 * what order; the palette supplies each type's label. "Not asked" is derived from two TRANSMITTED palette facts — a
 * `no_answer` value shape (a note) or the `prefill` editor (a hidden field) — never from a `{note, hidden}` literal,
 * which would be one more client-side type mirror nothing pins.
 */
import type { BuilderEnums, BuilderValidation, LocalField, LocalSection, PaletteType } from './types';
import type { ConversionAddedRule, ConversionCensusEntry, ConversionChange, ConversionPlan, ConversionRule } from './useBuilderStore';

export type ConvertPhase = 'loading' | 'load-failed' | 'choosing' | 'applying' | 'stale' | 'apply-failed';

export interface TargetOption {
    value: string;
    label: string;
}

/** `MdsSelect`'s `groups` shape. */
export interface TargetGroup {
    label: string;
    options: TargetOption[];
}

export interface RuleLine {
    label: string;
    value: string | null;
    shows: string | null;
}

export interface ConsequenceView {
    summary: string;
    /** What happens to this question's own settings, one sentence each. */
    question: string[];
    /** "N existing validation rules stay unchanged." — only when rules are also removed or added. */
    kept: string | null;
    removed: (RuleLine & { reason: string })[];
    added: RuleLine[];
    warnings: string[];
    elsewhere: { owner: string; message: string }[];
}

export interface ConsequenceContext {
    enums: BuilderEnums;
    fields: readonly LocalField[];
    sections: readonly LocalSection[];
    /** The converted question's own key, so its own condition reads as "this question's". */
    ownKey: string;
    labelOf: (type: string) => string;
}

export const GROUP_QUESTION_TYPES = 'Question types';
export const GROUP_NOT_ASKED = 'Not asked';

/** Whether a respondent answers a field of this type — false for a display-only note and for a prefilled hidden field. */
export function isAskedOfRespondent(type: PaletteType | undefined): boolean {
    return type !== undefined && type.value_shape !== 'no_answer' && type.config_editor !== 'prefill';
}

/** The targets in the server's order, split into the kinds a respondent answers and the kinds they do not. */
export function targetGroups(plans: readonly ConversionPlan[], palette: ReadonlyMap<string, PaletteType>): TargetGroup[] {
    const asked: TargetOption[] = [];
    const notAsked: TargetOption[] = [];

    for (const plan of plans) {
        const type = palette.get(plan.to);
        (isAskedOfRespondent(type) ? asked : notAsked).push({ value: plan.to, label: type?.label ?? plan.to });
    }

    return [
        { label: GROUP_QUESTION_TYPES, options: asked },
        { label: GROUP_NOT_ASKED, options: notAsked },
    ].filter((group) => group.options.length > 0);
}

/**
 * Whether the author must read this plan before it is applied. The census counts even on a lossless plan: changing a
 * single choice to a multiple choice loses nothing, and still changes what another question's condition means.
 */
export function needsReview(plan: ConversionPlan): boolean {
    return plan.requires_confirmation || plan.census.length > 0;
}

const CONFIG_SENTENCES: Record<string, string> = {
    options: 'Its choices are removed.',
    levels: 'Its levels are removed.',
    rows: 'Its rows are removed.',
    columns: 'Its columns are removed.',
    cells: 'Its cell choices are removed.',
    accepted_types: 'Its accepted file types are reset.',
    capture_source: 'Its capture source is removed.',
    max_file_size_bytes: 'Its file size limit is removed.',
    max_count: 'Its maximum number of files is removed.',
    min_count: 'Its minimum number of files is removed.',
    prefill_source: 'Where its value comes from is removed.',
    url_param: 'Its link parameter is removed.',
    calculated_formula: 'Its formula is removed.',
    capture_altitude: 'Altitude capture is turned off.',
    accuracy_threshold: 'Its accuracy target is removed.',
    default_center: 'Its default map centre is removed.',
    default_zoom: 'Its default map zoom is removed.',
};

function optionLabel(options: readonly { value: string; label: string }[], value: unknown): string {
    const raw = value === null || value === undefined ? '' : String(value);

    return options.find((option) => option.value === raw)?.label ?? raw;
}

function changeSentence(change: ConversionChange, enums: BuilderEnums): string {
    switch (change.column) {
        case 'is_required':
            return `Its requiredness changes from ${optionLabel(enums.required_modes, change.from)} to ${optionLabel(enums.required_modes, change.to)}.`;
        case 'default_value':
            return change.to === null
                ? `Its default value “${String(change.from)}” is removed.`
                : `Its default value changes from “${String(change.from)}” to “${String(change.to)}”.`;
        case 'default_value_is_expression':
            return 'Its default is no longer a formula.';
        case 'is_queryable':
            return change.to === true ? 'It is now indexed for reporting.' : 'It is no longer indexed for reporting.';
        case 'indexed_data_type':
            return change.to === null
                ? `Its reporting data type (${optionLabel(enums.indexed_data_types, change.from)}) is cleared.`
                : `Its reporting data type changes to ${optionLabel(enums.indexed_data_types, change.to)}.`;
        default:
            return `Its “${change.column}” setting changes.`;
    }
}

function ruleLine(rule: ConversionRule | ConversionAddedRule, enums: BuilderEnums): RuleLine {
    if (rule.rule_type === null) {
        return { label: 'A custom check', value: 'expression' in rule ? rule.expression : null, shows: rule.error_message };
    }

    const operator = 'operator' in rule && rule.operator !== null ? optionLabel(enums.comparison_operators, rule.operator) : '';
    const value = [operator, rule.rule_value ?? ''].filter((part) => part !== '').join(' ');

    return {
        label: optionLabel(enums.validation_rule_types, rule.rule_type),
        value: value === '' ? null : value,
        shows: rule.error_message,
    };
}

/** A trimmed label, or the key when the label is empty — the way the Logic rail names a node. */
function named(label: string | null | undefined, key: string): string {
    const trimmed = (label ?? '').trim();

    return trimmed === '' ? key : trimmed;
}

/**
 * Who owns a census entry, in words. A `template` key may name a field, a section or the form's thank-you message —
 * and field and section keys are unique per table, not across them, so a shared key names BOTH rather than guessing.
 */
export function censusOwner(entry: ConversionCensusEntry, ctx: Pick<ConsequenceContext, 'fields' | 'sections' | 'ownKey'>): string {
    const field = ctx.fields.find((f) => f.key === entry.key);
    const section = ctx.sections.find((s) => s.key === entry.key);
    const fieldName = named(field?.label, entry.key);
    const own = entry.key === ctx.ownKey;

    switch (entry.site) {
        case 'relevance':
            return own ? 'This question’s own condition' : `The condition on “${fieldName}”`;
        case 'section_relevance':
            return `The condition on the section “${named(section?.label, entry.key)}”`;
        case 'formula':
            return `The formula of “${fieldName}”`;
        case 'constraint':
            return own ? 'A custom check on this question' : `A custom check on “${fieldName}”`;
        case 'rule':
            return `A rule on “${fieldName}”`;
        case 'template': {
            const candidates: string[] = [];
            if (field !== undefined) candidates.push(`The text of “${named(field.label, entry.key)}”`);
            if (section !== undefined) candidates.push(`The title or description of the section “${named(section.label, entry.key)}”`);
            if (entry.key === 'confirmation_message') candidates.push('The thank-you message');

            return candidates.length > 0 ? candidates.join(', or ') : `Text that mentions this question (“${entry.key}”)`;
        }
        default:
            return `“${entry.key}”`;
    }
}

/** One plan in plain words, for the dialog. */
export function consequenceView(plan: ConversionPlan, ctx: ConsequenceContext): ConsequenceView {
    const rulesMove = plan.dropped.length > 0 || plan.added.length > 0;
    const kept = plan.kept.length;

    return {
        summary: needsReview(plan)
            ? `Here is what changing it to ${ctx.labelOf(plan.to)} does.`
            : 'Nothing is lost: its rules and settings carry over, and nothing else in the form is affected.',
        question: [
            ...plan.config_dropped.map((key) => CONFIG_SENTENCES[key] ?? `Its “${key}” setting is removed.`),
            ...plan.changes.map((change) => changeSentence(change, ctx.enums)),
        ],
        kept: kept > 0 && rulesMove ? `${kept} existing validation ${kept === 1 ? 'rule stays' : 'rules stay'} unchanged.` : null,
        removed: plan.dropped.map((rule) => ({ ...ruleLine(rule, ctx.enums), reason: rule.reason_message })),
        added: plan.added.map((rule) => ruleLine(rule, ctx.enums)),
        warnings: plan.warnings.map((warning) => warning.message),
        elsewhere: plan.census.map((entry) => ({ owner: censusOwner(entry, ctx), message: entry.message })),
    };
}

/** What the dialog says when the question changed while it was open and the plans were read again. */
export function staleMessage(nowTypeLabel: string | null, choiceStillOffered: boolean): string {
    const lead = nowTypeLabel === null ? 'Here is what changing its type does now.' : `It is now ${nowTypeLabel}. Here is what changing it does now.`;

    return choiceStillOffered ? lead : `${lead} The type you chose is no longer offered, so choose again.`;
}

/*
|--------------------------------------------------------------------------
| Increment M125 — the palette's variant groups (`R-a367bf9e`).
|--------------------------------------------------------------------------
| The palette shows one "Text" and one "Number"; the Basics tab switches a field between the members of its group.
| Which types form a group is TRANSMITTED (`PaletteType.variant`), never listed here.
*/

/** The members of a variant group, in palette order, as segmented-control options labelled with their own type labels. */
export function variantOptions(palette: Iterable<PaletteType>, group: string): TargetOption[] {
    return [...palette].filter((type) => type.variant?.group === group).map((type) => ({ value: type.value, label: type.label }));
}

/** The one rule "Allow negative numbers" writes: a minimum of exactly 0, the existing `min_value` rule — no new config key. */
export const NEGATIVE_FLOOR_RULE = 'min_value';

/**
 * What a field's rules say about negative answers. `custom` is any OTHER minimum — one the author set on the Validation
 * tab — which the toggle reads but never rewrites; `allowed` then reports whether that minimum still admits a negative.
 */
export interface NegativeFloor {
    state: 'none' | 'zero' | 'custom';
    allowed: boolean;
}

function isMinimum(row: BuilderValidation): boolean {
    // `expression === null` first, mirroring the M116 partition: a raw-expression row is never a structured rule.
    return row.expression === null && row.rule_type === NEGATIVE_FLOOR_RULE;
}

export function negativeFloor(rows: readonly BuilderValidation[]): NegativeFloor {
    const minimums = rows.filter(isMinimum);
    if (minimums.length === 0) return { state: 'none', allowed: true };
    if (minimums.length === 1 && (minimums[0].rule_value ?? '').trim() === '0') return { state: 'zero', allowed: false };

    // A blank threshold constrains nothing, so a row being typed does not read as a refusal.
    return { state: 'custom', allowed: minimums.every((row) => (row.rule_value ?? '').trim() === '' || Number(row.rule_value) < 0) };
}

/**
 * The rows with the toggle's own row added or removed — and nothing else moved. The M116 discipline, re-stated because
 * `ConfigPanel`'s partition is private to a hub: every row this toggle does not own keeps its relative order, so the
 * "Required when…" reveal beside it and the Validation tab never reshuffle. Returns the SAME array when there is nothing
 * to do, including over a `custom` minimum, which belongs to the Validation tab.
 */
export function withNegativesAllowed(rows: BuilderValidation[], allowed: boolean): BuilderValidation[] {
    const { state } = negativeFloor(rows);
    let next: BuilderValidation[];

    if (allowed && state === 'zero') {
        next = rows.filter((row) => !isMinimum(row));
    } else if (!allowed && state === 'none') {
        next = [
            ...rows,
            { rule_type: NEGATIVE_FLOOR_RULE, operator: null, rule_value: '0', expression: null, error_message: null, related_field_key: null, sequence: rows.length },
        ];
    } else {
        return rows;
    }

    return next.map((row, i) => ({ ...row, sequence: i }));
}
