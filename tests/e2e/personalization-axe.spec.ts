import { test } from '@playwright/test';
import { assertClean, forcePersonalization, forceTheme } from './support/axe';
import { openBuilder } from './support/navigate';

/**
 * Increment G11 — the personalization axes (design-system-reference.md §2.9).
 *
 * Two things need covering, and they need covering differently:
 *
 *  · ACCENT is a colour surface, so it needs axe in both themes. Until G11 nothing in this suite had
 *    ever set data-accent, which is exactly why the shipped C2 accent stub could carry a 1.74:1
 *    action-primary-fg on the dark ground undetected. Loop A closes that hole permanently.
 *
 *  · TEXT SIZE and the DYSLEXIA FACE are reflow surfaces — they change glyph widths and line boxes,
 *    so what matters is that nothing overflows or collides.
 *
 * Deliberately NOT a full matrix. Crossing font-size × accent × dyslexia × theme × viewport would be
 * 72 runs on `workers: 1`. Instead: extra_large strictly dominates large for reflow, and dark+teal is
 * the only genuinely new colour pairing — so Loop B runs the maximum-stress combination only, and the
 * existing responsive-axe baseline (untouched) keeps covering the un-personalized default.
 *
 * Total: 6 + 12 = 18 runs across the three viewport projects.
 */

// ── Loop A: accent contrast ──────────────────────────────────────────────────────────────────────
// /forms is the densest colour surface in the app — primary CTA, DataTable, status badges, pagination
// — so it exercises the accent's action/focus tokens against the most backgrounds per page load.
for (const theme of ['light', 'dark'] as const) {
    test(`Teal accent on Forms (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        await page.goto('/forms', { waitUntil: 'networkidle' });
        await forceTheme(page, theme);
        await forcePersonalization(page, { accent: 'teal' });
        await assertClean(page, `Forms (teal, ${theme})`);
    });
}

// ── Loop B: maximum reflow ───────────────────────────────────────────────────────────────────────
// extra_large text + the wider OpenDyslexic body face + teal, on the dark ground. If any composed page
// is going to overflow or lose contrast under personalization, it is this combination.
const reflowPages = [
    // Settings owns the four Appearance rows themselves — the widest of the new controls lives here.
    { name: 'Settings', path: '/settings' },
    { name: 'Forms', path: '/forms' },
    { name: 'Submissions', path: '/submissions' },
];

for (const target of reflowPages) {
    test(`${target.name} at extra_large + dyslexia font + teal — accessible & no horizontal overflow`, async ({
        page,
    }) => {
        await page.goto(target.path, { waitUntil: 'networkidle' });
        await forceTheme(page, 'dark');
        await forcePersonalization(page, {
            accent: 'teal',
            fontSize: 'extra_large',
            dyslexia: true,
        });
        await assertClean(page, `${target.name} (max personalization)`);
    });
}

// The builder is the densest layout in the app (three panes, a canvas, a tabbed config panel), so it is
// the most likely place for a 25% text growth to collide. Scanned once, at maximum stress.
test('Builder at extra_large + dyslexia font + teal — accessible & no horizontal overflow', async ({
    page,
}) => {
    await openBuilder(page, 'Community Health Survey');
    await page.getByRole('tab').first().waitFor({ state: 'visible', timeout: 10_000 });

    await forceTheme(page, 'dark');
    await forcePersonalization(page, { accent: 'teal', fontSize: 'extra_large', dyslexia: true });
    await assertClean(page, 'Builder (max personalization)');
});
