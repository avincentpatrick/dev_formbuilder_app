import { expect, test, type Page } from '@playwright/test';
import { settlePaint } from './support/axe';
import { openConsole } from './support/console';
import { formEntry, openBuilder } from './support/navigate';

/**
 * Increment JR4 — the two properties this increment adds, neither of which any existing gate can see.
 * (JR5 added a third at the bottom of this file, for the same structural reason: the builder's compact
 * layout is a container query, and a container query is invisible to every other gate in this repo.)
 *
 * ⚠️ `assertClean` CANNOT STAND IN FOR EITHER OF THESE, AND THAT IS STRUCTURAL RATHER THAN AN OVERSIGHT.
 * `.app-shell` is `overflow-x: clip` (`AppLayout.vue`), so a table wider than its region is CLIPPED, not
 * scrolled: `document.documentElement.scrollWidth` never grows and the shared overflow assertion stays
 * green over a sideways-scrolling strip. The same blindness is why `search-nav.spec.ts` measures bounding
 * boxes rather than document width. And a content column's width is invisible at 1440 — the widest project
 * in the matrix — so every width case below sets its own viewport (M173 removed the column's cap).
 *
 * So this file measures ELEMENTS: the scroll wrapper's own overflow, and the content column's own width.
 */

// Every one of these mounts an unconditional `MdsDataTable` with 4+ columns (5+ counting the action cell),
// so `--stackable` is present on all of them and the E2eSeeder gives each one rows. `/integrations` is
// deliberately absent: it renders one table PER connection inside a `v-for`, so its table count depends on
// fixture data in a way that would make the anti-vacuity check below fragile rather than useful.
const listPages = [
    { name: 'Submissions', path: '/submissions' },
    { name: 'Members', path: '/members' },
    { name: 'Webhooks', path: '/webhooks' },
    { name: 'Audit log', path: '/audit-log' },
    { name: 'Feedback', path: '/feedback' },
    { name: 'Forms (table view)', path: '/forms?view=table' },
];

for (const p of listPages) {
    test(`${p.name} — no dense table scrolls sideways below the collapse threshold`, async ({ page }, info) => {
        test.skip(
            info.project.name === 'desktop',
            'Above 56em of container a dense table is DESIGNED to scroll — the seven-column forms table ' +
                'needs ~1136px and gets it at 1440. What this asserts is the band BELOW the threshold, ' +
                'which is the 375 and 834 projects.',
        );

        await page.goto(p.path, { waitUntil: 'networkidle' });
        await settlePaint(page);

        const scrollers = page.locator('.mds-table__frame--stackable .mds-table__scroll');
        const count = await scrollers.count();

        // Not vacuous: a renamed class, or a page that stopped rendering a table, must fail here loudly
        // rather than pass over zero elements.
        expect(count, `${p.path} rendered no stackable table`).toBeGreaterThan(0);

        for (let i = 0; i < count; i++) {
            const overflow = await scrollers.nth(i).evaluate((el) => el.scrollWidth - el.clientWidth);

            expect(overflow, `table ${i} on ${p.path} still scrolls sideways at this viewport`).toBeLessThanOrEqual(1);
        }
    });
}

// ── M131 — THE FORMS TABLE'S ACTION CLUSTER, COLLAPSED TO CARDS ─────────────────────────────────────────
// The same clip, one element deeper. Below the 56em threshold each table row IS a card with no scroll region,
// and `Index.vue` used to keep the action cluster on one line in BOTH layouts. Measured when M131 added the
// tenth action ("Move to folder"): ten actions are 293px against a 341px cell at 375px, so it still fits —
// but nothing could have said so, because the scroll-wrapper check above cannot see a cluster (the wrapper is
// not a scroll container down here) and neither can `assertClean` (the shell clips). This case can.
test('Forms (table view) — no row action runs past its card below the collapse threshold', async ({ page }, info) => {
    test.skip(info.project.name === 'desktop', 'Above the threshold the row is a table row and scrolls by design.');

    await page.goto('/forms?view=table', { waitUntil: 'networkidle' });
    await settlePaint(page);

    const rows = page.locator('.mds-table__frame--stackable .mds-table__row');
    const count = await rows.count();
    expect(count, 'the forms table rendered no rows').toBeGreaterThan(0);

    let measured = 0;
    for (let i = 0; i < count; i++) {
        // BOTH edges: the cluster is right-aligned in its cell, so a cluster wider than its card grows to the
        // LEFT. A right-edge-only check stayed green while a forced-wide cluster ran 62px past the card's
        // left edge at 375px (measured, M131); this one reads 62 and 58 there and at 834px.
        const spill = await rows.nth(i).evaluate((row) => {
            const cluster = row.querySelector('.form-actions');
            if (cluster === null) return null;

            const c = cluster.getBoundingClientRect();
            const r = row.getBoundingClientRect();

            return Math.max(c.right - r.right, r.left - c.left);
        });
        if (spill === null) continue;

        measured++;
        expect(spill, `row ${i}'s action cluster runs past its card`).toBeLessThanOrEqual(1);
    }

    // Anti-vacuity: the seeded Owner sees every action on most rows, so a renamed class must fail here.
    expect(measured, 'no row carried an action cluster to measure').toBeGreaterThan(0);
});

