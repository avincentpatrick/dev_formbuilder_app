/**
 * The progress bar's chapters — M249 (`progress-chapters`, `D233`).
 *
 * A chapter is STARTED by a section (`chapter` set on it) and runs until the next section that starts one (`D233`
 * answer 1). This helper turns the form's sections and the steps currently visible into the chapters the bar draws.
 *
 * ⛔ THE GROUPS ARE BUILT OVER EVERY SECTION, IN ORDER, AND ONLY THEN FILTERED TO THE VISIBLE STEPS. A chapter whose
 * starting page is hidden by a condition still names its later pages: membership is a matter of position, and a
 * condition hiding one page must not move every later page into the chapter before it. A chapter with no visible page
 * is dropped, so the bar's "3 of 10" counts only chapters the respondent will meet.
 *
 * Pages before the first chapter — the lead block of top-level questions, and any section ahead of the first that starts
 * one — form a leading group with no name of its own; the bar names it after its first page.
 *
 * Null when no section in the form starts a chapter: the bar then draws exactly what it drew before M249.
 */
import { resolveText } from './schema-mapping';
import type { RenderSection } from './types';

/** The parts of a step this helper reads — a page's key and the section it belongs to (null for the lead block). */
export interface ChapterStep {
    key: string;
    sectionKey: string | null;
}

export interface Chapter<S extends ChapterStep = ChapterStep> {
    /** The starting section's key, or {@link LEADING_CHAPTER_KEY} for the pages before the first chapter. */
    key: string;
    /** Its name in the locale, or null for the leading group (the bar names it after its first page). */
    name: string | null;
    /** Its visible pages, in order — never empty. */
    steps: S[];
}

export const LEADING_CHAPTER_KEY = '__leading';

export function chaptersOf<S extends ChapterStep>(
    sections: readonly Pick<RenderSection, 'key' | 'chapter' | 'chapterTranslations'>[],
    steps: readonly S[],
    locale: string,
): Chapter<S>[] | null {
    if (!sections.some((section) => section.chapter !== null)) {
        return null;
    }

    const chapterOfSection = new Map<string, string>();
    const names = new Map<string, string | null>([[LEADING_CHAPTER_KEY, null]]);
    let current = LEADING_CHAPTER_KEY;
    for (const section of sections) {
        if (section.chapter !== null) {
            current = section.key;
            names.set(current, resolveText(section.chapter, section.chapterTranslations, locale));
        }
        chapterOfSection.set(section.key, current);
    }

    const chapters: Chapter<S>[] = [];
    for (const step of steps) {
        const key = step.sectionKey === null ? LEADING_CHAPTER_KEY : (chapterOfSection.get(step.sectionKey) ?? LEADING_CHAPTER_KEY);
        const last = chapters[chapters.length - 1];
        if (last !== undefined && last.key === key) {
            last.steps.push(step);
            continue;
        }
        chapters.push({ key, name: names.get(key) ?? null, steps: [step] });
    }

    return chapters;
}

/** A chapter's name as the bar and the announcer say it: the pages before the first chapter are named after their first page. */
export function chapterName<S extends ChapterStep>(chapter: Chapter<S>, textOf: (step: S) => string | null): string {
    const first = chapter.steps[0];

    return chapter.name ?? (first !== undefined ? textOf(first) : null) ?? 'Start';
}

/** The chapter holding a step, with its position among the chapters and the step's among the chapter's pages. */
export function chapterOfStep<S extends ChapterStep>(
    chapters: readonly Chapter<S>[],
    stepKey: string | undefined,
): { chapter: Chapter<S>; index: number; page: number } | null {
    for (const [index, chapter] of chapters.entries()) {
        const page = chapter.steps.findIndex((step) => step.key === stepKey);
        if (page !== -1) {
            return { chapter, index, page };
        }
    }

    return null;
}
