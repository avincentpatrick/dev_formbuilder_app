import { defineConfig } from '@playwright/test';
import base from './playwright.config';

/**
 * The Help page's pictures (M163, `D115`) — NOT a test suite.
 *
 * `*.capture.ts` files take screenshots and WRITE them into `public/images/help/`, the files the Help page shows. The
 * main config's default `testMatch` (`*.spec.ts` / `*.test.ts`) never picks one up, so CI's E2E job cannot overwrite a
 * committed picture; this config runs them, on the desktop project only, where the builder shows all three panes.
 *
 *     docker compose run --rm e2e test --config=playwright.capture.config.ts
 *
 * Same prerequisites as any local E2E run (`docker-compose.yml`'s e2e block): the seeded E2E database, built assets,
 * no `public/hot`, and `CI` unset.
 */
export default defineConfig({
    ...base,
    testMatch: '**/*.capture.ts',
    reporter: 'list',
    retries: 0,
    projects: (base.projects ?? []).filter((project) => project.name === 'desktop'),
});
