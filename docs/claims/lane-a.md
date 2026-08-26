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

## Status: ACTIVE CLAIM — `M20`, the three design-system merge-gate rows

**Taken 2026-08-26.** Branch `m20-ds-merge-gate` off `origin/main` (`336d295`), PR into `main`.
The three rows are the whole of the **Design system** heading in `docs/feature-backlog.md`'s merge-gate
review that M16 did not already close, and they are grouped because all three live in
`packages/design-system/src/components/` — **Lane A's exclusive column**, so nothing here can collide
with Lane B on the merits. The shared artefacts below are the only reason this file needs to exist.

**THE ROWS.** (1) `major` — the combobox highlight leaves the 22rem box after ~the sixth option and
cannot be brought back (WCAG 2.4.7). (2) `major` — the stacked sort chip ships a 32px touch target in
the one layout that exists only on the touch band (DSR §4.4, stricter than SC 2.5.8). (3) `minor` —
the pending-state ring measures below WCAG 1.4.11's 3:1 against its own ground, in two files that must
move together or they disagree.

**⛔ ALL EIGHT CITATIONS RE-VERIFIED BEFORE THIS CLAIM WAS WRITTEN, AND ONE IN THE ROW IS WRONG.**
`Combobox.vue:353-358` (`max-height: 22rem` + `overflow-y: auto`), `:267-271` (rows carry no
`tabindex`), `:176-192` (arrow keys `preventDefault`ed), `DataTable.vue:488-495` (`min-height: 32px`),
`:472-473` (`display: none` default), `:702` (revealed in the container block),
`PasswordStrength.vue:212-218` and `Checklist.vue:289-295` (`opacity: 0.55` on `currentColor`) **all
hold**. ⚠️ **The container query is at `DataTable.vue:598`, not `:657-659` as the row states** — `:657`
is the empty-row `grid-column` rule *inside* it. A `grep` for `scrollIntoView` across
`packages/design-system/src` returns **0**, and a `grep` for `opacity: 0.55` across the package,
`resources/js` and `resources/public-runtime` returns **exactly the two lines the row names**.

**✅ AND THE CONTRAST ROW'S NUMBERS REPRODUCE TO TWO DECIMAL PLACES, WHICH IS WHY IT IS BEING FIXED
RATHER THAN RE-ARGUED.** `currentColor` on the pending ring is `--mds-color-text-secondary` →
`--mds-neutral-600`, composited at 55% over `--mds-color-bg-surface`. Light: `#5A6478` over `#FFFFFF`
→ `#A4AAB5`, **2.334:1**. Dark: `#9AA5BD` over `#1a2130` → `#606A7E`, **2.961:1**. The row said 2.33
and 2.96. **Nine rows running had their own framing wrong; this one does not.**

### Files claimed

**Lane A's own column — claimed for completeness, not because they are contested:**

- `packages/design-system/src/components/Combobox/Combobox.vue` — the scroll fix
- `packages/design-system/src/components/Combobox/Combobox.test.ts`
- `packages/design-system/src/components/Combobox/Combobox.stories.ts` — **on a prediction**: the
  stories seed four options, which is precisely why no gate sees this defect, so a long-list story is
  the likely shape of the Storybook half
- `packages/design-system/src/components/DataTable/DataTable.vue` — the hit area
- `packages/design-system/src/components/DataTable/DataTable.test.ts`
- `packages/design-system/src/components/PasswordStrength/PasswordStrength.vue` — the ring
- `packages/design-system/src/components/PasswordStrength/PasswordStrength.test.ts`
- `packages/design-system/src/components/Checklist/Checklist.vue` — the ring's other half
- `packages/design-system/src/components/Checklist/Checklist.test.ts`

**Shared artefacts, which are claimed and never owned:**

