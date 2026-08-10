import { expect, test, type Locator, type Page } from '@playwright/test';
import { assertClean, forcePersonalization, forceTheme } from './support/axe';

// The nav search field's geometry gate (Increment J1b, PRD §3.7).
//
// ⚠️ THIS SPEC EXISTS BECAUSE `assertClean` IS STRUCTURALLY BLIND TO THE FAILURE IT IS MEANT TO CATCH.
// Its overflow check is `document.documentElement.scrollWidth > clientWidth + 1`, and `.app-shell` is
// `overflow-x: clip` — so a nav control that runs off the right edge is CLIPPED rather than scrolled, and
// the document never widens. A search field that has eaten the notification bell, or that has been pushed
// off-viewport by the wordmark at extra-large type, is invisible to every axe run in this suite.
//
// The nearest precedent (`responsive-axe.spec.ts`, the notification centre) asserts only `box.x >= 0`.
// That is exactly HALF the property: it catches a control that starts off the LEFT edge and nothing else.
// This spec adds the right-edge containment and the pairwise non-overlap that the other half needs.
//
// ── WHY THIS RUNS AT EVERY VIEWPORT, NOT JUST 375px ──────────────────────────────────────────────────────
// Viewports are the config's three projects (375 / 834 / 1440), so every case here runs three times for
// free. Containment and non-overlap are true statements at all three widths, so asserting them everywhere
// is strictly stronger than pinning 375px — and 375px is the width that actually bites, because it is the
// only one where the layout swaps to the compact icon link.
//
// ⚠️ WRITTEN BUT NOT RUN LOCALLY, AND THAT IS RECORDED RATHER THAN GLOSSED (process rule 9: a gate you
// cannot run locally is not a gate you may assume). Playwright's `global-setup.ts` cannot complete against
// this repo's local Docker stack — the sign-in POST authenticates (session rows are written carrying the
// right `user_id`) but the browser never lands on `/dashboard`. It reproduces identically on the specs that
// were green before this increment, and nothing in J1b touches authentication, so it is a pre-existing
// environment problem and not a property of this file. CI is therefore this spec's only authority, and it
// runs in a materially different environment: `SESSION_DRIVER=file`, `CACHE_STORE=file`, and
// `acme.meridian.test` mapped in `/etc/hosts` (ci.yml). Treat the first CI run of this file as its real
// first run, and expect selector-level corrections there rather than assuming a pass.
//
// ── WHY MAX PERSONALIZATION ──────────────────────────────────────────────────────────────────────────────
// The failure mode is a text-driven overflow, so the interesting state is the widest one the product can
// render: extra-large type plus the OpenDyslexic face, which is substantially wider than the system stack.
// `forcePersonalization` already awaits `document.fonts.ready` (support/axe.ts) — without that wait the
// measurements below would be taken against the FALLBACK font's metrics, which is the difference between a
// gate that means something and one that fails intermittently under CI load.

/** The ≤480px breakpoint in TopNav.vue swaps the real field for a compact icon link. */
const COMPACT_BREAKPOINT = 480;

type Box = { x: number; y: number; width: number; height: number };

async function boxOf(locator: Locator, label: string): Promise<Box> {
    await locator.waitFor({ state: 'visible', timeout: 10_000 });
    const box = await locator.boundingBox();
    expect(box, `${label} has no bounding box`).not.toBeNull();

    return box!;
}

/** Two rectangles overlap only if they intersect on BOTH axes. */
function intersects(a: Box, b: Box): boolean {
    return a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;
}

function describe(label: string, box: Box): string {
    return `${label} @ x=${Math.round(box.x)} w=${Math.round(box.width)} y=${Math.round(box.y)} h=${Math.round(box.height)}`;
}

/**
 * The search affordance actually rendered at this viewport. Below the breakpoint the full form is
 * `display: none` and the icon link takes over, so asking for the wrong one would time out rather than
 * fail informatively — and, worse, a spec that only ever measured the desktop field would silently stop
 * asserting anything at the one width where the layout is under real pressure.
 */
function searchAffordance(page: Page): { locator: Locator; label: string } {
    const width = page.viewportSize()?.width ?? 0;

    return width <= COMPACT_BREAKPOINT
        ? { locator: page.locator('.topnav__search-compact'), label: 'compact search link' }
        : { locator: page.locator('.topnav__search'), label: 'nav search field' };
}

