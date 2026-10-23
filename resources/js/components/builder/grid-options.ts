/**
 * A grid's `{value, label}[]` lists — its rows, columns and cell choices — edited one entry at a time (M185, `R-7418e6bd`).
 *
 * Lifted out of `GridOptionList.vue` so the Grid tab and the builder's Preview add and rename an entry the same way: a new
 * entry takes the first free `<prefix>_<n>` from the list's length on and is labelled `<Singular> <n>`; a rename changes
 * the label only — the value is what an answer is saved under — and keeps every other key the entry carries (a matrix
 * column's type, choices and required flag). Each returns a fresh array; completeness and distinctness are publish's.
 */
export interface GridEntry {
    value: string;
    label: string;
}

/** The list with one entry added, valued `<prefix>_<n>` for the first `n` no entry holds, from the list's length up. */
export function addGridEntry<T extends GridEntry>(list: readonly T[], prefix: string, singular: string): T[] {
    const existing = new Set(list.map((entry) => entry.value));
    let n = list.length + 1;
    while (existing.has(`${prefix}_${n}`)) n++;

    return [...list, { value: `${prefix}_${n}`, label: `${singular} ${n}` } as T];
}

/** The list with one entry's value or label changed, every other key it carries kept. */
export function patchGridEntry<T extends GridEntry>(list: readonly T[], index: number, patch: Partial<GridEntry>): T[] {
    return list.map((entry, i) => (i === index ? { ...entry, ...patch } : entry));
}

/** The list with one entry's label changed — never its value, which answers are saved under. */
export function renameGridEntry<T extends GridEntry>(list: readonly T[], index: number, label: string): T[] {
    return patchGridEntry(list, index, { label });
}

export function removeGridEntry<T extends GridEntry>(list: readonly T[], index: number): T[] {
    return list.filter((_, i) => i !== index);
}
