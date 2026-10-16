import { describe, expect, it } from 'vitest';
import { containerBlock, nested, read, rule, styleOf } from '../__tests__/css-source';

/**
 * The detail pages gain a side column on a wide screen (M174, `D120`): from a 75rem container a page's facts sit in a
 * 26rem column beside its tables, answers or log, instead of stacking above them so every row runs edge to edge. The
 * DOM order never changes — the facts stay first for a screen reader and on a phone — so the placement is CSS alone.
 * happy-dom lays out nothing, so the columns themselves are measured in a real browser (`tests/e2e/list-layout.spec.ts`,
 * at 1920 and in the console at 1440); this file pins the source shape that produces them.
 *
 * ⚠️ An element cannot query its own size, so each page declares the container on its root and splits an inner
 * wrapper — the `M129` trap `FormSettingsSections.vue` records, and the scans page's shape since `M173`.
 */

/** Main content first and widest; the facts in a bounded column beside it. */
const ASIDE_SPLIT = /grid-template-columns:\s*minmax\(0,\s*1fr\)\s+minmax\(18rem,\s*26rem\)/;

const HUB = 'resources/js/Pages/forms/Show.vue';
const SUBMISSION = 'resources/js/Pages/submissions/Show.vue';

describe('form hub — the versions sit beside the latest responses', () => {
    it('declares the page root as the container the split reads', () => {
        expect(read(HUB)).toMatch(/^ {4}<div class="hub">$/m);
        expect(rule(styleOf(HUB), '.hub')).toMatch(/container-type:\s*inline-size/);
    });

    it('wraps the two panels, latest first, so the DOM order is the visual order', () => {
        const source = read(HUB);
        const wrapper = source.indexOf('<div class="hub__panels">');
        const latest = source.indexOf('<section class="hub__panel hub__panel--latest">');
        const versions = source.indexOf('<section class="hub__panel hub__panel--versions">');

        expect(wrapper).toBeGreaterThan(-1);
        expect(latest).toBeGreaterThan(wrapper);
        expect(versions).toBeGreaterThan(latest);
    });

    it('splits the panels from a 75rem container, the versions in the bounded column', () => {
        const body = nested(containerBlock(styleOf(HUB), '75rem'), '.hub__panels');

        expect(body, '@container (min-width: 75rem) .hub__panels').not.toBeNull();
        expect(body).toMatch(/display:\s*grid/);
        expect(body).toMatch(ASIDE_SPLIT);
        expect(body).toMatch(/align-items:\s*start/);
    });
});

describe('submission page — the facts sit beside the answers', () => {
    it('declares the page root as the container the split reads', () => {
        expect(read(SUBMISSION)).toMatch(/^ {4}<div class="detail">$/m);
        expect(rule(styleOf(SUBMISSION), '.detail')).toMatch(/container-type:\s*inline-size/);
    });

    it('wraps the fact cards and the answers, facts first, and marks a scanned response', () => {
        const source = read(SUBMISSION);
        const layout = source.indexOf('class="detail__layout"');

        expect(layout).toBeGreaterThan(-1);
        expect(source.indexOf('<div class="detail__grid">')).toBeGreaterThan(layout);
        expect(source).toContain(":class=\"{ 'detail__layout--scan': scan_pages.length > 0 }\"");
    });

    it('places the facts in the bounded column from a 75rem container — never beside a scanned paper', () => {
        const block = containerBlock(styleOf(SUBMISSION), '75rem');
        const layout = nested(block, '.detail__layout:not(.detail__layout--scan)');

        expect(layout, '@container (min-width: 75rem) .detail__layout:not(.detail__layout--scan)').not.toBeNull();
        expect(layout).toMatch(/display:\s*grid/);
        expect(layout).toMatch(ASIDE_SPLIT);
        expect(nested(block, '.detail__layout:not(.detail__layout--scan) > .detail__grid')).toMatch(/grid-column:\s*2/);
        expect(nested(block, '.detail__layout:not(.detail__layout--scan) > .detail__body')).toMatch(/grid-column:\s*1/);
    });

    it('lays the answer blocks in a grid that gains columns, never wider blocks', () => {
        const body = rule(styleOf(SUBMISSION), '.detail__blocks');

        expect(body, '.detail__blocks').not.toBeNull();
        expect(body).toMatch(/display:\s*grid/);
        expect(body).toMatch(/grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(min\(100%,\s*[0-9.]+rem\),\s*1fr\)\)/);
        expect(body).toMatch(/align-items:\s*start/);
    });
});

