/** A question label's limit — the server's `max:500` and the `form_fields.label` column — and where its count appears. */
export const LABEL_MAX = 500;
export const LABEL_COUNT_FROM = 450;

/**
 * M176 (`R-923c5041`) — a question's label is one line of text, however long it runs. The label boxes became growing
 * textareas so a long question can be read whole; a textarea takes a pasted line break, and a label must not keep one
 * (the respondent, the PDF and every export show it as a single line). Each break, and the spaces around it, becomes one
 * space.
 */
export function oneLine(text: string): string {
    return text.replace(/[ \t]*[\r\n]+[ \t]*/g, ' ');
}
