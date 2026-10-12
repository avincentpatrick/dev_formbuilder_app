import { test, type Locator, type Page } from '@playwright/test';
import { openBuilder, showBuilderPane } from '../support/navigate';
import { forceTheme, settleAnimations } from '../support/axe';

/**
 * The Help page's two builder pictures (M163, `R-ccc0be00`, `D115`) — run by `playwright.capture.config.ts`, never by CI.
 *
 *     docker compose run --rm e2e test --config=playwright.capture.config.ts
 *
 * It opens the seeded "Clinic Intake" draft (seven question types), selects its Single choice question, puts a numbered
 * badge beside each part the manual describes, and writes one PNG per panel into `public/images/help/`. The badge
 * numbers are the item numbers of the list under each picture in `resources/js/components/help/manual.ts` — change one,
 * change the other, and re-run this file. `manual.test.ts` then asks for the pictures' new sizes.
 *
 * ⚠️ NOTHING IS TYPED. Selecting a question and reading a tab writes nothing; typing would autosave into the seeded
 * draft every other builder spec reads.
 *
 * ⚠️ THE BADGES GET A GUTTER OF THEIR OWN. Drawn at a target's top-left they covered its first letters ("dd a field"),
 * because both panels start their text a few pixels from the edge. So for the capture only, both side columns are
 * widened by the gutter and padded on the left by it — the content keeps its real width, and each badge sits in the
 * empty strip beside the line it marks.
 */

const OUT = 'public/images/help';
const GUTTER = 30;
const BADGE = 22;

// Tall enough that neither panel scrolls: the panes are viewport-height and clip their content.
test.use({ viewport: { width: 1440, height: 1900 }, deviceScaleFactor: 2 });

type Spot = 'gutter' | 'before';

/** Numbered badges, drawn INSIDE the pane (it clips overflow): in the gutter beside a line, or just before an element. */
async function badge(pane: Locator, targets: { at: Locator; spot?: Spot }[]): Promise<void> {
    const paneBox = await pane.boundingBox();
    if (paneBox === null) throw new Error('pane not on screen');

    const points: { x: number; y: number }[] = [];
    for (const { at, spot = 'gutter' } of targets) {
        const box = await at.first().boundingBox();
        if (box === null) throw new Error(`badge target not on screen: ${at.toString()}`);
        // Centred on the target's first line, so a tall target (a text area, a tab strip) is marked at its label.
        const y = box.y - paneBox.y + Math.max(0, (Math.min(box.height, 32) - BADGE) / 2);
        const x = spot === 'gutter' ? (GUTTER - BADGE) / 2 : box.x - paneBox.x - BADGE - 4;
        points.push({ x, y });
    }

    await pane.evaluate(
        (el, { spots, size }) => {
            const host = el as HTMLElement;
            if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
            spots.forEach((point, i) => {
                const mark = document.createElement('span');
                mark.textContent = String(i + 1);
                mark.setAttribute('data-help-badge', '');
                Object.assign(mark.style, {
                    position: 'absolute',
                    left: `${point.x}px`,
                    top: `${point.y}px`,
                    zIndex: '50',
                    width: `${size}px`,
                    height: `${size}px`,
                    boxSizing: 'border-box',
                    borderRadius: '999px',
                    background: '#c2410c',
                    color: '#ffffff',
                    border: '2px solid #ffffff',
                    boxShadow: '0 1px 3px rgba(0,0,0,.35)',
                    font: `700 12px/${size - 4}px system-ui, sans-serif`,
                    textAlign: 'center',
                    pointerEvents: 'none',
                });
                host.appendChild(mark);
            });
        },
        { spots: points, size: BADGE },
    );
}

/** The pane from its top to just below `lastContent`, so the picture ends where the described part ends. */
async function shoot(page: Page, pane: Locator, lastContent: Locator, file: string): Promise<void> {
    const paneBox = await pane.boundingBox();
    const last = await lastContent.boundingBox();
    if (paneBox === null || last === null) throw new Error('nothing to shoot');
    const height = Math.round(Math.min(paneBox.height, last.y + last.height - paneBox.y + 16));
    await page.screenshot({ path: `${OUT}/${file}`, clip: { x: paneBox.x, y: paneBox.y, width: paneBox.width, height } });
}

test('the builder panels, for the Help page', async ({ page }) => {
    await openBuilder(page, 'Clinic Intake');
    await showBuilderPane(page, 'settings');
    await page.locator('[role="tab"]').first().waitFor({ timeout: 10_000 });
    await forceTheme(page, 'light');
    await page.addStyleTag({
        content: `
            .builder__panes { grid-template-columns: ${260 + GUTTER}px minmax(0, 1fr) ${340 + GUTTER}px !important; }
            .builder__pane--left, .builder__pane--config { padding-left: ${GUTTER}px !important; box-sizing: border-box; }
        `,
    });

    // ── The left panel, down to the end of the Choice group (where the first "Adv" is) ──────────────────────
    const left = page.locator('.builder__pane--left');
    await badge(left, [
        { at: left.locator('.builder__left-tabs') }, // 1 Fields / Library
        { at: left.locator('.palette__title') }, // 2 Add a field
        { at: left.locator('.palette__group-head') }, // 3 a group
        { at: left.locator('.palette__item') }, // 4 a question type
        { at: left.locator('.palette__adv'), spot: 'before' }, // 5 Adv
    ]);
    await page.mouse.move(0, 0);
    await settleAnimations(page);
    await shoot(page, left, left.locator('.palette__group').nth(3), 'left-panel.png');

    // ── The right panel, a Single choice question selected ─────────────────────────────────────────────────
    await page.locator('.canvas__field-main', { has: page.locator('.canvas__field-label', { hasText: /^Single choice$/ }) }).click();
    const right = page.locator('.builder__pane--config');
    await right.locator('[data-field-type-control]').waitFor();
    await right.getByRole('group', { name: 'Requiredness' }).waitFor();
    await badge(right, [
        { at: right.locator('[role="tablist"]') }, // 1 the tabs
        { at: right.locator('[data-field-type-control]') }, // 2 Question type / Change type
        { at: right.locator('.mds-field', { has: page.getByLabel('Label', { exact: true }) }) }, // 3 Label
        { at: right.locator('.mds-field', { has: page.getByLabel('Help text', { exact: true }) }) }, // 4 Help text
        { at: right.locator('.mds-field', { has: page.getByLabel('Placeholder', { exact: true }) }) }, // 5 Placeholder
        { at: right.locator('.config__group', { hasText: 'Requiredness' }) }, // 6 Requiredness
    ]);
    await page.mouse.move(0, 0);
    await settleAnimations(page);
    await shoot(page, right, right.locator('.config__group', { hasText: 'Requiredness' }).last(), 'right-panel.png');
});