const WEBHOOK = 'resources/js/Pages/webhooks/Show.vue';
const RULE = 'resources/js/Pages/integrations/RuleShow.vue';
const TENANT = 'resources/js/Pages/admin/TenantDetail.vue';

describe.each([
    ['webhook', WEBHOOK],
    ['integration rule', RULE],
])('%s page — the cards sit beside the delivery log', (_name, path) => {
    it('declares the page root as the container the split reads', () => {
        expect(read(path)).toMatch(/^ {4}<div class="detail">$/m);
        expect(rule(styleOf(path), '.detail')).toMatch(/container-type:\s*inline-size/);
    });

    it('wraps the cards and the log, cards first, the notice with the log', () => {
        const source = read(path);
        const layout = source.indexOf('<div class="detail__layout">');
        const grid = source.indexOf('<div class="detail__grid">');
        const main = source.indexOf('<div class="detail__main">');

        expect(layout).toBeGreaterThan(-1);
        expect(grid).toBeGreaterThan(layout);
        expect(main).toBeGreaterThan(grid);
        expect(source.indexOf('class="detail__notice"')).toBeGreaterThan(main);
        expect(source.indexOf('<section class="detail__log">')).toBeGreaterThan(main);
    });

    it('places the cards in the bounded column from a 75rem container', () => {
        const block = containerBlock(styleOf(path), '75rem');
        const layout = nested(block, '.detail__layout');

        expect(layout, '@container (min-width: 75rem) .detail__layout').not.toBeNull();
        expect(layout).toMatch(/display:\s*grid/);
        expect(layout).toMatch(ASIDE_SPLIT);
        expect(nested(block, '.detail__layout > .detail__grid')).toMatch(/grid-column:\s*2/);
        expect(nested(block, '.detail__layout > .detail__main')).toMatch(/grid-column:\s*1/);
    });
});

describe('console workspace page — the identity and plan cards sit beside the sections', () => {
    it('wraps the page in its own container, since its root is the console layout', () => {
        const source = read(TENANT);

        expect(source).toMatch(/^ {8}<div class="admin-td">$/m);
        expect(rule(styleOf(TENANT), '.admin-td')).toMatch(/container-type:\s*inline-size/);
        expect(source.indexOf('<div class="admin-td__main">')).toBeGreaterThan(source.indexOf('<div class="admin-td__grid">'));
    });

    it('places the cards in the bounded column from a 75rem container', () => {
        const block = containerBlock(styleOf(TENANT), '75rem');
        const layout = nested(block, '.admin-td__layout');

        expect(layout, '@container (min-width: 75rem) .admin-td__layout').not.toBeNull();
        expect(layout).toMatch(ASIDE_SPLIT);
        expect(nested(block, '.admin-td__layout > .admin-td__grid')).toMatch(/grid-column:\s*2/);
        expect(nested(block, '.admin-td__layout > .admin-td__main')).toMatch(/grid-column:\s*1/);
    });

    it('styles the dialogs\' paragraphs as every tenant dialog does, and takes the tracking token', () => {
        const source = read(TENANT);
        const css = styleOf(TENANT);

        // The two dialogs' three paragraphs: none bare, so none falls back to the browser's default margins.
        expect(source.slice(source.indexOf('<MdsModal'), source.indexOf('</template>\n\n<style'))).not.toMatch(/<p>/);
        expect(rule(css, '.admin-td__prose')).toMatch(/margin:\s*0/);
        expect(rule(css, '.admin-td__subhead')).toMatch(/letter-spacing:\s*var\(--mds-tracking-wide\)/);
    });
});
