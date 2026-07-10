import { defineConfig } from 'vitest/config';

/**
 * Vitest runs the Phase-1 form-runtime engine's unit + golden-vector suites (Increment F6a). It is a
 * NODE-environment runner only — the engine is framework-free and the golden runners read the shared
 * `tests/golden/**` fixtures off disk, so no jsdom / Vue plugin / design-system wiring is needed here
 * (those arrive with the component tests in F6b). Kept separate from `vite.config.ts` so the app build is
 * untouched.
 */
export default defineConfig({
    test: {
        include: ['resources/public-runtime/**/*.test.ts'],
        environment: 'node',
    },
});
