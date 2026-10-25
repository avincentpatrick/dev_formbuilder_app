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

/**
 * M186 (`R-ed7f3127`): one set that narrows a question's choices — or a cascade level's — by what another form recorded,
 * as the same endpoint sends it beside the lists. `level` is the cascade level's index, or null for a question's own list.
 */
export interface ChoiceFilterSet {
    field_key: string;
    level: number | null;
    stamp: string;
    available: boolean;
    truncated: boolean;
    values: string[];
}

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

/** M184 (`R-596d6afd`): whether a Short text's suggestions come from another form. */
function suggestsFromForm(config: unknown): boolean {
    if (config === null || typeof config !== 'object') return false;
    const source = (config as Record<string, unknown>).suggestions_source;

    return source !== null && typeof source === 'object' && (source as Record<string, unknown>).kind === 'form';
}

/** M186 (`R-ed7f3127`): whether a question's choices, or a cascade level's, are narrowed by another form — picked or not. */
function declaresFilter(config: unknown): boolean {
    if (config === null || typeof config !== 'object') return false;
    const { options_filter: filter, levels } = config as Record<string, unknown>;
    if (filter !== null && typeof filter === 'object') return true;

    return Array.isArray(levels) && levels.some((level) => level !== null && typeof level === 'object' && typeof (level as Record<string, unknown>).filter === 'object' && (level as Record<string, unknown>).filter !== null);
}

/** Whether a schema has any question that takes its choices — or its suggestions — from another form, or is narrowed by one: the only case worth a request. */
export function hasLinkedChoices(schema: SchemaResponse): boolean {
    // Tolerant of a partial schema (a fixture, or a cached response from before a key existed): no fields, no request.
    return (schema.version?.schema?.fields ?? []).some((field) => isLinked(field.config) || suggestsFromForm(field.config) || declaresFilter(field.config));
}

/**
 * A copy of `schema` whose linked questions carry their lists as typed choices, and a note where a list is missing
 * or empty. `lists` null means the request failed. Every other field, and the schema object itself, is untouched —
 * the cached response is never mutated. `notes` lets the builder's preview (M184) say what a missing list means there;
 * the guest page's own sentences are the default.
 *
 * M186 (`R-ed7f3127`): `sets` — the narrowing sets served beside the lists — are written into each question that declares
 * a filter as `config.choice_filter_sets`, which only the RENDER side reads (`schema-mapping.ts`'s `choiceFilterOf()`): the
 * engine is built from `options`, untouched here, so an answer outside a set is never refused. `sets` null means the
 * request failed, and the question shows every choice with a note (`D163`).
 */
export function mergeLinkedChoices(
    schema: SchemaResponse,
    lists: LinkedChoiceLists | null,
    notes: { unavailable: string; empty: string } = { unavailable: LINKED_CHOICES_UNAVAILABLE, empty: LINKED_CHOICES_EMPTY },
    sets: ChoiceFilterSet[] | null = null,
): SchemaResponse {
    if (!hasLinkedChoices(schema)) {
        return schema;
    }

    const withLists = (field: SchemaResponse['version']['schema']['fields'][number]): typeof field => {
        // M184: a Short text's suggestions are its list's labels — and no note when there are none, since any answer goes.
        if (suggestsFromForm(field.config)) {
            const list = lists?.[field.key];
            const suggestions = list !== undefined && list.available ? list.options.map((option) => option.label) : [];

            return { ...field, config: { ...(field.config ?? {}), suggestions } };
        }
        if (!isLinked(field.config)) {
            return field;
        }

        const list = lists?.[field.key];
        const usable = list !== undefined && list.available;
        const note = !usable ? notes.unavailable : list.options.length === 0 ? notes.empty : null;
        const hint = note === null ? field.hint : [field.hint, note].filter((part) => part !== null && part !== undefined && part !== '').join(' ');

        return {
            ...field,
            hint,
            config: { ...(field.config ?? {}), options: usable ? list.options.map((option) => ({ ...option })) : [] },
        };
    };

    const fields = schema.version.schema.fields.map((field) => {
        const merged = withLists(field);
        if (!declaresFilter(field.config)) {
            return merged;
        }

        const own = sets === null ? null : sets.filter((set) => set.field_key === field.key).map((set) => ({ ...set, values: [...set.values] }));

        return { ...merged, config: { ...(merged.config ?? {}), choice_filter_sets: own } };
    });

    return { ...schema, version: { ...schema.version, schema: { ...schema.version.schema, fields } } };
}
