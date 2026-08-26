# Lane A — active claim

**One writer: Lane A.** Lane B never edits this file, and Lane A never edits `lane-b.md`. That is
what makes a claim conflict structurally impossible rather than merely unlikely.

**The protocol is Standing Rule 7(g)**, not this header. In one line: **a claim is a *pushed*
commit** — write it here, `git push origin HEAD:main`, and only then open the first file. An
unpushed claim does not exist; M14 proved that by writing a perfect one that nobody could see.

**Before opening any shared or paired artefact**, `git fetch` and read both lane files.

Shared artefacts, which are claimed and never owned: `docs/**`, `openapi.json`, `phpunit.xml`,
`PROGRESS.md` (own block only), and the top-level `tests/e2e/*.spec.ts`.
Paired files — where a change obliges you to edit *both* halves in the same PR — are listed in
Standing Rule 7(b-bis).

---

## Status: ACTIVE CLAIM — `M23`, the four App-UI rows, taken as one increment

**Taken 2026-08-26.** Branch `m23-app-ui-quartet`, cut from `origin/main` at `e14d73b`, PR into `main`.
Rows: all four bullets under **App UI** in `docs/feature-backlog.md` — one `major` (the double-clicked
"Create" that provisions two spreadsheets) and three `minor` (the rule modal's unfiltered submit, the
unearned-badge medallion in dark, the top-nav search field on an Inertia arrival).

