/**
 * M184 (`R-d8c2ce84`) — the choices the builder's Preview, and an old version opened read-only, show of choices that
 * come from elsewhere: another form's answers, a cascade's list files, a question's list file, a Short text's suggestions.
 * Until now the preview said they "appear on the live form, not here".
 *
 * The server builds them (`ChoicePreviewPresenter`) in the guest endpoints' shapes, so the runtime's own merge functions
 * write them into the schema — with sentences of their own here, since "open this form while you are online" means
 * nothing to an author. A long list is cut to its first 200, and the question says so.
 *
 * M185 (`R-bb79aec0`, `D161`): a grid's rows, shared cells and choice columns that come from a list or a form, as
 * publishing will copy them in — written over the grid's own keys, a column's by its value.
 */
import { sendSettingsRequest } from '@/components/forms/reference-files';
import { mergeChoiceLists, type ChoiceList } from '../../../public-runtime/lib/choice-lists';
import { LINKED_CHOICES_EMPTY, mergeLinkedChoices, type LinkedChoiceLists } from '../../../public-runtime/lib/linked-choices';
import type { SchemaResponse } from '../../../public-runtime/lib/types';

export interface PreviewChoices {
    linked: LinkedChoiceLists;
    cascades: Record<string, ChoiceList & { total: number }>;
    flat: Record<string, { options: { value: string; label: string }[]; total: number }>;
    suggestions: Record<string, { suggestions: string[]; total: number }>;
    /** M185: absent from a payload built before grids could take their lists from elsewhere. */
    grids?: Record<string, PreviewGrid>;
}

interface PreviewList {
    options: { value: string; label: string }[];
    total: number;
}

export interface PreviewGrid {
    rows?: PreviewList;
    cells?: PreviewList;
    columns?: Record<string, PreviewList>;
}

/** Said under a question whose choices come from another form when there is no list to show. */
export const PREVIEW_LINKED_UNAVAILABLE = 'The preview cannot show these choices yet. Check the form and the question they come from.';

/** Said under a cascade whose list files cannot be read into choices. */
export const PREVIEW_LIST_UNAVAILABLE = 'The preview cannot show these choices yet. Check the list files on the Levels tab.';

/** "Showing the first 200 of 42,010 choices" — or null when the whole list is shown. */
export function shownOf(shown: number, total: number): string | null {
    return total > shown
        ? `Showing the first ${shown.toLocaleString('en')} of ${total.toLocaleString('en')} choices here; the form shows them all.`
        : null;
}

/** The draft's choices for the builder, or a published version's for its read-only view. */
export function previewChoicesUrl(formId: string, versionId?: string): string {
    const form = encodeURIComponent(formId);

    return versionId === undefined ? `/forms/${form}/choices` : `/forms/${form}/versions/${encodeURIComponent(versionId)}/choices`;
}

/** The lists, or null when they could not be read — the preview then shows what it showed before. */
export async function fetchPreviewChoices(url: string): Promise<PreviewChoices | null> {
    try {
        const json = (await sendSettingsRequest(url, { method: 'GET' }, 'The choices could not be read.')) as { data?: PreviewChoices | null } | null;

        return json?.data ?? null;
    } catch {
        return null;
    }
}

function withNote(hint: string | null | undefined, note: string | null): string | null {
    if (note === null) return hint ?? null;

    return [hint, note].filter((part) => part !== null && part !== undefined && part !== '').join(' ');
}

/** A copy of `schema` carrying the choices, each long list's question saying how much of it is shown. */
export function applyPreviewChoices(schema: SchemaResponse, choices: PreviewChoices): SchemaResponse {
    const linked = mergeLinkedChoices(schema, choices.linked, { unavailable: PREVIEW_LINKED_UNAVAILABLE, empty: LINKED_CHOICES_EMPTY });
    const listed = mergeChoiceLists(linked, choices.cascades, PREVIEW_LIST_UNAVAILABLE);

    const fields = listed.version.schema.fields.map((field) => {
        const config = field.config ?? {};
        const flat = choices.flat[field.key];
        if (flat !== undefined && config.options_list != null) {
            return { ...field, hint: withNote(field.hint, shownOf(flat.options.length, flat.total)), config: { ...config, options: flat.options.map((option) => ({ ...option })) } };
        }
        const cascade = choices.cascades[field.key];
        if (cascade !== undefined && field.field_type === 'cascading_select') {
            return { ...field, hint: withNote(field.hint, shownOf(cascade.options.length, cascade.total)) };
        }
        const suggestions = choices.suggestions[field.key];
        if (suggestions !== undefined && field.field_type === 'short_text') {
            return { ...field, config: { ...config, suggestions: [...suggestions.suggestions] } };
        }
        const grid = choices.grids?.[field.key];
        if (grid !== undefined && (field.field_type === 'matrix' || field.field_type === 'likert_matrix')) {
            return { ...field, hint: withNote(field.hint, gridShownOf(grid)), config: withGridLists(config, grid) };
        }

        return field;
    });

    return { ...listed, version: { ...listed.version, schema: { ...listed.version.schema, fields } } };
}

const copyOf = (list: PreviewList) => list.options.map((option) => ({ ...option }));

/** A grid's config with each list the server built written over the grid's own key. */
function withGridLists(config: Record<string, unknown>, grid: PreviewGrid): Record<string, unknown> {
    const next: Record<string, unknown> = { ...config };
    if (grid.rows) next.rows = copyOf(grid.rows);
    if (grid.cells) next.cells = copyOf(grid.cells);
    const own = grid.columns;
    if (own && Array.isArray(config.columns)) {
        next.columns = config.columns.map((column) => {
            const value = column !== null && typeof column === 'object' ? String((column as { value?: unknown }).value ?? '') : '';

            return own[value] ? { ...(column as Record<string, unknown>), options: copyOf(own[value]) } : column;
        });
    }

    return next;
}

/** "Showing the first 200 of 1,000 choices" for the longest list the grid shows only part of — or null. */
function gridShownOf(grid: PreviewGrid): string | null {
    const lists = [grid.rows, grid.cells, ...Object.values(grid.columns ?? {})].filter((list): list is PreviewList => list !== undefined);
    const cut = lists.filter((list) => list.total > list.options.length).sort((a, b) => b.total - a.total)[0];

    return cut ? shownOf(cut.options.length, cut.total) : null;
}
