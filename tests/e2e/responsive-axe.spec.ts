import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Composed-page responsive + accessibility gate. Each authenticated tenant page is scanned at the
// three reference viewports (the config's projects) in light AND dark for zero WCAG 2.2 AA violations,
// and asserted to never scroll horizontally (Feature #5's responsive contract). This is integration
// coverage the per-component Storybook axe run cannot provide (heading order, landmark structure, and
// real text-on-surface contrast only exist once a page is assembled).

const pages = [
    { name: 'Dashboard', path: '/dashboard' },
    { name: 'Members', path: '/members' },
    { name: 'Settings', path: '/settings' },
];

const themes = ['light', 'dark'] as const;

for (const p of pages) {
    for (const theme of themes) {
        test(`${p.name} (${theme}) — accessible & no horizontal overflow`, async ({ page }) => {
            await page.goto(p.path, { waitUntil: 'networkidle' });

            // The server renders the default (system) theme; force the attribute for the dark pass so
            // axe measures the dark palette on the real composed page.
            await page.evaluate((t) => {
                if (t === 'dark') document.documentElement.setAttribute('data-theme-mode', 'dark');
                else document.documentElement.removeAttribute('data-theme-mode');
            }, theme);

            const overflows = await page.evaluate(
                () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            );
            expect(overflows, `${p.name} scrolls horizontally at this viewport`).toBe(false);

            const results = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
                .analyze();

            expect(
                results.violations,
                results.violations.map((v) => `${v.id}: ${v.help}`).join('\n'),
            ).toEqual([]);
        });
    }
}
