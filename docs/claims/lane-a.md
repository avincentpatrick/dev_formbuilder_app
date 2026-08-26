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

## CLAIMED — M19, draining `KNOWN_OVERFLOWING` (`m19-overflow-drain`)

Opened: 2026-08-26, cut from `origin/main` at `eb2973f`. ⚠️ **NUMBERED M19, NOT M18, AND THE REASON IS
THE PROTOCOL WORKING FOR THE SECOND TIME IN TWO DAYS.** Lane B's `m18-sso-domain-verification` claim
(`eb2973f`) landed **between this session's opening `git fetch` and this push**, and its own text
reasons — correctly, on what it could see — *"Lane A holds no claim, so the next free increment number
is mine."* It was right when written. **Lane B pushed first, so M18 is theirs and this is M19.** The
identical race cost M16 an ADR number three days ago; it costs nothing here because a claim was read
again immediately before writing, which is the whole of rule 7(g).

Row: the five entries in `KNOWN_OVERFLOWING` (`tests/e2e/support/axe.ts:138-144`), filed at
`docs/feature-backlog.md:576-631` as *"Found by the repaired overflow gate on its first run (M17)"* —
`page-header__title` 17px · `mds-segmented__seg` 30px · `builder__title-row` 24px on two panes · the
form hub 28px at tablet in both themes.

