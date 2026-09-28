import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Increment JR5 — the builder's compact layout, asserted as SOURCE TEXT.
 *
 * ── WHY THERE IS NO MOUNT HERE, AND WHY THERE ARE NO STORYBOOK STORIES EITHER ────────────────────────────
 * Nothing in this repo can execute a container query. happy-dom computes no layout at all; the Storybook
 * axe run renders one viewport; and the e2e horizontal-overflow assertion reads a `documentElement`
 * scrollWidth that `.app-shell { overflow-x: clip }` pins flat. JR4 hit the same wall in `MdsDataTable` and
 * answered it three ways — source-text assertions, narrow-container stories, and an element-measuring e2e
 * case. Only two of those three are available here: the rules live in a PAGE's scoped `<style>`, which
 * Storybook cannot reach without importing the page, and a hand-built composition story would exercise a
 * COPY of the CSS — green while the page regressed. So the layout itself is measured by
 * `tests/e2e/list-layout.spec.ts` (which counts the panes that actually have a box), and what is pinned
 * here is the REASONING: a later edit that reaches for a viewport media query, for `px`, or for `v-if` is
 * re-introducing a defect that was measured once and would be invisible on the way back in.
 *
 * Mounting `Builder.vue` would drag `useBuilderStore`, the CSRF fetch sidecar and ~20 child components
 * into a new Inertia mock for zero added coverage of the thing under test, which is CSS text. This mirrors
 * `DataTable.test.ts`'s threshold block exactly.
 */