- `docs/feature-backlog.md` — closing the three rows
- `docs/ux/design-system-reference.md` — as-built amendments to §3.4.1 (the combobox pattern gains a
  scroll obligation) and §4.4 (the sortbar's hit-area arithmetic). **Lane B is not in this file.**
- `docs/claims/lane-a.md` — this file
- `PROGRESS.md` — **Lane A's own Current Status block and Lane A's own hand-off line only**, per 7(d)
- `MEMORY.md` and the files beside it — Lane A only, per 7(b); announced in chat when written

**⚠️ CLAIMED ON A PREDICTION THAT IT MAY BE RELEASED UNTOUCHED — the M17 `DnsRecordBlock.vue` / M18
three-file precedent, stated here rather than discovered later:**

- `tests/e2e/list-layout.spec.ts` — the sort chip's 44px hit area **overhangs its 32px visual box by
  6px vertically**, and this spec is the one that measures the table's own scroll wrapper rather than
  `documentElement.scrollWidth` (`DataTable.vue:594-597` says so in as many words). If the overhang
  needs a gate, it needs one here. **A file that might get written to is a file the other lane must
  not be holding.**

**⛔ NOTHING IN `resources/js/`, `resources/public-runtime/`, `app/`, `config/`, `scripts/`,
`ci.yml`, `phpunit.xml` or `openapi.json`.** M20 touches no PHP and no `/api/v1` route, so
`openapi.json` must come back byte-identical; if it moves, that is the other lane's merge, not this
diff.

**PAIRED FILES (7(b-bis)) — CHECKED, AND ONE IS IN RANGE.**
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` scans
`packages/design-system/src` and asserts `KNOWN_UNGUARDED` by **exact equality**. M20 adds a
`position: relative` to `.mds-table__sortchip` and a `::before` overlay; neither is a clipped
visually-hidden node, so the list must not move — **and if it does, that is a finding, not a
rebaseline.** The other two paired gates read `notifications/types.ts` and `shell/nav-model.ts`, which
M20 does not open.

**Namespaces: M20 SPENDS NOTHING.** No ADR (these are three defect fixes inside components this
system already documents, not a decision — the M18 precedent for not minting a number to say the same
thing); no migration; no `§D<n>`. `0022` stays free and stays Lane A's block-opener; `0010` stays
reserved for H1d; `#16` stays free; the next migration prefix stays `2026_08_17_000111`.

### ⚠️ EXTENSION 1 — one new file, claimed before it was created

`packages/design-system/src/components/Combobox/scroll-reveal.ts` — **a file M19's own lesson says must
be named here rather than appear in the diff.** (M19 claimed `docker-compose.yml` and then created
`docker/e2e/Dockerfile`, which the claim never mentioned.)

⚠️ **AND THE FIRST PUSH OF THIS EXTENSION LOST EVERY FILE NAME IN IT, WHICH IS THE FAILURE IT EXISTS TO
PREVENT.** The text was passed to `node -e` inside a double-quoted shell string, so bash ran every
backticked path as a command substitution and committed the paragraph with the names deleted —
`17da814` on `main` is that commit, and this one repairs it. **A claim is only worth the names in it**,
so prose for this file goes through a quoted heredoc to a scratchpad file from here on, never inline.

**WHY A SIBLING MODULE RATHER THAN A FUNCTION INSIDE THE SFC.** The fix is arithmetic — given the list's
scroll position and the active option's box, what should `scrollTop` become — and **happy-dom computes no
layout**, so a mounted assertion would pass whatever the code said. A pure function is the only shape this
repository can actually gate. The precedent is already here twice: `Modal/focus-target.ts` and
`PasswordStrength/describedby.ts` are both logic lifted out of an SFC precisely so a unit test can reach
it.

⛔ **AND IT IS NOT `scrollIntoView`, WHICH IS THE OBVIOUS FIX AND THE WRONG ONE HERE.**
`Element.scrollIntoView({ block: 'nearest' })` walks **every** scrollable ancestor, so it can scroll the
page as well as the listbox — in a repository whose last three increments were about a page gaining scroll
it should not have. Writing `list.scrollTop` directly can only ever move the one box that is supposed to
move.

**Baseline this delta is measured against** — `origin/main` at `336d295`, after M18 + M19: CI Pest
**4544 / 19,280** (2 pre-existing warnings) · Vitest **130 files / 2,213** (design-system 35/545 ·
public-runtime 35/782 · resources/js 60/886) · Storybook axe **42 / 299** · E2E **551 passed + 10
skipped, no flaky line** · PHPStan CI `[OK]` · `openapi.json` byte-identical. ⚠️ **The two lane files
disagree on the host lint gates** — `lane-a.md` carries `97 · 111 · 31 · 111/119/0` and `lane-b.md`
carries `97 · 113 · 31 · 113/121/0`. M18 added two migrations, so **Lane B's is the post-M18 number
and Lane A's is stale**; M20 touches no migration, so this is re-measured rather than assumed.

---

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
