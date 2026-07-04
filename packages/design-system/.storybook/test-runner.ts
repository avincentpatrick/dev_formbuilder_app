import type { TestRunnerConfig } from '@storybook/test-runner';
import { checkA11y, injectAxe } from 'axe-playwright';

/**
 * Runs axe-core against EVERY story (docs/ux/design-system-reference.md §4.6).
 * Any new violation fails CI — WCAG 2.2 AA is a merge-blocking, build-time constraint.
 */
const config: TestRunnerConfig = {
    async preVisit(page) {
        await injectAxe(page);
    },
    async postVisit(page) {
        await checkA11y(page, '#storybook-root', {
            detailedReport: true,
            detailedReportOptions: { html: true },
        });
    },
};

export default config;
