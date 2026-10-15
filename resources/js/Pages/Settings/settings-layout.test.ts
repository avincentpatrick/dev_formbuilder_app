import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The settings pages fill the width (M173, `R-ba9d6349`, `D120`): the cards sit two or three across instead of one 640px
 * column. happy-dom lays out nothing, so the columns themselves are counted in a real browser
 * (`tests/e2e/list-layout.spec.ts`, at 1440 and 1920); this file pins the source shape that produces them, and that no
 * card brings its own cap back — each card component used to re-declare it, because scoped CSS reaches only a child
 * component's root.
 */

const read = (path: string): string => readFileSync(join(process.cwd(), path), 'utf8');
const styleOf = (path: string): string => {
    const source = read(path);

    return source.slice(source.indexOf('<style'));
};

/** The body of a top-level rule written at column 0, or null. */
function rule(css: string, selector: string): string | null {
    const start = css.indexOf(`\n${selector} {`);

    return start === -1 ? null : css.slice(start, css.indexOf('}', start));
}

const PAGES = {
    workspace: 'resources/js/Pages/Settings/Index.vue',
    sso: 'resources/js/Pages/Settings/Sso.vue',
    console: 'resources/js/Pages/admin/Settings.vue',
};

const CARDS = [
    'resources/js/components/settings/AboutCard.vue',
    'resources/js/components/settings/MaintenanceCard.vue',
    'resources/js/components/settings/ModulesCard.vue',
    'resources/js/components/settings/AccessCard.vue',
    'resources/js/components/settings/NotificationPreferencesCard.vue',
    'resources/js/components/settings/BrandingCard.vue',
    'resources/js/components/sso/SsoStatusCard.vue',
    'resources/js/components/sso/SsoPolicyCard.vue',
    'resources/js/components/sso/SsoFailuresCard.vue',
    'resources/js/components/sso/SpDetailsCard.vue',
    'resources/js/components/sso/IdpMetadataCard.vue',
];

/** A grid that gains a column as the page widens: more cards across, never wider cards. */
const FILLING_GRID = /grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(min\(100%,\s*[0-9.]+rem\),\s*1fr\)\)/;

describe('settings pages — no card carries a width cap of its own', () => {
    it.each([...Object.values(PAGES), ...CARDS])('%s declares no 640px cap', (path) => {
        expect(styleOf(path)).not.toMatch(/^\s+max-width:\s*640px/m);
    });
});

describe('settings pages — the cards sit in a filling grid', () => {
    it('lays the workspace settings cards in an auto-fill grid, top-aligned', () => {
        expect(read(PAGES.workspace)).toContain('<div class="settings__cards">');

        const body = rule(styleOf(PAGES.workspace), '.settings__cards');
        expect(body, '.settings__cards').not.toBeNull();
        expect(body).toMatch(/display:\s*grid/);
        expect(body).toMatch(FILLING_GRID);
        expect(body).toMatch(/align-items:\s*start/);
    });

    it('keeps the 380px measure on the forms inside a card', () => {
        // `D120` keeps a measure for inputs: the cards widen, the Profile, Drafts and Password forms do not.
        expect(rule(styleOf(PAGES.workspace), '.settings-form')).toMatch(/max-width:\s*380px/);
    });

    it('lays the single sign-on cards in the same grid', () => {
        const body = rule(styleOf(PAGES.sso), '.sso__stack');
        expect(body, '.sso__stack').not.toBeNull();
        expect(body).toMatch(/display:\s*grid/);
        expect(body).toMatch(FILLING_GRID);
    });

    it('lays the console cards in the same grid, with the conflict alert and the Save foot across the row', () => {
        const css = styleOf(PAGES.console);

        expect(rule(css, '.platform')).toMatch(/display:\s*grid/);
        expect(rule(css, '.platform')).toMatch(FILLING_GRID);
        expect(rule(css, '.platform__conflict')).toMatch(/grid-column:\s*1 \/ -1/);
        expect(rule(css, '.platform__foot')).toMatch(/grid-column:\s*1 \/ -1/);
    });
});
