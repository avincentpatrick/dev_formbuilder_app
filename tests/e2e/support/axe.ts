import { expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Shared composed-page gate helpers (used by both the authenticated responsive-axe scan and the guest
 * public-runtime scan): assert no horizontal overflow (Feature #5's responsive contract) and zero WCAG 2.2 AA
 * violations, and force the dark theme so axe measures the dark palette on the real composed page.
 *
 * ⛔ **THE OVERFLOW HALF OF THAT SENTENCE WAS FALSE FOR THIRTY-SIX DAYS, ON EVERY `AppLayout` PAGE.** It
 * measured `documentElement.scrollWidth` only, while `.app-shell` has been `overflow-x: clip` since G11
 * (`506ff97`, 2026-07-21) — and `clip` mints no scroll container, so the number it read could not move.
 * M17 measured the gap at **312px of real overflow read as 0**. It now measures the shell's own scroll
 * region too; `assertNoHorizontalOverflow` below owns the reasoning, including what it still cannot see.
 * ⚠️ The guest public-runtime scan was never affected — that tree carries no clip — so its results across
 * the whole period stand.
 */
/**
 * Wait for the next paint to actually land.
 *
 * Two frames, not one: the first callback fires BEFORE the paint that applies the recalculated style, so a
 * single `requestAnimationFrame` still returns too early. Exported because BOTH things this module does to a
 * page before scanning it — flipping the theme and parking the pointer — invalidate style and settle a frame
 * later, and only one of them used to wait.
 */
export async function settlePaint(page: Page): Promise<void> {
    await page.evaluate(
        () => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))),
    );
}

/**
 * Wait until nothing is animating, then let the resulting style land.
 *
 * ⚠️ THE TWO-FRAME WAIT ABOVE IS NOT THIS, AND THE GAP BETWEEN THEM COST TWO CI RUNS. `settlePaint`
 * waits for the next PAINT; it says nothing about whether a 400ms transition is a tenth of the way
 * through. Everything the file already knew about mid-transition sampling was about transitions the
 * SCAN started (a theme flip, an un-hover) — and the one that actually bit was started by the TEST,
 * on the way in, long before any of this code runs: the *share panel, live link* case in
 * `builder-axe.spec.ts` (`:198` when the incident was recorded, `:205` after this change — cite the
 * test's NAME, which is stable, rather than a line this file's own comments keep moving) opens
 * `MdsModal`, whose
 * `.mds-modal-enter-active` fades opacity over `--mds-duration-slow`, and axe sampled the primary
 * action button at ~96.5% opacity. That composites `--mds-primary-600` `#0E6FE8` — 4.71:1 and
 * PASSING — against the white page as `#1674e9`, which is 4.45 and fails. The backlog row read the
 * hex as a token and filed it as a contrast defect; there is no such token in this repository.
 *
 * `playwright.config.ts` now sets `reducedMotion: 'reduce'` at context creation, which collapses
 * every `--mds-duration-*` to 1ms and is the real fix. This is the belt to that pair of braces, and
 * it earns its place by stating the invariant where the next reader will look for it: NOTHING IS
 * ANIMATING WHEN AXE SAMPLES. It also covers anything a future component drives outside the token
 * system — a Web Animations API call, or a hard-coded duration no media query reaches.
 *
 * Two deliberate limits. Infinite animations (spinners, skeletons) are filtered out, because their
 * `finished` promise never settles by definition. And the whole wait is raced against a short cap, so
 * a paused or pathologically long animation degrades to today's behaviour instead of hanging until
 * the 60s test timeout — a gate that hangs teaches people to skip it.
 */
const ANIMATION_SETTLE_CAP_MS = 1_000;

export async function settleAnimations(page: Page): Promise<void> {
    await page.evaluate(async (capMs) => {
        const running = document
            .getAnimations()
            .filter((animation) => animation.effect?.getComputedTiming().iterations !== Infinity)
            // A rejected `finished` (an animation cancelled mid-flight, which a Vue <Transition>
            // does routinely) is not a failure of the page — swallow it and keep waiting on the rest.
            .map((animation) => animation.finished.catch(() => undefined));

        if (running.length === 0) return;

        await Promise.race([
            Promise.all(running),
            new Promise((resolve) => {
                setTimeout(resolve, capMs);
            }),
        ]);
    }, ANIMATION_SETTLE_CAP_MS);

    await settlePaint(page);
}

