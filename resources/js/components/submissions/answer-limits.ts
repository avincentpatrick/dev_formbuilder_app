/**
 * M243 (`R-1da063f4`) — a question's answer limits as the respondent meets them, for `FieldInput.vue`: the live word count
 * under a text box, and a multi-select that cannot take one choice too many and whose "only answer" choices clear and
 * lock the rest. Pure, so each rule is tested without mounting the control.
 *
 * The engines hold the answer to the same limits on submit (`SemanticValidator::collectAnswerLimitErrors()` and its
 * twin); nothing here is the check. The count is the engine's own `wordCount()`, so the number a person sees is the
 * number they are held to.
 */
import { wordCount } from '../../../public-runtime/engine/words';

/** A lower and upper limit, each a whole number of at least 1 or null. */
export interface Bounds {
    min: number | null;
    max: number | null;
}

/** What `FieldInput` is handed (`EncodeField.limits`): each adapter builds it from the question's `config`. */
export interface AnswerLimitsView {
    words: Bounds | null;
    choices: Bounds | null;
    exclusive: string[];
}

function grouped(value: number): string {
    return value.toLocaleString('en-US');
}

function words(count: number): string {
    return `${grouped(count)} ${count === 1 ? 'word' : 'words'}`;
}

/** How many words the answer holds. */
export { wordCount };

/**
 * The line under a text box: "12 of 1,500 words", "12 words (at least 50)", "12 words (50 to 1,500)", and over the
 * maximum, "1,512 of 1,500 words: 12 too many" — said in words, so the error tone is never colour alone.
 */
export function wordCounterText(count: number, bounds: Bounds): string {
    const { min, max } = bounds;
    const over = max !== null && count > max ? `: ${grouped(count - max)} too many` : '';

    if (min !== null && max !== null) return `${words(count)} (${grouped(min)} to ${grouped(max)})${over}`;
    if (max !== null) return `${grouped(count)} of ${grouped(max)} words${over}`;
    if (min !== null) return `${words(count)} (at least ${grouped(min)})`;

    return words(count);
}

/** The answer after a box is ticked or unticked: ticking an "only answer" choice clears every other one. */
export function nextChoices(current: string[], value: string, checked: boolean, exclusive: ReadonlySet<string>): string[] {
    if (!checked) return current.filter((held) => held !== value);
    if (exclusive.has(value)) return [value];

    return [...current, value];
}

/**
 * Whether an unticked box is locked. A ticked box never is, so a person can always take a choice back, and an "only answer"
 * box never is, because ticking it replaces everything. Every other box locks while an "only answer" choice is ticked, or
 * once the most choices allowed are ticked.
 */
export function choiceLocked(value: string, current: string[], max: number | null, exclusive: ReadonlySet<string>): boolean {
    if (current.includes(value) || exclusive.has(value)) return false;
    if (current.some((held) => exclusive.has(held))) return true;

    return max !== null && new Set(current).size >= max;
}

/**
 * The note under a multi-select's question: what the limits are, and once a lock is on, why. Null when it has none.
 *
 * @param labelOf the label a choice's value shows as
 */
export function choiceNote(current: string[], choices: Bounds | null, exclusive: ReadonlySet<string>, labelOf: (value: string) => string): string | null {
    const only = current.find((held) => exclusive.has(held));
    if (only !== undefined && current.length === 1) return `“${labelOf(only)}” is chosen on its own. Untick it to choose others.`;

    const min = choices?.min ?? null;
    const max = choices?.max ?? null;
    const held = new Set(current).size;
    // An answer can already hold too many — a resumed draft, a scan, a version that allowed more — and is shown as it is.
    if (max !== null && held > max) return `${grouped(held)} chosen, and at most ${grouped(max)} are allowed. Untick ${grouped(held - max)}.`;
    if (max !== null && held === max && only === undefined) return `${grouped(max)} of ${grouped(max)} chosen. Untick one to choose another.`;

    if (min !== null && max !== null) return min === max ? `Choose ${grouped(max)}.` : `Choose ${grouped(min)} to ${grouped(max)}.`;
    if (max !== null) return `Choose up to ${grouped(max)}.`;
    if (min !== null) return `Choose at least ${grouped(min)}.`;

    return null;
}
