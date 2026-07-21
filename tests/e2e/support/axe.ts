import { expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Shared composed-page gate helpers (used by both the authenticated responsive-axe scan and the guest
 * public-runtime scan): assert no horizontal overflow (Feature #5's responsive contract) and zero WCAG 2.2 AA
 * violations, and force the dark theme so axe measures the dark palette on the real composed page.
 */
export async function assertClean(page: Page, label: string): Promise<void> {
    // Park the pointer off every control so axe measures resting styles, not a `:hover` state left over from
    // the test's last click (a parked cursor over a primary button reads its lighter hover bg and mis-flags
    // its contrast — a test artifact, not a real violation).
    await page.mouse.move(0, 0);

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

export type Personalization = {
    accent?: 'blueprint' | 'teal';
    fontSize?: 'standard' | 'large' | 'extra_large';
    dyslexia?: boolean;
};

/**
 * Sibling of forceTheme for the other three §2.9 personalization axes (G11), driving the root
 * attributes directly rather than round-tripping through Settings — so a scan costs one page load.
 *
 * Follows the same "default = ABSENCE of the attribute" convention the server uses, so a scan with
 * `{}` measures exactly what an un-personalized user sees.
 *
 * The `document.fonts.ready` await is NOT optional. The dyslexia face is fetched only once a rule
 * using the family matches an element — i.e. only after the attribute below is set — so without
 * waiting, axe and the horizontal-overflow assertion measure the FALLBACK stack's glyph metrics.
 * OpenDyslexic is substantially wider than the system stack, so that is the difference between a
 * scan that means something and an intermittent failure that only reproduces under CI load.
 */
export async function forcePersonalization(page: Page, options: Personalization): Promise<void> {
    await page.emulateMedia({ reducedMotion: 'reduce' });

    await page.evaluate((opts) => {
        const html = document.documentElement;
        const set = (attribute: string, value: string | null): void => {
            if (value === null) html.removeAttribute(attribute);
            else html.setAttribute(attribute, value);
        };

        set('data-accent', opts.accent === 'teal' ? 'teal' : null);
        set('data-font-size', opts.fontSize && opts.fontSize !== 'standard' ? opts.fontSize : null);
        set('data-dyslexia-font', opts.dyslexia ? 'true' : null);
    }, options);

    await page.evaluate(() => document.fonts.ready.then(() => undefined));
}
