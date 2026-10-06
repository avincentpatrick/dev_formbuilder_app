/**
 * A form's CSV choice lists (M141, `R-f69aab42`, `D92` = A, `D95`): list, upload and remove them on its draft. One file
 * per cascade level, in Kobo's format — `name` (a code) and `label` columns, and below the first level a column named
 * after the level above. A list is named after its file (`provinces.csv` is `provinces`), and uploading a file of the
 * same name replaces the list. The requests reuse the Files section's `sendSettingsRequest()`, so every refusal reaches
 * the author as the server's own sentence ("“POB” is on rows 2 and 3 …").
 */
import { sendSettingsRequest } from '@/components/forms/reference-files';

export interface ChoiceListRow {
    name: string;
    file_name: string;
    row_count: number;
    columns: string[];
}

const FALLBACK = 'That did not work. Check your connection and try again.';

function base(formId: string): string {
    return `/forms/${encodeURIComponent(formId)}/choice-lists`;
}

/** The draft's lists, by name. */
export async function listChoiceLists(formId: string): Promise<ChoiceListRow[]> {
    const json = (await sendSettingsRequest(base(formId), { method: 'GET' }, FALLBACK)) as { data?: unknown } | null;
    return Array.isArray(json?.data) ? (json.data as ChoiceListRow[]) : [];
}

/** Add a list from a CSV file, or replace the list of the same name. */
export async function uploadChoiceList(formId: string, file: File): Promise<ChoiceListRow> {
    const body = new FormData();
    body.append('file', file);
    const json = (await sendSettingsRequest(base(formId), { method: 'POST', body }, 'The file was not accepted.')) as { data?: ChoiceListRow } | null;
    if (json?.data === undefined || typeof json.data.name !== 'string') {
        throw new Error(FALLBACK);
    }
    return json.data;
}

/** Remove a list from the draft. A published version that uses it keeps it. */
export async function removeChoiceList(formId: string, name: string): Promise<void> {
    await sendSettingsRequest(`${base(formId)}/${encodeURIComponent(name)}`, { method: 'DELETE' }, 'The list was not removed.');
}

/** "1,234 choices" — a list's size as a person reads it. */
export function describeChoiceList(row: ChoiceListRow): string {
    return `${row.row_count.toLocaleString('en')} ${row.row_count === 1 ? 'choice' : 'choices'}`;
}
