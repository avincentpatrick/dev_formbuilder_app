import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The form's Settings tab on a wide screen (M173, `R-14971e37`, `D135`). The tab keeps its rail and shows one section at a
 * time — the same component the builder's Settings pop-up mounts — so once the shell stopped capping the page, a
 * section's boxes would have stretched across all of it. The user chose a reading width for every labelled box, with the
 * lists and tables inside a section left to fill.
 *
 * Pinned as source text because happy-dom lays out nothing (the `builder-layout.test.ts` idiom: anchored at column 0, so a
 * comment quoting the rule cannot satisfy it); the widths themselves are measured at 1920 in
 * `tests/e2e/list-layout.spec.ts`.
 */

const source = readFileSync(join(process.cwd(), 'resources/js/components/forms/FormSettingsSections.vue'), 'utf8');
const style = source.slice(source.indexOf('<style'));

describe('FormSettingsSections — the boxes keep a reading width', () => {
    it('holds every labelled box inside a section at 48rem', () => {
        expect(style).toMatch(/^\.form-settings__section :deep\(\.mds-field\) \{\s*max-inline-size: 48rem;\s*\}/m);
    });

    it('keeps the rail beside the body, and the narrow-container query that stacks them', () => {
        expect(style).toMatch(/^\.form-settings \{[^}]*grid-template-columns: 140px minmax\(0, 1fr\);/m);
        expect(style).toMatch(/^@container \(max-width: 420px\) \{/m);
    });
});
