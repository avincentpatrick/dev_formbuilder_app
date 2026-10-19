/**
 * `insertAtCaret()` (M178, `R-75719ac4`). happy-dom has no `execCommand`, so these cases exercise the fallback path;
 * the native-undo half of the contract is `tests/e2e/builder-insert-question.spec.ts`'s.
 */
import { afterEach, describe, expect, it } from 'vitest';
import { insertAtCaret } from './insert-at-caret';

function box(value: string, caret: number, maxLength?: number): HTMLTextAreaElement {
    const area = document.createElement('textarea');
    area.value = value;
    if (maxLength !== undefined) area.maxLength = maxLength;
    document.body.appendChild(area);
    area.setSelectionRange(caret, caret);

    return area;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('insertAtCaret', () => {
    it('puts the text at the caret, not at the end, and leaves the caret after it', () => {
        const area = box('How old is ?', 11);

        expect(insertAtCaret(area, '${name}')).toBe(true);
        expect(area.value).toBe('How old is ${name}?');
        expect(area.selectionStart).toBe(18);
    });

    it('replaces what the author typed to find the question', () => {
        // "@na" typed at 11..14 — the picker passes where the "@" stands.
        const area = box('How old is @na?', 14);

        insertAtCaret(area, '${name}', 11);

        expect(area.value).toBe('How old is ${name}?');
    });

    it('tells a v-model it changed, as typing would', () => {
        const area = box('', 0);
        const heard: string[] = [];
        area.addEventListener('input', () => heard.push(area.value));

        insertAtCaret(area, '${q}');

        expect(heard).toEqual(['${q}']);
    });

    it('refuses an insert that would pass the box’s limit, rather than leaving half a reference', () => {
        const area = box('abcdefgh', 8, 10);

        expect(insertAtCaret(area, '${name}')).toBe(false);
        expect(area.value).toBe('abcdefgh');
    });
});
