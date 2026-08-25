import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The tree-wide containing-block guard (J3b).
 *
 * ── WHY A WHOLE-TREE SCAN AND NOT A FOURTH PER-COMPONENT TEST ──────────────────────────────────────────
 * This repository has now paid for the same defect four times. G11 found it horizontally on
 * `MdsDataTable`, whose own comment already called it "a latent bug in this component". JR5 found it
 * vertically on `MdsSegmentedControl`, where a hidden `<legend>` sat 73px below the workspace and the page
 * gained 73px of real scroll. J3b found `MdsSpinner` and `MdsTimeSeriesChart` still carrying it while
 * building `MdsPasswordStrength` against the same hazard — and then found seven more in the app trees.
 *
 * A per-component test guards the component it lives in and nothing else, which is precisely why four
 * separate discoveries were needed. This one fails at the moment a NEW instance is written anywhere.
 *
 * ── THE MECHANISM, BECAUSE THE ASSERTION LOOKS ARBITRARY WITHOUT IT ────────────────────────────────────
 * The visually-hidden idiom is `position: absolute` + `clip: rect(0 0 0 0)`. Absolute positioning resolves
 * against the nearest POSITIONED ancestor — so with none, the node's containing block is established far
 * up the tree, and no scroll container in between is able to clip it. The node then contributes to the
 * DOCUMENT's scrollable box instead of its own component's, which is how a 1px announcement region comes
 * to add tens of pixels of real page scroll.
 *
 * ⚠️ NOTHING ELSE HERE CAN SEE IT. happy-dom computes no layout, so a mounted assertion passes whatever
 * the CSS says. The e2e overflow assertion reads `documentElement.scrollWidth`, which
 * `.app-shell { overflow-x: clip }` pins flat on every authenticated page. axe has no rule for it. Source
 * text is the only place this can be held, which is also why it stayed latent for four increments.
 *
 * ── WHAT THIS CHECKS, AND WHAT IT HONESTLY DOES NOT ────────────────────────────────────────────────────
 * Whether a containing block is CORRECT needs a laid-out DOM. What is checkable from source is the shape
 * every one of the six real instances had: a file that clips a node and positions NOTHING AT ALL, so
 * there is no candidate ancestor inside the component. That is an approximation in the safe direction —
 * it cannot produce a false alarm for a file that positions something, and it catches the exact failure
 * that has actually occurred here every time.
 */

const REPO_ROOT = process.cwd();
const SCAN_ROOTS = ['packages/design-system/src', 'resources/js', 'resources/public-runtime'];

const CLIPPED = /clip:\s*rect\(0 0 0 0\)/;
const POSITIONED = /position:\s*(relative|absolute|fixed|sticky)/;
/** A CSS rule: a selector run, then a brace-delimited declaration block with no nesting. */
const RULE = /([^{}]+)\{([^{}]*)\}/g;

/**
 * Components that already carried this shape when the guard was written, all of them sr-only live
 * regions. They are listed rather than fixed because the fix is one line but the VERIFICATION is not:
 * adding `position: relative` to a container also makes it the containing block for any other absolutely
 * positioned descendant and establishes a stacking context, so each needs a look at the running app
 * before it is called done. Recorded in `docs/feature-backlog.md` under Discovered defects.
 *
 * ⚠️ THIS LIST MAY ONLY EVER SHRINK. The test below asserts it exactly, so removing a file from the app
 * tree or fixing one without deleting its entry fails just as loudly as adding a new violation.
 *
 * ✅ IT SHRANK IN J4c: `resources/js/components/shell/CommandPalette.vue` is GONE, and not by being
 * "fixed" in place. Its two clipped nodes — the combobox's label and its polite live region — moved into
 * `MdsCombobox`, which positions its own root, so their containing block now resolves inside the component
 * that owns them. The app-tree file no longer matches the clip idiom at all. This is the first entry ever
 * removed, and the shape of the removal is the one the note above asks for: the defect left, rather than
 * the list being edited to stop noticing it.
 *
 * ✅ AND AGAIN IN M15, WHICH IS THE FIRST TIME THIS FILE DID THE JOB IT WAS BUILT FOR ACROSS THE LANE
 * BOUNDARY. `resources/public-runtime/components/SyncStatus.vue` is GONE. That file lives in the OTHER
 * lane's tree, and this test — in this one — is what forced its containing block to be fixed rather than
 * routed around: `SCAN_ROOTS` reads it off disk, so the guard and this entry could only ever move
 * together, in one PR, by one author. `.sync-status` now sets `position: relative`, and that component's
 * own comment records the descendant audit the note above demands. Standing Rule 7(b-bis) exists because
 * of files like this one.
 */