**Files.** `resources/js/components/shell/PageHeader.vue` · `resources/js/Pages/forms/Builder.vue` ·
`resources/js/components/builder/ConfigPanel.vue` ·
`packages/design-system/src/components/StatTile/StatTile.vue` ·
`packages/design-system/src/components/SegmentedControl/SegmentedControl.vue` (+ its test and story) ·
`tests/e2e/support/axe.ts` · `tests/e2e/personalization-axe.spec.ts` · `docker-compose.yml` ·
`README.md` · `docs/feature-backlog.md` · `docs/ux/exceptions-log.md` ·
`docs/ux/design-system-reference.md` · `PROGRESS.md` (Lane A's block only).

⚠️ **`SegmentedControl.vue` AND `ConfigPanel.vue` ARE CLAIMED ON A PREDICTION THAT MAY BE FALSIFIED BY
THE FIRST MEASUREMENT** — the evidence below says the 30px segment spill is **absorbed** by an
`overflow-y: auto` ancestor and therefore owns none of the five entries. They are claimed anyway,
because a file that might be written to is a file another lane must not be holding (the M17
`DnsRecordBlock.vue` precedent, released untouched by design).

**Shared artefacts taken:** `tests/e2e/support/axe.ts` and `tests/e2e/personalization-axe.spec.ts`
("claim first" in 7(b)) · `docker-compose.yml`, which is in **neither** lane's column and is therefore
claimed rather than assumed · four `docs/` files · `PROGRESS.md` (own block). **`ci.yml` is NOT taken**
— the Linux runner lands in a compose profile, not in the workflow, and no CI step moves.
**`openapi.json`, `phpunit.xml` and `playwright.config.ts` are NOT taken** — no `/api/v1` route is
reached and no Playwright project, timeout or retry setting changes.

⚠️ **`docs/feature-backlog.md` IS HELD BY BOTH LANES AT ONCE, DELIBERATELY, AND THE REGIONS ARE NAMED
SO THE OVERLAP IS A MERGE AND NOT A COLLISION.** Lane B's M18 takes it for the SSO row at `:1514`;
this row takes `:576-631` (the five overflow rows) and the design-system / app-UI / test-gate rows in
the merge-gate section. **Disjoint by inspection, in a file that appends rather than restructures** —
which is the same reasoning 7(d) already applies to `PROGRESS.md`. A rebase before the push is what
proves it rather than assumes it.

**Paired files taken: none, and that is checked rather than assumed.** No `NotificationType` and no
ability key moves, so neither PHP parity gate is reachable. ⚠️ **The third gate is one edit away and
is the one to watch**: `clipped-node-containment.test.ts` asserts `KNOWN_UNGUARDED` with exact
equality, so **if any fix here adds an sr-only element or a `clip: rect(0 0 0 0)`, that file becomes
paired and both halves ship in this PR.** The current design adds none.

**Namespaces spent: NOTHING from either — and Lane B has just moved two of them.** Re-read at
`eb2973f`: migration prefixes `2026_08_17_000109` **and** `000110` are **SPENT by M18**, so the next
free prefix is **`2026_08_17_000111`**; ADR-0016 sub-decision **`§D34` is SPENT**. **ADR `0022` stays
free and stays Lane A's block-opener** (`0022-0025`), `0010` stays reserved for H1d, `#16` stays free.
This row mints no ADR: the invariants land in the gate's own comments, in DSR §3.4/§3.11 beside the
components they constrain, and in `exceptions-log` #13, which currently records a measurement this row
falsifies.

### What is already measured, so the plan is not built on the row's own framing

⛔ **THE STATED REASON ALL FIVE WERE QUARANTINED IS WRONG, AND THAT IS WHAT UNBLOCKS THEM.** M17 filed
rather than fixed because *"none reproduces on this Windows host"*, attributing 17/24/28px to
*"Linux-vs-Windows font metrics"* after a probe that inlined **OpenDyslexic** measured 0 overflow.
**That probe was aimed at a font that cannot reach the elements under test.**
`theme-overrides.css:404-406` re-points **only** `--mds-font-family-body`, and its own docblock at
`:389-395` says so — *"The Display role (headings) … untouched"*. Both failing headings
(`PageHeader.vue:67`, `Builder.vue:682`) are `--mds-font-family-display`. The real variables are
`extra_large` (heading-1 **38px → 48px**, `theme-overrides.css:364-387`) and the **display stack's
Linux fallback**: `"Segoe UI Variable Display"` does not exist on Linux, so `system-ui` resolves to a
materially wider face (`tokens/typography.json:3`). The form hub, entry five, carries **no
personalization at all** and was never touched by that probe's premise.

⚠️ **AND A SECOND, INDEPENDENT LOCAL/CI DIVERGENCE WAS ALREADY MEASURED BY J8 AND WRITTEN DOWN**
(commit `50dfd2d`, restated at `ThemeQuickToggle.vue:70-81`): the dyslexia face *never loads on this
host* — `document.fonts` reports it `error`, `fonts.check()` is false — because `public/hot` points
the stylesheet at `:5173` while the document is on `:8080`, making `/fonts/*.woff2` cross-origin.
**CI has neither problem: `ci.yml:434-437` runs `ds:tokens` + `npm run build` and serves built,
same-origin assets.** `forcePersonalization` awaits `document.fonts.ready` (`axe.ts:347`), which
resolves happily with the face in `error` state — which is exactly why this host is silently green.

⛔ **TWO OF THE FIVE ENTRIES ARE MISATTRIBUTED BY THE GATE'S OWN OFFENDER HEURISTIC, WHICH MEANS THE
BACKLOG ROWS INHERITED A DEFECT FROM THE TOOL THAT FILED THEM.**
**(a)** `axe.ts:178-191` skips elements that are themselves scroll containers **but walks their
descendants**, so it can name a box that spills inside an inner `overflow: auto` region and therefore
contributes **nothing** to the measured `.app-shell__content.scrollWidth`. The 30px
`mds-segmented__seg` is **not** the builder's pane switcher — that is measured at 272px of 351px
(`exceptions-log.md:573-577`) and separately asserted at `personalization-axe.spec.ts:98-102`. It is
`ConfigPanel.vue:307-312`'s **Requiredness** control (`Optional / Required / Conditional`, no icons,
non-compact) inside `.config`, which is `overflow-y: auto` (`:546-553`) and **absorbs the spill**. So
the row *"`MdsSegmentedControl` spills 30px out of the builder's content region"* is **falsified** —
it spills out of the config pane. All three builder scans measured the identical **24px**, and that is
`.builder__title-row`. **One fix should retire all three entries; fixing the segmented control alone
would retire none.**
**(b)** The loop iterates `querySelectorAll('*')`, so an overflowing **anonymous line box around a
text node** has no `getBoundingClientRect()` to report — `worst` is `null` and the message reads
*"suspect an intrinsic minimum on a grid or flex track"* (`axe.ts:240`). On the form hub that is a red
herring: `.hub__tiles` is `repeat(auto-fit, minmax(200px, 1fr))` (`Show.vue:528`), and a **fixed** min
track function resolves each tile's `min-width: auto` to 0, so no tile box can blow out. The cause is
unbreakable text in `.mds-stat-tile__value` (`StatTile.vue:187-215`), which carries no
`overflow-wrap` while its own siblings `.mds-stat-tile__note/__caption` (`:278-284`) do.

