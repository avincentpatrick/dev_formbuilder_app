/**
 * The choice lists a form's cascading questions take from uploaded CSV files (M141, `R-f69aab42`, `D92` = A, `D95`):
 * where the guest page reads them, the key the service worker keeps them under, and how they reach the questions.
 *
 * ── BESIDE THE SCHEMA, AS LINKED CHOICES ARE ──────────────────────────────────────────────────────────────────
 * A barangay list is about 42,000 rows. The published version holds it as the cascade's options — every server-side
 * reader needs it there — but the schema a browser is sent leaves it out (`FormVersion::schemaWithoutListOptions()`),
 * and the list is its own response: `GET …/choice-lists/{token}/{version}`, each choice a tuple
 * `[level index, value, label, parent]`. `fetchSchema()` asks for it whenever the schema has such a cascade and merges
 * it into a COPY, before the engine is built, so the engine and the controls read one list.
 *
 * ⚠️ FROZEN PER VERSION, UNLIKE LINKED CHOICES. So the service worker keeps it by version with the share token
 * stripped, and an answer is checked on the server against the very list it was picked from.
 *
 * ⛔ A LIST THAT CANNOT LOAD NEVER BREAKS THE FORM: the question shows no choices and says why. It imports nothing
 * that touches the DOM, because `sw.ts` imports the cache key from here and is type-checked against the worker library.
 */

import type { SchemaResponse } from './types';

export const CHOICE_LIST_PREFIX = '/api/v1/public/choice-lists/';

/** The runtime cache `sw.ts` registers for them. */
export const CHOICE_LIST_CACHE = 'guest-choice-lists';

/** One cascade's list, as `GET …/choice-lists/{token}/{version}` sends it. */
export interface ChoiceList {
    levels: string[];
    /** `[level index, value, label, parent]`; the parent is null at the first level. */
    options: [number, string, string, string | null][];
}

export type ChoiceLists = Record<string, ChoiceList>;

/** Said under a CSV-backed question whose list could not be loaded. */
export const CHOICE_LIST_UNAVAILABLE =
    'These choices are not available right now. Open this form once while you are online to load them.';

/** Where the guest page reads the lists of one version: the route takes the current share token, which scopes the read. */
export function choiceListsUrl(shareToken: string, versionId: string): string {
    return `${CHOICE_LIST_PREFIX}${encodeURIComponent(shareToken)}/${encodeURIComponent(versionId)}`;
}

/**
 * The key a version's lists are cached under: the absolute URL with the share token removed. A URL that is not a
 * choice-list read is returned unchanged, so the plugin can never merge two unrelated requests.
 */
export function choiceListsCacheKey(absoluteUrl: string): string {
    const url = new URL(absoluteUrl);
    if (!url.pathname.startsWith(CHOICE_LIST_PREFIX)) {
        return absoluteUrl;
    }

    const segments = url.pathname.slice(CHOICE_LIST_PREFIX.length).split('/');
    if (segments.length !== 2 || segments[1] === '') {
        return absoluteUrl;
    }

    return `${url.origin}${CHOICE_LIST_PREFIX}${segments[1]}`;
}

/** Whether every level of a cascade names a CSV list — then its choices come from the lists. */
export function isListBacked(config: unknown): boolean {
    if (config === null || typeof config !== 'object') return false;
    const levels = (config as Record<string, unknown>).levels;
    if (!Array.isArray(levels) || levels.length === 0) return false;

    return levels.every((level) => level !== null && typeof level === 'object' && typeof (level as Record<string, unknown>).list === 'string' && (level as Record<string, unknown>).list !== '');
}

/** Whether a schema has any CSV-backed cascade — the only case worth a request. */
export function hasChoiceLists(schema: SchemaResponse): boolean {
    return (schema.version?.schema?.fields ?? []).some((field) => field.field_type === 'cascading_select' && isListBacked(field.config));
}

/**
 * A copy of `schema` whose CSV-backed cascades carry their lists as ordinary options, and a note where a list is
 * missing. `lists` null means the request failed. Every other field, and the schema object itself, is untouched.
 */
export function mergeChoiceLists(schema: SchemaResponse, lists: ChoiceLists | null): SchemaResponse {
    if (!hasChoiceLists(schema)) {
        return schema;
    }

    const fields = schema.version.schema.fields.map((field) => {
        if (field.field_type !== 'cascading_select' || !isListBacked(field.config)) {
            return field;
        }

        const list = lists?.[field.key];
        if (list === undefined) {
            const hint = [field.hint, CHOICE_LIST_UNAVAILABLE].filter((part) => part !== null && part !== undefined && part !== '').join(' ');

            return { ...field, hint, config: { ...(field.config ?? {}), options: [] } };
        }

        const options = list.options.map(([level, value, label, parent]) => ({ level: list.levels[level] ?? '', value, label, parent }));

        return { ...field, config: { ...(field.config ?? {}), options } };
    });

    return { ...schema, version: { ...schema.version, schema: { ...schema.version.schema, fields } } };
}