**⚠️ NUMBERED `M23`, AND THE REF WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN, NOT AT SESSION
OPEN.** Both reads return `e14d73b`; `docs/claims/lane-b.md` reads **ACTIVE CLAIM `M22`** in both. M20 is
merged and released (PR #212, `836a182`). So the next free number is `M23`.

**No collision on the merits.** Every file this increment edits is under `resources/js/**` or
`packages/design-system/**`, both of which Standing Rule 7(b) grants Lane A outright as widened
2026-08-25. `M22` is wholly inside `resources/public-runtime/`, which is Lane B's outright. The two
columns do not meet. The only overlaps are shared artefacts by disjoint region — `docs/feature-backlog.md`
(Lane A closes the four **App UI** rows; Lane B closes one under the guest-runtime review) and
`PROGRESS.md` (own block only, per 7(d)).

**⛔ NAMESPACES — THIS INCREMENT SPENDS NOTHING FROM ANY OF THEM.** No ADR, no migration prefix, no
`§D<n>`, no threat-model row. Next free ADR remains **`0022`** and remains Lane A's block-opener
(`0022-0025`); `0010` reserved for H1d; `#16` free; next free migration prefix remains
**`2026_08_17_000111`**. This diff touches no PHP, so `openapi.json` must stay byte-identical.

### Every file this claim brings into existence, named before it is created

- `resources/js/components/integrations/SheetsRuleFields.test.ts` — new; the double-provision guard.
- `resources/js/components/integrations/RuleFormModal.test.ts` — new; what is rendered equals what is sent.
- `packages/design-system/src/components/Button/Button.test.ts` — new; the component-level in-flight guard,
  and the handler-ordering fact it depends on, measured rather than reasoned about.

Files edited, all inside Lane A's column: `SheetsRuleFields.vue`, `RuleFormModal.vue`,
`Pages/achievements/Index.vue`, `components/shell/TopNav.vue`, `components/shell/TopNav.test.ts`,
`Pages/achievements/index.test.ts`, and `packages/design-system/src/components/Button/Button.vue`
(+ its `Button.stories.ts`). Shared, claimed here: `docs/feature-backlog.md`, `docs/claims/lane-a.md`,
`PROGRESS.md` (Lane A's block and hand-off line only). **If this list grows, the claim is extended as its
own pushed commit before the file is opened.**

### What was verified against the code BEFORE this claim was written

M20 makes it five rows running whose own evidence was wrong somewhere, so every citation was re-walked
against the tree at `e14d73b`. **All four rows HOLD, and their line numbers hold too** — which is itself
worth recording, because it is the first clean sweep in six increments.

- **`SheetsRuleFields.vue:168`** is `async function create()`; `busy.value = true` at `:171`,
  `busy.value = false` at `:190`, and no `inFlight` guard between them. `:276-278` is the button:
  `:loading="busy"` and `:disabled="!connectionId || destination !== null"`. `destination` is assigned at
  `:180`, i.e. **after** the awaited `createDestination()` returns, so the disabled expression is false for
  the entire flight and `MdsButton` renders **no native `disabled`**.
- **`Button.vue`** — `:disabled` binds the **native** attribute at `:68`, `aria-disabled` is a separate
  binding at `:70`, and the internal guard at `:50-53` calls `event.stopPropagation()`, not
  `stopImmediatePropagation()`. The consumer's `@click` is a fallthrough listener on the **same** element,
  so bubbling never enters into it and `create` runs a second time.
- **`RuleFormModal.vue:114-116`** narrows `availableEvents` to `submission.*` for a tabular grant;
  `:194` sends `data.event_types` whole; and `:160` is the seed that puts the stale value there in the
  first place. The checkbox `v-for` at `:253` iterates the narrowed list, so the unrendered event is
  unreachable by `toggleEvent()` at `:182`.
- **`Pages/achievements/Index.vue:391`** is `background-color: var(--mds-neutral-100)` on
  `.ach__badge-mark`. A repo-wide grep for primitive ramp tokens under `resources/js/` returns exactly two
  lines and **the second is a prose comment** (`Pages/audit/Index.vue:367`), so this is the only live
  primitive-token reference in the whole of `resources/js/`.
- **`TopNav.vue:39-42`** is `computed(() => new URLSearchParams(window.location.search).get('q') ?? '')`.
  `window.location` is not reactive, the bar lives in a persistent layout, and the computed therefore
  caches for the life of the page load.

### ⛔ M20's lesson applied to the sibling, and it changed the answer

`AirtableRuleFields.vue` is the other tabular editor, takes an identical prop set and emits an identical
`change` — the exact shape that made M20 file one row against two files. **Measured, it does not have this
defect and it is not because it guards better: it has no create-destination button at all.** The two
`busy`-aware `:disabled` bindings in that file (`:227`, `:245`) are on `MdsSelect`, not `MdsButton`, and
`createDestination()` in `integrationsClient.ts:96` has exactly one caller. A census of every `:loading=`
binding under `resources/js/` was taken rather than sampled, and the remainder are re-checked below rather
than waved past — the row's claim that this is the *only* such button is the row's own framing, and framing
is what has been wrong five rows running.

### ⚠️ CLAIM EXTENSION 1 — two more files, named before they are opened

**No new test file beyond the three already named.** Two EDITS were not in the original list, both inside
Lane A's column, both found by verifying the rows rather than by planning them:

- `packages/design-system/src/theme/__tests__/token-references.test.ts` — the medallion fix is a CSS token
  in an SFC, and `happy-dom` applies no scoped styles, so a mounted test cannot see it. The honest gate is
  a source-text scan, and this file already walks `resources/` with block-comment stripping and
  Style-Dictionary flattening. One added `describe` bans primitive ramp references in application code.
  **Zero new Vitest FILES; the design-system chunk goes 567 → 569 tests.**
- `resources/js/components/builder/LogicRail.vue` — a SECOND live instance of the medallion defect, found
  by the adversarial pass and confirmed by hand (below).

**Dropped from the original list:** `Button.stories.ts`. `Loading` and `Disabled` stories already exist,
so the fix needs no new story and the Storybook axe baseline (42 suites / 303) must not move.

**⛔ THE PRIMITIVE-BAN GATE DOES NOT COVER THE DEFECT CLASS, AND THE SECOND INSTANCE IS THE PROOF.**
`LogicRail.vue:294` paints `.rail__dot` with `var(--mds-color-bg-sunken)` — a *semantic* token, so the new
gate is green on it — and the dot's nearest painting ancestor is `.builder` (`Builder.vue:643`), which is
`--mds-color-bg-canvas`. In dark, `bg-sunken` and `bg-canvas` are **both** `--mds-neutral-50`, so that disc
measures **1.000:1** exactly as the medallion did. Same bug, same signature (the glyph inside stays
legible, so it reads as "the disc vanished" rather than "the icon vanished"), reached through a token the
gate is designed to permit. It is fixed here, in the same increment, because it is one token in Lane A's
own column and shipping "the medallions are visible now" beside a knowingly-invisible identical disc is
incoherent — but the gate is reported as catching the primitive half only, never as closing the class.

**⚠️ AND THE TOPNAV FIX CHANGED SHAPE UNDER THE ADVERSARIAL PASS, WHICH IS THE ROW'S FRAMING BEING WRONG
FOR THE SIXTH TIME.** The row says the field must show `q` from `usePage()`. Read unconditionally that is
a REGRESSION: `q` is not the global search's private parameter, it is the shared filter key on **six** list
pages — `forms/Index.vue:60`, `audit/Index.vue:59`, `feedback/Index.vue:47`, `submissions/Inbox.vue:144`,
`members/Index.vue:63`, `webhooks/Index.vue:73` — every one committing through a client-side
`router.get(..., { preserveState: true })`. An unscoped read puts the audit ledger's filter term into the
workspace-search box, where pressing Enter silently converts "filter this list" into "search everything".
The fix is therefore gated on `page.component === 'search/Index'` (`SearchController.php:31`), which also
fixes the pre-existing full-page-load bleed rather than widening it.

⚠️ **THE HOST LINT NUMBERS IN THIS FILE WERE STALE AND ARE NOW CORRECTED BY MEASUREMENT.** The four gates
are **97 · 113 · 31 · 113/121/0**, run unpiped on the host on a tree that touches no PHP. `lane-b.md` had
it right after M18; this file was still carrying **111 · 111/119/0**, the pre-M18 migration count.

**Baseline on `origin/main` after M20 (`836a182`), every figure from CI rather than quoted:** CI Pest
**4544 / 19,280** (2 pre-existing warnings) · Vitest **130 files / 2,258** (design-system **35/567** ·
public-runtime **35/805** · resources/js 60/886) · Storybook axe **42 suites / 303** · E2E **551 passed +
10 skipped, no flaky line** · PHPStan CI `[OK]` · four host lint gates **97 · 113 · 31 · 113/121/0** ·
`openapi.json` byte-identical. ⚠️ **Only Vitest and axe moved, and both by exactly what M20 and M21 added
(+22 / +23 tests, +4 stories).** Pest and E2E are unchanged to the digit — which is the point: a diff of
design-system CSS, one scroll behaviour and no selector changes must not move them.

---

## RELEASED — M20, the three design-system merge-gate rows (merged as PR #212, `836a182`, 6/6 green)

**All three fixed, all three measured in a browser, and one of them was only half a defect.**

⛔ **THE HEADLINE FINDING: A CHARACTER-IDENTICAL DECLARATION IS NOT AN IDENTICAL DEFECT.** The pending-ring
row cited `PasswordStrength.vue:212-218` and `Checklist.vue:289-295` together, with **one pair of numbers
for both**, because a repo-wide grep returned exactly those two lines and they matched to the character.
Measured, they were never the same ring: `currentColor` is `--mds-color-text-secondary` in one and
`--mds-color-text-body` in the other.

| | as shipped (0.55 alpha) | as fixed (solid) | verdict |
|---|---|---|---|
| `MdsPasswordStrength` light | **2.28:1** | 5.55:1 | a real 1.4.11 failure |
| `MdsPasswordStrength` dark | **3.08:1** | 7.51:1 | already passing, by 0.08 |
| `MdsChecklist` light | **3.60:1** | 14.84:1 | already passing |
| `MdsChecklist` dark | **5.37:1** | 15.70:1 | already passing |

**One of four filed cases was actually below the bar.** Both were still fixed, and the dark row is why: the
same declaration reads **2.96:1 on `--mds-color-bg-surface` and 3.08:1 on `--mds-color-bg-canvas`** — it
fails on a card and passes on the page, and **nothing in the component decides which one it gets.** An
alpha composite is a function of the ground behind it; a shared component does not own its ground. Raising
the alpha to 0.75 clears the bar today (3.43:1) and leaves that fragility exactly where it was.

⛔ **AND THE REASON ALL THREE SHIPPED BEHIND A GREEN GATE STACK IS FIXTURES, NOT SCANNERS — WHICH IS M19'S
LESSON ARRIVING FROM THE OTHER DIRECTION.** M19 learned that a probe measuring zero proves nothing unless
it exercised the defect. Here, three separate gates were reporting zero over fixtures too small to reach
the thing they were pointed at:

- Every `MdsCombobox` story seeded **four** options, so `max-height: 22rem` was never reached: axe's
  `scrollable-region-focusable` had **no scrollable region to look at**, and happy-dom computes no layout.
- Every stacked `MdsDataTable` story declared **one** sortable column, so the sortbar has rendered a single
  chip for as long as it has existed — **it had never wrapped**, never had a second row to be 8px away
  from, and never had a short header to overhang.
- And for the touch target there is no scanner at all: **SC 2.5.8's floor is 24×24, so axe passes a 32px
  control and always will.** What is breached is DSR §4.4's stricter 44×44.

Two new fixtures now reach all of it — a 21-option `Scrolling` story and a five-column `StackedSortWrap`
story, both light and dark. **Storybook axe 42 suites / 303 in CI, up from 299 — four new stories, still green.**

### What each row cost, and the one citation that was wrong

**(1) The combobox highlight — all three citations HELD, and the row was right end to end.** Measured with
a positive control before the fix was trusted: at the palette's real worst case the list is **1195px in a
352px band**, pinned to the top **exactly five of twenty-one rows are visible**, and the last option sits
**843px** below the box. After the fix, arrowing through all 21 options put **zero** out of view.
⛔ **Not `scrollIntoView`** — `block: 'nearest'` walks *every* scrollable ancestor, and this listbox sits
inside a dialog inside a page whose horizontal scroll M17 and M19 spent two increments removing. A new
sibling module, `Combobox/scroll-reveal.ts`, computes a `scrollTop` and returns `null` for "already
visible", so a reader scrolling by wheel or touch is never fought. The watcher is `flush: 'post'` because a
pre-flush watcher measures the row highlighted a moment ago — **consistently**, which reads as "the fix
does not work" rather than as a timing bug.

**(2) The sort chip — the row is right on the merits and WRONG ON ONE CITATION.** The container query is
`DataTable.vue:598`, **not `:657-659`**; `:657` is the empty-row `grid-column` rule *inside* it. That makes
five rows running whose own evidence was wrong somewhere. ⚠️ **The `::before` idiom alone would have left
this half-fixed**: §4.4 asks for 8px between **hit areas**, and a 44px target on a 32px chip overhangs 6px
each way, so two *wrapped* rows at the uniform 8px gap overlapped by **4px of invisible target**. The gap
is now two values (`row-gap` 20px = 8 + 2×6, `column-gap` 8px) with a gate that fails on anyone tidying
them back into one. The horizontal overhang is designed out rather than measured away —
`min-width: calc(44px + 2px)`, where the **2px is the border that `box-sizing: border-box` folds inward**
while an absolutely positioned child's `width: 100%` resolves against the *padding* box. Hit-tested at
320px: overlay **44×44 on every chip**, **8px horizontal / 9px vertical** clearance, **0px overhang past
the frame**, `scrollWidth === clientWidth`.

**(3) The pending ring — see the table above.**

### Claimed files: what was touched, and what was released untouched

Every file in the claim was edited **except one**, and it was claimed on a stated prediction that it might
not be — the M17 `DnsRecordBlock.vue` / M18 three-file precedent. **`tests/e2e/list-layout.spec.ts` is
released untouched**: it was taken because the 44px hit area overhangs its 32px visual box and that spec is
the one that measures the table's own scroll wrapper. The overhang turned out to be **vertical only** — the
`min-width` designs the horizontal one out entirely — so there was nothing for a horizontal-overflow gate
to assert. **One file released untouched on a prediction that was written down first is the claim working.**

**The claim was extended once**, as its own pushed commit before the file was opened, for
`Combobox/scroll-reveal.ts`.

⚠️ **AND THAT EXTENSION IS ITS OWN LESSON, BECAUSE THE FIRST PUSH OF IT LOST EVERY FILE NAME.** The text was
passed to `node -e` inside a double-quoted shell string, so bash ran each backticked path as a command
substitution and committed a paragraph whose entire job is to *name a file* with the names deleted
(`17da814`; repaired in `f3fc136`). **Prose for these files goes through the Write tool, never inline** —
and note the same shell collapses a doubled backslash to a single one, which silently broke two regexes in
a test file until they were rewritten as plain substring reads, and which made a long heredoc of this very
document fail to parse. Lane B's recorded rule — *Write tool for prose, shell for the splice* — is correct
and now has a Lane A sighting.

⚠️ **PAIRED FILES: CHECKED AND UNMOVED.** `clipped-node-containment.test.ts` was run directly — 3 passed,
`KNOWN_UNGUARDED` unchanged in both directions, exactly as the claim predicted.

⚠️ **`packages/design-system/package-lock.json` WAS REVERTED, NOT COMMITTED.** Installing Storybook to run
the axe gate locally (`npm --prefix packages/design-system install`) rewrites that tracked lockfile —
here, dropping `"peer": true` markers on four packages. It is churn, it was not in the claim, and it has
nothing to do with M20. **Anyone running the axe gate locally will hit this; revert it before committing.**

### How the gates were actually run, because two of them cannot run where you would expect

- ⛔ **Storybook axe CANNOT run in `dev_formbuilder_app-node-1`** — that container is musl-based and
  Playwright's Chromium is a glibc build, so the launch fails with `spawn ... ENOENT` **while the binary is
  present and executable**, which reads as a missing browser and is not one. Build Storybook in the node
  container (`npm run ds:storybook:build`) and run the scan in **M19's e2e image** instead, via
  `docker compose run --rm --entrypoint sh e2e`, serving `storybook-static` with `npx http-server` on 6006
  and pointing `npx test-storybook --url` at it. ⚠️ The design-system deps are **not installed in either
  tree by default** — that install is a 4-minute step and is what rewrites the lockfile above.
- ✅ **The same image runs standalone probe scripts**, which is how every number in this record was
  measured: `docker compose run --rm -v "<scratchpad>:/probe" --entrypoint sh e2e`, with
  `createRequire('/work/')` inside the script to resolve `playwright` from the repo's own `node_modules`.
- ⚠️ **`playwright.config.ts` sets `workers: 1`, so the full e2e suite is ~3-4 hours locally** — a full run
  reached 34 of 551 in the first stretch and was stopped deliberately. M20 ran the three specs its diff can
  reach and left the full suite to CI, which is the authority. **Say which one you ran.**
- ⚠️ **A hit-test probe measures one pixel short and it is the probe, not the code.**
  `document.elementFromPoint` treats a box as `[top, bottom)`, so walking outward from an element's edge
  loses the far boundary and a true 44px target reports 43. Read `getComputedStyle(el, '::before')` for the
  authoritative box and keep the walk as an independent reachability check with a 1px tolerance.

## RELEASED — M19, draining `KNOWN_OVERFLOWING` (merged as PR #210, `8ab9ae8`, 6/6 green)

**`KNOWN_OVERFLOWING` is empty.** All four defects fixed, all five entries deleted, and the mechanism
that kept them hidden removed rather than worked around.

⚠️ **TWO CLAIMED FILES WERE RELEASED UNTOUCHED, AND THAT IS THE CLAIM WORKING.** `ConfigPanel.vue` and
`SegmentedControl.vue` were claimed with the reason written down *before* they were opened — *"claimed
on a prediction that may be falsified by the first measurement"* — and the measurement falsified it:
the 30px segment spill is **absorbed** by `.config`'s `overflow-y: auto` and owns none of the five
entries. **A file claimed because it might be written to and then correctly released is the M8/M11/M17
shape, this time flagged in advance rather than discovered.**

⛔ **THE CLAIM WAS INCOMPLETE IN A WAY WORTH RECORDING: `docker/e2e/Dockerfile` WAS CREATED WITHOUT
BEING NAMED.** The claim named `docker-compose.yml` — correctly, as a file in neither lane's column —
and never named the Dockerfile that compose file `build:`s. No collision followed (Lane B's M18 touches
no `docker/`), but the gap is structural rather than lucky: **a claim that names a config file must also
name the files that config brings into existence.** The claim was *not* otherwise extended.

⚠️ **THE CLAIM WAS ALSO NUMBERED WRONG ONCE AND CORRECTED BEFORE PUBLICATION.** It opened as M19 rather
than M18 because Lane B's `m18-sso-domain-verification` landed **between this session's opening `git
fetch` and this claim's push** — the second such race in two days, after M16 lost an ADR number to the
same shape. It cost nothing here only because the claim file was re-read immediately before writing
rather than once at the start of the session.

⛔⛔ **THE ROW'S PREMISE WAS FALSIFIED — 37-FOR-37, AND FOUR ROWS RUNNING WHOSE OWN EVIDENCE WAS WRONG.**
M17 quarantined all five because *"none reproduces on a Windows host"*, after a probe that inlined
**OpenDyslexic** and measured 0. **OpenDyslexic cannot reach the elements that failed**:
`theme-overrides.css` re-points only `--mds-font-family-body`; both failing headings are
`--mds-font-family-display`; the form hub had no personalization at all. The real variable is the
display stack's Linux fallback — `system-ui` → Segoe UI on Windows, **DejaVu Sans** in CI, ~27% wider,
**256px against 324px** for one page title.

✅ **REPRODUCED TO THE PIXEL BEFORE ANY CSS CHANGED — 17 / 24 / 28 — THEN 0 / 0 / 0**, and the two
predictions the claim wrote down were measured rather than explained afterwards:
- *"Vitest and Storybook axe WILL move"* — **wrong.** 35/545, 60/886 and 42/299 are exactly unmoved. A
  `overflow-wrap` declaration touches no source-text gate and adds no story.
- *"The one I most expect to be wrong: that fixing `.builder__title-row` alone retires all three builder
  entries"* — **right**, and verified rather than inferred: `showBuilderPane` returns `false` when the
  switcher is hidden, which would have made two `assertClean` calls vanish rather than pass. Measured
  `switchVisible=true fields=true canvas=true`.

⛔ **THE GATE MISATTRIBUTED TWO OF THE FIVE ENTRIES ITSELF.** It walked descendants of scroll containers
it skipped (naming an absorbed spill that contributes nothing to the asserted number), and it iterated
elements only (so an overflowing line box had nothing to report and the message guessed at "a grid or
flex track"). Both closed, both proved on the defect that exposed them.

⚠️ **AND A FAILURE THAT READ EXACTLY LIKE A FIXTURE PROBLEM WAS MY OWN ORPHANED CONTAINER** — cancelling
a background sweep killed the client, not the container, which ran 21 more minutes and timed out the
next run's login. **`docker ps` before concluding anything about the suite.**

---

## RELEASED — the horizontal-overflow assertion, made able to fail (merged as PR #208, `2279293`, 6/6)

**Every claimed file was edited except one, and that one is the mutation subject.**
`DnsRecordBlock.vue` was claimed *"for the MUTATION ONLY … reverted before the PR opens"*, and it was:
restored from saved bytes, **sha256-identical**, absent from the diff. **A file claimed because it will
be written to and then correctly released untouched is the claim working, not a wasted one** — the
M8/M11 shape, this time by design rather than by surprise. `personalization-axe.spec.ts` was **added to
the claim mid-build** (its labels needed quarantining), as its own pushed commit before the file was
opened. Nothing was spent from either namespace.

⛔ **THE ROW'S OWN PROOF WAS A NO-OP — 36-FOR-36, AND THE SECOND ROW RUNNING WHOSE EVIDENCE WAS WRONG.**
*"Delete `min-width: 0` from `achievements/Index.vue:452-459` and every scan still passes"* proves
nothing: that rule also carries `overflow: hidden`, which already resolves `min-width: auto` to 0.
**343/343 with it and without it.** The gate WAS inert — proven by the clip, not by that demonstration.

✅ **PROVEN WITH A MUTATION THAT DOES SOMETHING.** Deleting `overflow-wrap: anywhere` from `.dns__code`
and scanning `/domains` at 375px: **unfixed gate → 2 PASSED over 312px of real overflow**; **fixed gate
→ 2 FAILED, `Received: 312`**; **reverted → 71 passed.** Leg 1 is the row: the document read **0** while
the content region was overrun by 312px, on a test named *"no horizontal overflow"*.

⚠️⚠️ **THE PREDICTION I FLAGGED AS MOST LIKELY WRONG WAS WRONG, AND THAT IS THE INCREMENT'S BEST
OUTCOME.** The claim said *"a page CI flags and my survey did not is a real defect this gate was built
to find — file or fix it, do not widen the tolerance."* CI flagged **five**, all pre-existing, none
visible to any prior gate: `page-header__title` 17px · `mds-segmented__seg` 30px · `builder__title-row`
24px on **two** panes · the form hub 28px at tablet with **no personalization at all**.

⛔ **48 OF THE FIRST RUN'S 52 FAILURES WERE MINE, AND BOTH WRONG VERSIONS ARE WORTH KEEPING.** v1 asked
*"is `.app-shell` present without `.app-shell__content`?"* — and the **guest runtime has an `.app-shell`
of its own** (`resources/public-runtime/App.vue:457`) with no clip. **A class-name collision across the
lane boundary, structurally invisible to a Lane A grep.** v2 over-corrected to *"does ANY element clip
or hide?"*, which is true on nearly every page (`overflow: hidden` draws every sr-only paragraph and
every scroll lock) — caught locally before pushing. The guard now keys on the single property that
causes the blindness: a shell that is specifically `overflow-x: clip`.

⚠️ **PLAYWRIGHT ABORTS A TEST AT ITS FIRST FAILED ASSERTION, SO EACH QUARANTINE REVEALED THE NEXT
DEFECT.** One test calls `assertClean` three times; the base failure hid `— Add`, which hid `— Form`.
**Three CI runs to drain a test that had reported exactly one problem.** None was ever pre-listed on a
hunch, deliberately: `KNOWN_OVERFLOWING` asserts a quarantined page *still* overflows, so a speculative
entry fails **exactly as loudly** as a missing one. **A symmetric list makes guessing worthless, which
is the property that keeps it honest.**

⛔ **QUARANTINED RATHER THAN FIXED, AND THE REASON IS STATED NOT ASSUMED: NONE REPRODUCES ON WINDOWS.**
A probe inlining OpenDyslexic as a data URI — defeating the recorded cross-origin font trap, with
`document.fonts.check()` returning `true` — measured **0 overflow across all six page × viewport
combinations**, as did a plain tablet probe of the form hub. 17/24/28px is the size of Linux-vs-Windows
font metrics. **Guessing at CSS verifiable only through 20-minute CI round-trips, for overruns nobody
here can see, is how a plausible-but-wrong fix ships.**

⚠️ **AND THE MISLEADING COMMENTS MISATTRIBUTED REAL CATCHES, WHICH IS WORSE THAN OPTIMISM.** Two claimed
specific saves — *"has now caught Domains and the Audit log"*, *"has reddened this gate three times
(H12b, H14, H15b)"*. The clip landed 2026-07-21 (`506ff97`); all three merged 07-26/07-27, six days
later, with the assertion already dead. Those went red on **axe violations**, credited to the
neighbouring check. Corrected **in place rather than deleted**: **a comment citing three specific saves
is exactly what stops the next reader from testing the claim.**

**Gates.** E2E **551 + 10 skipped, no flaky line**; Pest, PHPStan, four lint gates, `openapi.json`,
Vitest and axe unmoved (no `.vue`, no `packages/`, no `resources/`, no PHP in the final diff). Six jobs,
real step counts 16 · 11 · 11 · 18 · 20 · 12.

---

## SUPERSEDED CLAIM (kept for the record) — M17 (`m17-overflow-gate`)

Opened: 2026-08-26, cut from `origin/main` at `e5fe62e`.

Row: `docs/feature-backlog.md:502` — *"`major` · THE END-TO-END HORIZONTAL-OVERFLOW ASSERTION IS
STRUCTURALLY INERT ON EVERY `AppLayout` PAGE — IT CANNOT FAIL, AND HAS NOT BEEN ABLE TO SINCE THE
CLIP LANDED."* The review that filed it calls this **"the single most valuable output of the review:
it invalidates a gate this project has been trusting."**

**Files:** `tests/e2e/support/axe.ts` · `tests/e2e/builder-axe.spec.ts` ·
`tests/e2e/responsive-axe.spec.ts` · `docs/feature-backlog.md` · `PROGRESS.md` (Lane A's block only).
⚠️ **`resources/js/components/domains/DnsRecordBlock.vue` is claimed for the MUTATION ONLY** — it is
Lane A's outright under 7(b), it is edited to prove the gate can fail, and **it is reverted before
the PR opens.** Claimed anyway, because a file that gets written to is a file another lane must not
be holding, whatever my intention for it.

**Shared artefacts taken:** the three `tests/e2e/` files ("claim first" in 7(b)) and
`docs/feature-backlog.md`. **`playwright.config.ts` is NOT taken** — M16 spent it and this row needs
nothing from it.

**Paired files taken:** none. No `clip: rect(0 0 0 0)` is added or removed, so
`clipped-node-containment.test.ts` cannot fire; ⚠️ **and Lane B's M15 is holding that file right now**
(its `KNOWN_UNGUARDED` shrinks with `SyncStatus.vue` in the same PR), which is exactly the collision
this claim exists to avoid. No `NotificationType` and no ability key moves.

**Namespaces spent:** nothing from either. No migration, no ADR — **`0022` stays free** and `0021`
stays Lane B's. The invariant lands in the gate files' own comments, replacing ~40 lines that
currently assert the opposite.

### What is already measured, so the plan is not built on the row's own framing

⛔ **THE ROW'S PROOF MUTATION IS INERT AND CANNOT BE USED.** It offers *"delete `min-width: 0` from
the leaderboard name cell (`achievements/Index.vue:452-459`) and every scan still passes"* as its
evidence. That rule **also** carries `overflow: hidden`, which already resolves `min-width: auto` to
0, so the declaration is redundant: **343/343 with it and without it**, measured in Chromium. The
scan passing is the *correct* result, not a symptom. **The gate is still inert — but that is proven
structurally by the clip, not by this demonstration, and the row's evidence was never evidence.**

✅ **THE SUBSTITUTE, MEASURED AT 268px AGAINST A 1px TOLERANCE** (~90×, against the 3px the
achievements rule manages even with `overflow: hidden` removed): delete `overflow-wrap: anywhere`
from `.dns__code` (`DnsRecordBlock.vue:127`) and scan `/domains` at 375px. Its own comment states why
it is load-bearing — *"The token has no break opportunities of its own"* — `E2eSeeder.php:1017-1047`
guarantees a **64-hex** `verification_token` on an **unverified** domain (the state that renders the
block), and `/domains` is already in the 16-page list. ⚠️ **`min-width: 0` sits beside it there too;
check which half is load-bearing rather than assuming, because that is precisely the shape that made
the achievements mutation a no-op.**

✅ **RECONNAISSANCE: 11 pages × 3 viewports, `.app-shell__content` overflow of 0 EVERYWHERE.** The
fixed gate reddens nothing that exists today — including the `Checklist` 44px `::before` overhang on
`/dashboard`, which `Checklist.vue:222-246` warns about by name and which was the top false-positive
risk. `.app-shell__content` is present on every page, so a selector-drift guard is not vacuous.

### Prediction, written before the run

- **Vitest 129/2,175 · axe 42/299 · Pest 4515/19,161 · PHPStan · four lint gates · `openapi.json`**
  unmoved. ⚠️ **Unless M15 lands first**, which moves `public-runtime` to ~35/765 and the total to
  ~130/~2,196 — **so the Vitest number is re-measured against whatever `origin/main` says at the
  time, never against 129.**
- **E2E `551 passed + 10 skipped`, no flaky line, and no new test count** — the change is to an
  assertion inside existing tests, not new cases.
- ⚠️ **The prediction I most expect to be wrong: that CI agrees with my local survey that nothing
  overflows.** CI renders at a different DPI with different fonts, and the survey ran against
  `DemoSeeder`-plus-`E2eSeeder` data on a slow local stack. **A page CI flags and my survey did not
  is a real defect this gate was built to find, not a false positive — file or fix it, do not widen
  the tolerance.**

---

## RELEASED — the share panel scans mid-transition (merged as PR #206, `9fb1bed`, 6/6 green)

**Namespaces after M16:** nothing spent. ⚠️ **ADR `0021` is LANE B's** (M15's
`0021-respondent-scoped-device-outbox.md`), so **next free overall is `0022`** and Lane A's block is
`0022-0025`. `0010` stays reserved for H1d, `#16` stays free, ADR-0016's next sub-decision stays
`§D34`, migration block `2026_08_17_000109` unspent.

**Every claimed file was edited; none was released untouched** — the M8/M11 shape did not recur — and
**the claim was never extended.** Both `docs/` files, `playwright.config.ts`, `support/axe.ts` and
`builder-axe.spec.ts` all moved. **Nothing was spent from either shared namespace.**

**The prediction held on every gate, including the one it flagged as most likely to be wrong.** CI
Pest **4515 / 19,161**, PHPStan, the four lint gates, `openapi.json`, Vitest **129 / 2,175** and axe
**42 / 299** all unmoved — asserted from the diff (no `.vue`, no `packages/`, no `resources/`, no
PHP) and proved independently by CI's own jobs. **E2E `551 passed + 10 skipped`, no flaky line**, and
`reducedMotion` at context level was **inert for the ten specs that never call `forceTheme`**, which
the claim had named as its least confident line. All six jobs carried real step counts (18 · 12 · 11
· 20 · 11 · 16).

⛔ **THE ROW WAS FALSIFIED AND THE FIX IS NOT THE ONE IT ASKED FOR — 35-FOR-35.** `#1674e9` is not a
token; it is `--mds-primary-600` `#0E6FE8` (4.71:1, **passing**) composited at ~96.5% over white
during `.mds-modal-enter-active`'s 400ms fade. **Retuning the token, which the row invites, would
have darkened a passing colour to hide a broken gate.**

⚠️ **AND MEASURING IT IN THE RUNNING APP MADE IT WORSE THAN TWO CI RUNS HAD SHOWN.** At the instant
`assertClean` hands the page to axe: backdrop **opacity 0.5138**, three animations running, button
composited to `#83b5f3` — **2.13:1**. CI caught it at 96.5% (4.45) purely by where in the fade its
scan landed. ⛔ **So the defect is a CONTINUUM, not an intermittency** — the ratio is a function of
elapsed fade time, from 2.13 to 4.71, and the case passes only after the full 400ms. *That* is why it
read as flaky and why the retry "worked". **A defect that reports a different number every run is not
thereby intermittent; it may simply be sampled at a different point on one curve.**

⚠️ **THE PROJECT'S OWN FIX FOR THIS CLASS WAS ALREADY PRESENT AND STRUCTURALLY COULD NOT REACH IT.**
`forceTheme` has emulated reduced motion since J1e and its docblock names this exact hazard — but
every incident it was written for was a transition **the scan itself started** (a theme flip, an
un-hover), so one more paint sufficed. This one was started by the **test**, on the way in, before
`assertClean` was called at all. **Ask which actor started the animation, not merely whether the code
waits for one.**

✅ **BOTH HALVES PROVED, EACH WITH A CONTROL, BECAUSE THE MERGE RUN COULD PROVE NEITHER.** A green
E2E job is equally green with and without the flag, and a passing scan is equally passing whether or
not the timing fix works. So: the same probe with `reducedMotion` on reads opacity **1**, empty
chain, `#0e6fe8`, **4.71:1**; the real spec ran green locally on *"share panel, live link (light)"* —
the precise case that failed CI twice — in 28.0s; and `failOnFlakyTests` was proved against a
deliberately flaky spec at **red with the flag, green without it, printing `1 flaky` in both**. Full
table in D2.

⚠️ **ONE THING THE FIX'S OWN PROBE JUSTIFIED AFTER THE FACT.** `settleAnimations` filters infinite
animations because awaiting one can never return — designed defensively, before anything was
measured. The post-fix probe then found **exactly one animation still running**, and it is infinite.
**The guard was speculative when written and load-bearing when measured.**

---

## SUPERSEDED CLAIM (kept for the record) — the share panel (`m16-axe-scan-timing`)

Opened: 2026-08-26. **Numbered M16, not M15**: Lane B cut `m15-respondent-session-scope` on
2026-08-25, and although it carries zero commits, two different rows answering to "M15" is the
shared-namespace hazard 7(g) exists for. M17 is reserved for the row below it.

Row: `docs/feature-backlog.md:1461` — *"The builder's share-panel live link fails WCAG AA contrast
at 4.45, and the e2e gate reports it as FLAKY rather than red"* (`major`, Design system).

**Files:** `playwright.config.ts` · `tests/e2e/support/axe.ts` · `tests/e2e/builder-axe.spec.ts` ·
`docs/feature-backlog.md` · `docs/claims/decisions.md` · `PROGRESS.md` (Lane A's block only).

**Shared artefacts taken:** `playwright.config.ts` (repo root, in neither lane's column in 7(b) —
claimed rather than assumed) · `tests/e2e/support/axe.ts` and `tests/e2e/builder-axe.spec.ts` (the
e2e tree, "claim first") · two `docs/`. **`ci.yml` is NOT taken** — the retry policy lands in the
Playwright config, not in the workflow.

**Paired files taken:** none. Nothing here adds a `clip: rect(0 0 0 0)`, so
`clipped-node-containment.test.ts` cannot fire; no `NotificationType` and no ability key moves, so
neither PHP parity gate is reachable. ⚠️ Lane B's `SyncStatus.vue` row still takes the clipped-node
test with it — that is unaffected by this claim and remains Lane B's to take.

**Namespaces spent:** nothing from either. No migration, so Lane B's block stays
`2026_08_17_000109`. No ADR — `0010` stays reserved for H1d, `#16` stays free, ADR-0016's next
sub-decision stays `§D34`. The invariant lands in the two gate files' own docblocks.

⚠️ **AMENDED MINUTES AFTER PUBLISHING, AND THE AMENDMENT IS THE POINT.** This claim first said
*"`0021` stays free"*. It was already false when written: Lane B's M15 claim (`8d49842`) landed
**between my `git fetch` and my `git push`** and spends `0021` on
`0021-respondent-scoped-device-outbox.md`. My own briefing had allocated `0021-0025` to Lane A, so
two lanes each held a correct-looking map to the same number — the ADR-0017 collision shape 7(g)
exists for, arriving live rather than as a cautionary tale. **Lane B pushed first, so `0021` is
theirs; Lane A's block is `0022-0025` and next-free-overall is `0022`.** Nothing was lost only
because this row spends no ADR at all. **A claim that has gone stale is worse than no claim, so it
is corrected at source rather than in a note further down.**

### ⚠️ ONE CROSS-LANE EFFECT LANE B SHOULD KNOW ABOUT, STATED RATHER THAN DISCOVERED

`reducedMotion: 'reduce'` in `playwright.config.ts` `use:` applies to **every** e2e spec, including
`tests/e2e/public-runtime-offline.spec.ts`, which M15's claim records as *"grepped, not edited —
the design preserves all nine of its assertions."* That assertion still holds as far as I can
measure: no e2e test in this repo asserts an animation or a transition (grepped across all sixteen
spec files), and its `:103` reference to a "sync transition" is a state change, not a CSS one. But
**the file is reached by my diff without appearing in it**, which is the paired-file symptom 7(b-bis)
tells us to treat as structural rather than as a flake. Recorded here so that if M15's run reddens
there, the first place to look is this config line and not their own change.

### ⛔ THE ROW'S FRAMING IS FALSIFIED, AND THE FIX IS NOT A COLOUR CHANGE

`#1674e9` **is not a token in this repository.** The button is `--mds-color-action-primary-bg` →
`--mds-primary-600` → `#0E6FE8` (`theme-overrides.css:125`), which is **4.71:1 against white and
passes.** `#1674e9` is what `#0E6FE8` becomes when composited at ~96.5% opacity over the white page:
`0.965·0E + 0.035·FF = 16`, `0.965·6F + 0.035·FF = 74`, `0.965·E8 + 0.035·FF = E9` — to the byte, on
all three channels. That opacity is `.mds-modal-enter-active` (`Modal.vue:481-483`) still running its
`--mds-duration-slow: 400ms` fade when axe samples.

**So this is a scan-timing defect, and retuning the token would darken a passing colour to hide a
gate bug.** `forceTheme` already emulates reduced motion (`support/axe.ts:62`) and its own docblock
at `:59-61` names this exact hazard — but it runs *after* the modal opens, and a CSS transition keeps
the duration it started with, so emulating afterwards cannot collapse one already in flight.

### Prediction, written before the run so the measurement has something to disagree with

- **Vitest 129 files / 2,175 · Storybook axe 42 suites / 299 · PHPStan CI `[OK]` / local 18 by file
  list · four host lint gates 97 · 111 · 31 · 111/119/0 · `openapi.json` byte-identical** — all
  unmoved, and asserted rather than re-measured for the axe and PHP gates, because no `.vue`, no
  `packages/` source, no `resources/`, and no PHP file is touched.
- **CI Pest 4515 / 19,161** unmoved (2 pre-existing warnings), same reason.
- **E2E reads `551 passed + 10 skipped` with no flaky line** — and unlike M13's and M14's identical
  reading, that is an improvement rather than a coin-flip, because `failOnFlakyTests` makes the
  absence of the line load-bearing instead of lucky.
- ⚠️ **The prediction I most expect to be wrong**: that `reducedMotion: 'reduce'` at context level is
  inert for the ten specs that never call `forceTheme`. Grepping found no e2e test asserting motion,
  but "no test asserts it" is not "no test depends on it", and the honest answer comes from the run.

---

## Template

```
## CLAIMED — <row name> (<branch>)
Opened: <date>. Row: <the backlog row, quoted enough to identify it>.
Files: <every file to be edited, repo-relative>.
Shared artefacts taken: <docs/…, openapi.json, … — or "none">.
Paired files taken: <7(b-bis) entries, and the other half of each — or "none">.
Namespaces spent: <migration prefix / ADR number — or "nothing from either namespace">.
Prediction: <what you expect the gates to do, written BEFORE the run so it can be measured
             against rather than explained afterwards>.

## RELEASED — <row name> (merged as PR #<n>, <sha>, 6/6)
<what was actually taken; whether every claimed file was edited; anything the claim was
 extended to mid-build, each of which was its own pushed commit before the file was opened>
```
