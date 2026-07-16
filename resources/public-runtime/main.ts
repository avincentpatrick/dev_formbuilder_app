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
    };
}

const el = document.getElementById('app');
if (el !== null) {
    createApp(App, { bootstrap: readBootstrap(el) }).mount(el);
    // Increment G8a — make the guest runtime installable + offline-capable. Only this entry registers a
    // service worker (scoped to /f/); the Inertia admin app (resources/js/app.ts) never does.
    registerServiceWorker();
}
