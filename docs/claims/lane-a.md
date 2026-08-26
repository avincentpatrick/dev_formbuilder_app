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

## Status: NO ACTIVE CLAIM

Lane A holds nothing. **The three-row queue handed over after M23 is fully discharged** — `M25` (PR #216),
`M27` (PR #217) and `M28` (PR #218), all merged 6/6 with real steps counts. **Rule 7(f) governs from
here**: take from the gap backlog, or the unassigned remainder, and claim it here first.

**Namespaces after M28:** next free ADR is still **`0022`** and it is still **Lane A's** block-opener
(`0022-0025`) — **M25, M27 and M28 each spent nothing**, which makes it eight consecutive Lane A
increments; `0010` reserved for H1d; `#16` free; next free migration prefix still **`2026_08_17_000111`**.

**Baseline on `origin/main` after M28, every figure from CI rather than quoted:** CI Pest **4544 /
19,280** · Vitest **134 files / 2,292** (design-system **36/574** · resources/js **62/899** ·
public-runtime **36/819**) · Storybook axe **42 suites / 303** · E2E **551 passed + 10 skipped, no flaky
line** (re-measured on M25's own run, 17.9m) · PHPStan CI `[OK]` · **FIVE** host lint gates
**97 · 113 · 31 · 113/121/0 · 180** · `openapi.json` byte-identical.

⚠️ **M25 MOVED NOTHING, AND THAT IS THE MEASUREMENT RATHER THAN AN OMISSION** — it adds assertions to
existing tests and creates none, so an unchanged E2E count is the *prediction being confirmed*. A moved
count would have meant a test was accidentally created or dropped. ⚠️ **Lane B's `M24` and `M26` both
landed against this same base**; neither moves a Lane A figure, but the standing warning holds — a gate
number that moves on a diff that cannot move it is the OTHER LANE.

---

---

⚠️ **THE HOST LINT QUARTET IS A QUINTET FROM M28 ONWARD — `97 · 113 · 31 · 113/121/0 · 180`.**
The fifth is `component-import-lint` (180 SFCs). A hand-off quoting four numbers is quoting a
pre-M28 baseline.

---

## RELEASED — M28, the component-import gate (merged as PR #218, `d0a5452`, 6/6 green)

**Taken 2026-08-26.** Branch `m28-component-import-gate`, cut from `origin/main` at `4c97c8b`, PR into
`main`. Row: the `minor` under **Test suite & CI gates** in `docs/feature-backlog.md` — *no gate in this
repository detects a component used in a template but never imported*.

**⚠️ NUMBERED `M28`, AND `lane-b.md` WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN.** It reads
**NO ACTIVE CLAIM**; `M24` and `M26` are merged and released. `M25` and `M27` are mine and both merged
(PRs #216, #217). So `M28` is the next free number.

### ⛔ THIS CLAIM CROSSES THE LANE BOUNDARY ON PURPOSE, AND THAT IS WHY IT RAN LAST

Two of the files below are **not Lane A's**, which is exactly why the row was scheduled last rather than
taken opportunistically:

- **`scripts/` is LANE B's column** under Standing Rule 7(b).
- **`.github/workflows/ci.yml` is in the "NEITHER — claim in this file FIRST" column.**

**Lane B holds nothing at this moment**, verified in the same read that fixed the increment number, so
this is the cheapest possible window for it. **This claim is the permission**; it is pushed before either
file is opened. ⚠️ **The row itself flagged this** — *"it lands in `scripts/` and moves a gate baseline,
which is a tooling row rather than the page row that found it"* — and it was right.

### ⛔ NAMESPACES — THIS CLAIM SPENDS NOTHING

**No ADR.** `0022` stays free and stays Lane A's block-opener (`0022-0025`) — **eighth consecutive Lane A
increment to spend nothing**. Adding a lint gate that enforces an existing, unwritten invariant is not a
decision; nothing was weighed and rejected. **No migration** (`2026_08_17_000111` still free). **No
ADR-0016 `§D<n>`**, no threat-model row. `0010` reserved for H1d; `#16` free.

### Every file this claim touches, named before it is opened

- `scripts/component-import-lint.php` — **NEW.** Lane B's column, claimed above. Named to match the four
  siblings (`constraint-boundary-lint`, `controller-gate`, `job-payload-lint`, `migration-lint`).
- `composer.json` — a `component-import-lint` script plus an entry in the `quality` aggregate. In neither
  lane's column; claimed here.
- `.github/workflows/ci.yml` — **one step** in the *Static analysis, style & security* job. Claimed above.
- `docs/feature-backlog.md` (this row only), `docs/claims/lane-a.md`, `PROGRESS.md` (Lane A's block and
  hand-off line only).

**If this list grows, the claim is extended as its own pushed commit before the file is opened.**

⛔ **THE `ci.yml` STEP IS NOT OPTIONAL AND `ci.yml:73-76` SAYS SO IN ITS OWN WORDS**: *"no CI job runs
`composer run quality` — the four steps above invoke the scripts individually, so a composer.json-only
registration would gate nothing."* A gate registered only in `composer.json` is a gate nobody runs, which
is this project's most-repeated lesson. Both registrations, or neither.

### ✅ ALREADY MEASURED BEFORE CLAIMING — THE ROW GIVES NO FALSE-POSITIVE ESTIMATE AND THAT IS THE RISK

A gate that fires on two hundred files is not a gate anybody will run, so the rule was **prototyped
read-only against the tree before this claim was written**:

- **180 `.vue` files** across `resources/js`, `resources/public-runtime` and `packages/design-system/src`.
- **All 180 are `<script setup>`** — no Options-API tail to special-case. The rule needs exactly one shape.
- Naive rule (imports + local declarations + a Vue/Inertia allow-list): **2 files flagged**.

**Both flags are real, and they are two DIFFERENT bugs in the naive rule** — which is the whole value of
prototyping first:

1. **`packages/design-system/src/components/Badge/Badge.vue:40` — a tag inside an HTML comment.** The
   template carries a long `<!-- … -->` that *quotes markup*: `` `<Badge :variant :icon :label />` ``.
   ⚠️ **This is the `NAME THE THING, NEVER QUOTE IT` lesson arriving from a new direction** — a comment
   that quotes the construct it discusses booby-traps the tool that reads it. **The rule must strip
   `<!-- … -->` before extracting tags.**
2. **`resources/js/components/builder/ConditionRows.vue:85` — a genuine RECURSIVE self-reference.**
   `<ConditionRows v-if="child.kind === 'group'">` inside its own template; Vue resolves a `<script
   setup>` SFC's own filename with no import. Correct code. **The rule must allow tag === basename.**

**With those two exemptions plus the globals allow-list, the baseline is ZERO** — so the gate ships
merge-blocking on day one with **no `KNOWN_*` quarantine list**, which is the shape M19 spent an entire
increment draining.

### ⛔ THE GATE MUST FAIL ON AN EMPTY SCAN, AND ITS OWN NEIGHBOURS DO NOT

`docs/feature-backlog.md` carries a live `minor` immediately below this row: *"Neither structural lint
gate fails on an empty scan"* — `scripts/constraint-boundary-lint.php:296-304` and
`scripts/migration-lint.php:140` print the file count and `exit(0)` regardless, so a discovery regression
is indistinguishable from a clean run. **This gate will assert a non-zero file count and fail if it finds
none**, because writing a fifth instance of a defect filed one bullet away would be indefensible.
⚠️ **That sibling row is NOT being closed here** — fixing the two existing scripts is its own change to
Lane B's files with its own baseline movement, and it is not this row.

### The positive control, stated before it is run

Per the standing rule, a gate is not proven by a green CI run. **Delete the `MdsBanner` import from
`resources/js/Pages/invitations/Show.vue`** — M9's actual historical instance, the defect that motivated
this row — and the gate must go **red naming that file**. `vue-tsc --noEmit` and `vite build` both exit 0
in that state (M9 measured it), which is precisely why this gate is owed. Restore by **saved-bytes
sha256 comparison**, never `git checkout --`.

### Blast radius, stated rather than discovered

⚠️ **THE HOST LINT-GATE QUARTET BECOMES A QUINTET.** The standing baseline `97 · 113 · 31 · 113/121/0` is
quoted in every hand-off; a fifth number joins it and the hand-off line must say so rather than leaving
the next reader to reconcile four against five.

**PHPStan does not cover `scripts/`** (`phpstan.neon` paths are `app`, `database`, `routes`) and **Pint's
Laravel preset does not list it either** — ⚠️ both to be **verified by running them**, not trusted, on
M9's lesson that *Pint's `passed` is not evidence it scanned anything*. No `.vue`, no `resources/`, no
test file, no route: **Vitest, Storybook axe, E2E, Pest and `openapi.json` are unmoved by construction.**

### ✅ WHAT THE GATES MEASURED, AND THE ONE THING WORTH CHECKING TWICE

**6/6 green with real steps counts** — and **Static analysis went 18 → 19 steps**, which is the new gate
itself. ⛔ **THAT DELTA IS THE MEASUREMENT THAT MATTERS, NOT THE GREEN TICK.** A gate registered but not
running is this project's most-repeated failure, so the step was read out of the CI log by name:
`Component import linter … success`, step 11, printing **`Component-import linter passed (180 SFC(s)
scanned)`**. The same 180 as locally, so CI's checkout and the host agree on discovery — which is itself
worth knowing, because container file discovery has silently under-counted here before.

**Five host linters — `97 · 113 · 31 · 113/121/0 · 180`**, the four prior figures re-measured and unmoved.
PHPStan **18**, this file not among them. **Pint scans `scripts/`**, proven by a deliberate misformat
probe rather than inferred from its `passed` line. E2E, Vitest, Storybook axe, Pest and `openapi.json`
unmoved by construction.

⚠️ **THE R2 CONTROL IS THE TRANSFERABLE PART OF THIS INCREMENT.** It was run as a genuine question rather
than a formality, and it found a defect in the gate: the first version handed *"add the import"* advice to
a **missing scan root**, whose remedy is entirely different. **A gate whose failure message points at the
wrong fix costs more than the bug it caught** — and nothing but running the control in its own right would
have surfaced it, because the exit code was already correct. A control that only checks the exit code is
half a control.

---

## RELEASED — M27, the README can bootstrap a fresh clone (merged as PR #217, `4c97c8b`, 6/6 green)

**Taken 2026-08-26.** Branch `m27-fresh-clone-bootstrap`, cut from `origin/main` at `6c5494e`, PR into
`main`. Row: the first `major` under **Documentation & specs** in `docs/feature-backlog.md` — *`npm run
build` cannot bootstrap a fresh clone or worktree, and the README is the only document that does not say
so*.

**⚠️ NUMBERED `M27`, AND `lane-b.md` WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN.** It reads
**NO ACTIVE CLAIM** — `M24` and `M26` are both merged and released — so `M27` is the next free number.
**Lane B holds nothing, so there is no live boundary to negotiate**, and this claim touches no PHP at all.

### ⛔ NAMESPACES — THIS CLAIM SPENDS NOTHING

**No ADR** — `0022` stays free and stays Lane A's block-opener (`0022-0025`), **seventh consecutive Lane A
increment to spend nothing**. A README correcting itself to match `ci.yml` is not a decision; the decision
(four ordered steps) was taken when `ci.yml` was written. **No migration** (`2026_08_17_000111` still
free). **No ADR-0016 `§D<n>`**, no threat-model row. `0010` reserved for H1d; `#16` free.

### Every file this claim touches, named before it is opened

- `README.md` — **the only file that needs fixing**, and that is the row's own finding: every other
  document already states the sequence correctly. Three sites, not one (see below).

Shared, claimed here: `docs/feature-backlog.md` (this row only), `docs/claims/lane-a.md`, `PROGRESS.md`
(Lane A's block and hand-off line only). **If this list grows, the claim is extended as its own pushed
commit before the file is opened.**

⚠️ **`docs/deployment-infrastructure.md` IS DELIBERATELY NOT IN THAT LIST.** The row cites `:39` as
documenting the true sequence and it does, correctly — *"`npm ci` + design-system deps + `npm run
ds:tokens` + `npm run build`"*. Nothing there needs changing, and opening it would be scope the row does
not ask for.

**No code, no test, no `.vue`, no PHP.** Every gate is unmoved **by construction**: Pest, Vitest,
Storybook axe, PHPStan, the four host lint gates, `openapi.json` and the E2E figure. **This is a
documentation-only PR and will be green by construction — which is exactly the shape that let the
1,086-line PROGRESS.md deletion merge in M16.** So the proof here is not CI; it is the worktree
reproduction below, which was done BEFORE this claim was written.

### ✅ ALREADY PROVEN, IN A THROWAWAY WORKTREE, BEFORE CLAIMING

Done during M25's e2e wait, exactly as the row demands (*"PROVE IT IN A THROWAWAY `git worktree`, NOT BY
MOVING `dist/` ASIDE"*). `git worktree add --detach <scratch>/fresh origin/main` at `a457b7d`;
`packages/design-system/dist` confirmed **absent** by `ls`, not inferred. Run with the main tree's root
`node_modules` bind-mounted so the toolchain is real and only the *repository* content is fresh.

**Step 3 without step 2 — `vite build`, exit 1:**

    Unable to resolve `@import "@meridian/design-system/tokens.css"` from /fresh/resources/css
    Unable to resolve `@import "@meridian/design-system/tokens.css"` from /fresh/resources/public-runtime
    ✗ Build failed in 11.18s

**Step 2 without step 1 — `ds:tokens`, exit 1:** `Cannot find package 'style-dictionary' imported from
/fresh/packages/design-system/build-tokens.mjs`. So **all three steps are load-bearing and each has a
measured failure**, rather than the row's assertion that the sequence is merely "documented elsewhere".

⚠️ **TWO MECHANICS WORTH KEEPING:** `-w` is mangled by MSYS (`MSYS_NO_PATHCONV=1` is required, the same
shape as the `docker exec -w` note in Lane B's hand-off), and vite writes `node_modules/.vite-temp`, so a
`:ro` mount fails with `EROFS` **before reaching the real error** — a read-only mount looks like a
different bug entirely.

### ⚠️ THE ROW IS RIGHT AND UNDERSTATES ITSELF THREE TIMES

**(1) TWO ENTRY POINTS FAIL, NOT ONE.** The row names only `resources/css/app.css:11`. The guest runtime's
`resources/public-runtime/public-runtime.css:4` imports the same artifact, and `vite.config.ts:26` lists
both as build inputs — the error output names both directories. ⚠️ **`resources/public-runtime/` is LANE
B's column, and this claim does not touch it**: it is evidence, not a target, and the fix is in `README.md`.

**(2) THE README HAS THREE SITES, NOT ONE.** The row cites `README.md:51-59`; the actual command is `:63`
(`:51` is the `## Everyday commands` heading). And the frontend block lists **`build` at `:63` BEFORE
`ds:tokens` at `:67`**, under a comment calling `ds:tokens` a *"regenerate"* — which reads as optional
maintenance rather than a prerequisite. **`ds:install` appears nowhere in the README at all.** There is
also a **third** site at `:97`, the e2e bootstrap, which runs `ds:tokens && build` correctly ordered but
still omits `ds:install`. So the defect is not a missing note; it is an **ordering that actively
misleads**, in two places, plus a step that is absent from the document entirely.

**(3) THE FAILURE PRINTS A SUCCESS AFTER IT — AND THIS IS THE SHARPEST PART.** The PWA plugin's
service-worker build runs *after* the client build fails and succeeds on its own: `✓ built in 329ms`,
`public/build/sw.mjs 134.93 kB`. **The last thing on screen is a green tick.** Only the exit code (`1`)
and the eleven lines above it disagree. A new contributor reading the tail of the output concludes the
build worked and then debugs a blank page. The row does not mention this and it is the strongest argument
for the fix.

### One thing found and deliberately NOT fixed

`packages/design-system/package.json`'s `exports` maps **two** `dist/` artifacts: `./tokens.css` →
`./dist/tokens.css` **and** `./tokens` → `./dist/tokens.ts`. Nothing imports the latter today (grepped
`resources/` and `packages/design-system/src`), so it is not a live second failure — a second build
artifact behind a public export path, worth a sentence in the README's note rather than a change.
**Filed here at the moment the decision not to fix it was taken**, per the standing rule that a
deliberately-unfixed finding goes in writing immediately or becomes invisible.

### ✅ WHAT THE GATES MEASURED

**6/6 green, every job with a real steps count** (20 · 11 · 18 · 11 · 16 · 12). Documentation-only, so
every figure is unmoved as predicted — and that is the weakness of this PR's CI rather than its strength.
**The proof is the worktree reproduction, not the green tick**, which is the same reasoning that makes
M16's 1,086-line PROGRESS.md deletion the standing warning about docs-only PRs.

⚠️ **THE SEQUENCE WAS RUN FORWARD AS WELL AS BACKWARD, AND ONLY THE FORWARD RUN PROVES THE FIX.**
Reproducing the failure shows the row was real; running `ds:install` → `ds:tokens` → `build` to
**exit 0, `✓ built in 13.58s`** shows the sequence now written in the README is *sufficient* and not
merely *different*. A documentation fix that is never executed is a guess with better formatting.

---

## RELEASED — M25, the axe scans that assert nothing about which page they landed on (merged as PR #216, `6c5494e`, 6/6 green)

**Taken 2026-08-26.** Branch `m25-axe-landing-assertions`, cut from `origin/main` at `d1d1d72`, PR into
`main`. Row: the first `major` under **Test suite & CI gates** in `docs/feature-backlog.md` — *the 16-page
responsive scan asserts nothing about which page it landed on*.

**⚠️ NUMBERED `M25`, AND `lane-b.md` WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN, NOT AT SESSION
OPEN — WHICH IS THE ONLY REASON THE NUMBER IS RIGHT.** The opening fetch returned `0e9c48d`; by the time
the branch was cut `main` was `d1d1d72`, one commit further on (`fix(M24)`, Lane B, pushed mid-read).
`lane-b.md` reads **ACTIVE CLAIM `M24`** in both reads, so `M25` is the next free number. Lane B's M24
touches `app/Services/Gamification/`, `tests/{Unit,Feature}/Gamification/` and `docs/adr/0020` — **no
file in this claim is in that set**, and the only shared artefacts are `docs/feature-backlog.md` (a
different row) and `PROGRESS.md` (own block only).

### ⛔ NAMESPACES — THIS CLAIM SPENDS NOTHING

**No ADR.** `0022` **stays free and stays Lane A's block-opener (`0022-0025`)** — sixth consecutive
increment to spend nothing. This is a test-fixture correction, not a decision: it adds no mechanism, no
option was weighed and rejected, and the reasoning it records belongs in the spec's own comment beside
the assertion. **No migration** (`2026_08_17_000111` stays free — no schema, no PHP at all). **No
ADR-0016 `§D<n>`.** No threat-model row. `0010` stays reserved for H1d; `#16` stays free.

### Every file this claim touches, named before it is opened

- `tests/e2e/responsive-axe.spec.ts` — the 16-page loop at `:148-156` gains **one** landing assertion,
  plus the comment recording why. **Shared artefact** (`tests/e2e/*.spec.ts`), claimed here.
- `tests/e2e/auth-axe.spec.ts` — the 5-page unauthenticated loop at `:81-89` gains the same one line.
  **Shared artefact**, claimed here. See the extension note below for why it is in scope and why the
  other three look-alikes are not.

Shared, claimed here: `docs/feature-backlog.md` (this row only), `docs/claims/lane-a.md`, `PROGRESS.md`
(Lane A's block and hand-off line only). **If this list grows, the claim is extended as its own pushed
commit before the file is opened.**

**No `.vue`, no `.ts` under `resources/`, no PHP.** So: no `docker restart` of the node container, no
Vitest movement, no Storybook axe movement, `openapi.json` byte-identical, PHPStan and the four host lint
gates unmoved. **Only the E2E figure can move, and it must not** — this claim adds assertions to existing
tests and creates no new test, so **551 passed + 10 skipped is a prediction, not a baseline to beat.**

### ⚠️ THE ROW'S CITATION IS WRONG, AND SO IS THE ONE IT OFFERS AS THE FIX

Both were re-walked against the tree at `d1d1d72` before this claim was written.

- **The row cites `responsive-axe.spec.ts:124-132`. That is inside the `pages` ARRAY** — the comment block
  on the `Two-factor required` entry. The `goto` → `forceTheme` → `assertClean` loop it means is
  **`:148-156`** (`goto` at `:151`, `assertClean` at `:153`).
- **The row cites the `filteredToZero` loop as `:154-163`. It is `:175-188`** (`goto` at `:178`, the
  `No matching` heading check at `:183`).
- **`support/console.ts:34` HOLDS exactly** — `expect(page.url(), …).toContain(path)`. That is the house
  idiom this fix adopts rather than inventing one.
- **`bootstrap/app.php:315-334` HOLDS** — `FeatureGateException` at `:323-329` and `ModuleDisabledException`
  at `:336-342` both `return back()->with('toast', …)`. **The row says "every plan/module refusal"; it is
  broader still — SEVEN handlers in that file return the same toast redirect** (`ScopeNodeException`
  `:286`, `GrantException` `:297`, `QuotaExceededException` `:310`, `FeatureGateException` `:323`,
  `ModuleDisabledException` `:336`, `XlsformImportException` `:389`, `FormNotAcceptingSubmissionException`
  `:458`), so the 302 is this application's standard web refusal rather than a special case.
- **`/achievements` is `module:gamification`** (`routes/tenant.php:258-259`), `RequireModule:60-62` throws
  `ModuleDisabledException` when the setting is off, and `E2eSeeder` enables no module — its only
  `gamification` mention is a comment at `:1134`. The row's example holds in full.

### ⚠️ WHERE THE ROW UNDERSTATES ITSELF — FOUR PAGES ARE EXPOSED, NOT ONE

The row names `/achievements` and leaves the impression it is the single instance. **Four of the sixteen
sit behind a gate that answers a web GET with a 302**, and the other three are gated on the tenant's
PLAN rather than a module default:

| Page | Gate | `routes/tenant.php` |
|---|---|---|
| `/achievements` | `module:gamification` | `:258-259` |
| `/analytics` | `feature:advanced_analytics` | `:891-892` |
| `/webhooks` | `feature:webhooks` | `:762-763` |
| `/integrations` | `feature:native_connectors` | `:823-824` |

The remaining twelve carry `can:` gates only, which raise **403** — a different and much weaker exposure
(a scan of an error page), **not** this defect. It is named here so a later reader does not "complete"
the sweep by adding assertions for a redirect that cannot happen.

⛔ **AND THE FILE ALREADY KNEW.** The `Analytics` entry's own comment (`:35-37`) says acme is reachable
"only because `E2eSeeder` upserts acme onto a Business plan (ADR-0011 §D9 names seeding that tenant as a
blocking obligation, **precisely so this gate cannot stay green over a page it never loads**)". The
obligation is real and it is discharged — **and nothing in this spec asserts that it was.** Three scans
are load-bearing on a seeder promise recorded in an ADR and enforced by nobody. That is the finding
above the fix.

### ⚠️ THREE LOOK-ALIKES ARE ALREADY PASSING — THE M20 LESSON, RE-MEASURED

A grep for `goto` → `assertClean` finds five more sites. **Three are already protected, and adding an
assertion to them would be noise dressed as diligence:**

- `tests/e2e/templates-axe.spec.ts:12-17` — `/forms/templates` IS `feature:form_templates`-gated
  (`routes/tenant.php:486-487`), the strongest-looking candidate of the five, **and it already asserts
  `Use this template` is visible**, which no dashboard can satisfy.
- `tests/e2e/admin-console-axe.spec.ts:45-52` — routes through `openConsole()`, which **ends** in
  `console.ts:34`'s URL assertion.
- `responsive-axe.spec.ts`'s own `filteredToZero` loop — already asserts the `No matching` heading, which
  is why `/webhooks?q=…` is covered while bare `/webhooks` twenty lines above is not.

**One is a genuine instance and is claimed** — `auth-axe.spec.ts:81-89`. Its five pages carry `guest`,
which redirects an authenticated visitor to `/dashboard` (`config/fortify.php:80`), and the ONLY thing
standing between it and ten green scans of the dashboard is the `test.use({ storageState: empty })` at
`:65`. **The file's own header names that exact hazard** — *"would be scanned as the LOGIN page it
redirects to and would pass while covering nothing"* — and then asserts nothing about it. Same class,
same one-line fix, same file family.

**One is NOT and is deliberately left** — `personalization-axe.spec.ts:36-42` and `:56-68` scan `/forms`,
`/settings` and `/submissions`. `/settings` carries no middleware at all (`:267`) and the other two are
`can:`-gated, so there is no 302 to catch. Filed here rather than swept in.

### The fix, and the positive control that will prove it

One line per loop — not per test — because both are `for` loops: **2 inserted lines cover 42 tests.**
The assertion is `console.ts:34`'s idiom verbatim, so the repository gains no new vocabulary.

**⛔ THE POSITIVE CONTROL IS THE ROW'S OWN SCENARIO, RUN BOTH WAYS.** Flip the `gamification` module
default off so `/achievements` genuinely 302s, then run the two Achievements tests **with** the assertion
(must go red naming `/achievements`) and **with it reverted** (must stay green — that green is the
defect, and it is the whole reason this row exists). Restore by **saved-bytes sha256 comparison**, never
`git checkout --`. The mutation is local and uncommitted; Lane B's tree and DB are separate checkouts and
a separate stack, so it cannot reach them.

### What will be run, and what will not

`responsive-axe.spec.ts` and `auth-axe.spec.ts` are the two specs this diff reaches, run in M19's e2e
image via `docker compose run --rm e2e`. `playwright.config.ts` pins `workers: 1`, so the full suite is
3–4 hours locally — **the rest of the suite is CI's, and this claim will say which specs were run rather
than implying the whole matrix.**

### ✅ WHAT THE GATES ACTUALLY MEASURED, AGAINST WHAT THIS CLAIM PREDICTED

**6/6 green, every job with a real steps count** (E2E 20 · a11y 11 · static 18 · Pest 11 · contract 16 ·
frontend 12 — no `steps: []`). **CI E2E `551 passed + 10 skipped`, NO flaky line — the baseline exactly**,
which is what this claim predicted rather than hoped: assertions were added to existing tests and no test
was created, so the count *must not* move. Every other gate unmoved by construction, as claimed.

⛔ **AND THE LOCAL RUN DISAGREED WITH CI IN BOTH DIRECTIONS, WHICH IS THE TRANSFERABLE PART.** The full
261-test local sweep returned **253 passed / 8 failed**, and NEITHER failure was the diff's:

- **6 × `Sheets rule detail — drift`** — reproduced **identically with the change reverted** on
  `origin/main`, and **pass in CI**. A stale drift fixture in this host's long-lived hybrid dev DB.
  Proving it took one extra 6.4m control run and was worth it: the alternative was reverting a correct fix.
- **2 × `Builder` (tablet)** — did **not** reproduce in the control run and passed cleanly on re-run
  (2 passed, 1.7m, ~30s each). A **load flake** inside a 1.1-hour saturated run, not a defect.

⚠️ **SO A LOCAL RED HAS AT LEAST THREE CAUSES AND THEY LOOK IDENTICAL FROM OUTSIDE:** the diff, the local
DB, and load. The only thing that separates them is re-running the failing subset with the change reverted
— which is cheap (6.4m for 12 tests) and was, this time, the difference between shipping and not.
⚠️ **AND `failOnFlakyTests` MAKES A FLAKY RESULT RED IN CI**, so a load flake reproduced there would have
blocked the merge; it did not appear in CI's 17.9m run.

---

## RELEASED — M23, the four App-UI rows (merged as PR #214, 6/6 green)

**All four fixed, every fix mutation-proven, and two of the four rows were wrong about something that
mattered.**

⛔ **THE HEADLINE: A GUARD THAT READS AS WORKING AND BLOCKS NOTHING.** `Button.vue`'s in-flight guard
called `event.stopPropagation()`. The consumer's `@click` is a **fallthrough listener on the same
element** — Vue merges it onto the component's own root — so bubbling was never what stood between it and
a second request. For four increments the docblock promised *"must not fire a second submit"* and the
function delivered that only for a native `type="submit"`, where the sibling `preventDefault()` happened to
do the real work. The cost was the one button in the tree whose second click provisions a second Google
spreadsheet in the tenant's Drive.

**MEASURED BEFORE IT WAS WRITTEN, because two facts had to hold and neither is obvious:**

| probe | consumer's `@click` fires? |
|---|---|
| `stopPropagation()` (as shipped) | **yes** — 1 call |
| `stopImmediatePropagation()` | **no** — 0 calls |

Handler order came back `["inner","consumer"]` — the component's own handler runs **first**, which is the
only reason a design-system-level fix existed at all. Had the order been reversed, the local guard would
have been the only option. Vue patches `stopImmediatePropagation` on the event precisely so it breaks out
of a merged handler array; a plain DOM listener array would not honour it.

⚠️ **AND THE ROW'S CLOSING JUSTIFICATION WAS WRONG WHILE ITS CONCLUSION WAS RIGHT — DO NOT REUSE THE
REASONING.** It argued every other `:loading` button is safe because Inertia's stream is
`maxConcurrent: 1, interruptible: true`. That is the constructor, not a dedupe: `interruptInFlight()`
aborts the in-flight XHR and sends the second request anyway, and the first POST has already reached the
server. What actually protects the rest of the tree is that they are `type="submit"` inside a
`@submit.prevent` form.

### ⛔ THE TEST THAT PASSED WITH THE FIX REVERTED, AND WHY IT IS THE MOST TRANSFERABLE THING HERE

The first draft of `SheetsRuleFields.test.ts` stubbed the button as `MdsButton` and asserted one call for
three clicks. It was **green with the local guard removed** — because Vue Test Utils matches a stub against
the component's own **inferred name**, which for a `<script setup>` SFC is its **filename**. `Button.vue`
gives `Button`; `MdsButton` is only the barrel's export alias and matches nothing, so the real component
rendered and *its* guard absorbed the extra clicks. The same three-click case reports **1** call under the
`MdsButton` key and **2** under `Button`.

**Only the mutation found it.** A green run said nothing, and the assertion was correct — the fixture was
not. ⚠️ **THIS IS REPO-WIDE: thirteen stubs across four other files key on the same alias** and are
therefore silently inert, so four suites are exercising more component than they claim. Filed as its own
row rather than fixed, because repairing them changes what those suites cover.

### The two rows whose framing was wrong

- **THE TOP-NAV ROW'S OWN FIX WOULD HAVE SHIPPED A REGRESSION.** It says to read `q` from `usePage()`. Read
  unconditionally, the workspace-search field shows **any** page's `q` — and `q` is the shared filter key
  on six list pages (forms, members, webhooks, audit log, feedback, the submissions inbox), all committing
  client-side with `preserveState`. The box labelled "Search this workspace" would have carried the audit
  ledger's filter term, and Enter posts that box to `/search`: "filter this list" silently becomes "search
  everything." Shipped gated on `page.component === 'search/Index'`, which also fixes the pre-existing
  full-page-load version instead of widening it. ⚠️ The file's own docblock also asserted that a browser
  Back "arrives as a fresh render" — it does not, Inertia swaps in place on popstate — and that false
  sentence was the stated justification for the implementation.
- **THE RULE-MODAL ROW DESCRIBED THE MILDER OUTCOME.** "Silently sends the unfiltered set" is true only for
  a disconnected grant. On a live one the server **does** reject it — under `event_types.{index}`, a dotted
  key this modal never renders — so the tenant got a 422 with no visible cause, on a modal that stays open,
  and could no longer rename the rule, rescope it, or repair its destination.

### ⛔ A SEMANTIC TOKEN IS NOT A VISIBLE ELEMENT, AND THE GATE THIS INCREMENT ADDED CANNOT SEE THAT

The medallion row was one token: in dark, `--mds-neutral-100` **is** `--mds-color-bg-surface`, so the disc
was painted its own card's colour at exactly **1.00:1** — absent, not faint. A primitive-ban gate was added
so the class cannot recur, proven by positive control (revert the token, it reddens naming the file).

**Then the adversarial pass found the identical defect wearing a token the gate permits.**
`LogicRail.vue`'s `.rail__dot` was `--mds-color-bg-sunken` on a `--mds-color-bg-canvas` ground, and in dark
**both resolve to `--mds-neutral-50`** — 1.000:1, live, and green under the new gate. Fixed in the same
increment. **The gate closes the cheap half only** and must never be reported as closing the class: the
real check is "does every painted element differ from the ground it actually lands on, in both themes",
which needs the resolved ancestor chain and is not a source-text scan.

⚠️ **THE GATE ALMOST DOCUMENTED ITSELF INTO FAILING.** It strips `/* */` comments, and the fix comments
originally quoted the banned string — so moving either into HTML comment syntax would have made the gate
report its own documentation as the offender. Both are now written without the `var(` prefix, so the raw
count of banned strings under `resources/` is **zero with no stripping at all**.

⚠️ **AND IT READS LANE B'S TREE, SO IT IS REGISTERED RATHER THAN NARROWED.** `APP_SCAN_ROOT` is
`resources/` entire, which includes `resources/public-runtime/`. That is 7(b-bis)'s shape exactly, and that
paragraph says to add it to the table when found — so it is the table's fourth row, flagged as a
**prohibition rather than a parity assertion**, because the table's stated remedy ("edit both halves in the
same PR") has no second half to apply to here. Narrowing to `resources/js` was rejected on the merits: the
guest runtime is application code, and it is the one tree shipped to unauthenticated respondents.

### What was left for someone else, filed the moment it was decided

The modal's GET-only refresh button (same shape, no irreversible side effect); the semantic-token audit
above; and the thirteen inert stubs. All three are rows in `docs/feature-backlog.md` — **not** notes in
this file, because a finding that lives only in a claim is invisible to a backlog search.

### Two things measured rather than assumed, both of which changed an answer

- **`AirtableRuleFields.vue` does not share the double-provision defect** — and not because it guards
  better: it has no create-destination button at all, and its two `busy`-aware `:disabled` bindings are on
  `MdsSelect`. Checked because it is the other tabular editor with an identical prop set, which is exactly
  the shape that made M20 file one row against two files.
- **`WebhookFormModal.vue` does not share the unfiltered-submit defect** — it iterates the unfiltered
  `eventTypes` prop, so rendered already equals sendable there.

**THE FILE RECORD.** New: `packages/design-system/src/components/Button/Button.test.ts` (this component's
first spec, ever) · `resources/js/components/integrations/SheetsRuleFields.test.ts` ·
`resources/js/components/integrations/RuleFormModal.test.ts`. Amended: `Button.vue` ·
`token-references.test.ts` · `Pages/achievements/Index.vue` · `components/builder/LogicRail.vue` ·
`RuleFormModal.vue` · `SheetsRuleFields.vue` · `TopNav.vue` · `TopNav.test.ts`. Shared:
`docs/feature-backlog.md` · `docs/claims/lane-a.md` · `PROGRESS.md` (own block, own hand-off line, **and
Standing Rule 7(b-bis)'s new fourth row**).

⚠️ **ONE PROCESS NOTE WORTH THE LINE.** `main` moved twice mid-increment — Lane B merged `M22` while this
was building, so a claim-extension push was rejected non-fast-forward and needed
`git rebase --autostash origin/main`. That is the normal shape of two live lanes, not an incident; it is
recorded because the reflex on a rejected push is `--force`, and here that would have discarded a merged
increment. ⚠️ And a `git reset --hard` used to re-sync after the merge **silently ate an uncommitted
docs edit** — the same lesson M9 recorded about `git checkout --`, arriving through a different command.

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
