/**
 * Put text into a text box at the caret, as if the author had typed it (M178, `R-75719ac4`) — the "Insert a question"
 * picker's one write.
 *
 * ⛔ `execCommand('insertText')` FIRST, ON PURPOSE. It is the one route that keeps the box's own undo (Ctrl+Z takes the
 * insert back as ONE step) and fires a real `input` event, so a `v-model` on the component hears it exactly as it hears
 * typing. Assigning `.value` or calling `setRangeText()` empties the native undo stack. Both are kept only as the
 * fallback where `execCommand` is missing or refuses (happy-dom has none, so unit tests exercise only the fallback and
 * `tests/e2e/builder-insert-question.spec.ts` carries the undo claim).
 *
 * `replaceFrom` widens the selection leftwards first — the `@na` an author typed to find a question is replaced by the
 * reference, not left beside it. An insert that would pass the box's `maxlength` is refused whole rather than cut to a
 * half-written `${na`, and the caller says so.
 */
export function insertAtCaret(box: HTMLTextAreaElement | HTMLInputElement, text: string, replaceFrom?: number): boolean {
    const end = box.selectionEnd ?? box.value.length;
    const start = Math.min(replaceFrom ?? box.selectionStart ?? end, end);

    if (box.maxLength > 0 && box.value.length - (end - start) + text.length > box.maxLength) {
        return false;
    }

    box.focus();
    box.setSelectionRange(start, end);

    const inserted = typeof document.execCommand === 'function' && document.execCommand('insertText', false, text);

    if (!inserted) {
        box.setRangeText(text, start, end);
        box.setSelectionRange(start + text.length, start + text.length);
        box.dispatchEvent(new Event('input', { bubbles: true }));
    }

    return true;
}
