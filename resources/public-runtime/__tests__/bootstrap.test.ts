import { describe, expect, it } from 'vitest';
import { readBootstrap } from '../lib/bootstrap';

/*
 * M140 (`R-68656155`, `D20` = 2) — where the resume token comes from. The service worker caches every resume link
 * under one key with `data-resume-token` blanked, so a page served from that cache carries no token, or would carry
 * another link's. The address is what the server verified to render the page, so the address wins.
 */

function mountNode(data: Record<string, string>): HTMLElement {
    const el = document.createElement('div');
    for (const [key, value] of Object.entries(data)) {
        el.dataset[key] = value;
    }

    return el;
}

describe('readBootstrap', () => {
    it('takes the resume token from the address when the page carries none — the cached copy', () => {
        expect(readBootstrap(mountNode({ resumeToken: '', formSlug: 'intake' }), '/f/resume/tok-b').resumeToken).toBe('tok-b');
    });

    it('takes the address over the page when they disagree', () => {
        expect(readBootstrap(mountNode({ resumeToken: 'tok-a' }), '/f/resume/tok-b').resumeToken).toBe('tok-b');
    });

    it("falls back to the page's own token only when the address carries none it can read", () => {
        expect(readBootstrap(mountNode({ resumeToken: 'tok-a' }), '/f/resume/%E0%A4%A').resumeToken).toBe('tok-a');
        expect(readBootstrap(mountNode({}), '/f/clinic-intake').resumeToken).toBe('');
    });

    it('reads the other boot values from the page, with their defaults', () => {
        const bootstrap = readBootstrap(
            mountNode({ shareToken: 'share-1', expiresAt: '2026-10-07T00:00:00Z', formId: 'f-1', formTitle: 'Intake', formSlug: 'intake', brandVersion: 'abc' }),
            '/f/intake',
        );

        expect(bootstrap).toEqual({
            shareToken: 'share-1',
            expiresAt: '2026-10-07T00:00:00Z',
            formId: 'f-1',
            formTitle: 'Intake',
            slug: 'intake',
            defaultLocale: 'en',
            resumeToken: '',
            brandVersion: 'abc',
        });
        expect(readBootstrap(mountNode({}), '/f/intake').brandVersion).toBe('none');
    });
});
