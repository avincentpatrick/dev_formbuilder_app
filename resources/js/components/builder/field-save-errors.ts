/**
 * What a refused field save says, sorted by where the author can act on it (M128, `R-d001de0c`).
 *
 * The builder's PATCH answers a refusal with Laravel's `{ message, errors }` map, keyed by request path
 * (`label`, `config.options.2.label`, `validations.0.rule_value`). `builderClient` has always parsed that
 * map onto `BuilderRequestError.errors`; until now nothing read it, so a refusal was one sentence with
 * nothing marked. This module decides, for each key, whether the config panel can mark a control in place
 * (`inline`) or must say it in words (`listed`), and it names a path the way the panel names the control.
 *
 * Pure, so it is tested without mounting the panel; the panel only renders what it returns.
 */

/** The keys the panel shows a control for directly, so the message goes ON that control. */
export const INLINE_FIELD_KEYS = [
    'label',
    'hint',
    'placeholder',
    'key',
    'appearance',
    'default_value',
    'indexed_data_type',
    'config.calculated_formula',
] as const;

export type InlineFieldKey = (typeof INLINE_FIELD_KEYS)[number];

export interface ListedSaveError {
    key: string;
    text: string;
}

export interface FieldSaveErrors {
    /** First message per control the panel marks in place. */
    inline: Partial<Record<InlineFieldKey, string>>;
    /** Every other refusal, in words, in the server's order. Nothing is dropped. */
    listed: ListedSaveError[];
}

const NAMES: Record<string, string> = {
    label: 'Label',
    hint: 'Help text',
    placeholder: 'Placeholder',
    key: 'Field key',
    // M130: the free-text "Appearance hint" became the Options tab's choice layout.
    appearance: 'Choice layout',
    default_value: 'Default value',
    indexed_data_type: 'Indexed data type',
    'config.calculated_formula': 'Calculation formula',
    // M129: a note's content blocks, named by the tab that edits them — the refusal is one sentence for the whole
    // list (`ContentBlocks` answers "block 3 …"), so the pane's alert says it once, above every tab.
    'config.content': 'Content',
    is_required: 'Requiredness',
    relevant_expression: 'Show this question when',
    is_pii: 'Personal data',
    is_sensitive: 'Sensitive',
    is_queryable: 'Indexed',
    config: 'Settings',
    validations: 'Rules',
};

const LISTS: Record<string, string> = {
    options: 'Choice',
    levels: 'Level',
    rows: 'Row',
    columns: 'Column',
    cells: 'Cell',
    accepted_types: 'Accepted type',
};

/**
 * A request path in the panel's words: `validations.0.rule_value` is "Rule 1", `config.options.2.label`
 * is "Choice 3". Unknown paths fall back to the path itself rather than to nothing, so a reader can
 * always tell two refusals apart.
 *
 * M176 (`R-5554c526`) — `ruleRows` is the editor row each SENT rule was (the store records it at send time): a rule still
 * being written is left out of the request, so `validations.0` can be the second rule on screen, and "Rule 1" pointed at
 * the wrong one. The Validation tab's numbering is the one used — it lists every rule, Required-when ones included.
 */
export function describeSavePath(path: string, ruleRows?: readonly number[]): string {
    if (NAMES[path] !== undefined) return NAMES[path];

    const rule = /^validations\.(\d+)/.exec(path);
    if (rule) return `Rule ${(ruleRows?.[Number(rule[1])] ?? Number(rule[1])) + 1}`;

    const listed = /^config\.(options|levels|rows|columns|cells|accepted_types)\.(\d+)/.exec(path);
    if (listed) return `${LISTS[listed[1]]} ${Number(listed[2]) + 1}`;

    if (path.startsWith('config.')) return 'Settings';

    return path;
}

export function sortFieldSaveErrors(errors: Record<string, string[]> | undefined, ruleRows?: readonly number[]): FieldSaveErrors {
    const inline: Partial<Record<InlineFieldKey, string>> = {};
    const listed: ListedSaveError[] = [];

    for (const [key, messages] of Object.entries(errors ?? {})) {
        const message = messages[0];
        if (message === undefined) continue;

        if ((INLINE_FIELD_KEYS as readonly string[]).includes(key)) {
            inline[key as InlineFieldKey] ??= message;
            continue;
        }

        listed.push({ key, text: `${describeSavePath(key, ruleRows)}: ${message}` });
    }

    return { inline, listed };
}

const TAB_BY_PATH: Record<string, string> = {
    label: 'basics',
    hint: 'basics',
    placeholder: 'basics',
    is_required: 'basics',
    'config.calculated_formula': 'basics',
    key: 'advanced',
    default_value: 'advanced',
    indexed_data_type: 'advanced',
    relevant_expression: 'advanced',
    is_pii: 'advanced',
    is_sensitive: 'advanced',
    is_queryable: 'advanced',
    appearance: 'options',
};

/**
 * M172 (`R-733eff09`) — the config tab holding the control a refused path names, so the panel opens THAT tab when the
 * question is selected and marks it, instead of always opening Basics. The testers met a key refusal shown only under
 * Field key on Advanced, while the alert said "Select it to see why" and selecting it opened Basics. Null for a path
 * no tab owns; the panel then keeps its own choice and the alert says the refusal in words.
 */
export function tabForSavePath(path: string): string | null {
    if (TAB_BY_PATH[path] !== undefined) return TAB_BY_PATH[path];
    if (path.startsWith('validations')) return 'validation';
    if (path.startsWith('config.options') || path === 'config.other_specify_key') return 'options';
    if (path.startsWith('config.levels')) return 'cascading';
    if (/^config\.(rows|columns|cells)/.test(path)) return 'grid';
    if (path.startsWith('config.content')) return 'content';
    if (path.startsWith('config.accepted_types')) return 'media';

    return null;
}

/** The column's length, and so the longest key the server keeps (`UpdateFieldRequest`'s `max:150`). */
const KEY_MAX = 150;

/**
 * M172 (`R-733eff09`, `D132`) — the key the server will keep for `wanted` when another question (or section) already
 * holds it: the lowest free `{key}_{n}` with n from 2, shortened before the suffix to stay inside 150 characters. The
 * same rule as `FormBuilderService::freeKey()`, so the panel can say BEFORE the save what the save will do.
 */
export function freeKeyFor(wanted: string, taken: readonly string[]): string {
    const used = new Set(taken);
    if (!used.has(wanted)) return wanted;
    for (let n = 2; ; n++) {
        const suffix = `_${n}`;
        const candidate = `${wanted.slice(0, KEY_MAX - suffix.length)}${suffix}`;
        if (!used.has(candidate)) return candidate;
    }
}
