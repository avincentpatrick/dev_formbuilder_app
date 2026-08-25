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

## CLAIMED — the horizontal-overflow assertion is structurally inert (`m17-overflow-gate`)

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