/**
 * Assert nothing runs off the side — of the DOCUMENT *and* of the shell's own scroll region.
 *
 * ⛔ ── WHY THE DOCUMENT-ONLY VERSION OF THIS COULD NOT FAIL, ON ANY `AppLayout` PAGE ──────────────
 * This assertion used to read `documentElement.scrollWidth > clientWidth + 1` and nothing else, and
 * `AppLayout.vue` sets `.app-shell { overflow-x: clip }`. `clip` mints **no scroll container**, so an
 * overrun inside the shell neither scrolls nor widens the document: `documentElement.scrollWidth`
 * is pinned flat and the assertion is **structurally incapable of failing**. It had been that way
 * since the clip landed, while three files in this suite went on citing it as the reason their pages
 * are scanned at all.
 *
 * ⚠️ **MEASURED, NOT ARGUED (M17).** Deleting the load-bearing `overflow-wrap: anywhere` from
 * `.dns__code` — the 64-hex DNS verification token on `/domains` — put **312px** of real overflow
 * inside `.app-shell__content` at 375px. The document reading stayed at **0**, and
 * `responsive-axe.spec.ts`'s *"Domains — accessible & no horizontal overflow"* passed, twice, in
 * both themes. A test named for the thing it cannot see.
 *
 * ── SO IT MEASURES TWO BOXES, AND BOTH ARE LOAD-BEARING ────────────────────────────────────────
 * `.app-shell__content` catches every `AppLayout` page: it is `overflow-y: auto` (so its `overflow-x`
 * computes to `auto`) and the `--fluid` builder variant is `overflow: hidden` — **both mint a scroll
 * container, unlike `clip`, so `scrollWidth` genuinely grows.** The document check stays because it
 * is the *only* real one where there is no shell: `AuthLayout`, `AdminLayout`, the guest runtime and
 * `Welcome.vue` carry no clip, and anything teleported to `<body>` (modals, toasts) never enters the
 * shell at all. **Neither box subsumes the other. Do not delete one for tidiness.**
 *
 * ⚠️ The third assertion is the one that keeps the second honest: a page carrying `.app-shell` but no
 * `.app-shell__content` fails LOUDLY rather than quietly measuring nothing. A renamed class would
 * otherwise restore the exact blindness this function exists to end, and it would look green.
 *
 * ── WHAT IT STILL DOES NOT SEE, STATED SO NOBODY READS IT AS MORE THAN IT IS ───────────────────
 * An overrun of the **top nav** clips at `.app-shell`, above the content region, so neither box moves;
 * `search-nav.spec.ts` measures bounding boxes for exactly that reason. An element that is its own
 * scroll container (`MdsDataTable`'s wrapper on desktop) legitimately absorbs its own overflow —
 * `list-layout.spec.ts` owns that case. This is a third instrument beside those two, not a
 * replacement for either.
 */
