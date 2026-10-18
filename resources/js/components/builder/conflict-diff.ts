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
    max_size_mb: 'File size limit',
    max_files: 'Most files',
    min_files: 'Fewest files',
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

function fieldDifferences(mine: LocalField, theirs: ServerField, words: DiffWords): ConflictDifference[] {
    const labels = words.labels ?? {};
    const out: ConflictDifference[] = [];

    for (const setting of FIELD_SETTINGS) {
        if (setting === 'config') {
            const a = (mine.config ?? {}) as Record<string, unknown>;
            const b = (theirs.config ?? {}) as Record<string, unknown>;
            for (const key of [...new Set([...Object.keys(a), ...Object.keys(b)])].sort()) {
                if (same(a[key], b[key])) continue;
                out.push({ setting: CONFIG_NAMES[key] ?? describeSavePath(`config.${key}`), mine: plain(a[key]), theirs: plain(b[key]) });
            }
            continue;
        }
        if (setting === 'validations') {
            if (!same(storedRules(mine.validations), storedRules(theirs.validations))) {
                out.push({ setting: 'Rules', mine: rulesWords(mine.validations, words), theirs: rulesWords(theirs.validations, words) });
            }
            continue;
        }

        const a = (mine as unknown as Record<string, unknown>)[setting];
        const b = (theirs as unknown as Record<string, unknown>)[setting];
        // An index type the server clears when indexing is off reads as cleared, as `samePayload()` reads it.
        if (setting === 'indexed_data_type' && !mine.is_queryable && !theirs.is_queryable) continue;
        if (same(a, b)) continue;

        switch (setting) {
            case 'field_type':
                out.push({ setting: 'Question type', mine: words.typeLabel?.(String(a)) ?? String(a), theirs: words.typeLabel?.(String(b)) ?? String(b) });
                break;
            case 'is_required':
                out.push({ setting: 'Required', mine: optionLabel(words.enums.required_modes, a), theirs: optionLabel(words.enums.required_modes, b) });
                break;
            case 'relevant_expression':
                out.push({ setting: describeSavePath(setting), mine: conditionWords(a, labels, 'Always'), theirs: conditionWords(b, labels, 'Always') });
                break;
            case 'indexed_data_type':
                out.push({ setting: describeSavePath(setting), mine: optionLabel(words.enums.indexed_data_types, a) || EMPTY, theirs: optionLabel(words.enums.indexed_data_types, b) || EMPTY });
                break;
            case 'default_value_is_expression':
                out.push({ setting: 'Default is a formula', mine: plain(a), theirs: plain(b) });
                break;
            case 'is_queryable':
                out.push({ setting: 'Indexed for reporting', mine: plain(a), theirs: plain(b) });
                break;
            default:
                out.push({ setting: describeSavePath(setting), mine: plain(a), theirs: plain(b) });
        }
    }

    return out;
}

function sectionDifferences(mine: LocalSection, theirs: ServerSection, words: DiffWords): ConflictDifference[] {
    const labels = words.labels ?? {};
    const out: ConflictDifference[] = [];

    for (const [setting, name] of Object.entries(SECTION_SETTINGS)) {
        const a = (mine as unknown as Record<string, unknown>)[setting];
        const b = (theirs as unknown as Record<string, unknown>)[setting];
        if (same(a, b)) continue;
        out.push(
            setting === 'relevant_expression'
                ? { setting: name, mine: conditionWords(a, labels, 'Always'), theirs: conditionWords(b, labels, 'Always') }
                : { setting: name, mine: plain(a), theirs: plain(b) },
        );
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
