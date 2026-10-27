/**
 * M176 (`R-164579e8`) — what differs between the author's copy of a row and the one the server holds, in the words the
 * settings panel uses, for the "This item changed elsewhere" dialog.
 *
 * The testers' screenshot (2026-10-11, comment 5) showed two identical columns: the dialog printed only the label and the
 * key, and the difference was the Required setting. The store already keeps BOTH WHOLE ROWS on a conflict; this lists
 * every setting where they part, side by side, so the author can see what "Keep mine" and "Use the saved version" each keep.
 *
 * Pure, so it is tested without mounting the dialog. Values are compared the way the server stores them (jsonb hands a
 * config back in its own key order; an absent rule group reads as null) — the same rule `samePayload()` follows.
 *
 * M188 (`R-3894369d`): {@link settingWords} — one setting's two values in those words — is shared with the History tab,
 * which words each change a question's history records (`before → after`) exactly as this dialog words a difference.
 */

import { describe, type LabelLookup } from './condition-describer';
import { isNoNegativesRule, NO_NEGATIVES_LABEL } from './field-conversion';
import { describeSavePath } from './field-save-errors';
import { canonical } from './row-compare';
import type { BuilderEnums, BuilderValidation, LocalField, LocalSection, ServerField, ServerSection } from './types';

export interface ConflictDifference {
    /** The setting, as the panel names it. */
    setting: string;
    /** The author's value, in words. */
    mine: string;
    /** The value the server holds, in words. */
    theirs: string;
}

export interface DiffWords {
    enums: BuilderEnums;
    /** Question and section keys → their labels, so a condition reads as a sentence. */
    labels?: LabelLookup;
    /** A field type's value → its palette label. */
    typeLabel?: (value: string) => string;
}

const EMPTY = 'Empty';

/** The question settings the dialog compares, in the order the panel's tabs show them. */
const FIELD_SETTINGS = [
    'field_type',
    'label',
    'hint',
    'placeholder',
    'is_required',
    'relevant_expression',
    'appearance',
    'config',
    'validations',
    'key',
    'default_value',
    'default_value_is_expression',
    'is_pii',
    'is_sensitive',
    'is_queryable',
    'indexed_data_type',
] as const;

const SECTION_SETTINGS: Record<string, string> = {
    label: 'Section title',
    description: 'Description',
    relevant_expression: 'Show this section when',
    is_repeatable: 'Repeatable group',
    min_instances: 'Min instances',
    max_instances: 'Max instances',
    repeat_layout: 'Layout',
    key: 'Section key',
};

const CONFIG_NAMES: Record<string, string> = {
    options: 'Choices',
    other_specify_key: 'Other, please specify',
    levels: 'Levels',
    rows: 'Rows',
    columns: 'Columns',
    cells: 'Cell choices',
    calculated_formula: 'Calculation formula',
    content: 'Content',
    accepted_types: 'Accepted file types',
    // M188: these three were `max_size_mb` / `max_files` / `min_files`, keys no question has (MediaEditor's are these).
    max_file_size_bytes: 'File size limit',
    max_count: 'Most files',
    min_count: 'Fewest files',
    visibility: 'Show to respondents',
    options_source: 'Choices come from',
    options_list: 'Choices come from',
    suggestions_source: 'Suggestions come from',
    rows_source: 'Rows come from',
    rows_list: 'Rows come from',
    cells_source: 'Cell choices come from',
    cells_list: 'Cell choices come from',
    default_center: 'Map centre',
};

function optionLabel(options: readonly { value: string; label: string }[], value: unknown): string {
    const raw = value === null || value === undefined ? '' : String(value);

    return options.find((option) => option.value === raw)?.label ?? raw;
}

function same(a: unknown, b: unknown): boolean {
    return JSON.stringify(canonical(a ?? null)) === JSON.stringify(canonical(b ?? null));
}