/**
 * Pages that ALREADY overflow their content region, keyed `<assertClean label> @<viewport width>`.
 *
 * ✅ **EMPTY SINCE M19, AND THE MECHANISM STAYS BECAUSE IT IS WHAT EMPTIED IT.** M17 filled this with
 * five entries; M19 fixed all four underlying defects and deleted all five. The list is kept — not the
 * entries — so the next real find has somewhere to go that cannot rot.
 *
 * ⛔ **THIS LIST MAY ONLY EVER SHRINK, AND THE CODE BELOW ENFORCES THAT RATHER THAN ASKING.** A listed
 * page is asserted to *still* overflow; the moment somebody fixes one, its entry fails with an
 * instruction to delete it. So it cannot rot into a list of things that used to be broken — the exact
 * discipline `clipped-node-containment.test.ts`'s `KNOWN_UNGUARDED` already applies in this repo, and
 * the reason that one is trusted.
 *
 * ⛔⛔ **THE REASON THE FIVE WERE QUARANTINED WAS WRONG, AND IT IS WORTH MORE THAN THE FIXES.** This
 * block used to say they could not be reproduced here — *"none of them reproduces on a Windows host …
 * a probe that inlined OpenDyslexic as a data URI measured 0 overflow on all six page × viewport
 * combinations."* Both halves were true and the conclusion did not follow. **OpenDyslexic cannot reach
 * the elements that failed**: `theme-overrides.css` re-points only `--mds-font-family-body`, and its own
 * docblock says the Display role is untouched — while `.page-header__title` and `.builder__title` are
 * both `--mds-font-family-display`. The form hub had no personalization at all. **The face that differs
 * is the DISPLAY stack's fallback**: `system-ui` resolves to Segoe UI Variable Display on Windows and to
 * DejaVu Sans on a CI runner, ~27% wider — 256px against 324px for the word "Submissions" at 48px.
 * **A probe that measures 0 has told you nothing until you know it exercised the thing that broke.**
 *
 * ✅ **AND THE ENVIRONMENT IS NO LONGER AN EXCUSE: `docker compose run --rm e2e test …` REPRODUCES CI
 * TO THE PIXEL.** M19 measured 17 / 24 / 28 locally — the same three numbers CI reported — from a
 * Linux container with DejaVu present and the app serving BUILT, same-origin assets. Two prerequisites,
 * both silent when unmet, are documented on that compose service and in `README.md`. **Before
 * quarantining anything here again, run it there.**
 *
 * ⛔ **DO NOT ADD TO THIS LIST TO GET A BUILD GREEN.** A new entry means a page regressed; fix the page.
 */
const KNOWN_OVERFLOWING: ReadonlySet<string> = new Set([]);

