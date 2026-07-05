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
        // Inside Docker the Vite dev server must bind 0.0.0.0 and advertise the host port for HMR.
        host: '0.0.0.0',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