⛔ **`min-width: 0` IS NOT THE FIX, AND RE-APPLYING IT IS THE TRAP THIS PROJECT HAS ALREADY PAID FOR.**
It is already present on `.page-header__heading:49`, `.builder__title-row:674`,
`.builder__title-group:667` and `.mds-segmented:79` — inert on the first three, and on the fourth it
*causes* the spill by removing the fieldset's floor. That is precisely the shape that made M17's own
row-supplied mutation a no-op.

⚠️ **THE ROW'S MECHANISM CLAIM IS WRONG IN TWO MORE FILES THAT REPEAT IT.** `white-space: nowrap` is
**not** on `.mds-segmented__seg`; the only two in `SegmentedControl.vue` are the sr-only `legend`
(`:97`) and `input` (`:146`). `personalization-axe.spec.ts:94-95` and `ThemeQuickToggle.vue:33` both
assert otherwise. The real cause is `inline-flex` with no `flex-wrap`, a `__seg` with neither
`min-width: 0` nor `flex-shrink`, and `min-width: 0` on the fieldset removing its floor.

✅ **THE HOST CAN RUN LINUX CHROMIUM, AND THE CONTAINER THAT LOOKS LIKE IT CAN, CANNOT.**
`dev_formbuilder_app-node-1` is **Alpine/musl 3.24** with **zero fonts, no fontconfig, no freetype**;
Chromium 1228 is already unpacked in `/root/.cache/ms-playwright/` carrying `INSTALLATION_COMPLETE`
**and** `DEPENDENCIES_VALIDATED`, and `chrome --version` returns `not found` on a file that exists —
the musl-missing-`ld-linux` signature. **A validated-dependencies marker is not evidence.** The rig is
`mcr.microsoft.com/playwright:v1.61.1-noble` on `--network container:dev_formbuilder_app-app-1`, with
**`CI` deliberately unset** — it flips four things at once (`playwright.config.ts:27,28,39,85`) and the
fourth makes `reuseExistingServer` false, so Playwright would insist on booting `php artisan serve`
inside an image with no PHP.

⚠️ **`README.md:89-90` ALREADY ASSERTS THE CAPABILITY THIS ROW HAS TO BUILD** — *"Playwright / e2e run
in Linux (containers / CI), not against Windows-installed browsers, so local and CI results match."*
**False as built**, and believing it is how four live defects came to be recorded as unreproducible.
Corrected in place with the reason attached, per the M17 precedent on misleading comments.

### Prediction, written before the run so the measurement has something to disagree with

- **Pest 4515 / 19,161 · PHPStan `[OK]` · four host lint gates 97 · 111 · 31 · 111/119/0 ·
  `openapi.json` byte-identical** — unmoved and *asserted from the diff*, because no PHP file and no
  `/api/v1` route is touched.
- **Vitest and Storybook axe WILL move** and are re-measured rather than predicted: `StatTile` and
  `SegmentedControl` both carry suites that read source text. Baseline to measure the delta against is
  whatever `origin/main` says at the time — **130 / 2,213 and 42 / 299 today**, and M18 moves neither.
- **E2E `551 passed + 10 skipped`, no flaky line, and no new test count** — the change is to
  assertions and CSS inside existing cases, not new ones.
- ⚠️ **The prediction I most expect to be wrong: that fixing `.builder__title-row` alone retires all
  three builder entries.** The three identical 24px readings are strong evidence for one cause, but
  Playwright aborts at the first failed assertion, so the second and third labels have never been
  measured against a fixed first one. **If a builder label survives the fix, that is a fourth defect
  the gate has never yet been able to show, not a regression.**

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