export async function assertNoHorizontalOverflow(page: Page, label: string): Promise<void> {
    const measured = await page.evaluate(() => {
        const doc = document.documentElement;
        const content = document.querySelector('.app-shell__content');

        // ⚠️ THE DRIFT GUARD ASKS ABOUT A SHELL THAT IS SPECIFICALLY `clip`, AND BOTH LOOSER VERSIONS OF
        // IT WERE WRONG — each in a way worth keeping, because both are easy to write again.
        //
        // v1 asked "is `.app-shell` present without `.app-shell__content`?" and reddened **48 tests** on
        // the GUEST RUNTIME, which has an `.app-shell` of its own (`resources/public-runtime/App.vue:457`)
        // that is a plain flex column with no clip at all. A pure class-name collision across two trees,
        // invisible from `resources/js/` — the only place a Lane A grep would have looked.
        //
        // v2 over-corrected to "does ANY element clip or hide the horizontal axis?", which is true on
        // essentially every page in the app: `overflow: hidden` is how every sr-only paragraph is drawn
        // (`p.sync-status__sr`) and how a scroll lock is applied to `body` while a modal is open. It
        // reddened the guest suite just as thoroughly, for entirely different reasons.
        //
        // What actually matters is narrow: **the page shell is `overflow-x: clip`** — the one property
        // that makes `documentElement.scrollWidth` blind — **and the scroll region inside it was not
        // found.** `hidden` is deliberately NOT included: it mints a scroll container, so its overflow is
        // still measurable and it is not the blindness this gate exists for.
        //
        // ⚠️ Its limit, stated rather than discovered: renaming `.app-shell` ITSELF silences this. That is
        // a layout rewrite rather than drift, and no guard survives one — but do not read this as broader
        // than it is.
        const shell = document.querySelector('.app-shell');
        const shellIsClipped = shell !== null && getComputedStyle(shell).overflowX === 'clip';

        // Name the widest thing sticking out, so a failure is actionable rather than a number.
        //
        // ⛔ A BOX INSIDE A SCROLL CONTAINER IS SKIPPED WITH THE WHOLE SUBTREE, AND THE FIRST VERSION
        // SKIPPED ONLY THE CONTAINER ITSELF — WHICH IS WORSE THAN NAMING NOTHING AT ALL. Anything that
        // spills inside an `overflow: auto` ancestor is ABSORBED there and contributes exactly nothing to
        // the number this function asserts on, so naming it hands the reader a culprit that cannot be the
        // cause. Measured in M19: the builder's reported "30px `mds-segmented__seg`" is `ConfigPanel`'s
        // Requiredness control spilling inside `.config`, which is `overflow-y: auto`; the 24px actually
        // measured was `.builder__title-row`, a different element in a different component. That
        // misattribution reached `docs/feature-backlog.md` as a row of its own and cost the real defect
        // its name for a fortnight.
        //
        // Absorbed spills are still collected, separately and reported last, because "something does
        // overflow in here, and it is not what failed this assertion" is worth saying once rather than
        // leaving the next reader to re-derive it.
        //
        // A plain object rather than three `let`s: TypeScript does not track assignments made inside the
        // nested walker, and narrows a bare `let x: T | null = null` back to `null` for every read after
        // the loop.
        type Hit = { tag: string; cls: string; spill: number };
        const found: { worst: Hit | null; absorbed: Hit | null; text: (Hit & { text: string }) | null } = {
            worst: null,
            absorbed: null,
            text: null,
        };

        if (content) {
            const right = content.getBoundingClientRect().right;
            const scrolls = (el: Element): boolean => {
                const o = getComputedStyle(el).overflowX;
                return o === 'auto' || o === 'scroll';
            };
            const describe = (el: Element) => ({
                tag: el.tagName.toLowerCase(),
                cls: el.className?.toString().slice(0, 80) ?? '',
            });

            // Descend by hand rather than with `querySelectorAll`, so a scroll container can stop the walk
            // for its entire subtree instead of only for itself.
            const visit = (el: Element, inScroller: boolean): void => {
                const box = el.getBoundingClientRect();
                const spill = Math.round(box.right - right);
                if (box.width > 0 && spill > 1) {
                    if (inScroller) {
                        if (!found.absorbed || spill > found.absorbed.spill) found.absorbed = { ...describe(el), spill };
                    } else if (!found.worst || spill > found.worst.spill) {
                        found.worst = { ...describe(el), spill };
                    }
                }
                const childrenInScroller = inScroller || scrolls(el);
                for (const child of Array.from(el.children)) visit(child, childrenInScroller);
            };
            for (const child of Array.from(content.children)) visit(child, false);

            // ⚠️ AND WHEN NO *ELEMENT* STICKS OUT, THE OVERFLOW IS USUALLY A LINE BOX — WHICH HAS NO
            // ELEMENT TO NAME. `querySelectorAll` returns elements, so unbreakable text overrunning its own
            // block was invisible here, and the message fell back to blaming "an intrinsic minimum on a grid
            // or flex track". That is a guess, and on the form hub it was the wrong one: `.hub__tiles` is
            // `repeat(auto-fit, minmax(200px, 1fr))`, and a FIXED min track function resolves each tile's
            // `min-width: auto` to 0, so no tile box can blow out — the old message pointed at the one thing
            // that was provably innocent. A Range over the text node measures the line box directly.
            // Measured in M19: the hub's 28px is the single word "Accepting" in `.mds-stat-tile__value`.
            if (!found.worst) {
                const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
                for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
                    const raw = node.nodeValue ?? '';
                    if (raw.trim() === '') continue;
                    const parent = node.parentElement;
                    if (parent === null) continue;

                    let inScroller = false;
                    for (let a: Element | null = parent; a !== null && a !== content; a = a.parentElement) {
                        if (scrolls(a)) {
                            inScroller = true;
                            break;
                        }
                    }
                    if (inScroller) continue;

                    const range = document.createRange();
                    range.selectNodeContents(node);
                    for (const rect of Array.from(range.getClientRects())) {
                        const spill = Math.round(rect.right - right);
                        if (spill > 1 && (!found.text || spill > found.text.spill)) {
                            found.text = { ...describe(parent), spill, text: raw.trim().slice(0, 40) };
                        }
                    }
                }
            }
        }

        return {
            document: doc.scrollWidth - doc.clientWidth,
            content: content ? content.scrollWidth - content.clientWidth : null,
            clippedShellWithoutContentRegion: shellIsClipped && content === null,
            worst: found.worst,
            absorbed: found.absorbed,
            overflowingText: found.text,
        };
    });

    expect(
        measured.clippedShellWithoutContentRegion,
        `${label}: .app-shell is overflow-x: clip but .app-shell__content was not found — the selector ` +
            'this gate measures has drifted, and the page-level overflow check is silently measuring ' +
            'nothing, which is the exact blindness this assertion exists to end',
    ).toBe(false);

    // 1px, not 0: sub-pixel layout rounding is not a regression. Same tolerance the three
    // element-level checks in this suite already use.
    expect(measured.document, `${label} scrolls the document horizontally at this viewport`).toBeLessThanOrEqual(1);

    if (measured.content !== null) {
        const quarantineKey = `${label} @${page.viewportSize()?.width ?? 0}`;

        if (KNOWN_OVERFLOWING.has(quarantineKey)) {
            // The "may only shrink" half: a quarantined page must STILL be broken, so fixing one fails
            // here and forces its entry out. That is the only thing keeping the list from outliving the
            // defects it names.
            //
            // ⚠️ ENFORCED IN CI ONLY, AND THE REASON IS THE WHOLE REASON THESE ARE QUARANTINED. All three
            // overruns are text-driven and appear only under CI's Linux font metrics; on a Windows host
            // the same pages measure 0. Asserting "still overflows" everywhere would redden three tests
            // for every local run — a gate that is red for an environmental reason is one people learn to
            // ignore, which is the same disease as the flaky retry M16 removed.
            if (process.env.CI) {
                expect(
                    measured.content,
                    `"${quarantineKey}" no longer overflows — DELETE its KNOWN_OVERFLOWING entry in ` +
                        'tests/e2e/support/axe.ts and close the matching row in docs/feature-backlog.md. ' +
                        'This is the good failure.',
                ).toBeGreaterThan(1);
            }

            return;
        }

        // The order is the order of usefulness: an element that really sticks out, else the line box that
        // does, else an honest admission. ⚠️ The absorbed note comes LAST and is never the headline — it
        // names something that overflows without being the cause, which is the misreading this gate itself
        // produced in M17 and which reached the backlog as a row of its own.
        const offender = measured.worst
            ? ` Widest offender: <${measured.worst.tag} class="${measured.worst.cls}"> sticking out ` +
              `${measured.worst.spill}px.`
            : measured.overflowingText
              ? ` No element sticks out — the overflow is a LINE BOX: the text "${measured.overflowingText.text}" ` +
                `inside <${measured.overflowingText.tag} class="${measured.overflowingText.cls}"> runs ` +
                `${measured.overflowingText.spill}px past the region. Unbreakable text in a box with no ` +
                '`overflow-wrap`/`hyphens` escape — not a grid or flex track.'
              : ' Nothing measurable sticks out: no element and no line box. Suspect a pseudo-element, a ' +
                'transform, or padding on the region itself — and say which, rather than guessing again.';

        const absorbedNote = measured.absorbed
            ? ` (Also, but NOT the cause: <${measured.absorbed.tag} class="${measured.absorbed.cls}"> spills ` +
              `${measured.absorbed.spill}px inside a scroll container, which absorbs it. Real, separately ` +
              'filed, and it contributes nothing to the number above.)'
            : '';

        expect(
            measured.content,
            `${label} overflows the shell's content region horizontally at this viewport ` +
                `(${measured.content}px). The document width does NOT move for this — .app-shell is ` +
                `overflow-x: clip — so this is the assertion that can see it.${offender}${absorbedNote}`,
        ).toBeLessThanOrEqual(1);
    }
}

