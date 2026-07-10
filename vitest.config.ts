import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/**
 * Vitest runs the Phase-1 form-runtime suites under `resources/public-runtime/**`:
 *  - the F6a engine unit + golden-vector suites (framework-free; read the shared `tests/golden/**` fixtures
 *    off disk via Node), and
 *  - the F6b SPA component + composable tests, which mount Vue SFCs and reuse the design-system + the
 *    Inertia-tree `FieldInput.vue`.
 *
 * So it now compiles `.vue` (plugin-vue), provides a DOM (happy-dom), forces a single Vue instance
 * (dedupe — mirror `vite.config.ts`), and supplies the `@` → resources/js alias that the laravel-vite-plugin
 * injects in the app build but which is not present here. Kept separate from `vite.config.ts` so the app
 * build stays untouched.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        dedupe: ['vue'],
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/public-runtime/**/*.test.ts'],
        environment: 'happy-dom',
    },
});
