import { describe, expect, it } from 'vitest';
import { pageIsWide, stepsOnScreen } from '../lib/page-width';

// M247 (`grid-wide-sticky-explorer`, `D231`): a section marked "Wide page" widens the page while it is on screen.
const sections = [
    { key: 'about', pageWidth: 'normal' as const },
    { key: 'indicators', pageWidth: 'wide' as const },
    { key: 'closing', pageWidth: 'normal' as const },
];
const lead = { key: 'lead', sectionKey: null };
const about = { key: 'about', sectionKey: 'about' };
const indicators = { key: 'indicators', sectionKey: 'indicators' };
const closing = { key: 'closing', sectionKey: 'closing' };

describe('pageIsWide', () => {
    it('is wide while the step on screen belongs to a wide section, and only then', () => {
        expect(pageIsWide(sections, stepsOnScreen(false, [lead, about, indicators, closing], indicators))).toBe(true);
        expect(pageIsWide(sections, stepsOnScreen(false, [lead, about, indicators, closing], about))).toBe(false);
        expect(pageIsWide(sections, stepsOnScreen(false, [lead, about, indicators, closing], closing))).toBe(false);
    });

    it('is never wide for the lead questions, or with no step on screen', () => {
        expect(pageIsWide(sections, stepsOnScreen(false, [lead, indicators], lead))).toBe(false);
        expect(pageIsWide(sections, stepsOnScreen(false, [lead, indicators], null))).toBe(false);
    });

    it('in single-page mode is wide when any shown section is wide, and not when the wide one is hidden', () => {
        expect(pageIsWide(sections, stepsOnScreen(true, [lead, about, indicators, closing], about))).toBe(true);
        expect(pageIsWide(sections, stepsOnScreen(true, [lead, about, closing], about))).toBe(false);
    });

    it('is never wide when no section asks for it', () => {
        const plain = sections.map((section) => ({ ...section, pageWidth: 'normal' as const }));
        expect(pageIsWide(plain, stepsOnScreen(true, [lead, about, indicators, closing], null))).toBe(false);
    });
});
