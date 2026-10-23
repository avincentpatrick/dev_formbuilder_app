/**
 * A repeatable section laid out as a table (M185, `R-0a7275ce`, `D129`) — the browser's half of
 * `App\Support\Forms\RepeatTable`, read by the guest form, the encode page, the builder preview and the builder's Layout
 * setting. `tests/fixtures/repeat-table.json` holds the cases both halves read, and `FieldTypeMirrorDriftTest` pins
 * {@link REPEAT_TABLE_TYPES} to `RepeatTable::TYPES`.
 *
 * A cell holds one short answer — a text, a number, a date or a choice. Any other question a respondent sees keeps the
 * section as cards; one that renders nothing is no column.
 */
import { fieldRendersNothing } from '../../../public-runtime/engine/field-roles';

export const REPEAT_TABLE_TYPES = new Set<string>(['long_text', 'short_text', 'email', 'phone', 'url', 'integer', 'decimal', 'date', 'single_select', 'dropdown', 'multi_select']);

/** A member as either shape carries it: a snapshot field (`field_type`, `config.visibility`) or a rendered one. */
export interface RepeatMember {
    field_type: string;
    visibility?: string | null;
    config?: Record<string, unknown> | null;
}

function visibilityOf(member: RepeatMember): string | null {
    if (member.visibility !== undefined) return member.visibility;
    const stored = member.config?.visibility;

    return typeof stored === 'string' ? stored : null;
}

/** The section's columns: its members a respondent sees, in order. */
export function repeatTableColumns<T extends RepeatMember>(members: readonly T[]): T[] {
    return members.filter((member) => !fieldRendersNothing({ field_type: member.field_type, visibility: visibilityOf(member) }));
}

/** The first column a table cannot hold — the question that keeps the section as cards — or null. */
export function repeatTableBlocker<T extends RepeatMember>(members: readonly T[]): T | null {
    return repeatTableColumns(members).find((member) => !REPEAT_TABLE_TYPES.has(member.field_type)) ?? null;
}

/** Whether a section set to a table is drawn as one: it has a column, and every column fits a cell. */
export function drawsAsTable<T extends RepeatMember>(layout: string | null | undefined, members: readonly T[]): boolean {
    return layout === 'table' && repeatTableColumns(members).length > 0 && repeatTableBlocker(members) === null;
}
