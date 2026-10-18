/**
 * M177 (`R-f44e9d00`) — "Show to respondents: Always · Never · Only when…". The user (2026-10-16): every question gets
 * always visible and always hidden, aligned to the existing settings — so the mode sits beside the existing condition
 * editor ("Only when…" IS that editor), and it is DERIVED from what is stored rather than kept as a setting of its own:
 * `config.visibility` (`hidden` = Never; `shown` = Always, which only a calculation needs, since it is Never by default)
 * and `relevant_expression`. Absent keeps every type's own rule, so every existing question reads as it did.
 */
export type ShowMode = 'always' | 'never' | 'when';

type Shown = { field_type: string; config: Record<string, unknown>; relevant_expression: string | null };

/** The Hidden type is never shown and a page break shows nothing; neither offers the choice. */
export function showModeFixed(fieldType: string): boolean {
    return fieldType === 'hidden' || fieldType === 'page_break';
}

export function showModeOf(field: Shown): ShowMode {
    if (showModeFixed(field.field_type) || field.config.visibility === 'hidden') return 'never';
    if (field.field_type === 'calculated' && field.config.visibility !== 'shown') return 'never';

    return (field.relevant_expression ?? '').trim() !== '' ? 'when' : 'always';
}

/**
 * What choosing a mode stores. Always clears the condition (there is nothing left for it to decide); Never keeps it, to
 * use again under Only when; a calculation records that it shows, every other type that it does not hide.
 */
export function applyShowMode(field: Shown, mode: ShowMode): { config: Record<string, unknown>; relevant_expression: string | null } {
    const { visibility: _visibility, ...rest } = field.config;
    const calculated = field.field_type === 'calculated';

    if (mode === 'never') {
        return { config: calculated ? rest : { ...rest, visibility: 'hidden' }, relevant_expression: field.relevant_expression };
    }
    const shown = calculated ? { ...rest, visibility: 'shown' } : rest;

    return { config: shown, relevant_expression: mode === 'always' ? null : field.relevant_expression };
}
