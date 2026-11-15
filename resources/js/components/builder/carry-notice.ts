import type { CarryNotice, CarryUnread } from './useBuilderStore';

/**
 * M242 (`R-ade0b598`) — what the settings panel says under a key after a rename carried its references: how many other
 * rows followed, each text that named the old key but could not be read (so it may still name it), and what happened to
 * the thank-you message (`D227`). Plain words, one sentence each, in the order the panel shows them.
 */

const COLUMN_WORDS: Record<string, string> = {
    relevant_expression: '“Show … only when” condition',
    default_value: 'default value formula',
    calculated_formula: 'formula',
    constraint: 'custom rule',
};

/** "2 other questions and 1 section that named “age” now name “age_years”." — null when nothing else named it. */
export function carriedSentence(notice: CarryNotice): string | null {
    const parts: string[] = [];
    if (notice.fields > 0) parts.push(`${notice.fields} other ${notice.fields === 1 ? 'question' : 'questions'}`);
    if (notice.sections > 0) parts.push(`${notice.sections} ${notice.sections === 1 ? 'section' : 'sections'}`);
    if (parts.length === 0) return null;
    const one = notice.fields + notice.sections === 1;

    return `${parts.join(' and ')} that named “${notice.from}” now ${one ? 'names' : 'name'} “${notice.to}”.`;
}

/** "“Weight”'s “Show … only when” condition could not be read, so it may still name “age”." */
export function unreadSentence(unread: CarryUnread, from: string): string {
    const what = COLUMN_WORDS[unread.column] ?? 'text';
    const name = unread.label.trim() === '' ? (unread.kind === 'section' ? 'A section' : 'A question') : `“${unread.label}”`;

    return `${name}’s ${what} could not be read, so it may still name “${from}”.`;
}

/** The thank-you message's outcome, or null when it did not name the old key. */
export function confirmationSentence(notice: CarryNotice): string | null {
    if (notice.confirmation === 'carried') return `The thank-you message now names “${notice.to}” too.`;
    if (notice.confirmation === 'left') {
        return `The thank-you message is shared with the live form, so it still names “${notice.from}”. Change it just before you publish.`;
    }

    return null;
}

/** Every sentence for one notice, in order — empty when the rename named nothing else. */
export function carrySentences(notice: CarryNotice): string[] {
    return [
        carriedSentence(notice),
        ...notice.unread.map((u) => unreadSentence(u, notice.from)),
        confirmationSentence(notice),
    ].filter((s): s is string => s !== null);
}