const KNOWN_UNGUARDED = [
    'resources/js/Pages/scopes/Index.vue',
    'resources/js/components/builder/BuilderCanvas.vue',
    'resources/js/components/shell/FeedbackButton.vue',
    'resources/js/components/submissions/GeoInput.vue',
    // Still here on purpose: M15 changed `SyncStatus.vue` and did not touch this one, and a containing
    // block added without a look at the running app is the thing the note above refuses to accept.
    'resources/public-runtime/components/RuntimeShell.vue',
];

/**
 * Three whole-tree walks, and they run beside `token-references.test.ts`'s two. Vitest's default 5s
 * per-test timeout is not enough for that under load — the first combined run of these files failed the
 * anti-vacuity case at ~3.4s solo, purely on contention. The same constant and the same reason as
 * `token-references.test.ts`, which pays for identical walks.
 */
const WALK_TIMEOUT_MS = 30_000;

function walk(dir: string): string[] {
    const out: string[] = [];

    for (const entry of readdirSync(dir)) {
        if (entry === 'node_modules' || entry === 'dist') {
            continue;
        }

        const full = join(dir, entry);

        if (statSync(full).isDirectory()) {
            out.push(...walk(full));
        } else if (entry.endsWith('.vue')) {
            out.push(full);
        }
    }

    return out;
}

/** Every single-file component that clips a node without positioning anything, repo-relative. */
function unguardedFiles(): string[] {
    const found: string[] = [];

    for (const root of SCAN_ROOTS) {
        for (const file of walk(join(REPO_ROOT, root))) {
            const source = readFileSync(file, 'utf8');

            if (!CLIPPED.test(source)) {
                continue;
            }

            let positionsSomethingElse = false;

            for (const [, , body] of source.matchAll(RULE)) {
                if (!CLIPPED.test(body) && POSITIONED.test(body)) {
                    positionsSomethingElse = true;
                    break;
                }
            }

            if (!positionsSomethingElse) {
                found.push(file.slice(REPO_ROOT.length + 1).replace(/\\/g, '/'));
            }
        }
    }

    return found.sort();
}

describe('visually-hidden nodes have a containing block inside their own component', () => {
    it('finds a non-trivial number of clipped components at all (the scan is actually working)', () => {
        // Without this, a path change makes every assertion below iterate an empty list and pass — the
        // failure mode `token-references.test.ts` guards against with the same shape of case.
        const clipping = SCAN_ROOTS.flatMap((root) => walk(join(REPO_ROOT, root))).filter((file) =>
            CLIPPED.test(readFileSync(file, 'utf8')),
        );

        expect(clipping.length).toBeGreaterThan(10);
    }, WALK_TIMEOUT_MS);

    it('holds for every component in the design system, with no exceptions', () => {
        // The package is the part this increment owns and has cleaned. It stays at zero: a component
        // shipped from here is mounted by pages that cannot see its internals.
        const offenders = unguardedFiles().filter((file) => file.startsWith('packages/'));

        expect(offenders).toEqual([]);
    }, WALK_TIMEOUT_MS);

    it('has exactly the known app-tree exceptions, and no more', () => {
        // ⚠️ IF THIS FAILS WITH AN EXTRA ENTRY, DO NOT ADD IT TO THE LIST. A new clipped node with no
        // positioned ancestor is the bug this file exists to stop, and it is free to fix at the moment it
        // is written and expensive to find afterwards — four increments of evidence say so.
        expect(unguardedFiles()).toEqual([...KNOWN_UNGUARDED].sort());
    }, WALK_TIMEOUT_MS);
});
