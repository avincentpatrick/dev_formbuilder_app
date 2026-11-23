/**
 * M247 (`grid-wide-sticky-explorer`, `D231`) — whether the respondent's page is drawn wide.
 *
 * A section the author marked "Wide page" widens the page while it is on screen, so a big grid can be a table: the
 * page takes the scanned-paper view's wide column (`RuntimeShell`'s `wide`, 90rem from a 64rem viewport). A phone is
 * unchanged — the column only widens from 64rem, and a grid is cards below its own 30em anyway.
 *
 *  - A form shown a step at a time is wide while the current step belongs to a wide section.
 *  - A single-page form shows every section at once, so its page is wide when ANY shown section is wide.
 *  - A step outside every section (the lead questions) is never wide on its own account.
 */

import type { RenderSection } from './types';

/** The part of a step this decision reads: the section it belongs to, or null for the lead questions. */
export interface PageWidthStep {
    sectionKey: string | null;
}

export function pageIsWide(sections: readonly Pick<RenderSection, 'key' | 'pageWidth'>[], shown: readonly PageWidthStep[]): boolean {
    const wide = new Set(sections.filter((section) => section.pageWidth === 'wide').map((section) => section.key));

    return shown.some((step) => step.sectionKey !== null && wide.has(step.sectionKey));
}

/** The steps on screen: every shown step on a single page, otherwise only the current one. */
export function stepsOnScreen<T extends PageWidthStep>(singlePage: boolean, visible: readonly T[], current: T | null): readonly T[] {
    if (singlePage) {
        return visible;
    }

    return current === null ? [] : [current];
}
