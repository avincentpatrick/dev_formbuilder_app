/**
 * Public-runtime SPA entry (Increment F6b). A plain `createApp(App).mount('#app')` — NOT Inertia. The minted
 * share token + form metadata are read once from the mount node's dataset (embedded by `public-runtime.blade.php`)
 * and passed to the root as the `bootstrap` prop; from there the SPA drives the F5 `/api/v1/public` endpoints
 * same-origin.
 */
import { createApp } from 'vue';
import App from './App.vue';
import type { Bootstrap } from './lib/types';
import { registerServiceWorker } from './lib/register-sw';
import { syncBrandedShellCache } from './lib/brand-cache';
import { openDb } from './lib/db';
import './public-runtime.css';

function readBootstrap(el: HTMLElement): Bootstrap {
    const data = el.dataset;
    return {
        shareToken: data.shareToken ?? '',
        expiresAt: data.expiresAt ?? '',
        formId: data.formId ?? '',
        formTitle: data.formTitle ?? '',
        slug: data.formSlug ?? '',
        defaultLocale: data.defaultLocale || 'en',
        // Increment H10 — populated by the `/f/resume/{token}` web shell; empty on a normal `/f/{slug}` entry.
        resumeToken: data.resumeToken ?? '',
        // Increment H23b — the tenant ramp's fingerprint, or 'none'. Never blank in practice; the fallback
        // keeps a hand-built or pre-H23b cached shell from comparing `undefined` against a real value.
        brandVersion: data.brandVersion ?? 'none',
    };
}

const el = document.getElementById('app');
if (el !== null) {
    const bootstrap = readBootstrap(el);
    createApp(App, { bootstrap }).mount(el);
    // Increment G8a — make the guest runtime installable + offline-capable. Only this entry registers a
    // service worker (scoped to /f/); the Inertia admin app (resources/js/app.ts) never does.
    registerServiceWorker();
    // Increment H23b — if the tenant's brand has moved since this device last cached a guest shell, bring
    // the OTHER cached shells up to date so they don't render a superseded brand offline. Deliberately
    // fire-and-forget and deliberately AFTER mount: this is cosmetic housekeeping and must never sit in
    // front of a respondent's first paint. Every failure mode inside resolves quietly.
    void syncBrandedShellCache({
        brandVersion: bootstrap.brandVersion,
        db: openDb(),
        currentUrl: window.location.href,
    });
}
