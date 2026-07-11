import { expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Shared composed-page gate helpers (used by both the authenticated responsive-axe scan and the guest
 * public-runtime scan): assert no horizontal overflow (Feature #5's responsive contract) and zero WCAG 2.2 AA
 * violations, and force the dark theme so axe measures the dark palette on the real composed page.
 */
export async function assertClean(page: Page, label: string): Promise<void> {
    const overflows = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(overflows, `${label} scrolls horizontally at this viewport`).toBe(false);

    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

    expect(
        results.violations,
        results.violations
            .map((v) => `${v.id}: ${v.help} → ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`)
            .join('\n'),
    ).toEqual([]);
}

export async function forceTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    // Emulate reduced motion so the design system's central duration guard collapses every transition to 1ms:
    // otherwise axe can measure an element (e.g. a button's background-color) mid theme-flip transition and read
    // an intermediate, failing contrast — a timing flake that surfaces on heavier composed pages.
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.evaluate((t) => {
        if (t === 'dark') document.documentElement.setAttribute('data-theme-mode', 'dark');
        else document.documentElement.removeAttribute('data-theme-mode');
    }, theme);
}
