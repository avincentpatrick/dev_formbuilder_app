/**
 * Registers the guest PWA service worker (Increment G8a). The worker is re-served from the root path
 * `/sw.js` (see App\Http\Controllers\Public\ServiceWorkerController) so it can claim the narrower `/f/`
 * scope — controlling the guest form pages while never touching the Inertia admin app on the same origin.
 *
 * Best-effort by design: if the Service Worker API is unavailable (older browser / insecure non-localhost
 * origin) or registration rejects (e.g. the app isn't built in local `vite` dev, so `/sw.js` 404s), the
 * form keeps working fully online — there is simply no offline support. Registration is deferred to the
 * `load` event so it never competes with the initial render.
 */
export function registerServiceWorker(nav: Navigator = navigator, win: Window = window): void {
    if (!('serviceWorker' in nav)) {
        return;
    }
    win.addEventListener('load', () => {
        void nav.serviceWorker.register('/sw.js', { scope: '/f/' }).catch(() => {
            // No offline support if registration fails — never surfaced to the respondent.
        });
    });
}