function plain(value: unknown): string {
    if (value === null || value === undefined || value === '') return EMPTY;
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) {
        if (value.length === 0) return EMPTY;
        // A list of choices, levels, rows …: their labels, in order.
        return value
            .map((item) => (item !== null && typeof item === 'object' && 'label' in item ? String((item as { label: unknown }).label) : plain(item)))
            .join(', ');
    }
    if (typeof value === 'object') return JSON.stringify(canonical(value));

    return String(value);
}

function conditionWords(expression: unknown, labels: LabelLookup, always: string): string {
    if (typeof expression !== 'string' || expression.trim() === '') return always;
    const reading = describe(expression, labels, 'Shown when');

    return reading.status === 'described' ? reading.prose : expression;
}

function ruleWords(rule: BuilderValidation, words: DiffWords): string {
    if (rule.rule_type === null || rule.rule_type === '') return rule.expression ? `A custom check: ${rule.expression}` : 'An empty rule';
    if (isNoNegativesRule(rule)) return NO_NEGATIVES_LABEL;
    const parts = [
        optionLabel(words.enums.validation_rule_types, rule.rule_type),
        rule.operator ? optionLabel(words.enums.comparison_operators, rule.operator) : '',
        rule.related_field_key ? (words.labels?.[rule.related_field_key] ?? rule.related_field_key) : '',
        rule.rule_value ?? '',
    ].filter((part) => part !== '');
    const shows = rule.error_message ? ` (shows “${rule.error_message}”)` : '';

    return `${parts.join(' ')}${shows}`;
}

function rulesWords(rules: unknown, words: DiffWords): string {
    if (!Array.isArray(rules) || rules.length === 0) return 'No rules';

    return (rules as BuilderValidation[]).map((rule, i) => `${i + 1}. ${ruleWords(rule, words)}`).join('; ');
}

/** The rules as the server stores them: no ids, no order columns, an absent group read as null. */
function storedRules(rules: unknown): unknown {
    if (!Array.isArray(rules)) return [];

    return (rules as BuilderValidation[]).map((rule) => ({
        rule_type: rule.rule_type,
        operator: rule.operator,
        rule_value: rule.rule_value,
        expression: rule.expression,
        error_message: rule.error_message,
        related_field_key: rule.related_field_key,
        logic_group: rule.logic_group ?? null,
        logic_operator: rule.logic_operator ?? null,
    }));
}

/** One setting's two values, in the panel's words. */
export interface SettingWords {
    setting: string;
    a: string;
    b: string;
}

/** A question's place, as History words a move: "Household, 2nd" or "Top of the form, 4th". */
function placeWords(value: unknown): string {
    if (value === null || typeof value !== 'object') return EMPTY;
    const { section, position } = value as { section?: string | null; position?: number | null };
    const where = section ? `“${section}”` : 'Not in a section';

    return typeof position === 'number' ? `${where}, place ${position}` : where;
}

/**
 * One setting's two values in the words the settings panel uses — a column by its name, a config key as `config.<key>`, the
 * rules as `validations`, a move as `position`. The conflict dialog's rows (mine, theirs) and the History tab's lines
 * (before, after) are both this.
 */
export function settingWords(setting: string, a: unknown, b: unknown, words: DiffWords): SettingWords {
    const labels = words.labels ?? {};

    if (setting.startsWith('config.')) {
        const key = setting.slice('config.'.length);

        return { setting: CONFIG_NAMES[key] ?? describeSavePath(setting), a: plain(a), b: plain(b) };
    }

    switch (setting) {
        case 'validations':
            return { setting: 'Rules', a: rulesWords(a, words), b: rulesWords(b, words) };
        case 'position':
            return { setting: 'Place', a: placeWords(a), b: placeWords(b) };
        case 'field_type':
            return { setting: 'Question type', a: words.typeLabel?.(String(a)) ?? String(a), b: words.typeLabel?.(String(b)) ?? String(b) };
        case 'is_required':
            return { setting: 'Required', a: optionLabel(words.enums.required_modes, a), b: optionLabel(words.enums.required_modes, b) };
        case 'relevant_expression':
            return { setting: describeSavePath(setting), a: conditionWords(a, labels, 'Always'), b: conditionWords(b, labels, 'Always') };
        case 'indexed_data_type':
            return { setting: describeSavePath(setting), a: optionLabel(words.enums.indexed_data_types, a) || EMPTY, b: optionLabel(words.enums.indexed_data_types, b) || EMPTY };
        case 'default_value_is_expression':
            return { setting: 'Default is a formula', a: plain(a), b: plain(b) };
        case 'is_queryable':
            return { setting: 'Indexed for reporting', a: plain(a), b: plain(b) };
        default:
            return { setting: describeSavePath(setting), a: plain(a), b: plain(b) };
    }
}

