/**
 * M189 (`version-diff-ui`, `D169`) — the Compare page's reading of two versions, pure so it is tested without mounting.
 *
 * The server compares (`App\Services\Forms\VersionComparison`): each question added, removed, changed or the same, each
 * changed setting with its value on both sides, and the marks form-versioning-schema-migration.md §5 and
 * piping-output-encoding-design.md §6.1 ask for. This turns that into what the page draws — groups by section in the
 * order a respondent meets them, and every setting in the builder's own words (`settingWords`, shared with the settings
 * panel's conflict dialog and the History tab), each side worded with its own version's question labels.
 */

import { sectionSettingWords, settingWords, type DiffWords } from '@/components/builder/conflict-diff';
import type { BuilderEnums } from '@/components/builder/types';

export type CompareStatus = 'added' | 'removed' | 'changed' | 'same';
export type CompareMark = 'comparability' | 'asked' | 'piping';

export interface CompareChange {
    setting: string;
    before: unknown;
    after: unknown;
    too_large?: boolean;
}

export interface CompareQuestion {
    key: string;
    label: string;
    field_type: string;
    section_key: string | null;
    status: CompareStatus;
    changes: CompareChange[];
    /** Why answers may not compare across the two versions: `type`, `required`, `indexing` or `removed`. */
    breaking: string | null;
    marks: CompareMark[];
}

export interface CompareSection {
    key: string;
    label: string;
    status: CompareStatus;
    changes: CompareChange[];
    marks: CompareMark[];
}

export interface Comparison {
    sections: CompareSection[];
    questions: CompareQuestion[];
}

export interface CompareWords {
    enums: BuilderEnums;
    type_labels: Record<string, string>;
}

export interface CompareLabels {
    from: Record<string, string>;
    to: Record<string, string>;
}

/** One changed setting, in words: its name, and its value in the From and the To version. */
export interface ChangeLine {
    setting: string;
    from: string;
    to: string;
}

/** The badge each mark shows. Words, never colour alone (WCAG 1.4.1). */
export const MARK_WORDS: Record<CompareMark, string> = {
    comparability: 'Answers may not compare across versions',
    asked: 'Changes who is asked',
    piping: 'Shows different answers in its text',
};

const BREAKING_WORDS: Record<string, string> = {
    type: 'its question type changed',
    required: 'it became required',
    indexing: 'how it is indexed for reporting changed',
    removed: 'the newer version no longer asks it',
};

/** Why a question's answers may not compare, as the end of a sentence, or null. */
export function breakingWords(code: string | null): string | null {
    return code === null ? null : (BREAKING_WORDS[code] ?? null);
}

const TRANSLATION_NAMES: Record<string, string> = {
    label_translations: 'Label, other languages',
    hint_translations: 'Help text, other languages',
    description_translations: 'Description, other languages',
};

const TOO_LARGE = 'Too large to show';

function translationWords(value: unknown): string {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) return 'None';
    const entries = Object.entries(value as Record<string, unknown>).filter(([, text]) => text !== null && text !== '');
    if (entries.length === 0) return 'None';

    return entries
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([locale, text]) => `${locale}: ${String(text)}`)
        .join(' · ');
}

function diffWords(words: CompareWords, labels: Record<string, string>): DiffWords {
    return { enums: words.enums, labels, typeLabel: (value) => words.type_labels[value] ?? value };
}

/**
 * Every changed setting of a question or a section, in the panel's words. Each side is worded with its own version's
 * labels, so a condition naming a question renamed since reads as each version read it.
 */
export function changeLines(kind: 'question' | 'section', changes: readonly CompareChange[], words: CompareWords, labels: CompareLabels): ChangeLine[] {
    const word = kind === 'question' ? settingWords : sectionSettingWords;
    const fromWords = diffWords(words, labels.from);
    const toWords = diffWords(words, labels.to);

    return changes.map((change) => {
        const translation = TRANSLATION_NAMES[change.setting];
        if (translation !== undefined) {
            return change.too_large === true
                ? { setting: translation, from: TOO_LARGE, to: TOO_LARGE }
                : { setting: translation, from: translationWords(change.before), to: translationWords(change.after) };
        }
        if (change.too_large === true) {
            return { setting: word(change.setting, null, null, fromWords).setting, from: TOO_LARGE, to: TOO_LARGE };
        }

        const from = word(change.setting, change.before, change.after, fromWords);
        const to = word(change.setting, change.before, change.after, toWords);

        return { setting: from.setting, from: from.a, to: to.b };
    });
}

export interface CompareGroup {
    /** The section's key, or null for the questions outside every section. */
    key: string | null;
    label: string;
    /** The section's own comparison — null for the top level, which is not a section. */
    section: CompareSection | null;
    questions: CompareQuestion[];
}

export interface CompareFilter {
    /** Only what carries a mark: answers that may not compare, who is asked, piped answers. */
    onlyMarked: boolean;
    /** Unchanged questions and sections too, not only the differences. */
    showUnchanged: boolean;
}

function visible(item: { status: CompareStatus; marks: readonly CompareMark[] }, filter: CompareFilter): boolean {
    if (filter.onlyMarked) return item.marks.length > 0;

    return filter.showUnchanged || item.status !== 'same';
}

/**
 * The questions grouped by section, in the order a respondent meets them: the top level first, then each section in the
 * newer version's order, then the sections only the older version had. A question removed with its section sits in it;
 * one whose section the comparison does not know (a hand-edited snapshot) is kept, in a group of its own.
 */
export function groups(comparison: Comparison, filter: CompareFilter): CompareGroup[] {
    const bySection = new Map<string | null, CompareQuestion[]>();
    for (const question of comparison.questions) {
        if (!visible(question, filter)) continue;
        const key = question.section_key;
        bySection.set(key, [...(bySection.get(key) ?? []), question]);
    }

    const out: CompareGroup[] = [];
    const top = bySection.get(null);
    if (top !== undefined) out.push({ key: null, label: 'Not in a section', section: null, questions: top });

    for (const section of comparison.sections) {
        const questions = bySection.get(section.key) ?? [];
        bySection.delete(section.key);
        if (questions.length === 0 && !visible(section, filter)) continue;
        out.push({ key: section.key, label: section.label, section, questions });
    }

    for (const [key, questions] of bySection) {
        if (key === null) continue;
        out.push({ key, label: key, section: null, questions });
    }

    return out;
}

export interface CompareCounts {
    changed: number;
    added: number;
    removed: number;
    same: number;
    marked: number;
}

export function counts(comparison: Comparison): CompareCounts {
    const out: CompareCounts = { changed: 0, added: 0, removed: 0, same: 0, marked: 0 };
    for (const question of comparison.questions) {
        out[question.status] += 1;
        if (question.marks.length > 0) out.marked += 1;
    }

    return out;
}

/** The summary line under the pickers: "Questions: 2 changed, 1 added, 1 removed and 12 unchanged." */
export function summary(c: CompareCounts): string {
    const differences = c.changed + c.added + c.removed;
    if (differences === 0) return c.same === 0 ? 'Neither version has any questions.' : `No questions differ — all ${c.same} are the same.`;

    const parts = [
        c.changed > 0 ? `${c.changed} changed` : null,
        c.added > 0 ? `${c.added} added` : null,
        c.removed > 0 ? `${c.removed} removed` : null,
        c.same > 0 ? `${c.same} unchanged` : null,
    ].filter((part): part is string => part !== null);
    const last = parts.pop() as string;
    const list = parts.length > 0 ? `${parts.join(', ')} and ${last}` : last;

    return `Questions: ${list}.`;
}
