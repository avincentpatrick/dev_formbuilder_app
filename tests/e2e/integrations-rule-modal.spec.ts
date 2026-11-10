import { expect, test, type Locator, type Page } from '@playwright/test';
import { assertClean, forcePersonalization, forceTheme } from './support/axe';

/**
 * M233 (R-03715adf) — the Google Sheets rule modal, opened for a NEW rule.
 *
 * Until this file no spec opened `RuleFormModal` at all, so `SheetsRuleFields`' segmented control — one of
 * `D28`'s four stretch-clamped hosts — had no gate of any kind, and neither did the modal's colours.
 *
 * ⛔ A NEW RULE IS SAFE TO OPEN HERE, AND THE ROUTE GUARD BELOW IS WHAT KEEPS IT SO. `RuleFormModal` loads no
 * channels for a spreadsheet provider, and `SheetsRuleFields` in create mode reads only the local
 * `/integrations/connections/{id}/columns` catalog. The sidecars that reach Google (`/destinations`) fire for a
 * SAVED rule, whose editor re-inspects its sheet, or for a pressed Find or Create, which is why
 * `responsive-axe.spec.ts` still never opens a saved rule's modal. Any `/destinations` request here is aborted and
 * fails the case, so no future change can make this spec reach Google quietly.
 *
 * ⛔ AND A PLAIN SPILL READ ON THIS HOST IS GREEN BY CONSTRUCTION, WHICH WAS MEASURED (M233). A segment is a flex
 * item with `min-width: auto` and a label that can break, so it shrinks to its LONGEST WORD, and the copy's
 * longest is "already". At 375, 834 and 1440px, at default text and at `extra_large` + dyslexia, the modal body
 * and the control read 0px in all twelve cases, with the host's `flex-wrap: wrap` and with it forced to `nowrap`.
 * Squeezed, the labels break onto more lines instead of overflowing. So the stress is what makes this case able
 * to fail: labels that cannot break, the worst case of a long word. With the host's wrap the two segments stack
 * and nothing spills. Without it the modal body scrolls 246px sideways at 375px and 103px at 834 and 1440px.
 * At default text both whole labels fit side by side, so the stress also needs the large type.
 *
 * Not covered here, and owned by `R-db28b120`: the `.mds-field` and `.encode-field` hosts, and widening the
 * builder's `.config` read beyond the 375px project.
 */

const DESTINATION_SIDECARS = '**/integrations/connections/*/destinations**';

async function openNewSheetsRule(page: Page): Promise<{ dialog: Locator; destinationCalls: string[] }> {
    const destinationCalls: string[] = [];

    await page.route(DESTINATION_SIDECARS, (route) => {
        destinationCalls.push(`${route.request().method()} ${route.request().url()}`);

        return route.abort();
    });

    await page.goto('/integrations', { waitUntil: 'networkidle' });

    // By the card's heading, not by a rule name: the seeded Sheets grant is the one whose label is the
    // provider's own, and "Add rule" exists once per connection card.
    const card = page
        .locator('section[aria-label="Connected workspace"]')
        .filter({ has: page.getByRole('heading', { level: 2, name: 'Google Sheets', exact: true }) });
    await expect(card, 'the seeded Google Sheets connection is missing, so the rule modal cannot be opened').toHaveCount(1);
    await card.getByRole('button', { name: 'Add rule' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    // The catalog request settles before anything is measured or scanned.
    await page.waitForLoadState('networkidle');

    return { dialog, destinationCalls };
}

async function spill(target: Locator): Promise<number> {
    return target.evaluate((el) => el.scrollWidth - el.clientWidth);
}

test('Sheets rule modal at extra_large + dyslexia font — the destination switch stays inside the modal, even with labels that cannot break', async ({
    page,
}) => {
    const { dialog, destinationCalls } = await openNewSheetsRule(page);
    await forcePersonalization(page, { fontSize: 'extra_large', dyslexia: true });

    const control = dialog.getByRole('group', { name: 'Where should the rows go?' });
    await expect(control, 'the destination switch did not render, so the measurements would be vacuous').toBeVisible();
    await expect(
        control.locator('input[type="radio"]'),
        'the switch did not render as two segments, so a spill of zero proves nothing about the control that can spill',
    ).toHaveCount(2);

    // `.mds-modal__body` is `overflow-y: auto`, so `overflow-x` computes to `auto` and a spill becomes a sideways
    // scrollbar INSIDE the modal, which `assertNoHorizontalOverflow` skips by construction.
    const body = dialog.locator('.mds-modal__body');

    expect(await spill(body), 'the Sheets rule modal scrolls sideways at maximum personalization').toBeLessThanOrEqual(1);
    expect(await spill(control), 'the destination switch overflows its own box at maximum personalization').toBeLessThanOrEqual(1);

    // The worst case: each label behaves as one long word. Applied as a page style rather than a fixture,
    // because the copy is fixed in code.
    await page.addStyleTag({ content: '.sheets-fields .mds-segmented__seg { white-space: nowrap !important; }' });

    const segments = control.locator('.mds-segmented__seg');
    await expect(segments.first(), 'the unbreakable-label stress did not apply').toHaveCSS('white-space', 'nowrap');

    expect(
        await spill(body),
        'with labels that cannot break, the Sheets rule modal scrolls sideways: the host lost its flex-wrap',
    ).toBeLessThanOrEqual(1);
    expect(
        await spill(control),
        'with labels that cannot break, the destination switch overflows its own box: the host lost its flex-wrap',
    ).toBeLessThanOrEqual(1);

    // ⛔ THE GUARD ON THE STRESS ITSELF. Two whole labels side by side means the stress never needed the wrap,
    // and a spill of zero measured there would look identical to a pass.
    const tops = await segments.evaluateAll((els) => els.map((el) => el.getBoundingClientRect().top));
    expect(
        tops[1] - tops[0],
        'both whole labels still fit on one line, so this stress could not have exercised the wrap',
    ).toBeGreaterThan(8);

    expect(destinationCalls, 'opening a new Sheets rule called a sidecar that reaches Google').toEqual([]);
});

for (const theme of ['light', 'dark'] as const) {
    test(`Sheets rule modal, new rule (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
        const { destinationCalls } = await openNewSheetsRule(page);
        await forceTheme(page, theme);
        await assertClean(page, `Sheets rule modal (${theme})`);
        expect(destinationCalls, 'opening a new Sheets rule called a sidecar that reaches Google').toEqual([]);
    });
}
