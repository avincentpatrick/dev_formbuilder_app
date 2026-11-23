// Pure helpers for the agreement list (M250, `agreement-report`, `D234`; ADR-0025 §D10). No Vue, no fetch: the
// server decides every number and status, and these only put them into words, so each is a table of inputs and
// outputs under test.

import type { BadgeVariant } from '@meridian/design-system';
import type { AgreementLine, AgreementPick, AgreementResult, AgreementStatus, Option } from './types';

const STATUS: Record<AgreementStatus, { label: string; variant: BadgeVariant }> = {
    short_list: { label: 'On the short list', variant: 'success' },
    discuss: { label: 'To discuss', variant: 'warning' },
    too_few: { label: 'Too few views', variant: 'neutral' },
};

export function statusLabel(status: AgreementStatus): string {
    return STATUS[status].label;
}

export function statusVariant(status: AgreementStatus): BadgeVariant {
    return STATUS[status].variant;
}

/** "75%" or "66.6%" as the server rounded it — down, so a share below the line never reads at it; a dash for no view. */
export function shareLabel(share: number | null): string {
    return share === null ? '—' : `${share.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`;
}

/** The answer an item's share counts: its most common answer, or the answers the list counts as agreeing. */
export function countedLabel(line: AgreementLine): string {
    return line.counted.length === 0 ? '—' : line.counted.map((answer) => answer.label).join(' + ');
}

/** Every answer given, with how many gave it, the left-out ones marked: "Agree 12 · Disagree 3 · No view 2 (left out)". */
export function answersSentence(line: AgreementLine): string {
    return line.answers
        .filter((answer) => answer.responses > 0)
        .map((answer) => `${answer.label} ${answer.responses.toLocaleString()}${answer.left_out ? ' (left out)' : ''}`)
        .join(' · ');
}

/** Where an item sits, beside its own label: the question and column of a table row, or the section of a question. */
export function lineContext(line: AgreementLine): string {
    if (line.column !== null) return `${line.question} · ${line.column}`;
    if (line.label !== line.question) return line.question;

    return line.section;
}

/** The rule the list was counted by, in one sentence. */
export function ruleSentence(list: AgreementResult): string {
    const leftOut = list.leave_out.length === 0 ? '' : ` Left out: ${list.leave_out.map((answer) => answer.label).join(', ')}.`;
    const agreeing =
        list.agreeing.length === 0
            ? ' Each item’s share is of its most common answer.'
            : ` Counted as agreeing where offered: ${list.agreeing.map((answer) => answer.label).join(', ')}; elsewhere, each item’s most common answer.`;

    return `On the short list: at least ${list.threshold}% agreeing, out of at least ${list.min_views} who gave a view.${leftOut}${agreeing}`;
}

/** The picks grouped by the section they sit in, in the form's order. */
export function pickGroups(picks: AgreementPick[]): { section: string; picks: AgreementPick[] }[] {
    const groups: { section: string; picks: AgreementPick[] }[] = [];

    for (const pick of picks) {
        const section = pick.section === '' ? 'Questions' : pick.section;
        const last = groups[groups.length - 1];

        if (last !== undefined && last.section === section) {
            last.picks.push(pick);
        } else {
            groups.push({ section, picks: [pick] });
        }
    }

    return groups;
}

/** How a pick reads in the picker: "Your view · Terms of reference · 7 rows". */
export function pickLabel(pick: AgreementPick): string {
    if (pick.kind === 'question') return pick.label;

    const rows = `${pick.rows} ${pick.rows === 1 ? 'row' : 'rows'}`;

    return pick.column === null ? `${pick.question} · ${rows}` : `${pick.label} · ${pick.question} · ${rows}`;
}

/** How many items the chosen picks put on the list — a question is one, a table column one per row. */
export function itemCount(picks: AgreementPick[], chosen: string[]): number {
    const set = new Set(chosen);

    return picks.reduce((total, pick) => (set.has(pick.value) ? total + Math.max(1, pick.rows) : total), 0);
}

/**
 * The answers the chosen questions offer, once each by value, with the first wording seen — what "Leave out" and
 * "Count as agreeing" pick from. A value chosen earlier that no chosen question offers any more is kept, by value, so
 * unticking a question never silently changes the rule.
 */
export function answerChoices(picks: AgreementPick[], chosen: string[], kept: string[] = []): Option[] {
    const set = new Set(chosen);
    const seen = new Map<string, string>();

    for (const pick of picks) {
        if (!set.has(pick.value)) continue;

        for (const option of pick.options) {
            if (!seen.has(option.value)) seen.set(option.value, option.label);
        }
    }

    for (const value of kept) {
        if (!seen.has(value)) seen.set(value, value);
    }

    return [...seen.entries()].map(([value, label]) => ({ value, label }));
}