// ── M173 — NO PAGE IS CAPPED ────────────────────────────────────────────────────────────────────────────────
// *"some pages only occupies the middle part of the page specially when screen is wide. can we make every page occupy
// the whole width? no content on the middle only. so no blank spaces on the left and right but still make it mobile
// responsive."* (the user, 2026-10-14; `D120`). JR4 capped every page at 1200px and ten list pages at 1600px; the shell
// now caps none, and each page lays its own width out. Measured at 1920 and 2560 because the widest project is 1440,
// where the old cap never bound (1440 − 240 − 64 = 1136): no project in the matrix could have seen it either way.

/** The shell's scroll region (less its scrollbar) against the column inside it. */
async function shellFill(page: Page): Promise<{ region: number; inner: number; padding: string }> {
    return page.evaluate(() => {
        const region = document.querySelector('.app-shell__content')!;
        const inner = document.querySelector('.app-shell__inner')!;

        return {
            region: region.clientWidth,
            inner: Math.round(inner.getBoundingClientRect().width),
            padding: getComputedStyle(inner).paddingLeft,
        };
    });
}

test('every page fills the content region at 1920 and 2560 — no centred column', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'Sets its own viewport — running it three times proves nothing.');

    await page.setViewportSize({ width: 1920, height: 1080 });

    // A list page (1600px under JR4), the settings page (1200px, JR4's worked example of a page it kept narrow) and the
    // dashboard.
    for (const path of ['/submissions', '/settings', '/dashboard']) {
        await page.goto(path, { waitUntil: 'networkidle' });
        const m = await shellFill(page);

        expect(Math.abs(m.inner - m.region), `${path} at 1920: ${JSON.stringify(m)}`).toBeLessThanOrEqual(1);
        // One more step of padding from 1920px, so a page's content never meets the edge of a wide screen.
        expect(m.padding, `${path} at 1920`).toBe('48px');
    }

    await page.setViewportSize({ width: 2560, height: 1440 });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    const wide = await shellFill(page);
    expect(Math.abs(wide.inner - wide.region), `/dashboard at 2560: ${JSON.stringify(wide)}`).toBeLessThanOrEqual(1);

    // ...and below 1920 the padding is what it always was.
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    expect((await shellFill(page)).padding, '/dashboard at 1440').toBe('32px');
});

