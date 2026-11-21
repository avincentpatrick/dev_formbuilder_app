/**
 * A form's glossary on the client (M245, `R-d0bbb7bb`): what a note's marked word and the Glossary button read.
 *
 * Pure, so the guest bundle may import it (`guest-bundle-boundary.test.ts` allows builder MODULES, never components).
 * The server is `App\Support\Forms\Glossary`; `glossaryKey()` is its `key()`, and both read
 * `tests/fixtures/glossary-term-keys.json`, so neither can change how a word finds its term alone. The bounds are
 * mirrored here and pinned by `GlossaryMirrorDriftTest`.
 *
 * Entries arrive already in the server's one order (by key, then term, byte-wise) and are never re-sorted here: a
 * JavaScript string sort compares UTF-16 units, not bytes, and would disagree with the printed form on rare letters.
 */

/** Mirror of `Glossary`'s bounds; the term bound is `ContentBlocks::MAX_TERM_LENGTH` (`CONTENT_LIMITS.maxTermLength`). */
export const GLOSSARY_LIMITS = {
    maxEntries: 200,
    maxDefinitionLength: 1000,
} as const;

export interface GlossaryEntry {
    term: string;
    definition: string;
}

/**
 * The matching key: spacing runs collapsed to one space, the ends trimmed, the whole lower-cased. The spacing set is
 * spelled out to match `Glossary::collapse()`: JavaScript's `\v` is the vertical tab alone, PCRE's is seven characters.
 */
export function glossaryKey(term: string): string {
    return term
        .replace(/[\p{Zs}\t\n\v\f\r\u0085\u2028\u2029]+/gu, ' ')
        .trim()
        .toLowerCase();
}

/**
 * A glossary read defensively from whatever carried it (a snapshot, a builder prop): entries that are not a term and
 * a definition are skipped, never thrown on, and a missing glossary is empty. The order is kept as it arrived.
 */
export function glossaryEntries(raw: unknown): GlossaryEntry[] {
    if (!Array.isArray(raw)) {
        return [];
    }

    const entries: GlossaryEntry[] = [];
    for (const entry of raw) {
        if (entry !== null && typeof entry === 'object') {
            const { term, definition } = entry as Record<string, unknown>;
            if (typeof term === 'string' && typeof definition === 'string') {
                entries.push({ term, definition });
            }
        }
    }
    return entries;
}

/** The entry a marked word opens, or null when the form's glossary has no such term. */
export function findEntry(entries: readonly GlossaryEntry[], term: string): GlossaryEntry | null {
    const key = glossaryKey(term);
    return entries.find((entry) => glossaryKey(entry.term) === key) ?? null;
}