function fieldDifferences(mine: LocalField, theirs: ServerField, words: DiffWords): ConflictDifference[] {
    const out: ConflictDifference[] = [];
    const push = (row: SettingWords): void => {
        out.push({ setting: row.setting, mine: row.a, theirs: row.b });
    };

    for (const setting of FIELD_SETTINGS) {
        if (setting === 'config') {
            const a = (mine.config ?? {}) as Record<string, unknown>;
            const b = (theirs.config ?? {}) as Record<string, unknown>;
            for (const key of [...new Set([...Object.keys(a), ...Object.keys(b)])].sort()) {
                if (same(a[key], b[key])) continue;
                push(settingWords(`config.${key}`, a[key], b[key], words));
            }
            continue;
        }
        if (setting === 'validations') {
            if (!same(storedRules(mine.validations), storedRules(theirs.validations))) {
                push(settingWords('validations', mine.validations, theirs.validations, words));
            }
            continue;
        }

        const a = (mine as unknown as Record<string, unknown>)[setting];
        const b = (theirs as unknown as Record<string, unknown>)[setting];
        // An index type the server clears when indexing is off reads as cleared, as `samePayload()` reads it.
        if (setting === 'indexed_data_type' && !mine.is_queryable && !theirs.is_queryable) continue;
        if (same(a, b)) continue;

        push(settingWords(setting, a, b, words));
    }

    return out;
}

/**
 * One section setting's two values in the panel's words. M189 (`version-diff-ui`): shared with the Compare page, which words
 * a section's changes between two versions exactly as this dialog words them.
 */
export function sectionSettingWords(setting: string, a: unknown, b: unknown, words: DiffWords): SettingWords {
    const labels = words.labels ?? {};
    const name = SECTION_SETTINGS[setting] ?? describeSavePath(setting);

    return setting === 'relevant_expression'
        ? { setting: name, a: conditionWords(a, labels, 'Always'), b: conditionWords(b, labels, 'Always') }
        : { setting: name, a: plain(a), b: plain(b) };
}

function sectionDifferences(mine: LocalSection, theirs: ServerSection, words: DiffWords): ConflictDifference[] {
    const out: ConflictDifference[] = [];

    for (const setting of Object.keys(SECTION_SETTINGS)) {
        const a = (mine as unknown as Record<string, unknown>)[setting];
        const b = (theirs as unknown as Record<string, unknown>)[setting];
        if (same(a, b)) continue;
        const row = sectionSettingWords(setting, a, b, words);
        out.push({ setting: row.setting, mine: row.a, theirs: row.b });
    }

    return out;
}

/**
 * Every setting where the author's copy and the server's part, in the panel's order. Empty when only the save token moved
 * — the dialog then says keeping the author's copy loses nothing.
 */
export function conflictDifferences(
    kind: 'field' | 'section',
    mine: LocalField | LocalSection,
    theirs: ServerField | ServerSection,
    words: DiffWords,
): ConflictDifference[] {
    return kind === 'field'
        ? fieldDifferences(mine as LocalField, theirs as ServerField, words)
        : sectionDifferences(mine as LocalSection, theirs as ServerSection, words);
}
