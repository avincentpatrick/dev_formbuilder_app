/**
 * The choices a form takes from another form's answers (M133, `R-5da4a30f` — Connect project v1): where the guest page
 * reads them, the key the service worker keeps them under, and how they reach the questions that use them.
 *
 * ── BESIDE THE SCHEMA, NEVER INSIDE IT (`D60` = A) ─────────────────────────────────────────────────
 * A linked question's snapshot holds the LINK (`config.options_source`) and no typed choices. The list is its own
 * response, with its own stamp and its own cache, because the schema is pinned by a checksum every offline device
 * holds and a busy source form would otherwise move it on every response. `fetchSchema()` asks for the list
 * whenever the schema it fetched has a linked question, and merges it into a COPY of the schema here — before the
 * engine is built, so the engine and the controls read one list.
 *
 * ⚠️ ONE MODULE FOR BOTH SIDES, `reference-files.ts`'s precedent: `sw.ts` stores each list under a key and the page
 * asks for the same URL. It imports nothing that touches the DOM, because `sw.ts` is type-checked against the worker
 * library. The route is not under `/api/v1/public/f/` — that prefix is the schema cache's — and the cache key drops
 * the share token (minted per visit) and keeps the VERSION, which is what a list belongs to.
 *
 * ⛔ A LIST THAT CANNOT LOAD NEVER BREAKS THE FORM. A failed request, an offline first visit or a source that stopped
 * sharing leaves the question with no choices and a note saying why; the schema is returned either way. The server
 * never checks an answer against the list (the snapshot has no typed choices, so neither engine runs the
 * membership check there), so a response answered from yesterday's list still submits.
 */

import type { SchemaResponse } from './types';

export const LINKED_CHOICE_PREFIX = '/api/v1/public/linked-choices/';

/** The runtime cache `sw.ts` registers for them. */
export const LINKED_CHOICE_CACHE = 'guest-linked-choices';

/** One question's list, as `GET …/linked-choices/{token}/{version}` sends it. */
export interface LinkedChoiceList {
    stamp: string;
    available: boolean;
    truncated: boolean;
    options: { value: string; label: string }[];
}

export type LinkedChoiceLists = Record<string, LinkedChoiceList>;

/** Said under a linked question whose list could not be loaded. */
export const LINKED_CHOICES_UNAVAILABLE =
    'These choices are not available right now. Open this form once while you are online to load them.';

/** Said under a linked question whose list loaded and holds nothing yet. */
export const LINKED_CHOICES_EMPTY = 'There are no choices to pick from yet.';

/** Where the guest page reads the lists of one version: the route takes the current share token, which scopes the read. */
export function linkedChoicesUrl(shareToken: string, versionId: string): string {
    return `${LINKED_CHOICE_PREFIX}${encodeURIComponent(shareToken)}/${encodeURIComponent(versionId)}`;
}

/**
 * The key a version's lists are cached under: the absolute URL with the share token removed. A URL that is not a
 * linked-choices read is returned unchanged, so the plugin can never merge two unrelated requests.
 */
export function linkedChoicesCacheKey(absoluteUrl: string): string {
    const url = new URL(absoluteUrl);
    if (!url.pathname.startsWith(LINKED_CHOICE_PREFIX)) {
        return absoluteUrl;
    }

    const segments = url.pathname.slice(LINKED_CHOICE_PREFIX.length).split('/');
    if (segments.length !== 2 || segments[1] === '') {
        return absoluteUrl;
    }

    return `${url.origin}${LINKED_CHOICE_PREFIX}${segments[1]}`;
}

/** Whether a field's config links its choices to another form (a missing or null key is "no"). */
function isLinked(config: unknown): boolean {
    if (config === null || typeof config !== 'object') return false;
    const link = (config as Record<string, unknown>).options_source;

    return link !== null && typeof link === 'object';
}

/** Whether a schema has any question that takes its choices from another form — the only case worth a request. */
export function hasLinkedChoices(schema: SchemaResponse): boolean {
    // Tolerant of a partial schema (a fixture, or a cached response from before a key existed): no fields, no request.
    return (schema.version?.schema?.fields ?? []).some((field) => isLinked(field.config));
}

/**
 * A copy of `schema` whose linked questions carry their lists as typed choices, and a note where a list is missing
 * or empty. `lists` null means the request failed. Every other field, and the schema object itself, is untouched —
 * the cached response is never mutated.
 */
export function mergeLinkedChoices(schema: SchemaResponse, lists: LinkedChoiceLists | null): SchemaResponse {
    if (!hasLinkedChoices(schema)) {
        return schema;
    }

    const fields = schema.version.schema.fields.map((field) => {
        if (!isLinked(field.config)) {
            return field;
        }

        const list = lists?.[field.key];
        const usable = list !== undefined && list.available;
        const note = !usable ? LINKED_CHOICES_UNAVAILABLE : list.options.length === 0 ? LINKED_CHOICES_EMPTY : null;
        const hint = note === null ? field.hint : [field.hint, note].filter((part) => part !== null && part !== undefined && part !== '').join(' ');

        return {
            ...field,
            hint,
            config: { ...(field.config ?? {}), options: usable ? list.options.map((option) => ({ ...option })) : [] },
        };
    });

    return { ...schema, version: { ...schema.version, schema: { ...schema.version.schema, fields } } };
}
