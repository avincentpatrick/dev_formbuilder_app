/**
 * Up to two initials for a person's name (DSR §3.12, Increment J4a).
 *
 * A separate module rather than a computed inside the SFC so the rules can be table-tested without mounting
 * anything — there are eight of them and most are about scripts, which is exactly the kind of thing that
 * gets an ASCII-only fixture set and ships broken.
 *
 * Deliberately NOT re-exported from the package barrel: no consumer needs it once `AccountMenu` stops
 * hand-rolling its own, and an exported helper is a second way to render an avatar's text.
 */

/**
 * The first GRAPHEME of a word, not its first code unit.
 *
 * ⚠️ THIS FIXES A LIVE BUG RATHER THAN GUARDING A HYPOTHETICAL ONE. `AccountMenu.vue` indexed with `[0]`,
 * which takes half a surrogate pair for any name outside the BMP and renders U+FFFD — the replacement
 * character, in the workspace's own account menu. `Intl.Segmenter` is the correct tool and is available in
 * every browser this product supports; the spread fallback is for the test environment and for any runtime
 * that lacks it, and is still correct for surrogate pairs (it is only wrong for combining marks, which
 * degrade to the base character rather than to a broken glyph).
 */
function firstGrapheme(word: string): string {
    const Segmenter = (Intl as { Segmenter?: typeof Intl.Segmenter }).Segmenter;

    if (typeof Segmenter === 'function') {
        const [first] = new Segmenter(undefined, { granularity: 'grapheme' }).segment(word);

        return first?.segment ?? '';
    }

    return [...word][0] ?? '';
}

/**
 * `null`, an empty string or whitespace answers `'?'` rather than an empty glyph: an avatar is a fixed
 * shape and an empty one reads as a rendering failure.
 */
export function avatarInitials(name: string | null | undefined): string {
    /**
     * ⚠️ A WORD ONLY COUNTS IF IT STARTS WITH A LETTER OR A NUMBER, AND THE FIRST DRAFT HAD NO SUCH RULE.
     * Without it a name of `"!!!"` renders `!` in a circle, which reads as a rendering fault rather than as
     * a person. `\p{L}`/`\p{N}` is the script-neutral way to ask — an `[A-Za-z]` test would discard every
     * Arabic, CJK and Devanagari name, which is precisely the failure mode this module exists to avoid.
     */
    const words = (name ?? '')
        .trim()
        .split(/\s+/)
        .filter((word) => /^[\p{L}\p{N}]/u.test(firstGrapheme(word)));

    if (words.length === 0) {
        return '?';
    }

    /**
     * ⚠️ FIRST AND LAST, WHICH DIVERGES FROM WHAT `AccountMenu` DID. It took `words.slice(0, 2)` — the
     * first two words — so "Maria de los Santos" was `MD`. First-and-last is the convention every roster
     * the user has seen elsewhere follows, and the two agree on every two-word name, which is nearly all of
     * them. Recorded because it is a behaviour change on a surface that already shipped.
     */
    const picked = words.length === 1 ? [words[0]] : [words[0], words[words.length - 1]];

    /**
     * ⚠️ ONE GRAPHEME FOR A ONE-WORD NAME, AND THIS IS THE LOAD-BEARING CHOICE RATHER THAN AN OMISSION.
     * Taking two letters reads as an acronym for a Latin mononym ("PR" for Prince) and is *correct* for
     * CJK, where 山田太郎's first two characters are the surname. A rule that is right for one script and
     * wrong for another is worse than a uniformly modest one, and the glyph is decorative anyway — the
     * person's full name is always rendered beside it.
     */
    const letters = picked.map(firstGrapheme).join('');

    /**
     * `toUpperCase`, NOT `toLocaleUpperCase`. The locale-aware form makes the rendered glyph depend on the
     * reader's browser locale, so the same roster would show a different letter to two colleagues — for a
     * decorative, `aria-hidden` character, determinism beats the Turkish dotted-İ nicety. Scripts with no
     * case mapping (Arabic, CJK, Devanagari) pass through unchanged, which is correct.
     */
    /**
     * ⚠️ NO `.slice(0, 2)` HERE, AND THE FIRST DRAFT HAD ONE. `letters` is already at most two graphemes by
     * construction — one per picked word, and at most two words are picked — so the cap was redundant. It
     * was also actively wrong: `slice` counts UTF-16 code units, and an astral initial is two of them, so
     * `𝐀S` was truncated to `𝐀`. That is the same class of defect as the `[0]` indexing this module
     * replaces, reintroduced two lines below the comment warning about it, and the astral test case is what
     * caught it.
     */
    const initials = letters.toUpperCase();

    // Belt and braces: the letter/number filter above should make this unreachable, and an empty chip is
    // bad enough that it is worth one comparison to be certain.
    return initials === '' ? '?' : initials;
}
