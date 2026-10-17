/**
 * How the builder compares two copies of a row — shared by the store's own-write checks and the conflict dialog's list of
 * differences (M176, `R-164579e8`), so the dialog never calls two copies different that the store would call the same.
 */

/** Keys sorted at every depth: Postgres `jsonb` hands a config back in its own key order, not the one it was sent in. */
export function canonical(value: unknown): unknown {
    if (Array.isArray(value)) return value.map(canonical);
    if (value !== null && typeof value === 'object') {
        const object = value as Record<string, unknown>;

        return Object.fromEntries(Object.keys(object).sort().map((key) => [key, canonical(object[key])]));
    }

    return value;
}
