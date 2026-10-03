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
    appearance: 'Appearance hint',
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
 */
export function describeSavePath(path: string): string {
    if (NAMES[path] !== undefined) return NAMES[path];

    const rule = /^validations\.(\d+)/.exec(path);
    if (rule) return `Rule ${Number(rule[1]) + 1}`;

    const listed = /^config\.(options|levels|rows|columns|cells|accepted_types)\.(\d+)/.exec(path);
    if (listed) return `${LISTS[listed[1]]} ${Number(listed[2]) + 1}`;

    if (path.startsWith('config.')) return 'Settings';

    return path;
}

export function sortFieldSaveErrors(errors: Record<string, string[]> | undefined): FieldSaveErrors {
    const inline: Partial<Record<InlineFieldKey, string>> = {};
    const listed: ListedSaveError[] = [];

    for (const [key, messages] of Object.entries(errors ?? {})) {
        const message = messages[0];
        if (message === undefined) continue;

        if ((INLINE_FIELD_KEYS as readonly string[]).includes(key)) {
            inline[key as InlineFieldKey] ??= message;
            continue;
        }

        listed.push({ key, text: `${describeSavePath(key)}: ${message}` });
    }

    return { inline, listed };
}
