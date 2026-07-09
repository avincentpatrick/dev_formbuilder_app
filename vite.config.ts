import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
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
