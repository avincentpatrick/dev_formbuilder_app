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

/**
 * A question's label as a sentence quotes it: whole when short, else its first words and an ellipsis — the conflict dialog and
 * its notice quoted a 470-character label in full (M176's real-browser look), which buried what they had to say.
 */
export function shortLabel(text: string, max = 60): string {
    const label = oneLine(text).trim();
    if (label.length <= max) return label;
    const cut = label.slice(0, max);
    const space = cut.lastIndexOf(' ');

    return `${(space > max / 2 ? cut.slice(0, space) : cut).trimEnd()}…`;
}
