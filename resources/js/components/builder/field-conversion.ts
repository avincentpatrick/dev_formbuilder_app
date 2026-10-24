/**
 * The pure half of changing a question's type (Increment M123, `B5b`): how the dialog lists the targets the server
 * offers, and how one plan reads in plain words. No store, no fetch — the dialog renders what this returns.
 *
 * ⚠️ NOTHING HERE RE-LISTS THE FIELD TYPES. The server decides which conversions exist (`FieldTypeConversion`) and in
 * what order; the palette supplies each type's label. "Not asked" is derived from two TRANSMITTED palette facts — a
 * `no_answer` value shape (a note) or the `prefill` editor (a hidden field) — never from a `{note, hidden}` literal,
 * which would be one more client-side type mirror nothing pins.
 */
import type { BuilderEnums, LocalField, LocalSection, PaletteType } from './types';
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

/**
 * The targets in the server's order, split into the kinds a respondent answers and the kinds they do not. M177 (`D140`,
 * `D145`): a retired type is never offered — the server still plans one, for the undo of a conversion to read.
 */
export function targetGroups(plans: readonly ConversionPlan[], palette: ReadonlyMap<string, PaletteType>): TargetGroup[] {
    const asked: TargetOption[] = [];
    const notAsked: TargetOption[] = [];

    for (const plan of plans) {
        const type = palette.get(plan.to);
        if (type?.retired) continue;
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

// M176 (`R-36e6f557`): in the DSR §1.5 voice — say what the author loses, in everyday words.
const CONFIG_SENTENCES: Record<string, string> = {
    options: 'Its choices are removed.',
    // M184: where a choice question's choices come from — another form's answers, or a list file.
    options_source: 'It no longer takes its choices from another form.',
    options_list: 'It no longer takes its choices from a list file.',
    suggestions_source: 'It no longer suggests answers.',
    levels: 'Its levels are removed.',
    rows: 'Its rows are removed.',
    columns: 'Its columns are removed.',
    cells: 'Its cell choices are removed.',
    // M185: where a grid's shared cell choices came from.
    cells_list: 'Its cell choices no longer come from a list file.',
    cells_source: 'Its cell choices no longer come from another form.',
    accepted_types: 'Its accepted file types are reset.',
    capture_source: 'Its choice of camera or upload is removed.',
    max_file_size_bytes: 'Its file size limit is removed.',
    max_count: 'Its maximum number of files is removed.',
    min_count: 'Its minimum number of files is removed.',
    prefill_source: 'Where its value comes from is removed.',
    url_param: 'The web-address setting that fills it in is removed.',
    calculated_formula: 'Its formula is removed.',
    // M180 (`R-f77aa45d`): how a calculation's value is shown.
    calculated_type: 'Its result type is removed.',
    decimal_places: 'Its decimal places are removed.',
    duration_unit: 'Its unit is removed.',
    capture_altitude: 'It no longer records height above sea level.',
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
            return `Whether it must be answered changes from ${optionLabel(enums.required_modes, change.from)} to ${optionLabel(enums.required_modes, change.to)}.`;
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

    // M176: a minimum of 0 is named as the Validation tab names it, with no value to show.
    if (isNoNegativesRule(rule)) return { label: NO_NEGATIVES_LABEL, value: null, shows: rule.error_message };

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
            ? `Here is what happens if it becomes ${ctx.labelOf(plan.to)}.`
            : 'Nothing is lost: its rules and settings carry over, and nothing else in the form changes.',
        question: [
            ...plan.config_dropped.map((key) => CONFIG_SENTENCES[key] ?? `Its “${key}” setting is removed.`),
            ...plan.changes.map((change) => changeSentence(change, ctx.enums)),
        ],
        kept: kept > 0 && rulesMove ? `${kept} ${kept === 1 ? 'rule stays as it is' : 'rules stay as they are'}.` : null,
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

/** "No negative numbers" is stored as what it always was: a minimum of exactly 0, the existing `min_value` rule. */
export const NEGATIVE_FLOOR_RULE = 'min_value';

/** The kind's name, wherever a rule is named — the Validation tab, the type dialog, the conflict dialog. */
export const NO_NEGATIVES_LABEL = 'No negative numbers';

/**
 * M176 (`R-61d43278`) — whether a rule reads as "No negative numbers": a structured minimum of 0, unless the author chose
 * it as a "Minimum value" (`shown_as`). It replaced the Basics checkbox's `negativeFloor()` / `withNegativesAllowed()`,
 * which derived a toggle from this very row — so deleting the row on the Validation tab ticked the box back on (the
 * user's report). One place now holds every minimum.
 */
export function isNoNegativesRule(row: { rule_type: string | null; rule_value: string | null; expression?: string | null; shown_as?: string }): boolean {
    if (row.shown_as === 'rule') return false;
    if (row.shown_as === 'no_negatives') return true;

    // `expression === null` first, mirroring the M116 partition: a raw-expression row is never a structured rule.
    return (row.expression ?? null) === null && row.rule_type === NEGATIVE_FLOOR_RULE && (row.rule_value ?? '').trim() === '0';
}