// ── JR5 — THE BUILDER'S COMPACT LAYOUT ──────────────────────────────────────────────────────────────────
// Same blindness, second surface, which is why this case lives here rather than in a new file: the header
// above describes a general problem (`.app-shell` is `overflow-x: clip`, so `assertClean` reads a document
// width that can never grow), not a JR4-specific one.
//
// What no other gate in this repo can see: below `@container (max-width: 60em)` of the builder's own box
// exactly ONE pane is displayed and the other two are `display: none`. A broken `--show-*` mapping — a typo
// in one CSS selector — would hide all three and leave a blank workspace that axe reports as perfectly
// clean, `assertClean` reports as non-overflowing, and every source-text assertion in
// `builder-layout.test.ts` still passes. Counting the panes that actually have a box is the only thing
// that catches it.
test('the builder shows exactly one pane below its threshold, and three above', async ({ page }, info) => {
    await openBuilder(page, 'Community Health Survey');
    await page.locator('.builder__panes').waitFor({ state: 'visible', timeout: 30_000 });
    await settlePaint(page);

    const panes = page.locator('.builder__pane');

    // `display: none`, never `v-if` — three gates in this suite need these attached at every width.
    expect(await panes.count(), 'the three panes must stay in the DOM at every width').toBe(3);

    const shown = await panes.evaluateAll(
        (els) => els.filter((el) => (el as HTMLElement).offsetParent !== null).length,
    );
    const compact = await page.locator('.builder__pane-switch').isVisible();

    expect(shown, compact ? 'compact: exactly one pane' : 'wide: all three panes').toBe(compact ? 1 : 3);

    // Anti-vacuity in BOTH directions, and it is what pins the threshold itself. The builder's box is
    // `viewport − sidebar`: 375 − 0 (drawer) and 834 − 64 are far below 60em (960px), while 1440 − 240 is
    // 1200px and above it. A threshold that drifted either way would leave a project on the wrong side.
    expect(compact, `${info.project.name} landed on the wrong side of 60em`).toBe(
        info.project.name !== 'desktop',
    );
});

// ── M171 — THE USER'S THREE COMPLAINTS, MEASURED ────────────────────────────────────────────────────────────────
// Each is a property of laid-out boxes, so no Vitest case can see it (happy-dom lays out nothing) and neither can axe or
// `assertClean`. Each case was run red against the unfixed pages first.

// *"there are some large words in the form overview specially the accepting responses and last response date is being
// wraped making the word cut in half"*. A word cut in half is a word whose text spans two line boxes, which a Range over
// that word reports as two rects — so this asserts exactly the complaint, whatever the font or the viewport.
test('Form hub — no stat tile breaks a word across two lines', async ({ page }, info) => {
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await formEntry(page, 'Community Health Survey').getByRole('link', { name: 'Community Health Survey' }).click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });
    const values = page.locator('.hub__tiles .mds-stat-tile__value');
    await values.first().waitFor({ state: 'visible', timeout: 10_000 });
    await settlePaint(page);

    const measured = await values.evaluateAll((els) =>
        els.map((el) => {
            const node = el.firstChild;
            const text = node?.textContent ?? '';
            const lines = (range: Range) => new Set([...range.getClientRects()].map((r) => Math.round(r.top))).size;
            const broken: string[] = [];
            for (const m of text.matchAll(/\S+/g)) {
                const range = document.createRange();
                range.setStart(node!, m.index!);
                range.setEnd(node!, m.index! + m[0].length);
                if (lines(range) > 1) broken.push(m[0]);
            }
            const whole = document.createRange();
            whole.selectNodeContents(el);

            return { text: text.trim(), broken, lines: lines(whole) };
        }),
    );

    // Not vacuous: the seeded form has no schedule, so it is open — "Accepting" is the word the user saw broken.
    expect(measured.map((m) => m.text)).toContain('Accepting');
    expect(measured.flatMap((m) => m.broken), 'a stat tile cut a word in half').toEqual([]);

    if (info.project.name === 'desktop') {
        // The last response wrapped onto three lines at 1440px; it now fits on one.
        expect(measured.every((m) => m.lines === 1), JSON.stringify(measured)).toBe(true);

        // ...and not by the luck of the seeded date: CI's seed gave "Oct 11, 2026", a digit longer than the local one, and the
        // first compact size wrapped it. Every month with a two-digit day, written into the tile as the page formats a date.
        const wrapped = await page
            .locator('.hub__tiles .mds-stat-tile')
            .filter({ hasText: 'Last response' })
            .locator('.mds-stat-tile__value')
            .evaluate((el) => {
                const out: string[] = [];
                for (let month = 0; month < 12; month++) {
                    el.textContent = new Date(2026, month, 28).toLocaleDateString(undefined, {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                    });
                    const range = document.createRange();
                    range.selectNodeContents(el);
                    if (new Set([...range.getClientRects()].map((r) => Math.round(r.top))).size > 1) out.push(el.textContent);
                }

                return out;
            });
        expect(wrapped, 'a date wrapped onto two lines in the Last response tile').toEqual([]);
    }
});

