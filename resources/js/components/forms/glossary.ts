/**
 * A form's glossary on its draft (M245, `R-d0bbb7bb`): list, add, change and remove terms and their definitions. The
 * requests reuse the Files section's `sendSettingsRequest()`, so every refusal reaches the author as the server's own
 * sentence ("This glossary already has “TWG”.").
 */
import { sendSettingsRequest } from '@/components/forms/reference-files';

export interface GlossaryRow {
    id: string;
    term: string;
    definition: string;
}

const FALLBACK = 'That did not work. Check your connection and try again.';

function base(formId: string): string {
    return `/forms/${encodeURIComponent(formId)}/glossary`;
}

function rowOf(json: unknown): GlossaryRow {
    const data = (json as { data?: GlossaryRow } | null)?.data;
    if (data === undefined || typeof data.id !== 'string') {
        throw new Error(FALLBACK);
    }
    return data;
}

/** The draft's terms, in the server's one order. */
export async function listGlossary(formId: string): Promise<GlossaryRow[]> {
    const json = (await sendSettingsRequest(base(formId), { method: 'GET' }, FALLBACK)) as { data?: unknown } | null;
    return Array.isArray(json?.data) ? (json.data as GlossaryRow[]) : [];
}

export async function addGlossaryTerm(formId: string, term: string, definition: string): Promise<GlossaryRow> {
    return rowOf(await sendSettingsRequest(base(formId), { method: 'POST', json: { term, definition } }, 'The term was not added.'));
}

export async function updateGlossaryTerm(formId: string, id: string, term: string, definition: string): Promise<GlossaryRow> {
    return rowOf(
        await sendSettingsRequest(`${base(formId)}/${encodeURIComponent(id)}`, { method: 'PATCH', json: { term, definition } }, 'The term was not saved.'),
    );
}

/** Remove a term from the draft. A published version keeps its own glossary. */
export async function removeGlossaryTerm(formId: string, id: string): Promise<void> {
    await sendSettingsRequest(`${base(formId)}/${encodeURIComponent(id)}`, { method: 'DELETE' }, 'The term was not removed.');
}

/**
 * Entries in the server's order — by the matching key, then the term, compared as the server compares them. The server
 * answers each list already sorted; this keeps an added or renamed row in place without asking for the list again.
 * `glossaryKey()` is the matching key both engines share.
 */
export function sortGlossary<T extends { term: string }>(rows: T[], key: (term: string) => string): T[] {
    // Each byte as two hex digits, so comparing the strings compares the UTF-8 bytes, as PHP's `strcmp` does.
    const bytes = (text: string): string => Array.from(new TextEncoder().encode(text), (byte) => byte.toString(16).padStart(2, '0')).join('');
    const compare = (x: string, y: string): number => (x < y ? -1 : x > y ? 1 : 0);
    return [...rows].sort((a, b) => compare(bytes(key(a.term)), bytes(key(b.term))) || compare(bytes(a.term), bytes(b.term)));
}