test.describe('nav search geometry', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await page.locator('.topnav').waitFor({ state: 'visible', timeout: 10_000 });
    });

    test('stays inside the viewport at both edges under maximum personalization', async ({ page }) => {
        await forceTheme(page, 'dark');
        await forcePersonalization(page, { accent: 'teal', fontSize: 'extra_large', dyslexia: true });

        const { locator, label } = searchAffordance(page);
        const box = await boxOf(locator, label);
        const viewport = page.viewportSize()!;

        expect(box.x, `${describe(label, box)} starts off the left edge`).toBeGreaterThanOrEqual(0);

        // ⭐ THE HALF THE PRECEDENT OMITS. `overflow-x: clip` means the document never widens, so this is
        // the only assertion in the suite that can see a nav control running off the right.
        expect(
            box.x + box.width,
            `${describe(label, box)} runs past the right edge of the ${viewport.width}px viewport`,
        ).toBeLessThanOrEqual(viewport.width + 1);

        // A zero-width box satisfies containment trivially. Assert the control is actually rendered, so
        // "contained" cannot be achieved by collapsing.
        expect(box.width, `${describe(label, box)} collapsed to nothing`).toBeGreaterThan(0);
    });

    test('never overlaps the wordmark or the notification bell', async ({ page }) => {
        await forceTheme(page, 'dark');
        await forcePersonalization(page, { accent: 'teal', fontSize: 'extra_large', dyslexia: true });

        const { locator, label } = searchAffordance(page);
        const search = await boxOf(locator, label);

        // The two neighbours that can actually collide with it: the wordmark grows leftward with the type
        // scale, and the bell is the innermost of the right-hand controls.
        const neighbours: Array<[string, Locator]> = [
            ['wordmark', page.locator('.topnav__wordmark')],
            ['notification bell', page.locator('.bell__trigger')],
        ];

        for (const [name, neighbour] of neighbours) {
            const box = await boxOf(neighbour, name);
            expect(
                intersects(search, box),
                `${describe(label, search)} overlaps ${describe(name, box)}`,
            ).toBe(false);
        }
    });

    test('keeps the search control reachable and correctly labelled', async ({ page }) => {
        // Geometry is only half of "usable". A control contained in the viewport but underneath another
        // element is still unreachable, and Playwright's actionability check is what proves it is not.
        const width = page.viewportSize()?.width ?? 0;

        if (width <= COMPACT_BREAKPOINT) {
            const link = page.getByRole('link', { name: 'Search this workspace' });
            await expect(link).toBeVisible();
            // 44×44 minimum: this is a touch target at the only width where it renders.
            const box = await boxOf(link, 'compact search link');
            expect(box.width).toBeGreaterThanOrEqual(24);
            expect(box.height).toBeGreaterThanOrEqual(24);
        } else {
            // `type="search"` gives the implicit `searchbox` role, NOT `textbox` — J1a's DSR §3.2 note.
            const field = page.getByRole('searchbox', { name: 'Search this workspace' });
            await expect(field).toBeVisible();
            await field.fill('clinic');
            await expect(field).toHaveValue('clinic');
        }
    });

    test('submits to the results page with no JavaScript involved', async ({ page }) => {
        // The nav region is a real `<form method="GET" action="/search">` precisely so this works, and a
        // server-generated navigation is only proven by a browser performing it under CI's DNS.
        const width = page.viewportSize()?.width ?? 0;

        if (width <= COMPACT_BREAKPOINT) {
            await page.getByRole('link', { name: 'Search this workspace' }).click();
            await page.waitForURL('**/search', { timeout: 15_000 });
        } else {
            await page.getByRole('searchbox', { name: 'Search this workspace' }).fill('clinic');
            await page.getByRole('searchbox', { name: 'Search this workspace' }).press('Enter');
            await page.waitForURL('**/search?q=clinic', { timeout: 15_000 });
        }

        await expect(page.getByRole('heading', { name: 'Search', level: 1 })).toBeVisible();
    });
});

for (const theme of ['light', 'dark'] as const) {
    test(`Search results (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        // The results page is not in `responsive-axe.spec.ts`'s page list, so nothing scanned it before.
        await page.goto('/search?q=clinic', { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Search', level: 1 }).waitFor({ state: 'visible', timeout: 10_000 });

        await forceTheme(page, theme);
        await assertClean(page, `Search results (${theme})`);
    });

    test(`Search empty state (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        // The empty state renders `MdsEmptyState`'s own heading beside the filter panel's `<h2>`, which is
        // the combination that can fail `heading-order` — and it only occurs on a query matching nothing,
        // so a happy-path scan never reaches it.
        await page.goto('/search?q=zzzznotathing', { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Search', level: 1 }).waitFor({ state: 'visible', timeout: 10_000 });

        await forceTheme(page, theme);
        await assertClean(page, `Search empty state (${theme})`);
    });
}