// *"the initial avatar size in the audit log is so large under the actor column"*. The chip is `min-inline-size: 24px`
// with `aspect-ratio: 1`, so any width handed to it becomes a disc that tall. 32px leaves room for the extra-large type
// setting's caption size and is still far below the ~100px+ disc a stretched cell produced.
test('Audit log — the actor chip stays a small disc beside the name', async ({ page }) => {
    await page.goto('/audit-log', { waitUntil: 'networkidle' });
    await settlePaint(page);

    const chips = await page.locator('.audit__actor .mds-avatar').evaluateAll((els) =>
        els.map((el) => {
            const r = el.getBoundingClientRect();

            return { width: Math.round(r.width), height: Math.round(r.height) };
        }),
    );

    // Not vacuous: the seeded owner's own changes are in the ledger, and a person's row carries a chip.
    expect(chips.length, 'no actor chip rendered on /audit-log').toBeGreaterThan(0);
    for (const chip of chips) {
        expect(chip.width, JSON.stringify(chip)).toBeLessThanOrEqual(32);
        expect(chip.height, JSON.stringify(chip)).toBeLessThanOrEqual(32);
    }

    // A row an operator drove has a second line ("via …"), and no seeded row does — so one is added to the first cell's text
    // block exactly as the page stacks it. A two-line cell is where a row's default `align-items: stretch` would hand the chip
    // the text's height; measured without this, a `stretch` mutant passed.
    const twoLine = await page.locator('.audit__actor').first().evaluate((cell) => {
        const line = document.createElement('span');
        line.textContent = 'via Platform operator';
        cell.querySelector('.audit__actor-text')!.appendChild(line);
        const r = cell.querySelector('.mds-avatar')!.getBoundingClientRect();

        return { width: Math.round(r.width), height: Math.round(r.height) };
    });
    expect(twoLine.width, JSON.stringify(twoLine)).toBeLessThanOrEqual(32);
    expect(twoLine.height, JSON.stringify(twoLine)).toBeLessThanOrEqual(32);
});

// *"in the integration sites page, the button connect google sheets is too close from the object above it"*. The object is
// the Google warning (shown while the Google app is in testing — the e2e default). Measured edge to edge, because the
// defect was a missing margin and only boxes can show one.
test('Integrations — the Connect button keeps its distance from the warning above it', async ({ page }, info) => {
    await page.goto('/integrations', { waitUntil: 'networkidle' });
    await settlePaint(page);

    const card = page.locator('.provider').filter({ has: page.locator('.provider__caution') });
    await expect(card, 'the Google warning is not on the page').toHaveCount(1);

    const gap = await card.evaluate((el) => {
        const caution = el.querySelector('.provider__caution')!.getBoundingClientRect();
        const button = el.querySelector('.mds-button')!.getBoundingClientRect();

        return Math.round(button.top - caution.bottom);
    });
    expect(gap, 'the Connect button sits on the warning').toBeGreaterThanOrEqual(12);

    if (info.project.name === 'desktop') {
        // One row of cards at 1440px, so one line of buttons rather than a ragged one.
        const tops = await page
            .locator('.provider .mds-button')
            .evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
        expect(tops.length).toBeGreaterThan(1);
        expect(new Set(tops).size, `button tops: ${tops.join(', ')}`).toBe(1);
    }
});

// ── M173 — EACH PAGE LAYS ITS OWN WIDTH OUT ─────────────────────────────────────────────────────────────────────
// With the shell's cap gone, a page that stacked narrow cards would leave the rest of a wide screen empty, and a page of
// inputs would stretch them across it. Each case runs once, on the desktop project, at the widths it names; each was run
// red against the unchanged pages first.

/** How many columns a set of cards sits in: their distinct left edges. */
async function columns(page: Page, selector: string): Promise<number> {
    const lefts = await page
        .locator(selector)
        .evaluateAll((els) =>
            els.filter((el) => (el as HTMLElement).offsetParent !== null).map((el) => Math.round(el.getBoundingClientRect().left)),
        );

    // Not vacuous: a renamed class must fail here rather than report one column of nothing.
    expect(lefts.length, `no ${selector} on the page`).toBeGreaterThanOrEqual(3);

    return new Set(lefts).size;
}

test('Settings — the cards sit two across at 1440 and three at 1920', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'Sets its own viewport — running it three times proves nothing.');

    await page.goto('/settings', { waitUntil: 'networkidle' });
    await settlePaint(page);
    expect(await columns(page, '.settings-card'), '/settings at 1440').toBe(2);

    await page.setViewportSize({ width: 1920, height: 1080 });
    await settlePaint(page);
    expect(await columns(page, '.settings-card'), '/settings at 1920').toBe(3);
});

