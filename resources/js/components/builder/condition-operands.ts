/**
 * What the condition editor offers for a question (Increment M134, `R-910d2286`) — the pure half of
 * `ConditionRow.vue`, in the style of `validation-options.ts`.
 *
 * ⛔ TRANSMITTED, NEVER RESTATED. Every decision reads a capability row the server built from `OperandKind`
 * (`BuilderPresenter::enums()` → `operand_kinds`), the same enum the publish gate judges an expression by
 * (`ExpressionOperandJudge`). So the editor cannot offer what publish refuses — "is more than" on a note, a yes/no
 * or a list; "is" on a multi-select; a date against a number — without the server changing first, and
 * `ConditionOffersPublishTest` holds the offer to a subset of what the gate accepts.
 *
 * ⚠️ A CATALOGUE WITH NO ROWS FILTERS NOTHING. A hand-built catalogue in a test, or a field the palette does not
 * know, reads as the editor did before M134: every operator, every question. Narrowing on missing data would be
 * the silent "offers nothing" failure `M115` recorded.
 *
 * ⚠️ A SAVED CHOICE THE EDITOR WOULD NOT OFFER STAYS VISIBLE, DISABLED, with the validation editor's suffix — a
 * condition written before M134, or by hand, is shown as it is rather than silently re-pointed.
 */

import type { Comparator, Condition, Operand } from './condition-model';
import type { ConditionCatalogue, ConditionFieldOption, OperandKindOption } from './types';

export type RowOperator = Comparator | 'blank' | 'not_blank' | 'includes' | 'excludes';

/** A row's subject: a question, a fixed value, or a count of a repeat section's entries. */
export type Subject = Operand | { kind: 'count'; section: string };

export interface PickerOption {
    value: string;
    label: string;
    disabled?: boolean;
}

/** The suffix `ValidationEditor.vue` gives a saved choice it would not offer — one wording across both editors. */
export const UNAVAILABLE = ' — not available for this question';

const ORDERINGS: ReadonlySet<string> = new Set(['gt', 'lt', 'gte', 'lte']);

/** The capability row of one operand kind, or null when the catalogue carries none for it. */
export function kindRow(catalogue: ConditionCatalogue, kind: string | undefined): OperandKindOption | null {
    if (kind === undefined) return null;

    return catalogue.kinds?.find((row) => row.value === kind) ?? null;
}

/** The capability row of a question named by key — null for an unknown key or an unclassified question. */
export function fieldKindRow(catalogue: ConditionCatalogue, key: string): OperandKindOption | null {
    return kindRow(catalogue, catalogue.fields.find((f) => f.key === key)?.operand_kind);
}

/** The subject's row. Only a question is filtered: a fixed value or a count may be compared any way it could before. */
export function subjectKindRow(catalogue: ConditionCatalogue, subject: Subject): OperandKindOption | null {
    return subject.kind === 'field' ? fieldKindRow(catalogue, subject.key) : null;
}

/** Whether a subject of this kind may take this operator. Null filters nothing. */
export function offers(row: OperandKindOption | null, op: string): boolean {
    if (row === null) return true;
    if (!row.offered) return false;
    if (op === 'blank' || op === 'not_blank') return true;
    if (op === 'includes' || op === 'excludes') return row.includes;
    if (ORDERINGS.has(op)) return row.orders;

    return row.equals;
}

/** The operators a subject may take, with a saved one it cannot take kept visible and disabled. */
export function offeredOperators<T extends PickerOption>(options: T[], row: OperandKindOption | null, saved: string): T[] {
    return options.flatMap((option) => {
        if (offers(row, option.value)) return [option];

        return option.value === saved ? [{ ...option, label: `${option.label}${UNAVAILABLE}`, disabled: true }] : [];
    });
}

/** The questions a condition may be ABOUT — every one whose kind is offered, plus the saved one, disabled. */
export function subjectFieldOptions(catalogue: ConditionCatalogue, savedKey: string | null): PickerOption[] {
    return catalogue.fields.flatMap((f) => {
        const row = kindRow(catalogue, f.operand_kind);
        const option = { value: `field:${f.key}`, label: f.label };

        if (row === null || row.offered) return [option];

        return f.key === savedKey ? [{ ...option, label: `${f.label}${UNAVAILABLE}`, disabled: true }] : [];
    });
}

/**
 * The questions the other side of a comparison may name. An ordering takes a question it can be ordered against
 * (`orders_with` — a date only a date or a date-time); an equality, one of the same kind or one it orders against.
 */
export function objectFieldOptions(catalogue: ConditionCatalogue, subjectRow: OperandKindOption | null, op: string, savedKey: string | null): PickerOption[] {
    return catalogue.fields.flatMap((f) => {
        const option = { value: `field:${f.key}`, label: f.label };

        if (subjectRow === null) return [option];

        const kind = f.operand_kind;
        const fits = kind !== undefined
            && (ORDERINGS.has(op) ? subjectRow.orders_with.includes(kind) : kind === subjectRow.value || subjectRow.orders_with.includes(kind));

        if (fits || kind === undefined) return [option];

        return f.key === savedKey ? [{ ...option, label: `${f.label}${UNAVAILABLE}`, disabled: true }] : [];
    });
}

/** The input a fixed value beside this subject uses — a date, time or date-time picker — or null for the default. */
export function literalInput(row: OperandKindOption | null): OperandKindOption['literal_input'] {
    return row?.literal_input ?? null;
}

/** Whether a fixed value compared by ORDERING should be a number: never beside a date or a time. */
export function orderingWantsNumber(row: OperandKindOption | null): boolean {
    return literalInput(row) === null;
}

/** The operator a row falls back to when its subject changes to a question that cannot take the current one. */
export function defaultOperator(row: OperandKindOption | null): RowOperator {
    if (row === null || row.equals) return 'eq';

    return row.includes ? 'includes' : 'not_blank';
}

/**
 * The question a new condition starts on: the first one that can be compared with a value (M134). A note, a page
 * break or a file first in the form would otherwise seed a row the editor no longer offers.
 */
export function firstSeedableField(catalogue: ConditionCatalogue): ConditionFieldOption | undefined {
    return catalogue.fields.find((f) => {
        const row = kindRow(catalogue, f.operand_kind);

        return row === null || (row.offered && (row.equals || row.includes));
    }) ?? catalogue.fields[0];
}

/**
 * A new row on that question, still INCOMPLETE so adding it writes nothing until the author says something
 * (`ConditionRows.vue`'s rule): "is ___" where the kind equals a value, "includes ___" for a list.
 */
export function seedRow(catalogue: ConditionCatalogue, key: string): Condition {
    const row = fieldKindRow(catalogue, key);

    if (row !== null && !row.equals && row.includes) {
        return { kind: 'selected', field: key, value: '', negated: false };
    }

    return { kind: 'compare', op: 'eq', left: { kind: 'field', key }, right: { kind: 'text', value: '' } };
}