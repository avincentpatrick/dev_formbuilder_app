import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            // Two independent entries: the Inertia admin app (app.ts + app.css) and the standalone
            // public form-runtime SPA (main.ts, Increment F6b — imports its own CSS). Both share the
            // single deduped Vue instance below.
            input: ['resources/css/app.css', 'resources/js/app.ts', 'resources/public-runtime/main.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // Increment G8a — installable PWA for the GUEST runtime only. The emitted sw.js is re-served
        // from the root route GET /sw.js (ServiceWorkerController) and registered with scope '/f/' from
        // main.ts, so it controls guest form pages and NEVER the Inertia admin app on the same origin.
        //
        // We precache NOTHING (globPatterns: []) because the admin + public-runtime bundles share
        // opaque-named vendor chunks that can't be split by glob; instead three runtimeCaching routes
        // cache only what a /f/* page actually requests, so the admin bundle is never cached regardless
        // of chunk naming. injectRegister:false (we register manually to pin scope) and manifest:false
        // (the per-form manifest is a Laravel route, and a backend-integration build has no index.html
        // for the plugin to inject into). inlineWorkboxRuntime keeps sw.js self-contained so no sibling
        // workbox-*.js is needed (which would 404 when the SW is re-served from /sw.js).
        VitePWA({
            strategies: 'generateSW',
            injectRegister: false,
            manifest: false,
            filename: 'sw.js',
            workbox: {
                globPatterns: [],
                inlineWorkboxRuntime: true,
                navigateFallback: null,
                cleanupOutdatedCaches: true,
                clientsClaim: true,
                skipWaiting: true,
                runtimeCaching: [
                    {
                        // The hashed public-runtime JS/CSS (and shared chunks) it pulls in.
                        urlPattern: ({ url, sameOrigin }) => sameOrigin && url.pathname.startsWith('/build/'),
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'guest-shell-assets',
                            expiration: { maxEntries: 80, maxAgeSeconds: 60 * 60 * 24 * 30 },
                        },
                    },
                    {
                        // The pinned form schema (choice lists inline) so a loaded form renders offline.
                        urlPattern: ({ url, sameOrigin }) =>
                            sameOrigin && url.pathname.startsWith('/api/v1/public/f/'),
                        method: 'GET',
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'guest-schema',
                            networkTimeoutSeconds: 5,
                            cacheableResponse: { statuses: [200] },
                            expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 * 7 },
                        },
                    },
                    {
                        // The shell HTML for /f/* navigations (serves the cached shell offline).
                        urlPattern: ({ request, url, sameOrigin }) =>
                            request.mode === 'navigate' && sameOrigin && url.pathname.startsWith('/f/'),
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'guest-shell-html',
                            networkTimeoutSeconds: 5,
                            expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 * 7 },
                        },
                    },
                ],
            },
            // No SW under the `vite` dev server; the guest runtime exercises the SW only after
            // `npm run build` (the e2e job builds + serves via artisan serve).
            devOptions: { enabled: false },
        }),
    ],
    resolve: {
        // @meridian/design-system is a file: dependency carrying its own vue@3.5 (for Storybook).
        // Without deduping, its source .vue files could resolve a SECOND Vue copy → broken
        // reactivity / "two Vue instances" warnings. Force a single Vue for the whole graph.
        dedupe: ['vue'],
    },
    optimizeDeps: {
        // The package ships source .vue/.ts (exports point at src/); let our own plugin-vue compile
        // it on import rather than pre-bundling the file: dep.
        exclude: ['@meridian/design-system'],
    },
    server: {
        // Inside Docker the Vite dev server binds 0.0.0.0 so the published port works, but it must
        // ADVERTISE a browser-reachable host. Without hmr.host the laravel-vite-plugin writes the bind
        // address ('0.0.0.0') into public/hot, and the browser can't connect to 0.0.0.0
        // (net::ERR_ADDRESS_INVALID) → blank page. hmr.host wins over server.host for the hot-file URL.
        host: '0.0.0.0',
        cors: true, // the app is served from a different origin (acme.localhost:8080); allow module loads
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