test.describe('the console', () => {
    test.use({ storageState: 'tests/e2e/.auth/admin.json' });

    test('Platform settings — the cards sit two across at 1440, and the console fills a 1920px window', async ({ page }, info) => {
        test.skip(info.project.name !== 'desktop', 'Sets its own viewport — running it three times proves nothing.');

        await openConsole(page, '/admin/settings');
        await settlePaint(page);
        expect(await columns(page, '.platform__card'), '/admin/settings at 1440').toBe(2);

        await page.setViewportSize({ width: 1920, height: 1080 });
        await settlePaint(page);
        const fill = await page.evaluate(() => ({
            region: document.querySelector('.admin__content')!.clientWidth,
            inner: Math.round(document.querySelector('.admin__inner')!.getBoundingClientRect().width),
        }));
        expect(Math.abs(fill.inner - fill.region), `/admin/settings at 1920: ${JSON.stringify(fill)}`).toBeLessThanOrEqual(1);
    });
});

// `D135` (the user, 2026-10-15): the tab keeps its rail, one section at a time; every labelled box keeps a reading width
// while the section itself fills the page.
test('Form settings tab — the boxes keep a reading width while the section fills the page', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'Sets its own viewport — running it three times proves nothing.');

    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: 'Clinic Intake', exact: true }).first().click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });
    await page.getByRole('navigation', { name: 'Clinic Intake' }).getByRole('link', { name: 'Settings' }).click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/settings$/, { timeout: 30_000 });
    await page.locator('.form-settings__section[data-section="general"] .mds-field').first().waitFor({ state: 'visible' });
    await settlePaint(page);

    const m = await page.evaluate(() => ({
        body: Math.round(document.querySelector('.form-settings__body')!.getBoundingClientRect().width),
        fields: [...document.querySelectorAll('.form-settings__section[data-section="general"] .mds-field')].map((el) =>
            Math.round(el.getBoundingClientRect().width),
        ),
    }));

    expect(m.fields.length, 'the Details section rendered no labelled box').toBeGreaterThan(0);
    // 48rem = 768px, plus a pixel of rounding either way.
    expect(Math.max(...m.fields), JSON.stringify(m)).toBeLessThanOrEqual(770);
    expect(m.body, JSON.stringify(m)).toBeGreaterThan(1200);
});

test('Scanned forms — the upload card keeps a measure beside a grid of scans', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop', 'Sets its own viewport — running it three times proves nothing.');

    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto('/forms', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: 'Clinic Intake', exact: true }).first().click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}$/, { timeout: 30_000 });
    await page.getByRole('navigation', { name: 'Clinic Intake' }).getByRole('link', { name: 'Responses' }).click();
    await page.waitForURL(/\/forms\/[0-9a-f-]{36}\/submissions$/, { timeout: 30_000 });
    await page.getByRole('button', { name: 'Scan paper forms' }).click();
    await page.waitForURL(/\/ocr\/scans$/, { timeout: 30_000 });
    await page.locator('.scans__row').first().waitFor({ state: 'visible' });
    await settlePaint(page);

    const m = await page.evaluate(() => {
        const box = (el: Element) => {
            const r = el.getBoundingClientRect();

            return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) };
        };

        return {
            upload: box(document.querySelector('.scans__upload')!.closest('.mds-card')!),
            recent: box(document.querySelector('.scans__recent')!),
            list: box(document.querySelector('.scans__list')!),
            row: box(document.querySelector('.scans__row')!),
        };
    });

    // 36rem = 576px, plus a pixel of rounding either way; beside the list rather than above it.
    expect(m.upload.width, JSON.stringify(m)).toBeLessThanOrEqual(578);
    expect(m.recent.left, JSON.stringify(m)).toBeGreaterThanOrEqual(m.upload.right);
    // A grid of rows rather than one row per line: at 1920 the list is about 970px and a 28rem track about 480px, so 60%
    // stays well clear of both a full-width row and two tracks.
    expect(m.row.width / m.list.width, JSON.stringify(m)).toBeLessThanOrEqual(0.6);
});
