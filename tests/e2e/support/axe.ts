import { expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Shared composed-page gate helpers (used by both the authenticated responsive-axe scan and the guest
 * public-runtime scan): assert no horizontal overflow (Feature #5's responsive contract) and zero WCAG 2.2 AA
 * violations, and force the dark theme so axe measures the dark palette on the real composed page.
 */
/**
 * Wait for the next paint to actually land.
 *
 * Two frames, not one: the first callback fires BEFORE the paint that applies the recalculated style, so a
 * single `requestAnimationFrame` still returns too early. Exported because BOTH things this module does to a
 * page before scanning it — flipping the theme and parking the pointer — invalidate style and settle a frame
 * later, and only one of them used to wait.
 */
export async function settlePaint(page: Page): Promise<void> {
    await page.evaluate(
        () => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))),
    );
}

export async function assertClean(page: Page, label: string): Promise<void> {
    // Park the pointer off every control so axe measures resting styles, not a `:hover` state left over from
    // the test's last click (a parked cursor over a primary button reads its lighter hover bg and mis-flags
    // its contrast — a test artifact, not a real violation).
    await page.mouse.move(0, 0);

    // ⚠️ AND WAIT FOR THE UN-HOVER TO PAINT — the half J1e left behind, and it came back to collect.
    // Moving the pointer off a control starts a transition on the control it left, exactly as flipping the
    // theme starts one on everything; "collapsing the window is not closing it" applies identically. J2b's
    // CI run flaked on `builder-axe.spec.ts` "share panel, live link (dark)" with 93 violations reporting
    // `#6f99b5` on `#123350` — the background settled to the real dark `bg-surface` token while the
    // FOREGROUND was still an intermediate that appears in no token file, over
    // `.share__row--actions > .mds-button--secondary`: the button the test had just clicked. A `transparent`
    // secondary button whose only opaque state is `:hover` is precisely the shape that produces this.
    await settlePaint(page);

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

    // ⚠️ AND THEN WAIT FOR THE FLIP TO ACTUALLY LAND, WHICH THE PARAGRAPH ABOVE PROMISED AND DID NOT DO.
    // Collapsing transitions to 1ms shortens the window; it does not close it. `setAttribute` only
    // invalidates style — the recalc and paint happen on a later frame — so a scan issued immediately
    // afterwards can still read the OLD foreground against the NEW background. J1e hit both halves of that
    // window on `builder-axe.spec.ts:104` at mobile: one run read `#7da9c4` on `#1d4260` (4.17, mostly
    // flipped) and the next read `#1c4b72` on `#123350` (1.42, dark-on-dark) across 309 lines of
    // violations — the "233 violations at once = styles not settled" shape this repo already had on record
    // as a standing flake in the same file.
    //
    await settlePaint(page);
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
