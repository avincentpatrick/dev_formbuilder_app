/**
 * How many words a text holds (M243, `R-1da063f4`) — the twin of PHP `AnswerLimits::words()`, pinned in both engines by
 * `tests/golden/validation/answer_rules.json`. The respondent's live counter reads this same function, so the count a
 * person sees is the count the server holds them to.
 *
 * ⛔ NEVER `\s`. PCRE's `\s` under `/u` matches U+0085 and U+180E and JavaScript's does not, and JavaScript's matches
 * U+FEFF and PCRE's does not, so a count built on `\s` would pass here and be refused on submit. Both engines list the
 * Unicode White_Space set instead.
 */
const SEPARATORS = /[\u0009-\u000D\u0020\u0085\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]+/u;

/** The number of maximal runs of anything that is not a separator. */
export function wordCount(text: string): number {
    let count = 0;
    for (const piece of text.split(SEPARATORS)) {
        if (piece !== '') {
            count++;
        }
    }

    return count;
}
