import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { FILLING_GRID, containerBlock, nested, rule, styleSlice } from '../__tests__/css-source';

const read = (path: string): string => readFileSync(join(process.cwd(), path), 'utf8');
const styleOf = (path: string): string => styleSlice(read(path));

/**
 * The stacks become grids (M174, `R-fb647b5e`, `D120`): a page that listed its items one full-width row under another —
 * a domain's card, a search group, the leaderboard beside the team's tiles — lays them side by side once the page is
 * wide, so a row's first word and its last figure are no longer 2,000px apart on a wide screen. happy-dom lays out
 * nothing; `tests/e2e/list-layout.spec.ts` counts the columns in a real browser, and this file pins the source shape.
 */

const DOMAINS = 'resources/js/Pages/domains/Index.vue';
const SEARCH = 'resources/js/Pages/search/Index.vue';
const ACHIEVEMENTS = 'resources/js/Pages/achievements/Index.vue';

describe('domains — the domain cards sit in a grid that gains columns', () => {
    it('lays the list in an auto-fill grid, top-aligned', () => {
        const body = rule(styleOf(DOMAINS), '.domains__list');

        expect(body, '.domains__list').not.toBeNull();
        expect(body).toMatch(/display:\s*grid/);
        expect(body).toMatch(FILLING_GRID);
        expect(body).toMatch(/align-items:\s*start/);
    });
});

describe('domains — the setup guide keeps a reading measure on a wide page', () => {
    it('holds its lead, steps and foot at 80ch, left-aligned', () => {
        // One rule over three selectors, one per line, as the component writes it.
        const body = rule(
            styleOf('resources/js/components/domains/DomainSetupGuide.vue'),
            `.domain-guide__lead,
.domain-guide__steps,
.domain-guide__foot`,
        );

        expect(body).toMatch(/max-inline-size:\s*80ch/);
    });
});

describe('search — the result groups sit side by side on a wide page', () => {
    it('declares the page root as the container the groups read', () => {
        expect(read(SEARCH)).toMatch(/^ {4}<div class="search">$/m);
        expect(rule(styleOf(SEARCH), '.search')).toMatch(/container-type:\s*inline-size/);
    });

    it('lays two groups across from 64rem, and a lone group (a filter) across the row', () => {
        const block = containerBlock(styleOf(SEARCH), '64rem');

        expect(nested(block, '.search__groups')).toMatch(/grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)/);
        expect(nested(block, '.search__group:only-child')).toMatch(/grid-column:\s*1 \/ -1/);
    });

    it('lets every group sit in one row from 120rem', () => {
        expect(nested(containerBlock(styleOf(SEARCH), '120rem'), '.search__groups')).toMatch(
            /grid-template-columns:\s*repeat\(auto-fit,\s*minmax\(26rem,\s*1fr\)\)/,
        );
    });
});

describe('achievements — the leaderboard sits beside the team tiles', () => {
    it('declares the page root as the container the split reads', () => {
        expect(rule(styleOf(ACHIEVEMENTS), '.ach')).toMatch(/container-type:\s*inline-size/);
    });

    it('wraps the team tiles and the note, then the leaderboard, in the workspace section', () => {
        const source = read(ACHIEVEMENTS);
        const workspace = source.indexOf('<div class="ach__workspace">');
        const team = source.indexOf('<div class="ach__team">');
        const board = source.indexOf('class="ach__board"');

        expect(workspace).toBeGreaterThan(-1);
        expect(team).toBeGreaterThan(workspace);
        expect(board).toBeGreaterThan(team);
        expect(source.indexOf('<ul class="ach__ladder" role="list">')).toBeGreaterThan(board);
    });

    it('splits the workspace in two from a 75rem container, a lone team across the row', () => {
        const block = containerBlock(styleOf(ACHIEVEMENTS), '75rem');

        expect(nested(block, '.ach__workspace')).toMatch(/display:\s*grid/);
        expect(nested(block, '.ach__workspace')).toMatch(/grid-template-columns:\s*minmax\(0,\s*1fr\)\s+minmax\(0,\s*1fr\)/);
        expect(nested(block, '.ach__team:only-child')).toMatch(/grid-column:\s*1 \/ -1/);
    });

    it('lays the badges in a grid that gains columns rather than stretching two badges across the page', () => {
        expect(rule(styleOf(ACHIEVEMENTS), '.ach__badges')).toMatch(
            /grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(min\(100%,\s*280px\),\s*1fr\)\)/,
        );
    });
});

describe('templates — every card\'s button sits at the foot of its card', () => {
    it('pushes the footer to the bottom of a stretched card, keeping the design system\'s gap above it', () => {
        // `.form-card` does the same (`FormCard.vue`); `padding-top` keeps the 16px the footer's own margin gave, which
        // `margin-top: auto` alone takes to zero in a row's tallest card.
        const body = rule(styleOf('resources/js/Pages/forms/Templates.vue'), '.templates__cell :deep(.mds-card__footer)');

        expect(body, '.templates__cell :deep(.mds-card__footer)').not.toBeNull();
        expect(body).toMatch(/margin-top:\s*auto/);
        expect(body).toMatch(/padding-top:\s*var\(--mds-space-4\)/);
    });
});