export async function assertClean(page: Page, label: string): Promise<void> {
    // Park the pointer off every control so axe measures resting styles, not a `:hover` state left over from
    // the test's last click (a parked cursor over a primary button reads its lighter hover bg and mis-flags
    // its contrast — a test artifact, not a real violation).
    await page.mouse.move(0, 0);

    // ⚠️ AND WAIT FOR THE UN-HOVER TO PAINT — the half J1e left behind, and it came back to collect.
    // Moving the pointer off a control starts a transition on the control it left, exactly as flipping the
    // theme starts one on everything; "collapsing the window is not closing it" applies identically. J2b's
    // CI run flaked on `builder-axe.spec.ts` "share panel, live link (dark)" with 93 violations reporting
    // `#6f99b5` on `#123350` (the dark `bg-surface` OF THE DAY — JR1 moved it to `#1a2130`; the hexes in
    // this file are preserved as the incident recorded them, not updated to the current palette)
    // — the background settled to the real dark `bg-surface` token while the
    // FOREGROUND was still an intermediate that appears in no token file, over
    // `.share__row--actions > .mds-button--secondary`: the button the test had just clicked. A `transparent`
    // secondary button whose only opaque state is `:hover` is precisely the shape that produces this.
    //
    // ⛔ AND THAT PARAGRAPH DESCRIBED THE CLASS CORRECTLY WHILE CLOSING ONLY THE HALF IT COULD SEE.
    // Every incident recorded above is a transition THIS FUNCTION started — a theme flip, an un-hover —
    // so waiting one more paint was enough for each of them. The one it could not reach was started by
    // the TEST on the way in and was already running before `assertClean` was called at all: the share
    // panel's own 400ms modal fade. Two CI runs five days apart failed on it and both merged green
    // (D2 in docs/claims/decisions.md). `settleAnimations` closes the general case, and
    // `reducedMotion: 'reduce'` in playwright.config.ts makes it a formality rather than a race.
    await settleAnimations(page);

    await assertNoHorizontalOverflow(page, label);

    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

    expect(
        results.violations,
        results.violations
            .map((v) => `${v.id}: ${v.help} → ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`)
            .join('\n'),
    ).toEqual([]);
}

