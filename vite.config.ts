import { execSync } from 'node:child_process';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

// Increment G8b — the client build version stamped onto every submission's `app_version` column (≤20 chars).
// Short git sha in CI/local; falls back to 'dev' where git is unavailable.
function resolveAppVersion(): string {
    try {
        return execSync('git rev-parse --short HEAD', { encoding: 'utf8' }).trim().slice(0, 20) || 'dev';
    } catch {
        return 'dev';
    }
}

export default defineConfig({
    define: {
        __APP_VERSION__: JSON.stringify(resolveAppVersion()),
    },
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
        // Increment G8a/G8b — installable, offline-capable PWA for the GUEST runtime only. The emitted sw.js
        // is re-served from the root route GET /sw.js (ServiceWorkerController) and registered with scope '/f/'
        // from main.ts, so it controls guest form pages and NEVER the Inertia admin app on the same origin.
        //
        // G8b switches from generateSW to injectManifest: the worker is hand-authored (resources/public-runtime/
        // sw.ts) so it can own a Background-Sync `sync` handler that drains the Dexie outbox with no tab open.
        // The three G8a runtime caches are re-expressed in Workbox code inside sw.ts; Rollup bundles Workbox
        // (and Dexie) into one self-contained sw.js, so there are no sibling workbox-*.js files (which would 404
        // when re-served from /sw.js) and no CSP change. We still precache NOTHING (injectManifest.globPatterns:
        // []) because the admin + public-runtime bundles share opaque-named vendor chunks. injectRegister:false
        // (we register manually to pin scope) and manifest:false (the per-form manifest is a Laravel route).
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/public-runtime',
            filename: 'sw.ts',
            injectRegister: false,
            manifest: false,
            injectManifest: {
                globPatterns: [],
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
