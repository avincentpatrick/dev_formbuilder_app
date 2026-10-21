import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { rule, styleSlice } from './css-source';

/**
 * A dialog's own paragraphs carry the page's rule (M182, `R-3ca62317`, `D159`). `MdsModal`'s body sets padding and
 * nothing else (`packages/design-system/src/components/Modal/Modal.vue`), so a bare `<p>` there takes the browser's
 * 1em margins — space above the first line and a gap no other dialog has. Every tenant dialog styles its own
 * (`members__prose`, `admin-td__prose`); these three did not. Where a dialog holds two paragraphs, the second keeps a
 * gap of its own (`TenantDetail.vue`'s sibling rule), or margin 0 would butt them together.
 *
 * happy-dom computes no margins, so this pins the source: every paragraph before the dialog's actions (or, in the
 * delete dialog, before its impact box, whose `p` rule covers what is inside it) names a class, and that class's rule
 * zeroes the margin.
 */

const read = (path: string): string => readFileSync(join(process.cwd(), path), 'utf8');

interface Dialog {
    file: string;
    title: string;
    prose: string;
    /** The rule that spaces a second paragraph, when the dialog holds two or more. */
    sibling: string | null;
}

const DIALOGS: Dialog[] = [
    {
        file: 'resources/js/Pages/admin/Users.vue',
        title: 'Reset two-step sign-in',
        prose: '.admin-users__prose',
        sibling: '.admin-users__prose + .admin-users__prose',
    },
    {
        file: 'resources/js/Pages/scopes/Index.vue',
        title: 'Move scope',
        prose: '.scopes__prose',
        sibling: '.scopes__prose + .scopes__prose',
    },
    {
        file: 'resources/js/components/scopes/DeleteNodeModal.vue',
        title: 'Delete scope',
        prose: '.delete-node__lead',
        sibling: null,
    },
];

/** The dialog's own paragraphs: from its opening tag to its actions, or to the first box it nests. */
function ownParagraphs(source: string, title: string): string[] {
    const at = source.indexOf(`title="${title}"`);
    expect(at, `no dialog titled "${title}"`).toBeGreaterThan(-1);

    const start = source.lastIndexOf('<MdsModal', at);
    const ends = ['<template #actions', '<div', '</MdsModal>']
        .map((marker) => source.indexOf(marker, at))
        .filter((index) => index !== -1);

    return source.slice(start, Math.min(...ends)).match(/<p(?=[\s>])[^>]*>/g) ?? [];
}

describe('a dialog\'s paragraphs never fall back to the browser\'s margins', () => {
    it.each(DIALOGS)('$title: every paragraph names a class', ({ file, title }) => {
        const paragraphs = ownParagraphs(read(file), title);

        // Anti-vacuity: a renamed title or a moved marker would otherwise check nothing.
        expect(paragraphs.length).toBeGreaterThan(0);
        for (const tag of paragraphs) {
            expect(tag, `${file}: ${tag}`).toMatch(/\bclass="[^"]+"/);
        }
    });

    it.each(DIALOGS)('$title: the paragraph rule zeroes the margin, and a second paragraph keeps a gap', ({ file, prose, sibling }) => {
        const css = styleSlice(read(file));

        expect(rule(css, prose), `${file} ${prose}`).toMatch(/margin:\s*0;/);
        if (sibling !== null) {
            expect(rule(css, sibling), `${file} ${sibling}`).toMatch(/margin-top:\s*var\(--mds-space-3\)/);
        }
    });

    it('sets the reset dialog\'s error line in the on-surface danger pair', () => {
        const source = read('resources/js/Pages/admin/Users.vue');
        const alert = ownParagraphs(source, 'Reset two-step sign-in').find((tag) => tag.includes('role="alert"'));

        expect(alert).toMatch(/class="[^"]*\badmin-users__error\b/);
        expect(rule(styleSlice(source), '.admin-users__error')).toMatch(/color:\s*var\(--mds-color-status-danger-fg\)/);
    });
});