export async function forceTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    // Emulate reduced motion so the design system's central duration guard collapses every transition to 1ms:
    // otherwise axe can measure an element (e.g. a button's background-color) mid theme-flip transition and read
    // an intermediate, failing contrast — a timing flake that surfaces on heavier composed pages.
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.evaluate((t) => {
        if (t === 'dark') document.documentElement.setAttribute('data-theme-mode', 'dark');
        else document.documentElement.removeAttribute('data-theme-mode');
    }, theme);

    // ⚠️ AND THEN WAIT FOR THE FLIP TO ACTUALLY LAND, WHICH THE PARAGRAPH ABOVE PROMISED AND DID NOT DO.
    // Collapsing transitions to 1ms shortens the window; it does not close it. `setAttribute` only
    // invalidates style — the recalc and paint happen on a later frame — so a scan issued immediately
    // afterwards can still read the OLD foreground against the NEW background. J1e hit both halves of that
    // window on `builder-axe.spec.ts:104` at mobile: one run read `#7da9c4` on `#1d4260` (4.17, mostly
    // flipped) and the next read `#1c4b72` on `#123350` (1.42, dark-on-dark) across 309 lines of
    // violations — the "233 violations at once = styles not settled" shape this repo already had on record
    // as a standing flake in the same file.
    //
    await settlePaint(page);
}

export type Personalization = {
    accent?: 'blueprint' | 'teal';
    fontSize?: 'standard' | 'large' | 'extra_large';
    dyslexia?: boolean;
};

/**
 * Sibling of forceTheme for the other three §2.9 personalization axes (G11), driving the root
 * attributes directly rather than round-tripping through Settings — so a scan costs one page load.
 *
 * Follows the same "default = ABSENCE of the attribute" convention the server uses, so a scan with
 * `{}` measures exactly what an un-personalized user sees.
 *
 * The `document.fonts.ready` await is NOT optional. The dyslexia face is fetched only once a rule
 * using the family matches an element — i.e. only after the attribute below is set — so without
 * waiting, axe and the horizontal-overflow assertion measure the FALLBACK stack's glyph metrics.
 * OpenDyslexic is substantially wider than the system stack, so that is the difference between a
 * scan that means something and an intermittent failure that only reproduces under CI load.
 */
export async function forcePersonalization(page: Page, options: Personalization): Promise<void> {
    await page.emulateMedia({ reducedMotion: 'reduce' });

    await page.evaluate((opts) => {
        const html = document.documentElement;
        const set = (attribute: string, value: string | null): void => {
            if (value === null) html.removeAttribute(attribute);
            else html.setAttribute(attribute, value);
        };

        set('data-accent', opts.accent === 'teal' ? 'teal' : null);
        set('data-font-size', opts.fontSize && opts.fontSize !== 'standard' ? opts.fontSize : null);
        set('data-dyslexia-font', opts.dyslexia ? 'true' : null);
    }, options);

    await page.evaluate(() => document.fonts.ready.then(() => undefined));
}