describe('forms/Builder — the compact-layout threshold', () => {
    const source = readFileSync(join(process.cwd(), 'resources/js/Pages/forms/Builder.vue'), 'utf8');

    it('collapses on the CONTAINER, never on the viewport', () => {
        // ⚠️ ANCHORED TO COLUMN 0, and that is not fussiness: this file's own script comment SPELLS the
        // string "@container (max-width: 60em)" while explaining the ref, so an unanchored positive
        // assertion is satisfied by prose and would stay green through a revert to a viewport media
        // query. An at-rule sits at column 0; a comment about one does not. Same reasoning as the
        // negative assertion below, which `DataTable.test.ts` already applies to `^@media`.
        expect(source).toMatch(/^@container \(max-width:/m);
        expect(
            source,
            'a viewport media query cannot see the sidebar inversion — the builder is fluid, so its box ' +
                'is `viewport − sidebar`: 960px at a 1024px viewport and 785px at 1025px. The old ' +
                '`@media (max-width: 1024px)` rule therefore kept the three-column grid in the NARROWEST ' +
                'box, giving the canvas a 185px track across 1025–1200 — a band no Playwright project ' +
                'renders.',
            // Anchored: the block comment in the page NAMES the rule it replaced, and prose about a
            // defect is not the defect. An at-rule sits at column 0.
        ).not.toMatch(/^@media \(max-width: 1024px\)/m);
    });

    it('states the threshold in em, so it is correct at all three type scales', () => {
        // A px threshold is right at exactly one setting of §2.9's font-size axis; JR3 shipped that and
        // had to fix it. 60em resolves to 960 / 1080 / 1200px. Anchored for the reason above.
        expect(source).toMatch(/^@container \(max-width:\s*60em\)\s*\{/m);
    });

    it('establishes the query container on .builder, not on .builder__panes', () => {
        // A container query never matches its own container, and the compact layout has to rewrite
        // `.builder__panes`'s own `grid-template-columns` — put `container-type` there and that
        // declaration is unreachable. The toolbar is keyed on the same threshold and is a SIBLING of the
        // panes, so `.builder` is also the only element that contains both.
        expect(source).toMatch(/\.builder\s*\{[^}]*container-type:\s*inline-size/);
        expect(source).not.toMatch(/\.builder__panes\s*\{[^}]*container-type/);
    });

    it('pins what `em` resolves against', () => {
        // A container query's font-relative units resolve against the CONTAINER's own computed font size,
        // so the threshold would shift silently under any ancestor that set a different one. `app.css`
        // already puts this token on `body`, which makes the declaration a no-op today and a guarantee
        // afterwards.
        expect(source).toMatch(/\.builder\s*\{[^}]*font-size:\s*var\(--mds-type-body-lg-font-size\)/);
    });

    it('lets the panes have the flexible row, not a warnings banner', () => {
        // `grid-template-rows: auto 1fr` assumed two children. With a banner up there are three, so the
        // BANNER took the `1fr` row — sunken background and all — while the panes fell into an implicit
        // `auto` row. A column flex container is correct for any number of banners.
        //
        // ⚠️ SCOPED TO THE `.builder` RULE, not a bare search for the declaration — the block comment
        // above it QUOTES the rule it replaced, and prose about a defect is not the defect. (`.builder\s*\{`
        // cannot match `.builder__panes {`, which legitimately does set `grid-template-rows` inside the
        // container query.) Same anchoring lesson as `DataTable.test.ts`'s `^@media` assertion.
        expect(source).toMatch(/\.builder\s*\{[^}]*display:\s*flex/);
        expect(source).not.toMatch(/\.builder\s*\{[^}]*grid-template-rows/);
        expect(source).toMatch(/\.builder__panes\s*\{[^}]*flex:\s*1/);
    });

    it('hides panes with display:none, never with v-if', () => {
        // Three gates need these attached at every width: `field-library-axe`'s axe `.include()` THROWS
        // when its selector matches nothing, `builder-axe` calls `.evaluate()` on `.builder__pane--left`
        // at all three viewports, and nine `[role="tab"]` locators need ConfigPanel mounted. None of them
        // needs the pane painted.
        expect(source).toMatch(/\.builder__pane\s*\{\s*display:\s*none/);

        // ⚠️ THE WHOLE OPENING TAG, NOT A SUFFIX MATCH. The first version of this asserted
        // `not.toMatch(/builder__pane--(left|canvas|config)"[^>]*v-if/)`, which required `v-if` to be
        // written AFTER the class attribute — and Vue templates in this repo put structural directives
        // FIRST, so the assertion could not fail on the change it exists to catch. Pull each pane's
        // opening tag out and check the tag itself.
        for (const modifier of ['left', 'canvas', 'config']) {
            const tag = source.match(new RegExp(`<div[^>]*builder__pane--${modifier}[^>]*>`))?.[0];
            expect(tag, `the ${modifier} pane must exist`).toBeDefined();
            expect(tag, `${modifier} pane: display:none, never v-if — three e2e gates need it attached`)
                .not.toMatch(/\sv-(?:if|else|else-if)\b/);
        }

        for (const p of ['fields', 'canvas', 'settings']) {
            expect(source, `the --show-${p} rule is what makes that pane reachable`).toContain(
                `.builder__panes--show-${p}`,
            );
            // The CSS half is useless without the template half that emits the class.
            expect(source, `nothing emits builder__panes--show-${p}`).toMatch(
                /:class="`builder__panes--show-\$\{pane\}`"/,
            );
        }
    });

    it("the switcher's group label shares no word with any of its options", () => {
        // The ariaLabel renders as the fieldset's visually-hidden <legend>, INSIDE the element
        // `showBuilderPane()` scopes its getByText to. A legend containing "Form" would resolve two nodes
        // and fail strict mode on every call at once.
        const label = source.match(/ariaLabel="([^"]+)"\s*\n\s*compact/)?.[1];
        expect(label, 'the pane switcher must declare an ariaLabel').toBeDefined();
        for (const word of ['Add', 'Form', 'Settings']) {
            expect(label).not.toContain(word);
        }
    });

    it('gives every compacted toolbar button an aria-label equal to its visible text', () => {
        // Below the threshold `.builder__label` is `display: none` and the aria-label is the ONLY name
        // left. `builder-axe` asserts getByRole('button', { name: 'Form settings' }) at all three viewports,
        // and WCAG 2.5.3 Label-in-Name holds only while the two strings match — so they cannot be allowed
        // to drift. `title` is pinned too: it is what gives a mouse user on an extra_large desktop (where
        // the threshold is 1200px of container) any affordance at all over a row of bare glyphs.
        const buttons = source.match(/<MdsButton[\s\S]*?<\/MdsButton>/g) ?? [];
        const compacted = buttons.filter((b) => b.includes('builder__label'));

        // ⛔ M117 — EIGHT BECAME SIX, AND THE NUMBER IS THE ASSERTION RATHER THAN BOOKKEEPING. Schedule,
        // Confirmation and Share became sections of one "Form settings" modal under `D63`, and the
        // save-and-resume checkbox went with them. This row wrapping to ~4 rows / ~250px at 375px is the
        // defect JR5 existed to fix, so a silent REGROWTH restores it — which is what an exact count
        // refuses and a `toBeLessThan` would wave through. Changing it is a deliberate act with a reason.
        expect(compacted, 'the six secondary toolbar actions').toHaveLength(6);

        for (const button of compacted) {
            const label = button.match(/aria-label="([^"]+)"/)?.[1];
            const text = button.match(/class="builder__label">([^<]+)</)?.[1];

            expect(label, button.slice(0, 90)).toBe(text);
            expect(button, button.slice(0, 90)).toContain(`title="${label}"`);
        }
    });

    it('opens the settings modal from the toolbar and mounts no dialog the modal now owns', () => {
        // ⛔ THE POINT OF `D63` IS ONE ENTRY POINT, AND THE WAY THIS REGRESSES IS A DIALOG LEFT BEHIND. If
        // a later change re-adds a Schedule or Share button "just for convenience" the builder is back to
        // two entry points to the same route, which is the drift the decision exists to make impossible.
        // Mounting the three converted components here would do it silently — the modal would still work.
        expect(source).toContain('<FormSettingsModal');
        expect(source).toContain('aria-label="Form settings"');

        for (const gone of ['<ScheduleModal', '<ConfirmationModal', '<ShareModal']) {
            expect(source, `${gone} belongs to the settings modal now`).not.toContain(gone);
        }

        // The save-and-resume checkbox was the only non-button control in the row; it is a section now.
        expect(source).not.toContain('<MdsCheckbox');

        // ⚠️ Save as template STAYS, deliberately: it mints a new object rather than setting anything on
        // this form, and `templates-axe.spec.ts` clicks that name unscoped with `.first()` on the forms
        // list — so a second control with the name is the thing to avoid, not this one.
        expect(source).toContain('aria-label="Save as template"');
    });

    it('brings the config pane on screen when a save fails', () => {
        // `saveError` is rendered in exactly ONE place in the client — ConfigPanel's
        // `<p v-if="saveError" role="alert">` — which lives inside the config pane. Without this watcher a
        // failed write raised while the author is on Add or Form mounts that alert inside a `display:none`
        // subtree: not painted, not in the accessibility tree, never announced. The rule this increment
        // replaced only linearized the panes, so the silence would be NEW (WCAG 3.3.1 / 4.1.3).
        //
        // Deliberately asserted here rather than in a mount test: the defect is the interaction between a
        // container query and a Vue watcher, and happy-dom lays out neither.
        expect(source, 'the store’s saveError must be destructured to be watchable').toMatch(
            /const \{[^}]*\bsaveError\b[^}]*\} = store;/,
        );
        expect(source, 'a failed save must pull the pane that owns the alert on screen').toMatch(
            /watch\(\s*saveError\s*,[\s\S]{0,160}?pane\.value = 'settings'/,
        );
    });

    it('does not publish over a save that failed', () => {
        // ⛔ `whenIdle()` RESOLVES ON FAILURE, DELIBERATELY AND UNCHANGEABLY. `enqueue()` ends with
        // `queue = run.catch(() => undefined)`, so the promise it returns can never reject, and two
        // existing Vitest cases (`save-state.test.ts`, `builderClient.test.ts`) `await whenIdle()` AFTER a
        // failed burst — making it reject would turn both into unhandled rejections. So the guard cannot
        // live in the store contract; it has to read the verdict after the await.
        //
        // Why that matters more than it looks: `router.post` passes no `preserveState`, so Inertia's
        // non-GET default REMOUNTS this page, `useBuilderStore(props)` re-runs and `save.error` resets to
        // null. The author therefore saw a publish outcome and never saw that their last edit was lost.
        expect(source, 'publish must inspect the save verdict after whenIdle() resolves').toMatch(
            /whenIdle\(\)[\s\S]{0,400}?saveState\.value === 'failed'/,
        );
        expect(source, 'publish must not POST when the last save failed').toMatch(
            /saveState\.value === 'failed'[\s\S]{0,300}?return;/,
        );
    });

    it('renders publish violations in their own full-bleed banner', () => {
        // The refusal banner copies `builder__warnings` for markup and geometry and NOTHING else: that
        // banner's payload is `list<string>` prose and its title says "Published, with …", which is untrue
        // over a refusal. The doubled class below is load-bearing — it is a specificity tie against
        // MdsAlert's own scoped rule, explained in this file's style block and pinned by the flex case.
        expect(source, 'the violations banner must exist').toMatch(
            /<MdsAlert[\s\S]{0,300}?class="builder__violations"/,
        );
        expect(source, 'it must be driven by the publishViolations flash').toMatch(
            /publishViolations[\s\S]{0,200}?page\.props\.flash/,
        );
        expect(source, 'it must carry the same full-bleed specificity fix as the warnings banner').toContain(
            '.builder__violations.builder__violations',
        );
    });

    it('keeps Publish carrying its word at every width', () => {
        // `templates-axe.spec.ts` asserts getByRole('button', { name: 'Publish' }), and that name comes
        // from the slot text rather than from an aria-label.
        const publish = (source.match(/<MdsButton[\s\S]*?<\/MdsButton>/g) ?? []).find((b) =>
            /Publish\s*<\/MdsButton>/.test(b),
        );

        expect(publish, 'the Publish button must keep its plain slot text').toBeDefined();
        expect(publish).not.toContain('builder__label');
    });

    // ── Increment M118, `B7` — the centre pane's third option ───────────────────────────────────────────
    //
    // Kept in this suite, and as SOURCE TEXT, for the reason this file's own header gives: nothing in this
    // repo mounts `Builder.vue`, because doing so would drag `useBuilderStore`, the CSRF sidecar and ~20
    // children into a new Inertia mock for zero coverage of the thing under test. `PreviewPane.test.ts`
    // mounts the PANE and proves the behaviour; what can only be asserted here is the page-level wiring.

    it('offers exactly three centre views, and Preview is one of them', () => {
        const options = source.match(/const centreViews = \[([\s\S]*?)\];/)?.[1];

        expect(options, 'the centre control must declare its options as a literal').toBeDefined();
        expect((options!.match(/value:/g) ?? []).length, 'three centre views').toBe(3);
        expect(options).toContain("value: 'preview'");
    });

    // ⛔ THE TERNARY THAT WOULD HAVE MADE THE NEW OPTION A NO-OP CLICK. Until M118 this handler read
    // `centreView = $event === 'logic' ? 'logic' : 'structure'`, which silently collapsed every unrecognised
    // value onto `structure` — so a third segment changed nothing and no gate anywhere said so. The positive
    // assertion names the handler, because a negative one alone would be satisfied by the comment above it.
    it('narrows the centre view through a guard, never through a two-way ternary', () => {
        expect(source).toContain('@update:model-value="onCentreViewChange"');

        const handler = source.match(/function onCentreViewChange\(value: string\): void \{[\s\S]*?\n\}/)?.[0];

        expect(handler, 'the centre view must be narrowed by a named function').toBeDefined();
        for (const view of ['structure', 'preview', 'logic']) {
            expect(handler, `the handler must admit ${view}`).toContain(`'${view}'`);
        }
        expect(handler, 'a ternary here silently swallows every value it does not name').not.toContain('?');
    });

    // ⛔ `list-layout.spec.ts` ASSERTS `.builder__pane` COUNT IS EXACTLY 3, at all three viewports, counting
    // by `offsetParent` — so even a `display:none` fourth pane reddens it. The preview is a centre VIEW
    // inside the canvas pane, which is also what keeps `builder__label` at six and adds no ARIA node.
    it('adds the preview inside the canvas pane, never as a fourth pane', () => {
        expect((source.match(/builder__pane builder__pane--/g) ?? []).length, 'exactly three panes').toBe(3);
        expect(source).toContain('<PreviewPane');
        expect(source).toContain(`v-show="centreView === 'preview'"`);
    });

    // ⚠️ THE PANE-SWITCHER CASE ABOVE CANNOT SEE THIS CONTROL, WHICH IS WHY THIS IS A SECOND CASE RATHER THAN
    // AN EXTRA ASSERTION IN IT. Its `source.match(/ariaLabel="([^"]+)"\s*\n\s*compact/)` is NON-GLOBAL, so it
    // returns the FIRST such pair — the pane switcher, only because that control happens to come first in the
    // template — and the centre control has never been covered by it. Matching globally and asserting BOTH
    // labels by name removes the dependency on source order from both cases at once.
    it("the centre control's group label shares no word with any of its options", () => {
        const labels = [...source.matchAll(/ariaLabel="([^"]+)"\s*\n\s*compact/g)].map((m) => m[1]);

        expect(labels, 'both segmented controls declare an ariaLabel').toHaveLength(2);
        expect(labels).toContain('Builder pane');
        expect(labels).toContain('Centre pane view');

        // The legend renders INSIDE the element `builder-axe` scopes its `getByText` to, so a shared word
        // resolves two nodes and fails strict mode on every call at once.
        const centre = labels.find((l) => l === 'Centre pane view')!;
        for (const word of ['Structure', 'Preview', 'Logic']) {
            expect(centre, `the centre legend must not contain ${word}`).not.toContain(word);
        }
    });

    it('gives the preview a glyph no other control on this page uses', () => {
        // `layout` is Structure, `filter` is Logic, `forms` is the pane switcher's Form, `sliders` its
        // Settings, `plus` its Add. A repeated glyph is the same collision the labels are guarded against,
        // one channel over — and `monitor` was chosen because it is the only unused one that reads as
        // "see it as a respondent".
        const icons = [...source.matchAll(/icon: '([a-z-]+)' as const/g)].map((m) => m[1]);

        expect(icons).toContain('monitor');
        expect(new Set(icons).size, 'no glyph is used twice on this page').toBe(icons.length);
    });
});
