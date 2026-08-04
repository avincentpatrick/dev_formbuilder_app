import type { AppliedQuery } from './types';

/**
 * The declaration → query-string serializer (Increment H24b2). ONE of them, deliberately.
 *
 * The filter bar, the export link and the save-view form all need to express the same declaration as
 * params. Three call sites building it independently is how a page comes to send `top_n` on a reload and
 * forget it on an export — so they all come here.
 *
 * `v` is never emitted. The server stamps `AnalyticsQuery::SCHEMA_VERSION` itself, and echoing the value
 * back would make the browser a second author of the persisted schema version.
 */
export function toQueryParams(
    applied: AppliedQuery,
    extra: Record<string, string> = {},
): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = {
        from: applied.from,
        to: applied.to,
        timezone: applied.timezone,
        granularity: applied.granularity,
        selection: applied.selection,
        axis: applied.axis,
        top_n: String(applied.top_n),
    };

    // Omitted rather than sent empty: `form_ids=[]` under `selection: forms` is refused by the VO, and an
    // empty `statuses[]` in the URL is noise that makes a shared link harder to read.
    if (applied.form_ids.length > 0) params.form_ids = applied.form_ids;
    if (applied.statuses.length > 0) params.statuses = applied.statuses;
    if (applied.sources.length > 0) params.sources = applied.sources;
    if (applied.locales.length > 0) params.locales = applied.locales;
    if (applied.scope_node_id !== null) params.scope_node_id = applied.scope_node_id;

    return { ...params, ...extra };
}

/**
 * The prop keys a filter change re-fetches.
 *
 * `filters` and `summary` are deliberately ABSENT. The option catalogues are tenant-scoped, never
 * range-scoped, so they cannot change when a filter changes — and leaving them out of every reload is
 * what makes shipping the full IANA timezone list affordable.
 *
 * `applied` and `views` are deliberately PRESENT. `applied` because the page re-seeds its controls from
 * it, which is the only thing that keeps them honest when the server coerced a value; `views` because
 * each view's `is_applied` flag changes with every filter, and re-deriving it client-side would mean
 * re-implementing the VO's default normalisation in TypeScript.
 */
export const RELOAD_ONLY: readonly string[] = ['applied', 'refusal', 'report', 'questions', 'question', 'views'];

/** A question selection re-fetches only its own aggregate — the report is unchanged by it. */
export const QUESTION_ONLY: readonly string[] = ['applied', 'question'];

/**
 * The same params as a real browser navigation, for the export download.
 *
 * Array params take PHP's `key[]=value` convention, which is what `URLSearchParams` cannot do on its own
 * and what Laravel's request parser expects.
 */
export function toSearchString(params: Record<string, string | string[]>): string {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value)) {
            for (const item of value) search.append(`${key}[]`, item);
        } else {
            search.append(key, value);
        }
    }

    return search.toString();
}
