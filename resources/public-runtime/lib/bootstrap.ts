/**
 * The boot values the blade embeds on the mount node (`public-runtime.blade.php`), read once.
 *
 * Moved out of `main.ts` in M140 (`R-68656155`) so the resume token's source can be tested: `main.ts` mounts the
 * app the moment it is imported.
 */
import { resumeTokenFromPath } from './resume-shell';
import type { Bootstrap } from './types';

export function readBootstrap(el: HTMLElement, pathname: string): Bootstrap {
    const data = el.dataset;

    return {
        shareToken: data.shareToken ?? '',
        expiresAt: data.expiresAt ?? '',
        formId: data.formId ?? '',
        formTitle: data.formTitle ?? '',
        slug: data.formSlug ?? '',
        defaultLocale: data.defaultLocale || 'en',
        // Increment H10 — set on a `/f/resume/{token}` entry; empty on a normal `/f/{slug}` entry.
        // ⛔ M140 — THE ADDRESS WINS. The service worker caches every resume link under one key with the token
        // blanked (`lib/resume-shell.ts`), so a page served from that cache carries no token, or would carry the
        // wrong one. The address is what the server verified to render the page, so it is the token's source;
        // the attribute is the fallback for an address this cannot parse.
        resumeToken: resumeTokenFromPath(pathname) || (data.resumeToken ?? ''),
        // Increment H23b — the tenant ramp's fingerprint, or 'none'. Never blank in practice; the fallback
        // keeps a hand-built or pre-H23b cached shell from comparing `undefined` against a real value.
        brandVersion: data.brandVersion ?? 'none',
    };
}
