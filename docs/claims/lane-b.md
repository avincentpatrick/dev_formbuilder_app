# Lane B — active claim

**One writer: Lane B.** Lane A never edits this file, and Lane B never edits `lane-a.md`. That is
what makes a claim conflict structurally impossible rather than merely unlikely.

**The protocol is Standing Rule 7(g)**, not this header. In one line: **a claim is a *pushed*
commit** — write it here, `git push origin HEAD:main`, and only then open the first file. An
unpushed claim does not exist; M14 proved that by writing a perfect one that nobody could see.

**Before opening any shared or paired artefact**, `git fetch` and read both lane files.

Shared artefacts, which are claimed and never owned: `docs/**`, `openapi.json`, `phpunit.xml`,
`PROGRESS.md` (own block only), and the top-level `tests/e2e/*.spec.ts`.
Paired files — where a change obliges you to edit *both* halves in the same PR — are listed in
Standing Rule 7(b-bis). ⚠️ **The next Lane B row that touches `SyncStatus.vue` takes one**:
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` lists it in an
exact-equality `KNOWN_UNGUARDED` assertion, so the list shrinks in the *same* PR as the fix.

---

## Status: NO ACTIVE CLAIM

**Lane B holds nothing as of 2026-08-27.** `M29` (PR #219, `7892f7f`), `M33` (PR #221, `f329e1b`), `M34`
(PR #222, `b6adb2e`) and `M35` (PR #224) are merged and released. Their entries follow.

⛔⛔ **BEFORE YOU NUMBER ANYTHING: READ THE WHOLE OF `lane-a.md`, NOT ITS `## Status` LINE.** A lane's
forward queue is a claim and it does not live under that heading — `M33` learned it, `M35` reconfirmed it.
At `M35`'s close `lane-a.md` reads **ACTIVE CLAIM `M32`** and, in its own words, *"the highest number spent
is `M35`, so `M36` is the next free one after this"*. **`M36` IS THE NEXT FREE NUMBER.** ⛔ **AND RUN
`git worktree list` TOO** — three consecutive increments had the number decided by the worktree rather than
by the claim files, in both directions.

✅ **THE `§D35` CORRECTION LANDED, AND THE DIRECTION IT TRAVELLED IS THE POINT.** `M34` wrote into this file
that `lane-a.md:43` was wrong — `M29` spent no `§D` at all, ADR-0016 runs to `§D34` — because Lane B may not
edit `lane-a.md`. The next Lane A session **read it, verified it independently, and corrected its own file**:
`lane-a.md` now states `§D35` free. The rule that prevents number collisions is what carried a correction
across a boundary neither lane may write through. **A reserved-then-released allocation is exactly the shape
that rots into a phantom, because the reservation is loud and the release is quiet.**

---

## RELEASED — M35, a super-admin console gated by a hand-written list of three route names (merged as PR #224)

**Shipped 2026-08-27.** Branch `m35-admin-console-gate-walk`. Row closed in `docs/feature-backlog.md` as its
own micro-commit.

### ⛔ THE ROW WAS RIGHT, AND ITS OWN STATED WEAKNESS WAS THE PART WORTH BUILDING FOR

Every citation exact — **third row running**. `routes/admin.php:83-84`; the index's three denials at
`FeedbackConsoleTest.php:153`/`:158`/`:162`; the screenshot's only case at `:338-348`, whose own comment says
the 404 proves the lookup resolved rather than that anyone was refused; and
`StepUpReauthenticationTest.php:135-146`, three route names under a comment claiming it covered *"every page
of the console"*.

⛔ **THE ROW SAID ITS FINDING WAS WEAKER THAN IT LOOKED AND NAMED THE MUTATION THAT MATTERS — A ROUTE
DECLARED OUTSIDE THE GROUP — SO THAT MUTATION WAS RUN FIRST, AGAINST THE UNCHANGED TREE.** Moving
`admin.feedback.screenshot` into the outer group, confirmed at the live route table to drop `superadmin.mfa`
**and** `step-up`, left `tests/Feature/{Admin,Auth,Feedback}` at **238 passed / 1,156 assertions — identical
to the baseline in both numbers.** A route streaming every tenant's feedback screenshots became reachable by
a super-admin with no confirmed second factor and no recent password, and nothing in this repository noticed.
**That measurement is the whole justification for the increment, and it could only be taken before the fix.**

**This is the first row in five whose prescribed remedy was CORRECT** — *walk the table and assert the
property* transfers exactly, where `M33`'s, `M34`'s, `M31`'s and `M32`'s did not. **The row's OTHER
suggestion still was not**: see the vacuity trap below. A row can be right in its evidence, right in its main
remedy, and still carry a second suggestion that would have produced a green test proving nothing.

### ✅ GATES

- **All six jobs `success` with real step counts, parsed individually rather than trusted from a tick** —
  E2E **20**, Static analysis **19**, Contract **16**, Frontend **12**, Design-system axe **11**, Pest **11**.
  **Not one `steps: []`.** Merged as `8aa6ea4`.
- **CI Pest `4609 passed / 19,459 assertions`** (2 pre-existing warnings) — **+14 tests and +26 assertions
  on the `4595 / 19,433` base this branch actually merged into after Lane A's `M31`, exactly the fourteen
  added and exactly the twenty-six.** Both halves of the prediction were exact. ⚠️ **The base moved MID-SESSION**:
  Lane A merged `M31` while this branch was building, so a delta quoted against `M34`'s `4591` would have
  been wrong by four. Fetch before you subtract.
- **E2E `551 passed + 10 skipped`, NO flaky line** — unchanged, and load-bearing (`failOnFlakyTests`).
- **Vitest `134 files`; Storybook axe `42 suites / 303`** — both unchanged, as a PHP-only diff must leave them.
- **Local Pest scope `252 passed / 1,182 assertions`** — 238 baseline + exactly the 14 added, zero failures.
- **Pint `PASS` over `1375` files, exit 0 — AND PROVEN LIVE FIRST.** A deliberately misformatted probe in
  `app/Policies/` returned exit **1** with the file named and its fixers listed; only then was the real
  `PASS` believed. **1374 + 1 new test file**, so the count itself is a check.
- **PHPStan local 18 across the same 10 FILES — zero delta BY FILE LIST**, and structurally so: it scans
  `app`, `database`, `routes`, never `tests`. ⚠️ **The probe file lived in `app/`, so it reddened PHPStan as
  well as Pint while it existed** — a probe placed inside an analysed path is a gate you have to remember to
  clean up, and deleting it is step one of the finish rather than an afterthought.
- **Five host lint gates unmoved** — 97 · 113 · 31 · 113/121/0 · 180.
- **`openapi.json` byte-identical** — predicted in the claim before the files were opened, and held.
- **Not run LOCALLY, and said so rather than implied:** Vitest, Storybook axe and E2E were left to CI, because
  the diff is PHP tests only — no `.ts`, no `.vue`, no selector. CI ran all three and all three are unmoved,
  which is the check on that reasoning rather than a restatement of it.

### ⛔ THE VACUITY TRAP THE ROW DID NOT NAME, AND THE MUTATION THAT PROVES IT WAS AVOIDED

`EnsureSuperAdmin` answers **404** for non-disclosure; the controller answers **404** for a report it cannot
resolve. **Same status, two different decisions.** A non-super-admin case written against a random id — or
against a report with no screenshot, which is the only fixture the console suite could build before this
increment — **passes with the middleware deleted**. `tests/Feature/Tenant/FeedbackTest.php:307-309` states
the identical trap on the tenant-side twin, so the repository had already paid for it once.

The arm therefore runs against a report a super-admin really does get 200 image bytes from — the first test
here ever to drive that route to a success — which needed `consoleScreenshot()`, the console suite's first
committed-screenshot fixture. ⚠️ **The attachment has to be COMMITTED for an FK reason, not a connection
reason**: `feedback_reports.screenshot_attachment_id` is a real foreign key, so pointing a committed report
at a row inside RefreshDatabase's open transaction makes the privileged UPDATE wait on a lock the test itself
holds — a hang, not an error. **And nothing was added to the purge, checked rather than assumed**:
`attachments.tenant_id` is `cascadeOnDelete` and the purge deletes reports before tenants.

### ⛔ ASKING THE KILLER QUESTION OF EVERY GATE FOUND A THIRD NAKED ONE

`FeedbackConsoleController.php:78`'s `abort_unless($attachment->virus_scan_status->servable(), 409)` was
asserted by **nothing**. M34 pinned that guard on the two routes it was looking at (`FeedbackController.php:75`,
`AttachmentController.php:43`) and **this console copy is the third** — so quarantined bytes could be served
to the one principal who reads across every tenant, with the whole repository green. **M34's rule paid out on
its first outing**: ask the question of every gate on the route you touch, not only the ones the row names.

### ✅ THREE MUTATIONS, EACH WITH A BASELINE OF THE SAME SCOPE FIRST

All against `routes/admin.php`, `php -l`'d, sha256 asserted to have MOVED, restored by **byte comparison**,
harness refusing to start while another test process lives.

| Mutation | Red set |
|---|---|
| screenshot route out of the gated group | **4** — exactly the four gates added, **zero pre-existing** |
| drop the console's `Route::domain()` constraint | **1** — only the new central-host case |
| drop `superadmin` from the group | **10** — the walk, **eight pre-existing denials**, and the new 404 |

⚠️ **THE THIRD IS THE ONE THAT MATTERS AND IT IS NOT ABOUT COVERAGE.** It is what proves the 404 case is not
vacuous: with the gate deleted the request reaches the controller, resolves the report AND the screenshot,
and streams it — so the case goes red **because the fixture is real**. Against a random id it would have
stayed green. A deny test's non-vacuity is a property of its FIXTURE, and only a mutation can show it.

⚠️ **THE SECOND FOUND A BLIND SPOT NOBODY HAD NAMED.** Dropping `Route::domain()` from the whole console —
which would serve every cross-tenant page on every tenant subdomain, where a tenant's context is already on
the connection — was invisible to the entire pre-existing suite, and is now caught by exactly one assertion.
It was not in the row. It came out of writing the walk.

### THE FILE RECORD

Edited: `tests/Feature/Auth/StepUpReauthenticationTest.php` · `tests/Feature/Feedback/FeedbackConsoleTest.php`
· `tests/Pest.php` · `docs/feature-backlog.md` · `docs/claims/lane-b.md` · `PROGRESS.md`.
New: `tests/Feature/Admin/AdminConsoleGateTest.php`.
**Every claimed file was edited, and `routes/admin.php` was claimed FOR THE MUTATION ONLY and released
byte-identical** at `8e330a5c1d9d…`, absent from the diff — the M17/M19 shape, predicted in writing and held.
⚠️ **`tests/Pest.php` was claimed as "probably", was needed, and was placed deliberately away from the file's
tail** because Lane A's live `M31` worked in the submission-answer helpers that live there. The merge of
current `main` into this branch was **clean, with no conflict in any file** — the near miss the claim named
did not happen.

**Namespaces: NOTHING SPENT.** ADR `0022` free and still Lane A's block-opener; migration
`2026_08_17_000111` free; **ADR-0016 `§D35` free** (re-verified this session rather than carried); `0010`
reserved for H1d; `#16` free. Adding tests that pin an existing, correctly-implemented guard is not a
decision — nothing was weighed and nothing rejected.

### ⚠️ TWO MEASURED THINGS WORTH MORE THAN THIS ROW

**(1) A WEB-ROUTE 409 ASSERTION COSTS ~60–100 SECONDS IN THIS SUITE, AND IT IS NOT NEW.** The new console
case runs **59.4 s**; the *pre-existing* M34 tenant-side twin runs **97.5 s**. So the cost is the error-page
render, not this increment — **measured on the pre-existing case rather than assumed**, which is the only
way to tell "my test is slow" from "this shape is slow". `withoutVite()` is **not** the cause: adding it made
the case **slower** (82 s), so that hypothesis is tested and dead rather than left as folklore. The pattern
should not be multiplied casually.

**(2) DOCKER DESKTOP DIED MID-SESSION AND THE FIRST SYMPTOM LOOKED LIKE A GATE FAILURE.** Pint exited **1**
and the Pest run failed — with `failed to connect to the docker API at npipe:...`. **A gate whose failure
message names the DAEMON is an infrastructure event, not a result**, and the tell is the same one M34 records
for a contaminated mutation run: read what the failure actually says before believing the number. Lane A's
stack restarted itself; **Lane B's did not** — app, postgres, redis, node and mailpit all sat `Exited (255)`
until `docker compose up -d` was run from the lane-b worktree. The `pgdata` volume survived, so nothing was
lost, but a lane that comes back half-up is indistinguishable from a lane that is fine until you list
containers with `-a`.

---

## RELEASED — M34, three streamed exports with no authorization deny test (merged as PR #222, `b6adb2e`, CI 6/6 green with real step counts)

**Shipped 2026-08-27.** Branch `m34-export-deny-tests`. Row closed in `docs/feature-backlog.md` as its own
micro-commit.

### ✅ GATES

- **All six jobs `success` with real step counts** — E2E **20**, Static analysis **19**, Contract **16**,
  Frontend **12**, Design-system axe **11**, Pest **11**. **Not one `steps: []`.**
- **CI Pest `4591 passed / 19,400 assertions`** (2 pre-existing warnings) — up from M30's `4580 / 19,383`:
  **+11 tests, exactly the eleven added, and +17 assertions, exactly the seventeen.** A delta against the base
  this branch actually merged into, never an absolute quoted from a hand-off.
- **E2E `551 passed + 10 skipped`, NO flaky line** — unchanged, and load-bearing (`failOnFlakyTests`).
- **Local `213 passed / 1,346 assertions`** across `Analytics`, `Xlsform`, `Attachments`, `Tenant`; zero failures,
  zero infrastructure errors.
- **PHPStan local 18 across 10 FILES = baseline, zero delta BY FILE LIST**, and structurally so: `phpstan.neon`
  scans `app`, `database`, `routes` — **not `tests`** — so a test-only diff cannot move it. CI `[OK]`.
- **Pint `PASS` over `1374` files, exit 0 — AND PROVEN LIVE FIRST.** A bare `passed` is not evidence a scan
  happened, so a deliberately misformatted probe went into `app/Policies/` and was run: exit **1**, the file
  named, its fixers listed. Only then was the real `PASS` believed.
- **`openapi.json` untouched** — the prediction written before the files were opened held: this increment adds
  tests, and Scramble infers from a controller's own returns.

### ⛔ THE ROW'S EVIDENCE WAS FLAWLESS AND ITS REMEDY WAS STRUCTURALLY IMPOSSIBLE

**Two rows running whose every citation held** — `M33` was the first in fifteen, and `M34` is the second.
`AnalyticsPageGateTest.php:110` really is an Owner on a Professional plan asserting a redirect; the suite's
only 403 really is on the `/analytics` index; the ability denial really does target the twin, because
`analyticsUrl()` defaults to the `'report'` suffix; and **no test of any kind had ever issued a request to
the API xlsform URI**.

⛔ **And the prescribed fix — copy `GET /forms/{form}/submissions/export`, "which asserts BOTH a role denial
and a scope denial" — does not transfer, for a reason no amount of care with the *evidence* would have
caught.** That pattern binds a **Form instance** (`can:export,Submission::class,form`). Both analytics
exports gate on `can:viewAny,SavedReportView`, whose policy method **takes no model**. A scope denial there
is not weak — it is *structurally impossible*, because there is nothing to be out of scope of. Only the
xlsform route takes both arms. **A row's evidence and a row's remedy are separately trustworthy, and this is
the second consecutive increment where the remedy was the defective half** — Lane A hit the identical shape
in `M32`'s row on the same day, two lanes, two unrelated rows. That is now a pattern, not a coincidence.

⚠️ **THE ROLE HALF NEEDED A FIXTURE THE ROW DOES NOT NAME EITHER.** All five seeded roles hold
`dashboard.form.view` and `viewAny` is `dashboard.org.view || dashboard.form.view`, so **no seeded role can be
refused**. A role-less active member (`makeActiveMember` then `syncRoles([])`) is the only construction that
reaches the gate. Copying the row's viewer-based fixture would have produced a 200 and a green test that
proved nothing — the exact failure mode the row was filed about, reproduced by following the row.

### ✅ THE INCREMENT'S LARGEST RISK WAS REFUTED BY MEASUREMENT RATHER THAN REASONED AWAY

Every one of these routes carries a `feature:` entitlement gate alongside its `can:`. If the entitlement ran
**first**, a deny test would measure the plan and the permission gate could be deleted with the test green —
which is precisely the defect this row reports. `route:list` resolves all three as
**`ability → Authorize → RequireFeature`**: the entitlement answers **last**. Both analytics suites already
assign Business in `beforeEach`, so the ambiguity is removed twice over. **The plan named this risk before
the first file was opened and the measurement settled it in one command.**

### ✅ PROVEN BY MUTATION — FOUR, EACH REDDENING ONLY WHAT IT SHOULD

| Mutation | Red set | Totals |
|---|---|---|
| `analytics.export` loses `can:viewAny,SavedReportView` | *403s the export for a member holding neither dashboard permission* | **1 failed / 123 passed / 867** |
| API xlsform loses `can:view,form` | *refuses the API export to a form_editor who is not a collaborator* | **1 failed / 123 passed / 867** |
| `analytics.report.export` loses `ability:read:analytics` | *refuses the EXPORT to a token holding every OTHER ability but not read:analytics* | **1 failed / 123 passed / 867** |

⚠️ **THE RED SETS ARE SCOPE-BOUND, AND SAYING SO MATTERS.** They were measured over
`tests/Feature/Analytics` + `tests/Feature/Xlsform`. A verifier pointed out that `GroupBPolicyGateTest` sits
in `tests/Feature/Api/` and would also catch mutation 2 — so rather than assume it (*a gate you cannot run is
not a gate you may assume*), it was **run**: baseline **4 passed / 11 assertions**, mutated **1 failed /
3 passed**, failing at `:129` with the offending route named in its own message. **Mutation 2's true red set
is two tests, not one** — the behavioural scope case and the structural gate, which is the structural gate
doing exactly the job its header claims. Mutations 1 and 3 touch no Group-B `can:` gate and leave it green.

Each replaced **one literal token in a unique context** (occurrence count asserted `== 1` before writing),
was `php -l`'d, had its **sha256 asserted moved**, and was restored **by byte-comparison against a saved
copy** rather than by `git checkout --`. The mechanism was committed before any mutation ran.
⚠️ **The third mutation is the row's own thesis proved from the other direction:** dropping the export's
ability gate left the **twin's** test green, which is exactly why the twin's coverage was never this route's.

### ⛔⛔ THE FIRST MUTATION-2 RUN WAS CONTAMINATED, AND THE CULPRIT WAS MY OWN SUBAGENT — RULE 7(c) INSIDE ONE LANE

It reported **`4 failed, 120 passed (856 assertions)`**: the real red plus three unrelated analytics
failures reading `SQLSTATE[42P01]: relation "tenants" does not exist` and
`column "acting_as_user_id" of relation "audits" does not exist`. **A concurrent `migrate:fresh` dropped the
schema mid-run.** The writer was a verification subagent of this increment's own analysis workflow, which ran
`docker exec fb-lane-b-app-1 php artisan test tests/Feature/Analytics/AnalyticsExportTest.php` to check a
claim. Its brief said *"READ-ONLY … DO NOT EDIT ANY FILE"* — **and a file-scoped prohibition does not stop an
agent running the test suite.** Standing Rule 7(c) exists for two lanes sharing one database; this was **one
lane sharing a database with itself**, and it is not covered anywhere.
⛔ **THE RULE THIS ADDS: a read-only brief must forbid the DATABASE, not just the files — and a mutation
harness must refuse to start while any other test process is alive.** The re-run does exactly that
(`ps aux | grep -cE '[v]endor/bin/pest|[a]rtisan test'`, abort if non-zero) and returned
**`1 failed, 123 passed (867 assertions)`, zero infrastructure errors** — the clean, disjoint result above.
⚠️ **The tell was in the assertion count, not the failure count**: 856 against a known 867. **A red run whose
assertion total moved is an infrastructure event until proven otherwise.**

### ⚠️ ADVERSARIAL VERIFICATION FOUND THREE DEFECTS IN MY OWN COMMITTED DIFF

Worth recording because they are the kind that survive a self-review:
1. **Both API deny tests asserted only `assertForbidden()`.** An ability refusal and a permission refusal are
   **both 403** and differ only in `error.code` (`bootstrap/app.php:255` vs `:258`), so status alone cannot
   say which gate answered — the test would have passed if the wrong one refused. Both now assert the code,
   as the twin's own test already did.
2. **A header comment claimed `:110` was "the one assertion that pointed at /analytics/export"** when **nine
   requests in that very file** point at it. What was unique was the assertion about its *gate*.
3. **That same cross-reference was already stale inside my own diff** — the five-line comment the same commit
   inserted into `AnalyticsPageGateTest.php` pushed `:110` to `:115`. **A citation into a file your own diff
   edits must be re-read after the edit.**

### ⛔ ONE CANDIDATE WAS ALREADY PASSING, AND CHECKING IT COST ONE GREP

The claim opened by declaring a **fourth** route the row omits — the web twin
`GET /forms/{form}/versions/{version}/xlsform`. It is omitted because it is **covered**:
`XlsformExportTest.php:228` has driven it as a non-collaborating `form_editor` since G7a and asserts
`assertForbidden()` at `:246`. **That is M20's *a character-identical declaration is not an identical defect*
holding for a fourth increment** — and the claim was corrected in place before the first file was opened, as
it said it would be. What that route *did* lack was the **role** arm (a `viewer` fails
`FormPolicy::canEdit`'s first clause where the `form_editor` fails its second), so **one** test was added
rather than the four an unchecked assumption would have produced.

### ⚠️ THE CENSUS UNIT WAS WRONG AGAIN, AND IT FOUND A LIVE SURFACE

Re-run with the **resource** as its unit, the stored-bytes census returned **sixteen** byte-returning routes
where M29's antecedent counted **ten**. The one that matters is filed as its own `major`:
**`GET /admin/feedback/{feedback}/screenshot`** (`routes/admin.php:83-84`) streams the same PII screenshot
bytes **cross-tenant, from the central host**, and no test asserts a refusal on it — while the console
**index** beside it has all three (`FeedbackConsoleTest.php:153`, `:158`, `:162`). ⚠️ **Filed with its own
weakness stated:** all three routes inherit that middleware from **one group**, so the index's denials do
transitively pin it today. It is `major` for what is behind the door, not for how close the suite is to green
on a mutation.

### ⛔⛔ THE ADVERSARIAL PASS FOUND A GATE THIS INCREMENT HAD LEFT NAKED — ON THE VERY ROUTE IT WAS REPAIRING

The sharpest single finding of the increment, and it is about **M34's own first commit**. Having closed the
API export's `ability:` and `can:` gates, a refuter asked the killer question of the third: **delete
`feature:advanced_analytics` from `routes/api.php:369` and the whole repository stays green.** The 402 that
looks like coverage (`AnalyticsApiTest.php:113`) asserts against `analyticsUrl()`, whose default suffix is
`'report'` — **the twin, again**. Every other case touching the export URI runs on Business, the contract test
asserts spec shape only, and `GroupBPolicyGateTest` looks for `Authorize::class` alone.
⛔ **That is the row's own defect, one gate over, on the route being repaired.** Closing two of a route's
three gates and leaving the third with the exact hole the row was filed about is how a row gets re-filed a
month later — so the 402 is now asserted on `exportUrl()`, and this increment's **fourth mutation** proves it.
⚠️ **My own header comment had cited the twin's test as if it covered this route.** It now points at the
new one. **A comment that borrows a sibling's coverage is the prose form of the defect being fixed.**

### ⚠️ TWO MORE FOUND AND DELIBERATELY LEFT, BOTH FILED

- **A `can:` gate naming the WRONG SUBJECT is invisible to everything**, including `GroupBPolicyGateTest`,
  which discards everything after the first colon (`:97`). Swap `SavedReportView::class` for
  `Submission::class` — the alternative `routes/api.php:359-362` says it *considered and rejected* — and every
  test stays green, because M34's role-less principal holds `submissions.view` no more than the dashboard
  keys. **The rejection is defended by a prose comment and nothing executable.** Left because an assertion
  about *which permission a gate names* is a new species here and wants designing once across the whole
  Group-B surface, not bolted onto the one route under repair. The fixture that would close it is named in
  the row.
- **`routes/api.php:114-116` describes an ordering the priority sorter does not produce** — it claims
  `feature:api_access` runs *"before throttle so a no-feature tenant is refused before consuming a burst
  slot"*, and `route:list` shows `ThrottleRequests:api` hoisted to **first**. Re-read at source rather than
  taken from the report (the reporter said `:115-116`). Same species as the *"signed read-back"* docblock this
  increment struck, and left because the fix is a decision — strike the claim, or make it true — rather than
  an edit.

### ⛔ NAMESPACES — THIS CLAIM SPENT NOTHING, AND THE OFFERED ONE WAS AGAIN THE WRONG HOME

**No ADR, no `§D<n>`, no migration, no new ability, permission key or `NotificationType`** — so
`ShellAbilityParityTest` and `NotificationTypeParityTest` both stayed still, and **no paired file moved**.
`ADR-0022` stays free and stays Lane A's block-opener; `2026_08_17_000111` stays free; `0010` stays reserved
for H1d; `#16` stays free. **`ADR-0016 §D35` STAYS FREE — offered to this lane a third time and spent a third
time not**, because ADR-0016 is the SAML SSO record and an export-authorization finding has no business in it.
⚠️ **No decision was owed at all**: every change here is test coverage plus one docblock, and nothing about
the product's behaviour was decided. `D1`, `D3` and `D4` in `docs/claims/decisions.md` remain open and were
not re-litigated.

### ⚠️ AND ONE PROCESS FINDING ABOUT MY OWN READING

The claim recorded the row at `docs/feature-backlog.md:2297`; it is at **`:2387`**. The early greps ran in
`fb-lane-b` **before** `git checkout -b … origin/main` — i.e. against the stale `m33-release` checkout the
worktree happened to be sitting on, which predates `M30`'s ~90-line row closure. **Cutting the branch from
`origin/main` protects the branch; it does nothing for the reading you did five minutes earlier.**
⛔ **Fetch and check out FIRST, then read.**

---

## RELEASED — M33, the attachment id that defeated every per-form boundary (merged as PR #221, `f329e1b`, CI 6/6 green with real step counts)

**Shipped 2026-08-26.** Branch `m33-attachment-scope`. Row closed in `docs/feature-backlog.md` as its own
micro-commit.

### GATES

- **CI Pest `4575 passed / 19,359 assertions`** (2 pre-existing warnings) — up from `4564 / 19,345` on the
  post-M29 tree, **+11 cases, exactly the eleven added**, measured as a DELTA against the base this branch
  actually merged into rather than quoted as an absolute.
- **All six jobs `success` with real step counts** — E2E **20**, Static analysis **19**, Contract **16**,
  Frontend **12**, Pest **11**, Design-system axe **11**. **Not one `steps: []`.**
- **E2E `551 passed + 10 skipped`, NO flaky line** — unchanged, and load-bearing (`failOnFlakyTests`).
- **Local**: `tests/Feature/Attachments/` **20 passed / 36 assertions**; the wider blast radius
  (`Submissions`, `Branding`, `Webhooks`, `Tenant`) **612 passed / 2,620 assertions / 0 failed**.
- **PHPStan local 18 across 10 FILES = baseline, zero delta BY FILE LIST**, and neither of this diff's PHP
  files appears in it. CI `[OK]`.
- **Pint `passed` over `1373` files — and PROVEN LIVE FIRST.** A bare `passed` is not evidence a scan
  happened, so a deliberately misformatted probe was written into `app/Policies/` and run: exit **1**, the
  file named, seven fixers listed. Only then was the real `passed` believed.
- **`openapi.json` byte-identical**; no `.vue`, no `resources/`, no `packages/`, and **`tests/e2e/` contains
  no reference to attachments at all** (grepped — *a gate you cannot run is not a gate you may assume*).
  Vitest and Storybook axe **not run, and recorded as UNMEASURED rather than green**: this diff is PHP-only
  so neither *should* move, and "should not move" is not "measured".

### ⛔⛔ THE FINDING: A ROW'S EVIDENCE AND A ROW'S PRESCRIBED FIX ARE SEPARATELY TRUSTWORTHY

**Every file:line in this row was correct.** All eight citations were opened first-hand and all eight held —
**the first time in fifteen increments the evidence half needed no correction at all.**

**And its one-sentence remedy was still wrong.** The row said the fix *"means resolving each kind's owner
**through the morph map** first."* That is impossible **and forbidden**. `attachable_type` carries five
aliases; only three are registered. **`tenant` and `feedback_report` are deliberately absent**, because
registering them would change how Sanctum's `tokenable_type` and Spatie's `model_type` serialize and split
existing rows between alias and FQCN — the `enforceMorphMap` break that cost 90 test failures.
`tests/Feature/Branding/BrandingMorphAliasTest.php` exists **solely** to pin that absence, **and prescribes
this increment's design by name**: *"a LOCAL resolution (a match on `kind`, or a dedicated relation), never a
global registration."* **This was that increment, and the repository had already decided it.**

⚠️ **`BrandingMorphAliasTest` IS A SIXTH PAIRED-FILE-SHAPED GATE.** It reddens if the obvious implementation
is written, which is exactly what it is for. It is not in the 7(b-bis) table because it guards a *decision*
rather than a cross-lane contract — but it behaves identically, so it is named here.

⚠️ **LANE A FOUND THE IDENTICAL SHAPE THE SAME DAY, INDEPENDENTLY, IN `M32`'s ROW** — *"REAL, and the row's
own prescribed fix does not work."* **Two lanes, two unrelated rows, one day.** Fifteen increments have
taught this project to verify a row's citations; **nothing has been verifying its prescribed fix — and that
is the half that decides what gets built.**

### WHAT THE ROW DID NOT NAME

1. **A SIXTH REACHABLE KIND.** The row enumerates four. A census run on the **resource** — every
   `AttachmentKind::` producer under `app/` and `database/` — returns **five production writers**, and the
   one it never names is the **brand logo** (`AttachmentStorageService.php:148`, owner alias `tenant`). It
   is deliberately **neither scoped nor narrowed**: `GET /branding/logo` serves the same bytes
   **unauthenticated** to email clients, so tightening this route protects nothing already public.
   `OcrSourceScan` and `Avatar` are declared and produced by nothing. **M29's census lesson held for a second
   increment: the unit is the resource, not the feature.**
2. **A FIXTURE THE FIX SILENTLY INVALIDATED.** `Attachment::factory()` defaults to a `form_field` alias with
   a **random uuid**. M29's positive control used it — and under a policy that resolves the owner it would
   403 for **every** role and prove nothing about permissions. Both M29 cases now own real rows.
   ⚠️ **A green suite after a scoping change is not evidence until the fixtures are checked for owners that
   never existed.**
3. **A LINK THAT KEEPS WORKING, AND ONE THAT CORRECTLY STOPS.** `GeneratePdfJob:175` mails a
   `/attachments/{id}` link; its recipient necessarily passed `SubmissionPolicy::export()` — the same scope
   plus `submissions.export` — so **every legitimate link still resolves**. The links that stop are exactly
   the ones held by someone since removed from the form, which *is* the defect.

### THE BUILD

The `match` is **exhaustive over all nine kinds with no `default` arm**, so PHPStan reports a tenth kind
rather than absorbing it — a default is what absorbed the seventh and produced `M29`. Three kinds are
answered on a permission alone because they have no form to be scoped to (feedback screenshot on
`feedback.view`; brand logo on any member; webhook envelope on `webhooks.manage`); the five
submission-domain kinds require `submissions.view` **and** their owner's scope; `Avatar` **fails closed**.

**The submission arm DELEGATES to `SubmissionPolicy::view()` through the Gate rather than copying its
predicate**, so a third surface agrees by construction rather than by review — the divergence
`Submission::scopeVisibleTo()`'s mirror-pin already exists to prevent. **The respondent test case is what
proves the delegation is real**: it passes only because the third arm came along with it, and a hand-copied
predicate that forgot it would satisfy every other case in the file.

**Two mutations, red sets DISJOINT.** *Never-narrows-the-webhook-envelope* reddened **1**;
*never-scopes-the-submission-kinds* reddened **4**. Each mutant was `php -l`'d and its **sha256 asserted to
have moved**; the original bytes were saved and compared back by hash. The M29 harness rules were followed
and cost nothing this time.

### ⚠️ THE BASE WAS WRONG ONCE, AND THE FAILURE LOOKED EXACTLY LIKE A BROKEN TEST

`M33` was first cut from `origin/main` — where `M29`'s code did not yet exist — and the suite came back
**17 passed / 3 failed** on `AttachmentFactory::feedbackScreenshot()`, a method `M29` adds. **The three
failures had nothing to do with the diff.** The branch was re-cut from `m29-feedback-screenshot-deny` and
the work replayed, after which `git rebase origin/main` was **clean in both files** — the conflict the M29
release note predicted never happened, *because the work was built on its dependency rather than beside it*.
⛔ **WHEN YOUR ROW EXTENDS AN UNMERGED PR OF YOUR OWN, `origin/main` IS THE WRONG BASE.** The saved-bytes
discipline made the re-cut cheap — though one file (the factory) had to be **re-derived rather than
restored**, because its saved copy was itself built on the wrong base. **Saving bytes protects you from
losing work; it does not protect you from having saved the wrong work.**

### ⚠️⚠️ TWO LANE-B WRITERS SHARED THIS WORKTREE, AND RULE 7 DOES NOT COVER IT

Mid-increment, `c:\laragon\www\fb-lane-b` was checked out from `m33-attachment-scope` onto a branch
`m29-release` that this session did not create, with live uncommitted edits to `PROGRESS.md`,
`PROGRESS_ARCHIVE.md` and this file. The reflog showed a checkout nobody here issued, and mtimes were moving
**31 seconds** before it was noticed. It also explains a `gh pr merge 219` returning
*"already merged"* — **the other writer merged `#219` seconds first.**

⛔ **THE CLAIM PROTOCOL HELD; THE WORKTREE DID NOT.** That writer read the pushed `M33` claim, **preserved it
as the `## Status` heading**, released only `M29`'s, corrected the Pest baseline to `4564 / 19,345` (the
figure this session had independently measured off the CI log), and left an explicit instruction to rebase.
Nothing was lost. **But Rule 7 assumes ONE WORKTREE PER LANE: the claim file protects the FILES, and nothing
protects the CHECKOUT.** Two agents in one worktree share a working tree and a `HEAD`, so a branch switch by
one silently relocates the other. **The cheap detection is the same one M30 found for claims —
`git worktree list` plus the reflog — and the cheap discipline is: commit and push before you switch, which
is what made this recoverable.**

### NAMESPACES AFTER M33

**ADR-0016 `§D35` STILL FREE** — reserved for this lane a second time and spent neither time.
⚠️ **The hand-off named it again and it is again the wrong home: ADR-0016 is SAML SSO.** M29 caught that and
wrote it down, so **M33 did not have to rediscover it — the first time a recorded namespace lesson paid out
on the very next increment.** The right home was **ADR-0015 §D10**, by the rule *the ADR whose own
sub-decision created the defect*: §D6 filed a second owner-class into the shared table and §D9 is already the
running commentary on it. ADR-0002 was the other candidate and was rejected — **it has no §D-series at all.**
ADR-0015's series is now **§D1–§D10**.

Migration block **`2026_08_17_000111` STILL FREE** (no column, index or table). **ADR `0022` STILL FREE** and
still Lane A's block-opener. `0010` stays reserved for H1d; `#16` stays free.
`docs/claims/decisions.md` gained **`D4`** (the webhook envelope's permission — a NARROWING, recommended and
implemented with the revert named, on the `D3`/`M26` precedent). **`D1` and `D3` stay OPEN and untouched;
this lane did not re-ask or re-litigate either.**

**The original M33 claim, as written when the row was taken, follows unedited.**

### ORIGINAL CLAIM (M33)

**Taken 2026-08-26.** Branch `m33-attachment-scope`, cut from `origin/main` at `d71e4ea`, PR into `main`.
Row: the `major` under **`### Submissions, drafts & the guest runtime`** (heading at
`docs/feature-backlog.md:891`, row at `:893`), inside the *Merge-gate review of `main` → `phase1-completion`*
section (`:480`) — *`AttachmentPolicy::view()` is flat where `SubmissionPolicy::view()` is scoped, so an id
defeats every per-form boundary*.

⛔⛔ **NUMBERED `M33`, NOT `M31`, AND THE ARITHMETIC WOULD HAVE COLLIDED.** `lane-a.md` was re-read at
**write time** — not from the opening fetch — and it does not merely hold `M30`. Its
`### THE QUEUE BEHIND IT` block (`:185`) **pre-claims `M31` and `M32` as well**, deliberately and in
writing, on Rule 7's *"the lane queue is the claim"*. Lane A says why in its own words: *"because Lane B is
taking rows from this exact section right now."* The highest number spoken for is therefore `M32`, and the
next free one is **`M33`**. ⚠️ **Had this lane computed the number the obvious way — `M30` is the highest
merged, so take `M31` — it would have collided with a claim that was pushed and readable.** The rule that
saved it is the one M29 recorded and M30 sharpened: **re-read at WRITE time, and read the whole file, not
the `## Status` line.** A lane's forward queue is a claim and it does not live under that heading.

⚠️ **AND THE FALLBACK ROW NAMED IN THIS LANE'S OWN NEXT-PROMPT IS GONE — IT IS LANE A'S `M32`.** The
hand-off named *the queued half of `gamification:backfill`* as the natural second row if this one proved too
large. Lane A claimed it as `M32` and has already scouted it. **There is no fallback; this row is the
increment.** A next-prompt's suggested second row is a suggestion made before the other lane's claim
existed, and it expires exactly the way a namespace reservation does.

**`git worktree list` was run too**, per M30's finding: `dev_formbuilder_app` on `m30-guest-limiter-key`
(Lane A, matching its claim), `fb-lane-b` on this lane's own `m29-feedback-screenshot-deny`, and
`fb-lane-c` on `lane-c-bootstrap` at `b44a36c` — **stale, M14-era, not a live lane** (no `docs/claims/lane-c.md`,
no `lane-c` branch on origin), as `PROGRESS.md:1837` already records. Three worktrees, two lanes.

**NO FILE COLLISION WITH LANE A'S THREE.** `M30` is `config/fortify.php` and the login/2FA limiters; `M31`
is `tests/Feature/Submissions/SubmissionAnswerEditTest.php`, `SubmissionEditRoutesTest.php` and
`database/factories/SubmissionAnswerFactory.php`; `M32` is the gamification backfill command tests and
`tests/Feature/Connectors/ConnectorTokenRefreshTest.php`. **All three sit under `### Test suite & CI gates`;
this row sits under `### Submissions, drafts & the guest runtime`.** No file below is in any of those sets —
the nearest approach is `database/factories/`, where Lane A may touch `SubmissionAnswerFactory.php` and this
lane may touch `AttachmentFactory.php`.

---

### THE ROW IS TRUE — AND FOR THE FIRST TIME IN FIFTEEN INCREMENTS, EVERY CITATION IS RIGHT

Verified read-only before this claim was written, every file:line opened first-hand:

- `routes/tenant.php:751-752` — `GET /attachments/{attachment}` → `AttachmentController@show`, `can:view,attachment`. ✅
- `app/Policies/SubmissionPolicy.php:87` — `view()` is `submissions.view` **AND** (org-wide **OR** collaboration **OR** respondent). ✅
- `:96` — `export()` requires the same scope again. ✅
- `:190` — `hasOrgWideVisibility()` is `dashboard.org.view`. ✅
- `RolePermissionSeeder` `:98-103` `form_editor`, `:105-109` `reviewer` — both hold `submissions.view` with only `dashboard.form.view`; **`viewer` (`:111-114`) holds `dashboard.org.view`**, so owner/admin/viewer have no gap and the affected roles are **exactly `form_editor` and `reviewer`**, as the row says. ✅
- `SubmissionPdfStorage.php:105` — `'kind' => AttachmentKind::ExportArtifact`, `is_pii => true`. ✅
- `WebhookPayloadArchive.php:55-68` — kind at `:55`, `ScanStatus::Skipped` at `:68`, directly under the comment at `:67` asserting the file is *"never served to a browser"*. `ScanStatus::servable()` admits `Skipped`. **The route makes that comment false.** ✅
- `tests/Feature/Attachments/AttachmentPolicyTest.php:125-137` — asserts a `form_editor` gets **200** on submission media, with a comment naming itself the filed-not-fixed boundary. **That is the assertion that flips.** ✅

**The streak of rows that are wrong about themselves breaks here on the evidence — and continues on the
remedy.** See the next two sections.

### ⚠️ WHAT THE ROW UNDERSTATES: THERE IS A SIXTH REACHABLE KIND, AND IT ENUMERATED FOUR

The row lists *"respondent uploads and media captures, the per-submission PDF, and archived webhook
envelopes."* **A census run on the RESOURCE rather than on the row — every `AttachmentKind::` producer under
`app/` and `database/` — returns five production writers, not three**, and the fifth is one the row never
names:

| Producer | Kind | `attachable_type` |
|---|---|---|
| `AttachmentStorageService.php:60` (`forFieldType`) | `SubmissionFile` / `FieldMediaSample` / `SignatureCapture` | `submission` (persisted) or `form_field` (staged, `:77`) |
| `AttachmentStorageService.php:148` | **`BrandingLogo`** | **`tenant`** (`:164`) |
| `AttachmentStorageService.php:245` | `FeedbackScreenshot` | `feedback_report` (`:261`) — closed by M29 |
| `SubmissionPdfStorage.php:105` | `ExportArtifact` | `submission` |
| `WebhookPayloadArchive.php:55` | `WebhookPayloadArchive` | `webhook_delivery` |

`OcrSourceScan` and `Avatar` are declared in the enum and **produced by nothing** — so the nine kinds are
six live ones and two unwired, plus the screenshot already gated. **This is M29's census lesson holding for
a second increment: the unit is the resource, not the feature.**

### ⛔⛔ WHAT THE ROW GETS WRONG: ITS PRESCRIBED REMEDY IS THE ONE MECHANISM A TEST EXISTS TO FORBID

The row says the fix *"means resolving each kind's owner **through the morph map** first."* **It cannot, and
the repository has an explicit, tested decision against it.**

`attachments.attachable_type` holds **five** aliases in production. Only three are registered
(`AppServiceProvider.php:340-346`: `submission`, `form_field`, `webhook_delivery`, plus `form`/`scope_node`
from `ResourceScopeable::morphMap()`). **`tenant` and `feedback_report` are deliberately absent**, and
`tests/Feature/Branding/BrandingMorphAliasTest.php` exists for the sole purpose of pinning that absence —
because registering `tenant` globally would change how Sanctum's `tokenable_type` and Spatie's `model_type`
serialize a Tenant and split existing rows between alias and FQCN. **That is the `enforceMorphMap` break
that cost 90 test failures**, and both `AttachmentStorageService.php:117-123` and `:205-208` restate it.

That test also **prescribes this increment's design, in advance and by name**:

> *"If a future increment genuinely needs `$attachment->attachable` on a branding logo, the fix is a LOCAL
> resolution (a match on `kind`, or a dedicated relation), never a global registration."*
> — `BrandingMorphAliasTest.php:20-21`

**This is that increment.** So the design is not open: resolve the owner with a **local match**, never
`$attachment->attachable`, never a global morph registration. ⚠️ **`BrandingMorphAliasTest` is therefore a
SIXTH paired-file-shaped gate for this work** — it reddens if the obvious implementation is written, which
is precisely what it is for. It is not in the 7(b-bis) table because it guards a decision rather than a
cross-lane contract; it is named here so the next reader does not rediscover it at CI.

**The generalisation, and it is this increment's finding: a backlog row's EVIDENCE and a backlog row's
PRESCRIBED FIX are separately trustworthy, and the fix half is checked far less often.** Fifteen increments
have verified the evidence half; this is the first row whose evidence survived intact and whose one-sentence
remedy pointed straight at a tested prohibition. ⚠️ **Lane A independently found the identical shape in its
`M32` scouting the same session** — *"REAL, and the row's own prescribed fix does not work"* — so this is
two for two on the same day, from two lanes, on two unrelated rows. **Verify the remedy, not just the
citations.**

---

### THE SHAPE OF THE FIX

`AttachmentPolicy::view()` enumerates all nine kinds explicitly — no `default` arm, so PHPStan flags a tenth
kind rather than a silent absorption, which is M29's §D9 argument carried one step further:

- **`FeedbackScreenshot`** → `feedback.view`. Unchanged; M29's.
- **`WebhookPayloadArchive`** → `webhooks.manage`. **A narrowing, and the increment's one real product
  question** (see `D4` below): a delivery envelope carries the full payload of whatever form fired it, so it
  crosses every per-form boundary at once and is tenant infrastructure rather than per-form data. Today it is
  readable by all five roles.
- **`BrandingLogo`** → any member (`submissions.view`, which all five seeded roles hold). **Deliberately not
  narrowed**: `/branding/logo` (`routes/tenant.php:1060`) serves these same bytes **unauthenticated** to email
  clients, so tightening this route protects nothing that is not already public.
- **`SubmissionFile` / `FieldMediaSample` / `SignatureCapture` / `ExportArtifact` / `OcrSourceScan`** →
  `submissions.view` **AND** the owner's scope, resolved locally off `attachable_type`:
  `submission` delegates to `SubmissionPolicy::view()` (so the two cannot disagree by construction, which is
  the same argument `Submission::scopeVisibleTo()`'s mirror-pin makes); `form_field` resolves the staged
  file's form and requires collaboration; **anything else fails closed.**
- **`Avatar`** → fails closed. Unwired today; a real avatar feature must make its own decision at this site.

⚠️ **`SubmissionPolicy` takes `ResourceGrantResolver` in its constructor (`:30`) — delegating means
injecting it here too, not re-implementing `collaboratesWith()`.**

### THE PRODUCT QUESTION — FILED AS `D4` IN `docs/claims/decisions.md`

*What does "scope" mean for an attachment whose owner is a webhook delivery rather than a form?* The
recommendation implemented is `webhooks.manage` (Owner/Admin). **Proceeding on the recommendation with the
revert path named in the entry, per the `M26`/`D3` precedent** — `D1` and `D3` are still open and unanswered
and this lane does not re-ask, re-litigate, or block on them. `D4` is the next free id.

### NAMESPACES

**ADR-0015 §D10.** The rule is *the ADR whose own sub-decision created the defect*: **§D6** put a second
owner-class into the shared table, and **§D9** — M29's, added last increment — is already the running
commentary on that table having a shared *reader*. §D10 continues that exact thread one step out: the shared
route needs the **scope** to reach the gate, not only the kind. ADR-0002 was the other candidate and is
rejected — **it has no §D-series at all.** ⚠️ **ADR-0016 §D35 is again NOT the home** (it is SAML SSO); M29
already caught and recorded that, and this lane did not have to rediscover it — **which is the first time a
recorded namespace lesson has paid out on the very next increment.**

**Left free and unspent:** ADR-0016 `§D35`, migration block `2026_08_17_000111`, ADR `0022` (Lane A's
block-opener), `0010` (H1d), `#16`.

### FILES

`app/Policies/AttachmentPolicy.php` · `tests/Feature/Attachments/AttachmentPolicyTest.php` ·
`database/factories/AttachmentFactory.php` (states for the export-artifact and webhook-envelope kinds) ·
`docs/adr/0015-feedback-screenshot-capture.md` (§D10) · `docs/claims/decisions.md` (`D4`) ·
`docs/feature-backlog.md` (close the row) · `PROGRESS.md` (own block only).

**No `.vue`, no `resources/`, no `tests/e2e/`, no `/api/v1` route — `openapi.json` stays byte-identical.**
Of the five 7(b-bis) paired gates, this diff moves **none**: no new ability key
(`ShellAbilityParityTest`), no new `NotificationType`, no front-end file for either theme scanner or for
`scripts/component-import-lint.php`. The sixth gate named above, `BrandingMorphAliasTest`, is the one this
work can actually trip.

---

## RELEASED — M29, the PII screenshot whose gate had no test, and the sibling route that walked around it (merged as PR #219, `7892f7f`, CI 6/6 green with real step counts)

⛔⛔ **`#219` MERGED FIRST, SO `M33` MUST REBASE ONTO `origin/main` BEFORE IT OPENS ITS PR — THIS IS THE
BRANCH ABOVE TELLING ITSELF SO.** The M33 claim predicted this exact fork and named the remedy: *"if `#219`
merges first, `M33` rebases onto it and its `match` grows arms."* That is the branch taken. `M33` is cut
from `d71e4ea`, which does **not** contain `AttachmentPolicy::view()`'s `match` or
`tests/Feature/Attachments/AttachmentPolicyTest.php`, so **`git rebase origin/main` will conflict in both
files and both conflicts are expected rather than a symptom.** Resolve by keeping M29's `match` and
**adding** M33's arms to it, and by keeping M29's five cases and **appending** M33's. ⚠️ **`M33` MUST ALSO
RE-READ `AttachmentPolicyTest`'s `form_editor` case before flipping it** — M29 wrote that assertion to
record the CURRENT behaviour precisely so the row could not be closed by accident, and closing the row is
what M33 is for. **It is the assertion that has to flip, and it is not a regression.**

✅ **THE OUTAGE LIFTED AND THE PATIENCE PAID.** CI ran on `32988697084` and returned **6/6 green with real
step counts, parsed individually rather than trusted from a tick**: E2E **20** · Static analysis **19** ·
Design-system axe **11** · Pest **11** · Contract **16** · Frontend **12**. Not one `steps: []`. Three
earlier runs had failed or sat queued 30–57 minutes with an empty job list; **nothing was merged blind, and
the thing that made that affordable was that the diff was finished and pushed while the waiting happened.**

⛔⛔ **AND THE CI NUMBER SETTLES A DISAGREEMENT BETWEEN THE TWO LANES' QUOTED BASELINES — MEASURE THE DELTA,
NEVER THE ABSOLUTE.** CI Pest on the merge commit is **4564 passed / 19,345 assertions (2 pre-existing
warnings)**. M29 adds exactly **seven** tests (five in `AttachmentPolicyTest`, two in `FeedbackTest`), and
**4557 + 7 = 4564** — the post-M26 figure lands on the nose. Lane A's hand-off quoted **4544** as the
post-M28 baseline, which is **13 low**; M25, M27 and M28 added no PHP tests, so it could not have fallen.
**Anyone measuring against 4544 would have read a phantom `+20` and gone looking for thirteen tests that do
not exist.** The corrected baseline is **4564 / 19,345**.

⚠️ **THE LOCAL FULL-SUITE NUMBER IS NOT THE CI NUMBER AND MUST NEVER BE QUOTED AS ONE.** Local was
**4229 passed / 17,970 assertions / 0 failed**; CI is **4564 / 19,345**. A 335-test gap between the two
harnesses on the same tree — quote the wrong one into a hand-off and the next lane's delta is nonsense.

✅ **AND EVERY GATE THIS CLAIM RECORDED AS *UNMEASURED* IS NOW MEASURED AND GREEN**: Vitest, Storybook axe,
E2E and the contract job all passed on the merge. The earlier entry deliberately called them unmeasured
rather than green, and that was the right call at the time — but the record should not be left implying a
gap that no longer exists.

**The claim, and the M33 merge-order note that was written while it was still held, follow unedited.**

---

## THE RECORD AS IT STOOD WHILE HELD — M29, CODE COMPLETE AND PUSHED, PR #219 OPEN, HELD ON A CI RUNNER OUTAGE

⛔ **RE-CHECKED AT THE OPEN OF THE M33 SESSION, 2026-08-26 ~16:20 UTC: THE OUTAGE HAS NOT LIFTED AND #219 IS
STILL UNMERGED.** `gh api .../actions/runs/32985349087/jobs` returns `{"total_count":0,"jobs":[]}` — **not
one job has started**, now **43 minutes** after the run was created. Not this branch and not this lane:
`M30`'s run (`32985770877`, Lane A) and a plain `main` push (`32984912723`) are queued **40 and 57 minutes**
with the same empty job list, and the last run that executed anywhere in the account finished at
**14:47 UTC**. A 60-second poll held across this session's whole verification pass never saw a job start.
**So the claim below stays held, the PR stays open, and nothing was merged blind.** Branch
`m29-feedback-screenshot-deny` at `cac6224`, three commits ahead of `d71e4ea`.

⚠️⚠️ **AND `M33`, CLAIMED ABOVE, TOUCHES THE SAME TWO FILES ON PURPOSE — READ THIS BEFORE MERGING EITHER.**
`M33` extends the very `match` that `M29` introduced in `app/Policies/AttachmentPolicy.php`, and it adds
cases to `M29`'s own `tests/Feature/Attachments/AttachmentPolicyTest.php`. **`M33` is cut from `origin/main`
at `d71e4ea`, NOT from this branch**, so the two diffs overlap by design rather than by accident: this is one
defect — a shared table reached through a shared route — being closed in two halves, split at the increment
boundary rather than at a seam in the code. **Whichever merges second must be rebased first**, and the
conflict will be in both files. If `#219` merges first, `M33` rebases onto it and its `match` grows arms; if
`M33` merges first, `#219` must be rebased before it is merged and **must not be force-merged past the
conflict.**

**The original M29 claim, unedited, follows.**

⛔⛔ **LANE B STILL HOLDS THIS CLAIM, AND THAT IS THE CORRECT STATE RATHER THAN AN OVERSIGHT.** Every file is
written, committed and pushed on `m29-feedback-screenshot-deny`; **PR #219 is open and NOT merged.** The
self-merge rule is 6/6 green with each job's step count parsed individually, and there is no green to be
had: **GitHub Actions has run nothing for this account for the better part of an hour.** The first run
(`32985349087`) *failed* after 55s with all six jobs at `status: queued` and **`steps: []`** — the
no-runner-ran signature this project already has a name for — and the re-run, plus **both of Lane A's M30
runs**, then sat queued for 30–47 minutes without a single job starting. It is account-wide, not this diff.

⚠️ **WHOEVER PICKS THIS UP: CHECK PR #219 FIRST AND MERGE IT ON 6/6 GREEN BEFORE TAKING ANYTHING NEW.** Then
release this claim. Do not re-take the row, do not re-cut the branch, and do not re-number — `M29` is spent
either way.

**What was verified locally, since CI could not be:**

- **Full local Pest: `4229 passed, 17,970 assertions, 0 failed`** (2 warnings, both pre-existing). The
  touched suites alone: **19 passed / 84 assertions**.
- **Pint `--test app tests database`: PASS over `1373 files`** — the file count read out, because a `PASS`
  over nothing is not a pass.
- **PHPStan local: 18 errors across 10 files, and none of them is this diff's.** Measured against the FILE
  LIST rather than the count, as the rule requires.
- **`openapi.json` untouched**, and no paired file moves.
- ⚠️ **Not run: Vitest, Storybook axe, E2E and the contract job.** This diff is PHP-only and touches nothing
  under `resources/`, `packages/` or `routes/api.php`, so none of them *should* move — but **"should not
  move" is not "measured", and it is recorded here as unmeasured rather than green.**

**Namespaces after M29:** migration block stays **`2026_08_17_000111`** (no column, index or table);
**ADR-0016's `§D35` stays FREE** — reserved by this claim and deliberately not spent; **ADR `0022` STAYS FREE
and stays Lane A's block-opener.** M29 amended **ADR-0015** with **§D9**, whose own §D6 created the defect.
**Seventh consecutive Lane B increment amending rather than minting.** ADR-0015's series is now §D1–§D9.
`0010` stays reserved for H1d; `#16` stays free. `D1` and `D3` in `docs/claims/decisions.md` are untouched
and stay open; M29 filed no new one, because what it found was a defect rather than a question.

⚠️ **THE CLAIM WAS WRONG ABOUT ITS ADR AND THE CODE CORRECTED IT BEFORE A WORD WAS WRITTEN INTO ONE.** The
claim reserved **ADR-0016 `§D35`** because the hand-off named that as Lane B's next free sub-decision.
ADR-0016 is **SAML SSO**; an attachment authorization decision has no home there. **ADR-0015 §D6 is the
decision that filed the screenshot into the shared table, §D7 already says *"both screenshot routes"*, and
§D8 marks the row PII** — so §D9 belongs there and the reserved slot was never spent. **A namespace handed
over in a next-prompt is a reservation, not a destination**, and reading the candidate ADR's own §D-series
is what separated the two.

⚠️ **AND ONE FILE WAS EDITED THAT THE CLAIM'S SCOPE LIST DID NOT NAME** — `database/factories/AttachmentFactory.php`
gained a `feedbackScreenshot()` state. Harmless (Lane B's tree, Lane A's M30 claim names nothing near it,
no paired gate reads it) and recorded anyway, because **the scope list is what the other lane reads to
decide it is safe**, and a list that drifts during the increment is worth less than one that does not.

---

## THE INCREMENT — M29, the PII screenshot whose gate had no test, and the sibling route that walked around it (PR #219, OPEN, CI never ran)

**Taken 2026-08-26.** Branch `m29-feedback-screenshot-deny`, cut from `origin/main` at `c7b8aae`, PR into
`main`. Row: the `major` under **`### Test suite & CI gates`** in `docs/feature-backlog.md` —
*`GET /feedback/{report}/screenshot` serves PII and has no DENY test at all*.

**⚠️ NUMBERED `M29`, AND `lane-a.md` WAS RE-READ IMMEDIATELY BEFORE THE CLAIM WAS WRITTEN.** Both reads
returned `c7b8aae` and both read `Status: NO ACTIVE CLAIM` — Lane A had discharged its whole three-row queue
(`M25` #216, `M27` #217, `M28` #218). `M28` was the highest number either lane had spent, so `M29` was next.
✅ **AND LANE A'S OWN M30 CLAIM CONFIRMS THE PROTOCOL WORKED FROM THE OTHER SIDE**: it read `lane-b.md` at
`b2089aa`, found `ACTIVE CLAIM M29`, named this increment's three files by hand and recorded that none of
its own is in that set. ⚠️ **BUT IT ALSO RECORDS A SIGNAL THIS LANE DID NOT KNOW IT WAS EMITTING** — at
Lane A's session open `lane-b.md` still read NO ACTIVE CLAIM while `git worktree list` already showed
`fb-lane-b` on a branch named `m29-feedback-screenshot-deny` with zero commits. **The worktree HEAD is
observable before the claim file is**, so the window between cutting a branch and pushing the claim is
visible to the other lane and costs it one command to check. Rule 7(g) is still right that an unpushed
claim does not exist; this is a second, earlier signal, and it is Lane A's finding rather than this one's.

### ⛔ THE ROW WAS TRUE, AND IT WAS THE SMALLER HALF OF WHAT WAS THERE

**Fourteen-for-fourteen on the row being real** — and the third increment running whose citation half needed
correcting. `routes/tenant.php:429-430` is exact and is the construct. `FeedbackTest.php:230` is exact and
is the test declaration. **`:154` had drifted**: it is a `Storage::assertExists` call, and the
`is_pii => true` assertion is at `:150` with the PII comment at `:149`.

⛔⛔ **AND THE ROW'S OWN NEIGHBOURHOOD WAS WRONG IN THIS FILE'S FIRST DRAFT, WHICH IS M26'S SLIP CAUGHT ONE
STEP EARLIER.** The claim said the row sat under *Security & authorization* — inherited from what the row is
*about* rather than read off the file. `awk` over the headings says **`### Test suite & CI gates`**. M26 made
the identical error and caught it after writing; this one was caught before pushing. **A row's neighbourhood
is as checkable as its file:line and still gets checked half as often.**

### ⛔⛔ THE FINDING: A GATE IS ONLY AS NARROW AS THE WIDEST ROUTE THAT REACHES THE SAME BYTES

`GET /attachments/{attachment}` (`routes/tenant.php:751-752`) is authorized by `AttachmentPolicy::view()`,
whose entire body was `$user->can('submissions.view')` — **it never touched its `$attachment` argument**.
ADR-0015 §D6 filed the feedback screenshot into that same shared table. `RolePermissionSeeder` grants
`submissions.view` to `viewer`, `reviewer` and `form_editor`; it grants `feedback.view` to **none** of them.
**So the id-addressed sibling served the PII image to exactly the three roles the dedicated route refuses** —
and `FeedbackController.php:59-65` says in its own words that it declines to route through
`AttachmentController` *precisely to avoid that coupling*, which was open in the other direction the whole
time. **Live, not latent.** Fixed by teaching the policy the kind through a `match`; `feedback.view` has
been in the catalog since Phase 0, so **no key was minted and no paired file moved**.

⚠️ **`AttachmentPolicy` HAD NO TEST OF ANY KIND, AND NO HTTP TEST ANYWHERE IN THE REPOSITORY DROVE THAT
ROUTE.** `AttachmentRlsTest` is four DB-level cases; every other `attachments` mention under `tests/` is
`TenantUrl` string-building. The only role gate on the entire authenticated media read path was unasserted.
`tests/Feature/Attachments/AttachmentPolicyTest.php` is its first coverage.

⛔ **THE METHOD IS THE TRANSFERABLE PART, AND IT IS M26'S CENSUS LESSON AT ONE MORE REMOVE.** M26 learned to
enumerate *consumers of a field* from the code rather than from the report. That was not enough here: the
field-level census of "who serves this screenshot" finds two routes and stops. What found the defect was
enumerating **every endpoint in the repository that serves stored bytes** — ten — and asking of each *which
test asserts a refusal*. **Four of ten had one.** The unit of the census has to be the RESOURCE, not the
feature: two routes reaching the same bytes under two different permissions is invisible to any sweep
organised by feature.

### ⚠️ THE ROW'S PROPOSED FIX WAS HALF-INERT, AND THAT IS MEASURED RATHER THAN ARGUED

The row asks for *"one `assertForbidden()` as a Viewer plus one cross-tenant `assertNotFound`"*. **The two
are not worth the same.** `bootstrap/app.php:217-218` runs `SubstituteBindings` **before** `Authorize`, so a
foreign report id 404s at route-model binding and never reaches the gate — the cross-tenant case **passes
unchanged with `can:feedback.view` deleted**. Mutation 3 below proves it. Both cases ship; the cross-tenant
one carries a comment saying in as many words that it is not a substitute, and it earns its place by pinning
RLS at binding with an **Owner** as the caller, so the only thing refusing it is isolation.

### ⛔⛔ MUTATION HARNESS — THREE MUTATIONS, PAIRWISE DISJOINT RED SETS

| mutation | red set | size |
|---|---|---|
| baseline | — | **19 passed / 84 assertions** |
| 1 · the policy stops reading the kind (exactly the pre-M29 behaviour) | `AttachmentPolicyTest › refuses a feedback screenshot to a viewer` | **1** |
| 2 · the default arm narrows to `feedback.view` (never grants) | `› serves respondent media to a viewer` **+** `› serves a submission attachment to a form_editor` | **2** |
| 3 · the middleware comes off the screenshot route | `FeedbackTest › refuses a screenshot to a member who lacks feedback.view` | **1** |

Mechanism committed before mutating; tree asserted green first; restores verified by **sha256** against
saved bytes, both files, after every mutation.

⛔ **AND THE HARNESS ITSELF FAILED FIRST, IN THE WAY THAT WOULD HAVE BEEN HARDEST TO NOTICE.** v1 spliced by
index arithmetic and produced **syntactically invalid PHP** — every test would have errored, which reads as
*"the mutation was detected"* if you only count red. It was caught because an editor notice showed the
mutated file's actual bytes. v2 replaces **one literal token** per mutation and adds two assertions the rule
does not yet name: **`php -l` the mutant, and assert its sha256 actually moved.** ⚠️ **A MUTATION THAT
BREAKS THE BUILD PROVES NOTHING, AND IT LOOKS EXACTLY LIKE A MUTATION THAT WAS CAUGHT.** Add the syntax
check.

⚠️ **AND KILLING THE HARNESS WAS A THREE-STEP PROBLEM, NOT ONE.** `TaskStop` killed the tool's wrapper and
**not the `bash` script**, which kept running and mutating; the script's `docker exec` host side died while
**the container's Pest kept going** and a new PID appeared after the first kill. Both had to be killed by
PID, on the host and inside the container, before the tree could be restored. **`TaskStop` on a background
shell is not `kill` on what that shell spawned.**

### THE FILE RECORD

New: `tests/Feature/Attachments/AttachmentPolicyTest.php`. Amended: `app/Policies/AttachmentPolicy.php` ·
`tests/Feature/Tenant/FeedbackTest.php` · `database/factories/AttachmentFactory.php` (a
`feedbackScreenshot()` state mirroring `brandingLogo()`) · `docs/adr/0015-feedback-screenshot-capture.md`
(**§D9**). Shared: `docs/feature-backlog.md` · `docs/claims/lane-b.md` · `PROGRESS.md` (own block, own
hand-off line, **and 7(b-bis)'s corrected row 1 plus its new fifth row**).

➕ **FILED, NOT FIXED, THE MOMENT EACH WAS DECIDED** — six rows in `docs/feature-backlog.md`, not notes here,
because a finding whose only home is a claim is invisible to a backlog search. The largest:
**`AttachmentPolicy::view()` is still flat where `SubmissionPolicy::view()` is scoped**, and the affected
roles are exactly `form_editor` and `reviewer` (`hasOrgWideVisibility()` is `dashboard.org.view`, which
owner, admin *and* viewer hold). ⛔ **ITS SHARPEST CONSEQUENCE IS THAT REVOCATION DOES NOT REVOKE**: remove a
`form_editor` from a form and `SubmissionPolicy` refuses them on the next request while every attachment id
they ever saw keeps working. Not fixed here deliberately — closing it means resolving each kind's owner
through the morph map and deciding what *scope* means for a kind owned by a webhook delivery, and folding
that in as a footnote to a feedback-screenshot row is **precisely how §D6 produced this defect**.
`AttachmentPolicyTest` asserts the current behaviour, so the row cannot be closed by accident.

⚠️ **ONE COSMETIC DEFECT LEFT ON `main` ON PURPOSE.** The claim commit `b2089aa` carries a stray leading and
trailing `@`: `git commit -m @'…'@` is PowerShell here-string syntax and this was the **Bash** tool, where it
degrades to `@` + a single-quoted string + `@`. It also silently ends the quoting at the first embedded
apostrophe — which is what broke the next commit outright and sent it to `-F` a file instead. **History on
`main` is not rewritten for a cosmetic defect in a docs commit.** Use `git commit -F <file>` for anything
containing an apostrophe.

---

## RELEASED — M26, the workspace headcount served with no permission at all (merged as PR #215, `7fe2260`, CI 6/6 green)

**Taken 2026-08-26.** Branch `m26-standing-headcount-gate`, cut from `origin/main` at `d429add`, PR into
`main`. Row: the `major` under **Gamification** in `docs/feature-backlog.md` — *`standing.of`
discloses the workspace headcount with no permission at all*.

**⚠️ NUMBERED `M26`, AND `lane-a.md` WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN, NOT AT SESSION
OPEN.** The opening fetch returned `d1d1d72`; by the time the branch was cut `main` was `d429add`, two
commits further on (`claim(M25)` from Lane A, then M24's own release doc). `lane-a.md` reads **ACTIVE CLAIM
`M25`** in both reads, so `M26` is the next free number. Lane A's M25 touches
`tests/e2e/responsive-axe.spec.ts` and `tests/e2e/auth-axe.spec.ts` and **explicitly commits to no `.vue`,
no `.ts` under `resources/`, and no PHP** — so no file in this claim is in that set.

### ⛔ NAMESPACES — THIS CLAIM SPENDS ONE SUB-DECISION AND NOTHING ELSE

**ADR-0020 gains `§D13`** (series becomes §D1–§D13). ⛔ **ADR `0022` STAYS FREE and stays Lane A's
block-opener.** This is the sixth increment running to amend rather than mint, and for the reason the
previous five recorded: §D7's own criterion is being corrected in place, and a correction filed away from
the sentence it corrects is one nobody finds. **Migration block `2026_08_17_000111` stays free** — no
column, index or table. **ADR-0016 `§D35` stays free.** `0010` stays reserved for H1d. `#16` stays free.

**⛔ NO NEW ABILITY AND NO THIRTIETH PERMISSION KEY**, which is what keeps both parity gates still. The fix
reuses `can('viewAny', PointAward::class)`, already resolved two fields away in the same method.
`ShellAbilityParityTest` fires on a new ability key and `NotificationTypeParityTest` on a new
`NotificationType`; **neither moves.** The third paired file
(`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts`) covers `RuntimeShell.vue`
and `SyncStatus.vue` — untouched.

### Every file this claim touches, named before it is opened

**Lane B's own column:**

- `app/Http/Controllers/Tenant/AchievementsController.php` — `of` at `:103` resolved against the same
  permission `scoreboard` already uses at `:115-120`.
- `app/Http/Resources/Api/V1/MemberProgressResource.php` — the same at `:41`.
- `app/Http/Controllers/Tenant/DashboardController.php` — `rank` and `of` **deleted** at `:123-124`. See
  the sixth-consumer note below: they are serialized and rendered nowhere.
- `app/Services/Gamification/MemberStanding.php` — the `:11-14` docblock, which states §D7's criterion in
  the form this increment corrects.
- `tests/Feature/Gamification/AchievementsPageTest.php` and `tests/Feature/Api/GamificationApiTest.php` —
  existing assertions that pin the defect as a requirement, plus new negative cases.

**Shared, claimed here:** `openapi.json` (**it WILL move** — `of` becomes nullable in
`MemberProgressResource`; regenerated with `php artisan scramble:export --path=openapi.json`, which CI
diffs for byte-identity at `ci.yml:329-332`), `docs/adr/0020-gamification-awarding-substrate.md`,
`docs/feature-backlog.md` (this row only), `docs/claims/lane-b.md`, `docs/claims/decisions.md` (**`D3`**),
`PROGRESS.md` (Lane B's block and hand-off line only).

**⚠️ LANE A'S COLUMN, CLAIMED PER-ROW UNDER 7(b) AND SAID SO HERE:**

- `resources/js/Pages/achievements/Index.vue` — `of: number | null` at `:56`, and the `standingLabel`
  degrade at `:87-93`.
- `resources/js/Pages/Dashboard.vue` — two now-dead prop fields deleted from `:91`.
- `resources/js/Pages/achievements/index.test.ts` — the `4th of 12` assertions at `:61,104-105,116,130`.

**Checked first, per the hand-off's instruction: the prop CANNOT be withheld without a Vue edit.**
`Index.vue:92` renders the label as `${ordinal(rank)} of ${number(of)}` and guards only on `rank === null`,
so omitting `of` from the payload would reach `undefined.toLocaleString()` and throw. Nulling it therefore
obliges the guard, and the guard is Lane A's file. **Three files, named, no more.** If this list grows, the
claim is extended as its own pushed commit from `origin/main` before the file is opened — the M24 lesson,
which is the one process rule this claim is written under.

**`docker restart fb-lane-b-node-1` is owed** (three `.vue`/`.ts` edits under `resources/`), after the
`ps -eo args` probe.

### ⚠️ THE ROW IS RIGHT ABOUT THE MECHANISM, WRONG ABOUT ONE CITATION, AND INVERTED ON ITS HEADLINE EXAMPLE

Re-walked against the tree at `d429add`. **Twelve-for-twelve becomes thirteen.**

- ✅ **`MemberStanding.php:33` HOLDS** — the `of` property, `public int $of`.
- ✅ **`AchievementsController.php:103` HOLDS** exactly, and **`:115-120` HOLDS** exactly: `scoreboard` is
  `$user->can('viewAny', PointAward::class) ? … : null`.
- ✅ **`MemberProgressResource.php:41` HOLDS** exactly.
- ❌ **`routes/api.php:440-442` IS WRONG — IT IS COMMENT PROSE, NOT A ROUTE.** Lines 437-455 are the §D7
  explanation block; the route is **`:456-458`**, sixteen lines further on. The row's *claim* about it
  holds: `:457` carries `ability:read:gamification` and `module:gamification` and **no `can:` gate**.
- ⚠️ **`DashboardMetricsService.php:55,60` ARE THE RIGHT LINES, AND THE ROW DRAWS THE WRONG CONCLUSION FROM
  THEM.** See below.
- ⚠️ **"THE IDENTICAL INTEGER" IS TWO DERIVATIONS OF ONE QUANTITY, NOT ONE NUMBER.** `standing.of` is
  `count()` of the ranked roster — `users` joined to active `tenant_users`, **no `withTrashed()`** and
  RLS-filtered (`LeaderboardService::roster()` at `:118-136`, `Leaderboard::fromRoster()` at `:85`).
  `kpis.members` is `count()` of `tenant_users` at `status = 'active'`
  (`DashboardMetricsService::activeMembersCount()` at `:319-324`). They agree except where a member's
  `users` row is soft-deleted, and `AchievementsController:231-234` **already says so** about the same pair.
  Same secret, two spellings; the row's word "identical" overstates it and nothing in the fix depends on
  the difference.

### ⛔⛔ THE CENSUS WAS A FLOOR, AND THE SIXTH CONSUMER IS THE ONE THAT INDICTS THE ROW'S OWN EXAMPLE

The row names five consumers and offers `/dashboard` as the surface that **correctly withholds** the
integer. **`/dashboard` leaks it too.**

`DashboardController::gamificationProgress()` emits `'of' => $standing->of` at **`:124`**, ungated, into the
**same Inertia payload** whose `kpis.members` is nulled at `:45` for the **same reader**. One `Inertia::render`
call, two fields, opposite answers to one question. `tests/Feature/Gamification/AchievementsPageTest.php:324`
pins it — `->where('progress.of', 2)` for a `form_editor` on `/dashboard`.

**And it is rendered NOWHERE.** `Dashboard.vue` declares `rank: number | null; of: number` at `:91` and its
template reads only `points`, `badges` and `streak` (`:450-457`). `dashboard.test.ts` asserts neither field.
So on the dashboard the headcount is **pure wire-level disclosure with zero product value** — which is why
that half of this fix is a deletion carrying no product question at all, and why it is worth more than the
half the row actually asked for.

**Full census (`of` as a value, not the English word), sixteen sites:** `MemberStanding.php:33` ·
`Leaderboard.php:110` · `LeaderboardService.php:95` · `AchievementsController.php:103` ·
`DashboardController.php:124` · `MemberProgressResource.php:41` · `openapi.json:6105` ·
`Index.vue:56,88,92` · `Dashboard.vue:91` · `achievements/index.test.ts:61,104,105,116,130` ·
`AchievementsPageTest.php:111,324` · `GamificationApiTest.php:125,133,145` · `LeaderboardTest.php` ·
`GroupBPolicyGateTest.php:70`. **Seeders and factories were grepped and write nothing here** — `of` is
computed at read time from the roster, so unlike M24's `('submission','updated')` tuple there is no
table for a seeder to write behind the census's back. That check was run because M24's near-miss says to
run it, and it came back clean.

### ⚠️ THE FIX IS A PATCH, NOT A RATIFICATION — AND THE CODE DECIDES THAT, NOT THE ROW

The hand-off warned this might be a ratification. It is not, and three independent surfaces built by three
increments are the evidence. **A Form Editor has no other route to the workspace headcount:**

| Surface | Withheld by |
|---|---|
| `/dashboard` `kpis.members` | `dashboard.org.view` (`DashboardMetricsService:55,60`) |
| `/members` page | `can:tenant.members.invite` (`routes/tenant.php:409-410`) |
| Member search arm | the same key; `MemberSearchArm::allowed()` at `:88-94`, whose docblock `:79` reads *Form Editor / Reviewer / Viewer — arm REFUSED — they cannot reach `/members` either* |
| `/achievements` `scoreboard.team.active_members` | `can('viewAny', PointAward::class)` (`AchievementsController:115-120`) |

So the number is treated as privileged everywhere it was considered, and handed over in the two places it
was not. Ratifying would mean un-gating `kpis.members` and `team.active_members` to match — a widening
nobody asked for, in the direction the product has consistently refused.

**⛔ AND THE ROOT CAUSE IS NOT A DISAGREEMENT WITH §D7 — IT IS §D7'S CRITERION BEING MOVED AND ONE FIELD NOT
RE-WALKED AGAINST THE NEW ONE.** §D7 gates *"the **named** ranked list"*: its criterion is **names**. K1e's
`AchievementsController` docblock at `:49-60` explicitly **rejects that criterion** — *"The tempting split
is 'names are gated, plain counts are not' … It is not"* — and re-gates `team` on **workspace-wide numbers
about other people's work**, calling the alternative *"a widening of an existing permission, performed by a
new page"*. `team.active_members` was moved behind the new line. **`standing.of` is the same number under
the same criterion and was not.** The defect is one file failing to finish applying its own paragraph, and
that is what §D13 records.

### ⚠️ `rank` STAYS, AND THE ADR HAS TO SAY WHY OR SOMEBODY WILL "COMPLETE" THIS SWEEP

A rank of 4 discloses that at least four members exist. That is a **floor, not the headcount**, and §D7
grants a member their own position in terms this increment does not touch. Removing `rank` would withhold
the one thing §D7 unambiguously approves in order to blur a bound the reader can already infer from having
colleagues. §D13 states this so the next reader does not read the sweep as unfinished.

### The gate, and the positive control it is proven with

**The defect is currently pinned as a requirement**, which is the whole reason `D3` exists:
`AchievementsPageTest.php:85` is titled *renders a members own points, badges, streak and standing with no
permission*, acts as a **`form_editor`** against the **real controller**, and asserts
`progress.standing.of === 2`. That is a genuine call-site test, not a hand-built prop array — the M21/M22/M24
lesson already applied by whoever wrote it. It changes in this PR, deliberately and visibly.

**Two mutations, red sets checked disjoint**, per the standing rule: (1) never-withhold — `of` always
emitted; (2) never-emit — `of` always null. The mechanism is committed before either is applied, the tree is
asserted green first, and the bytes are saved and compared back by sha256 (`git checkout --` is not a
restore).

### Predicted gate movement

**Pest moves** (assertions change; new negative cases added). **Vitest moves** (`achievements/index.test.ts`).
**`openapi.json` moves** — one property's type and description. **Storybook axe unmoved** (no design-system
file). **PHPStan unmoved in kind.** **Four host lint gates: Vue/TS counts unmoved, Pint run BEFORE the push.**

⚠️ **E2E MUST NOT MOVE, AND THE REASON IS MEASURED RATHER THAN HOPED.** `responsive-axe.spec.ts:52` states
that *the seeded acme owner holds `dashboard.org.view`*, so every scanned page renders the **granted** branch
of this gate and the visible markup is byte-identical for that user. **551 passed + 10 skipped is a
prediction this claim commits to**, and a move in it means the seeded role is not what that comment says.

---

## RELEASED — M24, the backfill that scored two review verbs the live engine never scores (landed on `main` as `be55d16` + `d1d1d72`, CI 6/6 green, NO PR — see the deviation above)

**Taken 2026-08-26.** Branch `m24-backfill-review-verbs`, cut from `origin/main` at `096d134`, PR into
`main`. Row: the first `major` under **Gamification** in `docs/feature-backlog.md` — *the backfill awards
review points for two verbs the live engine never scores*.

**⚠️ NUMBERED `M24`, AND THE REF WAS RE-READ IMMEDIATELY BEFORE THIS FILE WAS WRITTEN, NOT AT SESSION
OPEN.** Both reads return `096d134`; `docs/claims/lane-a.md` reads **ACTIVE CLAIM `M23`** in both, with two
pushed claim extensions. `M22` is merged (PR #213) and released. So the next free number is `M24`.

**No collision on the merits, and the reason has to be argued rather than asserted.** Standing Rule 7(b)
grants Lane B `app/Services/{Sso,Tenancy,Connectors}/` — **not** `app/Services/` wholesale, and the rule
that makes the table hold says so explicitly. `app/Services/Gamification/` is therefore **unclaimed, and
taken per-row here**, which is exactly what that paragraph provides for. Lane A's `M23` is entirely
`resources/js/**` and `packages/design-system/**`. The two columns do not meet: this diff contains no
`.vue`, no `.ts`, and nothing under `resources/`. The only overlaps are shared artefacts by disjoint
region — `docs/feature-backlog.md` (Lane A closes the four **App UI** rows; Lane B closes one under
**Gamification**) and `PROGRESS.md` (own block only, per 7(d)).

**⛔ THE DELIBERATE RE-ORDER IS PRESERVED, NOT SILENTLY UNDONE.** The higher-priority sibling —
*`standing.of` discloses the workspace headcount with no permission at all* — is **not** taken here. Gating
`of` changes what `AchievementsController` puts in the Inertia props, and `resources/js/Pages/
achievements/Index.vue` renders them and is **inside Lane A's ACTIVE `M23` claim**, which 7(b-bis) says is
the one split that cannot work. It is taken the moment `M23` merges, and it carries a genuine product call
for `docs/claims/decisions.md` (ADR-0020 §D7 approves *"4th of 12"* for everyone; the dashboard `null`s the
identical integer without `dashboard.org.view`; one of the two has to move).

### ⛔ NAMESPACES

**ADR-0020 gains `§D12`** — its own sub-decision series runs §D1–§D11 and this is a correction to **§D10's
own factual claim**, filed beside the decision it corrects on the precedent §D10 itself used to correct §D5
(*"a cross-document discharge nobody would find"*). ⛔ **ADR `0022` STAYS FREE and stays Lane A's
block-opener (`0022-0025`)** — fourth increment running to amend rather than mint, on the M18/M21/M22
reasoning: minting a number to restate an accepted decision's premise spends the scarcer namespace for
nothing. **No migration** (block `2026_08_17_000111` stays free — this reads an existing `jsonb` column and
adds no column, no index and no table). **No ADR-0016 `§D35`.** No threat-model row. `0010` stays reserved
for H1d; `#16` stays free.

### Every file this claim touches, named before it is opened

Edited, all inside the per-row claim above:

- `app/Services/Gamification/AuditReplayMap.php` — the discriminator and a new pinned constant.
- `app/Services/Gamification/ReplayableAudit.php` — one added readonly field.
- `app/Services/Gamification/GamificationBackfill.php` — `AUDITS_SQL` gains one scalar extraction; the
  single construction site follows it.
- `tests/Unit/Gamification/AuditReplayMapTest.php` — the `replayRow()` helper gains one optional
  parameter, new cases are added, and **one existing case is rewritten** (see below).
- `tests/Feature/Gamification/BackfillTest.php` — the archive / under-review cases, and the call-site test.
- `docs/adr/0020-gamification-awarding-substrate.md` — §D12.

Shared, claimed here: `docs/feature-backlog.md`, `docs/claims/lane-b.md`, `PROGRESS.md` (Lane B's block and
hand-off line only). **If this list grows, the claim is extended as its own pushed commit before the file
is opened.**

### What was verified against the code BEFORE this claim was written

The streak is eleven-for-eleven on rows being wrong about themselves, so every citation was re-walked
against the tree at `096d134`. **The row's file:line facts all HOLD. Its framing and its implied cost do
not.**

- **`AuditLogger::record()` does NOT diff, and this is the load-bearing check.** `:78-96` is a
  `forceFill` of the whole `$new` array — there is no old-vs-new comparison and no null-stripping anywhere
  in the class. Had it diffed, an `archive()` with no remarks argument would leave `remarks` unchanged, the
  key would never appear, and the row would have been substantially wrong. It does not. **The premise
  holds for all four verbs, including the ones that pass no remarks at all.**
- **`AuditReplayMap.php:160`** is the `in_array(self::REVIEW_MARKER, …)` return, exactly as cited.
  `SubmissionReviewService.php:156` opens the `$this->audit->record(` call (the row says `:156-162`; the
  call actually closes at `:163`). `snapshot()` is `:189-201` with `remarks` at `:199` (the row says
  `:189-200`). Both off by one line, neither materially.
- **`AuditRedactor::PII['submission']` (`:76`)** is `['guest_ip', 'guest_user_agent',
  'guest_contact_email', 'remarks']`. **`status` is not redacted**, so it survives verbatim on every
  historical row — which is what makes a read-side fix possible at all.
- **`PointRule::SubmissionReviewed` is 3 points** (`PointRule.php:75`). 400 × 3 = 1,200. Arithmetic holds.
- **The idempotency index** is `(tenant_id, user_id, rule, subject_type, subject_id)`
  (`2026_08_17_000101_create_point_awards_table.php:70-72`) with `ON CONFLICT DO NOTHING`
  (`PointsRecorder`), and `point_awards` has **no UPDATE or DELETE policy** (ADR-0020 §D4). Both halves of
  the row's consequence claim hold.
- **Exactly two writers of `('submission','updated')` today** — `SubmissionReviewService:156` and
  `SubmissionAnswerEditService:263`. The map's own docblock asserts this, and it is still true, which is
  what makes the fix safe rather than merely plausible.
- **`ReplayableAudit` has exactly two construction sites** — `GamificationBackfill:217` and the
  `replayRow()` helper at `AuditReplayMapTest:34`. The blast radius is genuinely that small.

### ⚠️ WHERE THE ROW IS WRONG ABOUT ITSELF, AND BOTH WAYS MATTER

**(1) IT NEVER SAYS WHICH TWO VERBS ARE *GOOD*, AND THE OBVIOUS READING IS A REGRESSION.** The title says
*"two verbs the live engine never scores"*, which invites the fix *"score only `approve`"*. Measured, that
is wrong: **`AwardPointsForSubmissionReturned` awards `PointRule::SubmissionReviewed` too**, from
`SubmissionReturned`. The live engine scores **two** verbs — `approve` **and** `return` — and a fix that
kept only `approve` would silently stop crediting every returned submission in every backfill, which is
the same class of silent under-scoring the row exists to complain about, pointed the other way.

**(2) ITS IMPLIED COST IS UNDERSTATED — M22'S LESSON, RECURRING IMMEDIATELY.** M22's row had exact facts
and a wrong difficulty estimate; this one does too, in the opposite direction. The row reads as a predicate
bug in one `match`. It is not. **`ReplayableAudit` carries key NAMES ONLY, deliberately and with an argued
docblock** (*"a compliance ledger's redacted contents have no business travelling through a scoring
service"*), and **all four verbs emit an identical six-key set** from one shared `snapshot()`. Key shape is
therefore **structurally incapable** of telling them apart — the map is not reasoning badly, it is being
handed insufficient evidence. The fix has to widen what the enumerator extracts (`GamificationBackfill`'s
`AUDITS_SQL`, whose `jsonb_object_keys` projection has its own *"is not a convenience"* docblock), which is
why this needs an ADR amendment and not a one-line change.

**(3) THE INFLATION IS BOUNDED, WHICH THE ROW'S ARITHMETIC GLOSSES.** The unique index is keyed on
`(…, rule, subject_type, subject_id)`, so at most **one** `SubmissionReviewed` per (member, submission).
"400 archived rows → 1,200 points" is therefore true only for submissions that actor never legitimately
reviewed — which is exactly a retention sweep over never-reviewed rows, so the headline survives, but the
mechanism is not the one the sentence describes.

### ⛔ THE ADR IS WHERE THE DEFECT CAME FROM, AND THAT IS WHY §D12 IS THE RIGHT SHAPE

ADR-0020 **§D10(a)** says, in the sentence that specifies this very map: *"a review always carries
`remarks`; an edit carries flattened `answers.<key>` entries and never does."* Every clause is true. The
sentence is still the bug: **all four review verbs carry `remarks`**, and §D10 never noticed that
*"a review"* was four different acts of which two score. The map was built from that sentence and is a
faithful implementation of it. Correcting the code without correcting the sentence would leave the next
reader to rebuild the same defect from the same authority — which is precisely the argument §D10 made when
it corrected §D5 in place.

### ⚠️ THE GATE, INCLUDING THE ONE EXISTING CASE THAT MUST GO RED

`AuditReplayMapTest:101` (*"reads a real review payload as a review"*) hands the map the six snapshot keys
with **no status at all** — which is equally a real `approve`, a real `archive` and a real
`markUnderReview` payload. **That test cannot fail on this defect, and after the fix it is wrong as
written**; it is rewritten in the same commit rather than deleted. `:184-197` pins `SCORED_PAIRS` in both
directions, so removing the tuple is not available — the fix must live inside `submissionUpdate()`.
`:194` probes that tuple with `['answers.x']` and stays green either way. And `:113` already warns, in a
comment, that *"a discriminator that keyed on `status` alone would call this a review"* — so the predicate
is `remarks` present **AND** status in the scoring set, with the existing `answers.` precedence untouched.

**⛔ AND THE GATE THAT WOULD HAVE CAUGHT THIS DOES NOT EXIST, WHICH IS M21/M22'S LESSON THIRD TIME
RUNNING.** `BackfillTest` proves the review/edit split with `replayAudit()`, a **hand-authored** row — and
its review fixture happens to carry `'status' => 'approved'`, so it is green before and after. **No test
anywhere drives the real `SubmissionReviewService` and then runs the real backfill over the rows it
actually wrote.** A unit test proves what a function does when called; only a call-site sweep proves the
production payload is the shape the unit test assumed. That test is added here, and it is the one that
fails on `main` today.

### ⚠️ CLAIM EXTENSION 1 — the file list is UNCHANGED; the row's magnitude story is not

**No file is added or dropped.** This extension exists because an adversarial pass over the row's
*consequence* half changed what the row means, and the claim above asserted one figure that does not
survive. Recording it before the fix is written, rather than discovering it in the backlog wording
afterwards.

**⛔ THE 400-ROW RETENTION SWEEP DOES NOT EXIST. THE FIGURE IS FICTIONAL AND THE CLAIM ABOVE REPEATED IT.**
`SubmissionReviewService::archive()` has exactly **one** caller in the entire tree —
`app/Http/Controllers/Tenant/SubmissionReviewController.php:35` — one submission per HTTP request, from
the inbox detail view, by one authenticated human. There is no bulk archiver, no retention job, no
scheduler entry, no `/api/v1` route. "One retention sweep of 400 archived rows" would be **400 individual
clicks by one reviewer.** The defect is real; the illustration is invented. The claim above said
*"400 × 3 = 1,200. Arithmetic holds"* — the arithmetic holds and the premise it multiplies does not.

**⛔ AND THE ROW LEADS WITH THE WRONG VERB.** Measured against the idempotency key, `archive` is **partly
self-cancelling** and `markUnderReview` is the larger leak:

- The happy path is already safe. Claim → approve by the *same* reviewer writes three audit rows but
  **one** award, because both live listeners key on `('submission', $submissionId)` with the reviewer as
  user — the identical key the backfill builds. The unique index collapses them.
- The real inflation is only where a reviewer touched a submission they never approved or returned:
  an **abandoned `under_review` claim**, which is a routine queue state rather than an edge case, or
  archiving somebody else's work. So the row's *"every archive scores"* framing **overstates**, and its
  silence on stale claims **understates**. The true magnitude is `3 × |{(actor, submission)}|` where that
  actor's only qualifying row for that submission is a claim or an archive.

**⛔ THE BADGE MECHANISM IN THE ROW IS WRONG, THOUGH ITS CONCLUSION IS RIGHT.** `BadgeAwarder::awardsOf()`
(`:213-227`) counts **award ROWS of one rule**, and `:187` compares that count against
`BadgeKey::threshold()`. `Reviewer` is **50** (`BadgeKey.php:117`) — fifty *awards*, not 1,200 *points*.
The row's phrasing invites the reading that a point total crosses a threshold; nothing anywhere reads a
point total for badging. Worth correcting because `FirstReview` is threshold **1**, so the very first
spurious award already mints a badge — which is a sharper statement of the same harm than the row's.

**⚠️ TWO READERS THE ROW MISSES, AND ONE OF THEM IS WORSE THAN THE LEADERBOARD.** Beyond `TeamProgress`
and the ladder (both confirmed, neither filtered by `rule`), the inflation also reaches
`BadgeShelfService:79` (per-rule counts) and **`StreakCalculator:53`, which walks `DISTINCT
awarded_at::date`**. The backfill stamps `awarded_at` with the *act's* date (ADR-0020 §D5), so spurious
awards manufacture spurious **streak days** — a member's longest streak becomes partly a record of days
they archived things. That is not recoverable either.

**⛔ AND THE PERMANENCE IS WORSE THAN "NO DELETE POLICY".** ADR-0020 `:127` names adding a DELETE policy as
an explicit **reversal trigger** for the ADR, and §D4 argues the absence as a feature. So the inflation is
not merely *undeleted by default* — it is uncorrectable without a migration that the ADR and a test both
argue against. The row understates this, and it is the strongest reason to fix the map before anyone runs
the command rather than after.

**⛔ ONE TRAP FOR THE IMPLEMENTATION, FOUND BEFORE IT WAS FALLEN INTO.** `AUDITS_SQL` already carries a
`LEFT JOIN submissions s` (`:88`), so `s.status` is one word away and is the **wrong answer**. That is the
submission's *current* status, not its status when the row was written: a submission approved and later
archived reads `archived` today, so keying on it would erase the **legitimate** award and keep nothing.
The value must come from `new_values`, which is the historical record. Stated here so that the cheaper
join is visibly rejected rather than never considered.

**✅ AND THREE THINGS THAT SURVIVED THE ADVERSARIAL PASS INTACT**, which is worth recording because the
whole point of the pass was to break them: `return` really does score `SubmissionReviewed` live; the
`answers.` precedence loop really does have to stay first, and **becomes load-bearing for the first time**
once the fix keys on `status`, because both writers emit that key; and a no-answer-change edit really is
reachable (`answerDiff()` returns `[]` with no guard) but can never carry `remarks`, so it is not a third
ambiguity — it maps to `unmapped`, exactly as the map's docblock already anticipates.

### ⛔ CLAIM EXTENSION 2 — a THIRD and FOURTH writer of the tuple exists, and it is not in `app/`

**Still no file added or dropped.** This extension records the one refutation that survived the
adversarial pass, because it changes what the fix's shape *means* even though it does not change the fix.

**`AuditReplayMap`'s own docblock and ADR-0020 §D10(a) both say `('submission','updated')` is written by
TWO services. That is true of `app/` and FALSE of the table the backfill reads.** Verified firsthand at
`database/seeders/DemoSeeder.php:1027-1029`, which writes that tuple with
`new_values = ['status' => 'approved', 'guest_contact_email' => …]` — **status-bearing and marker-less** —
and `E2eSeeder` writes the same shape through the real logger. A grep of `app/` cannot see either, which
is exactly how the count stood at two for three increments and how both authorities came to be confidently
wrong in the same direction.

**⚠️ AND THOSE ROWS ARE FULLY CREDITABLE, WHICH IS WHAT MAKES THIS A NEAR-MISS RATHER THAN A CURIOSITY.**
In the seeded database they carry a real `tenant_id`, a real non-null `user_id`, and a submission the join
resolves — and there is no pre-existing `submission.reviewed` award on that subject, so the idempotency
index would not refuse them either. Had the fix been written as *"score when status is `approved` or
`returned`"* **replacing** the `remarks` marker — which is the obvious simplification, because a status of
`approved` reads like proof enough on its own — the very first `gamification:backfill` on a demo tenant
would have minted a **brand-new** false award plus the `first_review` badge at threshold 1, permanently,
per §D4. That would have been a worse defect than the one being fixed: this one at least required a real
human to have clicked something.

**✅ THE FIX AS WRITTEN ALREADY HANDLES IT, AND THAT WAS LUCK RATHER THAN FORESIGHT.** The predicate is
conjunctive — the `remarks` marker is tested first and returns `null` when absent, and only then is the
status consulted — so a seeder row maps to nothing exactly as it did before M24. **What was missing was
any test that said so.** The pre-existing *"refuses to guess when a submission update carries neither
marker"* case leaves the status null, so it does not reach this shape at all. One unit case is added
pinning the seeder's exact payload, and it is the case a future *"these two checks are redundant, keep the
status one"* would break.

**So the conjunction is promoted from an implementation detail to a decision**, recorded as ADR-0020
§D12(c-bis), and the map's docblock is corrected in place rather than left saying "two".

⚠️ **THE GENERAL LESSON, WHICH IS THE REASON THIS IS WRITTEN DOWN AT ALL: the backfill does not read
application code, it reads `audits`.** Every census of "who writes this tuple" taken by grepping `app/`
is a floor and not a census, because seeders, factories and fixtures write the same table and their rows
are indistinguishable from real ones once written. That is the same shape as Standing Rule 7(b-bis)'s note
that its paired-file sweep matched literal path strings and is therefore a floor — a second instance of
the same reasoning error, found in a different subsystem on the same day.

### ⚠️ AND THE MUTATION HARNESS WAS RUN TWICE, WITH DISJOINT RED SETS

The mechanism was committed at `be55d16` **before** either mutation, the tree was proven green first
(AuditReplayMapTest 35, BackfillTest 16), and the restore was by **saved bytes compared back by sha256**
(`ec347436…9755c` both times) rather than by `git checkout --`.

- **Mutation A — the gate never refuses** (`return true` in place of the status test): **5 red.** Both
  `does not score` cases, the null-status refusal, and **both call-site feature tests**.
- **Mutation B — the gate never accepts** (`return false`): **5 red.** The approved case, the returned
  case, the pre-existing review-vs-edit feature case, and the ledger-vs-table case.

**Four red each way, and the sets intersect in exactly one test** — the call-site case that deliberately
asserts both directions in one body. A gate proven only by its refusal is indistinguishable from one that
refuses everybody; this one is proven in both directions, and the two call-site tests reddening under
mutation A is what makes the wiring asserted rather than assumed.

### Blast radius, stated rather than discovered

**`openapi.json` must stay byte-identical** — no `/api/v1` route, resource or controller is touched
(`MemberProgressResource` and `GamificationController` belong to the *next* row, not this one).
**Expect Pest to move and Vitest not to** — the mirror image of M22. No `.vue` file, so no
`docker restart` of the node container and no Storybook axe movement. **No paired file is reached**:
`ShellAbilityParityTest` fires on a new ability key (none is minted), `NotificationTypeParityTest` on a new
`NotificationType` (none), and `clipped-node-containment.test.ts` scans `resources/` (untouched).
`tests/e2e/responsive-axe.spec.ts:54` drives `/achievements`, but as a rendering and axe gate that asserts
no point values — grepped before claiming, not assumed.

---

## RELEASED — M22, the guest device's missing enumerator and reaper for abandoned answer content (merged as PR #213, `d4013c0`, 6/6 green)

**Taken 2026-08-26, merged the same day.** Branch `m22-guest-orphan-reaper`, cut from `origin/main` at `88ba1e8`,
merged into `main` as PR #213 (`d4013c0`), 6/6 green with every job's step count read individually (11-20, none empty). Row: the `major` in `docs/feature-backlog.md` — *"The guest device has no enumerator and no reaper
for abandoned answer content"* — filed by M21 the moment M21 decided not to fix it.

**⚠️ NUMBERED `M22`, AND THE REF WAS RE-READ TWICE.** The opening fetch and the fetch taken immediately
before this file was written both return `88ba1e8`, and `docs/claims/lane-a.md` reads **ACTIVE CLAIM
`M20`** on branch `m20-ds-merge-gate` in both. `M21` is merged and released (PR #211, `15dc10b`). So the
next free number is `M22`, not `M21` and not `M20`. M21 measured the opening fetch going stale in four
minutes; this claim was written from a read taken minutes before the push, not from the session-open read.

**No collision on the merits.** Lane A's `M20` is wholly inside
`packages/design-system/src/components/{Combobox,DataTable,PasswordStrength,Checklist}/`. This row is
wholly inside `resources/public-runtime/`, which Standing Rule 7(b) grants Lane B outright. The two
columns do not meet. The only overlap is `docs/feature-backlog.md`, by disjoint region — Lane A closes
rows under **Design system**, this closes one under the guest-runtime review — so it rebases as a merge.

---

### What was verified against the code BEFORE this claim was written

The row is M21's own, which is **no protection at all** — M15 wrote the row M21 took, and that row argued
the fix was impossible on two grounds and was wrong on both. So both halves were re-walked:

**(a) `media_queue` orphans — CONFIRMED.** `lib/media-queue.ts:40` `put`s a row and the caller supplies
`client_submission_uuid: null` for a mid-fill pick. The table's only two deleters are
`lib/outbox.ts:107` and `:159`, both `db.media_queue.where('client_submission_uuid').equals(uuid).delete()`
against a uuid **string**. A `null` value is not merely unequal to that string — **IndexedDB does not index
`null` at all**, so an orphan row is absent from the `client_submission_uuid` index and no `where()` on it
can ever reach the row, by any argument.

**(b) `draft_answers` pre-republish orphans — CONFIRMED.** Two readers (`App.vue:249`,
`useAutosave.ts:229`), two writers (`:153`, `:279`), one deleter (`:196`, `db.draft_answers.delete(pk)` for
the *current* pk only), and **no `where`/`orderBy`/`toArray`/`each`/`count` anywhere in the tree**. The
seven-day TTL is a branch inside `restore()` against the key it just fetched, so a row whose
`form_version_id` moved under a republish is never written, read or deleted again.

**⛔ ONE THING THE ROW DOES NOT SAY, AND IT CHANGES THE COST.** `draft_answers` is declared
`'[form_version_id+local_draft_id], form_version_id, updated_at'` (`lib/db.ts:195`) — **`updated_at` is
already a secondary index.** A TTL sweep over it is `where('updated_at').below(cutoff)`, not a table scan,
and it needs no `db.version()` bump. `media_queue` is `'attachment_local_id, client_submission_uuid,
status'` and has no time index, but `status` is indexed and every orphan is `queued`. So the enumerator
this table "has never had" costs no schema change on either store — which is a strictly better position
than the row assumed, and is why this is one increment rather than a migration.

**⚠️ A `db.version(3)` IS OFF THE TABLE AND THE REASON IS MEASURED.** `db.test.ts` asserts
`expect(db.verno).toBe(2)`, and `node_modules/dexie/dist/dexie.js:3832` throws
`Upgrade('Not yet support for changing primary key')` — the database fails to open on every device that
already has one. Un-indexed fields need no bump; a new **store** would. This increment adds neither.

---

### The hard half is a decision, and it is filed rather than guessed

*How long, on what trigger, and whether it runs in the service worker* is a genuine product call, so it
goes to `docs/claims/decisions.md` with a recommendation and this lane proceeds on that recommendation
rather than idling — Standing Rule 5. ⛔ **The service-worker half is not a preference, it is a
constraint**: `tsconfig.sw.json` re-checks `sw.ts`'s import graph with `types: []`, and that graph already
contains `lib/db.ts`, `lib/replay.ts`, `lib/outbox.ts` and `lib/media-queue.ts`. `sessionStorage` does not
exist there, so a visit cannot be read and `lib/respondent-session.ts` can never be imported into it.

**⚠️ THIS IS A RETENTION DEFECT, NOT A DISCLOSURE ONE.** M21 closed every read that could surface this
content: `listForSubmission` is by uuid, a `local:` ref only renders if the answers are restored, and
both `draft_answers` readers now refuse a foreign row through `draftBelongsToVisit`. It will not be
re-filed or re-argued as a security row.

---

### Shared and paired artefacts this claim touches

- `docs/feature-backlog.md` — closes one row under the guest-runtime review; Lane A's `M20` closes three
  under **Design system**. Disjoint regions, so **rebase before push and it is a merge, not a collision**.
- `docs/claims/decisions.md` — appends the retention decision as `D3`. `D1` (the sixteen `ShouldQueue`
  listeners) is not touched and is not this lane's to take.
- `PROGRESS.md` — Lane B's own status block and Lane B's own hand-off line only, per 7(d).
- ⛔ **`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` is NOT touched.**
  It is Lane A's tree and lists `RuntimeShell.vue` and `SyncStatus.vue` in an exact-equality
  `KNOWN_UNGUARDED`. This increment adds and removes no `clip: rect(0 0 0 0)` in `resources/public-runtime`,
  so the list must not move — and that will be **proved by running the design-system chunk**, not assumed.
  `NotificationTypeParityTest` and `ShellAbilityParityTest` are unreachable without a new `NotificationType`
  or ability key; neither is minted here.

**Namespaces unspent so far:** migration block `2026_08_17_000111` (nothing server-side moves here),
ADR-0016 `§D35`, ADR `0022` — which **stays free and stays Lane A's block-opener**; if this increment
needs an ADR the reasoning belongs as a sub-decision of `ADR-0021`, whose subject is exactly the
respondent-scoped device stores this row lives in. `0010` stays reserved for H1d; `#16` stays free.

**Baseline this branch starts from (`origin/main` @ `88ba1e8`, after M21):** CI Pest **4544 / 19,280**
(2 pre-existing warnings) · Vitest **130 files / 2,236** (design-system 35/545 · **public-runtime 35/805**
· resources/js 60/886) · axe **42 / 299** · E2E **551 passed + 10 skipped, no flaky line** · PHPStan CI
`[OK]`, local **18 against the FILE LIST** · four host lint gates **97 · 113 · 31 · 113/121/0** ·
`openapi.json` byte-identical. ⚠️ **This row is again `.ts` under `resources/public-runtime/`, so expect
the public-runtime chunk to move and Pest not to** — the chunk is what gets re-measured, never the total.

---

### What the gates actually measured, against what this claim predicted

Every prediction in the paragraph above held, and the one that mattered most was the narrowest: **only the
public-runtime chunk moved.**

| Gate | Predicted | Measured (CI, PR #213) |
|---|---|---|
| Vitest | +1 file, +14 tests, public-runtime only | **131 files / 2,272** — public-runtime **36/819**, design-system 35/567, resources/js 60/886 ✅ |
| Pest | unmoved, no PHP in the diff | **4,544 / 19,280 assertions, 2 pre-existing warnings** — unmoved to the digit ✅ |
| Storybook axe | unmoved | **42 / 303** ✅ |
| E2E | unmoved, **no flaky line** | **551 passed + 10 skipped**, no flaky line ✅ |
| `openapi.json` | byte-identical | Contract tests green, 16 steps ✅ |
| PHPStan + four lint gates | unmoved | Static analysis green (18 steps); gates re-measured **97 · 113 · 31 · 113/121/0** unpiped on the host ✅ |

6/6 with every job's step count read individually — 16 · 18 · 12 · 20 · 11 · 11, **none empty**.

⛔ **THE PAIRED FILE WAS RUN, NOT ASSUMED.** `packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts`
lives in Lane A's tree, scans `resources/public-runtime` off disk, and asserts `KNOWN_UNGUARDED` with exact
equality **in both directions** — so it reddens whether the list grows or shrinks. This increment adds and
removes no `clip: rect(0 0 0 0)`, and that was proved by running the chunk: **3/3 green, including its
anti-vacuity case.** The other two paired files were unreachable (no new `NotificationType`, no new ability key).

### The mutation harness ran twice, and the disjoint red sets are the finding

M21's lesson was that a gate proven only by its refusal is indistinguishable from one that refuses
everybody. M22 makes that mechanical: **one mutation is not enough.**

| Mutation | Red | Green |
|---|---|---|
| **A** — `reapAbandoned()` returns `{0,0}` without doing anything | **8 of 14**, every *"collects"* case, **including both call-site tests** | all six *"spares"* cases — a do-nothing reaper trivially spares everything |
| **B** — drop the `live.has()` reachability spare | **exactly 2**, the two reachability cases | the other 12, including every *"collects"* case |

**Neither red set contains a member of the other.** A reaps-nothing bug and a reaps-everything bug are
therefore both caught, by different tests, which is the property a single mutation cannot demonstrate.
`lib/reap.ts` was restored from **saved bytes** and confirmed **sha256-identical** after each mutation —
never `git checkout --`, per the M9 lesson.

⚠️ **AND THE TWO CALL-SITE TESTS ARE THE DIRECT DESCENDANT OF M21's DEEPEST FINDING.** *A unit test proves
what a function does when called; only a call-site sweep proves that it is called.* M15 shipped a docblock
claiming `respondentSession()` refreshed its stamp "on every read", pinned it in a green unit test, and ran
a whole increment while the runtime made that read once, at boot. So this increment's wiring is asserted
through the real entry points — `createSyncOutbox(...).refresh()` and `replayOutbox()` — and deliberately
against **real elapsed time**, because an injected clock is the one thing the production callers do not
have. Mutation A reddened both, which is what makes the wiring measured rather than assumed.

### Filed rather than fixed, the moment the decision was made

One `minor`: **a media pick made during a conflict review is protected only by the grace window.** That
session runs `createAutosave` with `enabled: false` (G8c, deliberately), so it writes **no `draft_answers`
row at all** and nothing on disk names its `local:` ref until the resubmit — which is why
`MEDIA_ORPHAN_GRACE_MS` is an hour rather than the five minutes the 800 ms autosave debounce would justify.
It fails safe (`needs_attention`, *"queued media is incomplete"*) rather than silently. The fix is to stop
the review session being invisible to the mark set, not to lengthen the window.
---

## RELEASED — M21, the abandoned draft that is restored into the next respondent's form (merged as PR #211, `15dc10b`, 6/6)

**Taken 2026-08-26.** Branch `m21-draft-respondent-scope`, cut from `origin/main` at `336d295`, PR into
`main`. Row: `docs/feature-backlog.md:1099` — *"An abandoned local draft is restored into the NEXT
respondent's form, silently, with their answers on screen"* (`major`) — plus the `minor` immediately
below it, *"`reconcile.ts:43`'s local-wins note tells a respondent a stranger's answers are theirs"*,
which the row itself says is closed by closing the major.

**⚠️ NUMBERED `M21`, NOT `M20`, AND THE PROTOCOL WORKED FOR THE THIRD TIME IN THREE DAYS.** This session
opened on a `git fetch` at `336d295`, where **both** lane files read `NO ACTIVE CLAIM`, and reasoned — as
M18's claim did, correctly on what it could see — that the next free increment number was mine. It was
right when read. **Lane A pushed `claim(M20)` at 10:34:46 while this lane was still in read-only recon**
(`987c571`, then extensions `17da814` and `f3fc136`), so **M20 is Lane A's and this is M21.** Caught by
re-reading the ref immediately before writing this file rather than trusting the opening fetch, which is
the whole of 7(g). ⛔ **THE COST OF THE OPENING FETCH IS NOW MEASURED RATHER THAN ARGUED: it was stale
within four minutes.** Recon took forty; a claim written from a forty-minute-old read of `origin/main`
would have collided on the number and been discovered at push time.

**No collision on the merits.** Lane A's M20 is *"the three design-system merge-gate rows"*, wholly inside
`packages/design-system/src/components/{Combobox,DataTable,PasswordStrength,Checklist}/`. This row is
wholly inside `resources/public-runtime/`, which 7(b) grants Lane B outright. The two columns do not meet.

---

### What was verified against the code BEFORE this claim was written

**The defect is LIVE, and the walk is four statements long.** `RuntimeSession.vue:142-162`'s `onMounted`
returns early only for a resume seed (`:145-153`); on plain entry `props.initialAnswers` is `undefined`,
so `:155` calls `autosave.restore()`, `:157-159` push the result into `runtime.restoreAnswers()`, the
locale and the step. `useAutosave.ts:139` reads `db.draft_answers.get(pk)` where `pk` is
`[options.formVersionId, options.slug]` (`:73`) — **no respondent dimension** — and `fresh()` at `:128-134`
gates on the schema checksum and a seven-day TTL (`:14`) and on nothing else. Respondent A abandons;
respondent B loads the same share link on the same browser; **B's fields render A's answers with no
banner**, because `WelcomeBackBanner` is `v-if="resume && resume.greet !== false"` (`:481`) and there is
no resume seed on plain entry.

**⚠️ SIX OF THE ROW'S TEN CITATIONS HAVE DRIFTED, AND THE DRIFT IS UNIFORMLY *FORWARD* — the file grew
under them.** Every claim is TRUE; six of the line numbers are not, so a reader who trusts them lands in
the wrong function.

| The row says | Actually at | Verdict |
|---|---|---|
| `useAutosave.ts:14` — 7-day TTL | `:14` | **holds exactly** |
| `useAutosave.ts:136-155` — the `draft_answers` read | `:136-182`; the `get(pk)` is `:139` | holds; the range stops mid-function |
| `db.ts:127` — the compound key | `:130` declares `Table<DraftRow, [string, string]>`; `:139` is the index string | **off by 3 / 12** |
| `RuntimeSession.vue:149-156` — `autosave.restore()` | `:155`, inside `onMounted` at `:142-162` | holds; range straddles |
| `RuntimeSession.vue:262`, `:313`, `:344` — `clear()` on the submit paths | `:267`, `:322`, `:353` | **off by 5 / 9 / 9** |
| `RuntimeSession.vue:471` — the banner's `v-if` | `:481` | **off by 10** |
| `App.vue:230` — the respondent-blind resume read | `:249`; `:230` is `loadResume()`'s signature | **off by 19** |
| `reconcile.ts:43` — the local-wins note | `:43` | **holds exactly** |
| `WelcomeBackBanner.vue:47` — renders the note | `:47` | **holds exactly** |
| `useAutosave.ts:14` — the TTL as the only other gate | plus `fresh()` at `:128-134` | holds |

⛔ **AND THE ROW IS WRONG ABOUT THE ONE THING THAT DECIDES WHETHER IT CAN BE FIXED AT ALL — SO THE
CITATIONS HOLDING IS AGAIN NOT THE ROW HOLDING, WHICH IS NOW TEN-FOR-TEN.** The row defers itself in two
sentences: *"the draft has **no analogue** [to the outbox's degraded surface] — its legitimate feature *is*
cross-visit restore, so scoping it to a visit would delete the feature rather than contain it"*, and
*"telling a personal device from a kiosk needs an operator concept the guest runtime does not have, which
is exactly the unbuilt **Kiosk mode** row — **that row is this one's precondition**."* Both halves are
false, and each is falsified by a document that predates the row.

**(1) THE ANALOGUE EXISTS AND M15 BUILT IT.** `SyncStatus.vue:123-128` renders *"One response from an
earlier session on this device is still waiting to send."* — existence, a count, and no content. That is
precisely the shape this row says the draft channel lacks.

**(2) THE SPEC BOUNDS THE PROMISE EVEN WHERE IT IS LOOSE ABOUT THE MECHANISM.**
`docs/ux/form-filling-ux-flow.md:148` opens *"This save is **session/device-scoped**, not a durable
resume-later feature"* and closes *"deliberately positioned to respondents as 'your progress is saved while
you're filling this out,' **never as 'come back later'** — the latter promise is reserved for §5.2."*
Appendix A #8 (`:336`) repeats the first half as a decision of record. §5.2's emailed resume link is the
sanctioned come-back-later channel and is respondent-bound by a signed token.

⚠️ **AMENDED BEFORE THE FIRST FILE WAS OPENED — THIS CLAIM ORIGINALLY OVERSTATED IT, AND THE ADVERSARIAL
PASS ON ITS OWN EVIDENCE IS WHAT CAUGHT IT.** The first version of this paragraph read *"the spec already
says the draft is session-scoped, so scoping it implements the spec rather than narrowing it"* and leaned on
the words *"session/device-scoped"* as decisive. **The same sentence continues *"so that a reload of the
same browser tab **or the same device's browser** recovers the draft"*** — which authorises the broader
reading in the same clause. So `:148` is **not** the arbiter this claim first made it. What survives is the
narrower and sufficient point: the *promise* is bounded to the fill in progress, and the come-back-later
promise is §5.2's. **The honest justification is ADR-0021's own move** (`:78-85`): a more faithful reading
of a case the spec never considered — every sentence at `:148` is written about *the* respondent, definite
article, singular, and the shared-device case is the one nobody wrote down. ⛔ **Recorded rather than
quietly rewritten, because a claim that overstated its authority and was corrected by its own verification
pass is worth more to the next reader than one that was right the first time.**

**The kiosk-mode row is NOT this row's precondition, and that is refuted by the ADR rather than by the
spec.** `ADR-0021:119-123` considered the device-owner / kiosk-unlock gate and **deferred** it in terms:
*"It needs an operator concept the guest runtime does not have, **and it would leave the defect live until
it was built**."* Kiosk mode adds operator *policy*; the mechanism this row needs is already wired and one
argument short.

### Three defects the row does not mention, found by grepping the shape rather than the row

- **⛔ THE LEAK HAS A SECOND DOOR, AND A FIX THAT GUARDS ONLY DEXIE LEAVES IT OPEN.**
  `useAutosave.ts:157-181` migrates a pre-G8b **localStorage** draft — `meridian:draft:{formId}:{slug}`
  (`:19-21`), also respondent-blind — into Dexie *and returns it* on first restore, reached whenever the
  Dexie `get` returns `undefined` **or throws** (`:153`). Same disclosure, different store.
- **`major` · An abandoned fill's MEDIA is never deleted, on the hardware `outbox.ts` names as the
  threat.** `media_queue` rows are removed at exactly two call sites, `outbox.ts:107` and `:159`, both
  `where('client_submission_uuid').equals(uuid)`. A blob stashed mid-fill by `App.vue:154-160` carries
  `client_submission_uuid: null` until finalize (`media-queue.ts:45-50`), so an **abandoned** fill's photo
  or signature is orphaned with no uuid, no TTL and no prune — permanently. I10d's own principle is
  *"the moment the server has the data there is no reason for a copy of someone's answers to stay on the
  device"*; here the server never got it, and the copy stays anyway.
- **`minor` · `lib/respondent-session.ts`'s docblock states a mechanism the build does not have.**
  `:26-27` says *"`touch()` runs on every read, and the runtime reads this on every refresh of the sync
  surface, so a respondent filling a long form never crosses [IDLE_MS]."* `respondentSession()` has
  **exactly one** non-test call site — `App.vue:140`, at the composition root, once per page load — so the
  `lastSeen` stamp is never refreshed during a fill. The **conclusion** survives (within one page load the
  id is a constant and cannot rotate at all), but the stated reason is an intention read as a measurement,
  which this project has now recorded four times. The real consequence is one the docblock does not name:
  a respondent who **reloads** after ten idle minutes gets a fresh visit id and loses sight of their own
  outbox rows. Filed, not fixed here.

### The design, and the alternative it rejects

**Stamp `respondent_session_id` on `DraftRow`, un-indexed, and scope the RESTORE — never the write.**
Exactly M15's shape, deliberately, so the runtime keeps one convention rather than two: `sessionId` is an
optional **argument** and never an import (`outbox.ts:31-38` — `lib/db.ts` is in `sw.ts`'s import graph and
`tsconfig.sw.json` re-checks it with no `Storage` type); `undefined` means *do not scope*, preserving every
pre-M21 call shape; and **`null` on a row means an EARLIER visit, never a wildcard** (`db.ts:63-66`).

**No Dexie `version()` bump**, and the document already states the rule rather than my inferring it:
`docs/offline-first-sync-design.md:124` — *"a new STORE requires a version bump, an un-indexed field on an
existing row shape does not"* — the precedent `conflict_code`, `server_reference` and M15's own
`respondent_session_id` all set.

**A draft this visit did not write is never applied silently. It is offered, content-free, or it is not
offered at all.** A matching session id restores exactly as today — which is what keeps
`form-filling-ux-flow.md:148`'s *"a reload of the same browser tab"* working, and is the case
`respondent-session.ts:10-16` chose `sessionStorage` for. A non-matching or null id renders a notice that
names **existence and a date and nothing else**, with an explicit choice.

**⚠️ THE REJECTED ALTERNATIVE IS THE ONE THE ROW ASSUMES, AND IT IS REJECTED ON A MEASUREMENT.** Putting
the session id into the **primary key** (`[form_version_id+local_draft_id]` → three parts) is stricter and
simpler and wrong twice: it needs a `version(3)` with an upgrade that would orphan every existing draft,
and it deletes the honest respondent's recovery outright, because `sessionStorage` dies with the tab. The
row's *"scoping it to a visit would delete the feature"* is a true statement **about that design only**.

### Files claimed

**`resources/public-runtime/` — Lane B's outright under 7(b)'s CRDT grant:**

- `lib/db.ts` — `respondent_session_id` on `DraftRow`
- `composables/useAutosave.ts` — stamp on write, scope on restore, **and close the localStorage door**
- `components/RuntimeSession.vue` — thread the id already injected at `:124` into `createAutosave`
- `App.vue` — scope the resume path's local read at `:249` so a stranger's draft cannot win reconciliation
- `lib/media-queue.ts` — **ADDED AFTER THE CLAIM, and named here rather than absorbed quietly.**
  `attachToSubmission()` re-points an already-owned blob, so the previous respondent's photo or signature
  was uploaded as this submission's attachment. Found by the sweep, inside `resources/public-runtime/`
  which 7(b) already grants Lane B outright — so it needed no extension commit, only this line.
- `lib/respondent-session.ts` — **ALSO ADDED AFTER THE CLAIM, and it is the one that had to be fixed
  rather than filed.** M15's ten-minute window was documented as idle time and was elapsed-since-boot.
  Scoping the draft to a visit turns a wrong visit boundary into LOST WORK, so shipping the scope without
  this would have traded a disclosure for silent data loss on a personal device. **A containment change
  inherits every defect in the boundary it starts trusting.**
- `__tests__/autosave.test.ts` · `__tests__/db.test.ts` · `__tests__/media-queue.test.ts` ·
  `__tests__/respondent-session.test.ts`

⛔ **THREE CLAIMED FILES WERE RELEASED UNTOUCHED, AND ONE OF THEM IS A DESIGN THIS CLAIM GOT WRONG.**

- `components/EarlierDraftNotice.vue` — **NEVER CREATED, AND THE CLAIM WAS WRONG TO PLAN IT.** It was to be
  a content-free "unfinished answers from an earlier visit — Resume / Start fresh" affordance. **The click
  IS the leak**: the runtime has no evidence of ownership to attach to that button, so its copy cannot
  distinguish *"yours"* from *"this device's"* — which is **verbatim the reason the sibling `minor` row
  existed**. It would have converted a silent disclosure into a *consented-looking* one, which is worse for
  the audit trail and no better for the previous respondent. Killed by the adversarial pass over this
  claim's own design, not by a gate. ⚠️ **It would also have made a Lane A file paired**, since
  `clipped-node-containment.test.ts` scans `resources/public-runtime` — so the wrong design was also the
  one that reached across the boundary.
- `lib/reconcile.ts` — released untouched **exactly as the claim predicted it might be**. The `minor`
  closes entirely in `App.vue` by never handing `reconcileDraft` a foreign draft, so `LOCAL_WINS_NOTE` is
  correct English for every case that can now reach it.
- `lib/types.ts` — released untouched. `DraftBlob` is what `restore()` hands its caller; the visit stays
  internal to the table and never needed to reach it.
- `__tests__/components.test.ts` and `__tests__/reconcile.test.ts` — released untouched, and **that is a
  measurement rather than an oversight**: `sessionId` defaults to *do not scope*, so all forty bare
  `RuntimeSession` mounts and all five pure-function reconcile cases behave exactly as before. The
  `undefined`-means-unscoped convention is what bought that, and it was chosen for it.

**Shared artefacts, claimed and never owned:**

- `docs/feature-backlog.md` — see the disjoint-region note below
- `docs/security-threat-model.md:75` — that row's **Residual** paragraph names this exact defect and
  repeats both false premises; it is corrected in the same PR that closes it. **Lane A is not in this file.**
- `docs/offline-first-sync-design.md` — §3's table row for `draft_answers` (`:119`) and a §8 amendment
  beside M15's at `:219-236`, where P3a, M12 and M14 also landed theirs
- `docs/adr/0021-respondent-scoped-device-outbox.md` — **Lane B's own ADR** (M15), amended rather than
  superseded: same decision, second channel
- `docs/ux/form-filling-ux-flow.md` — §5.1 records as-built what `:148` has always specified
- `docs/claims/lane-b.md` — this file
- `PROGRESS.md` — **Lane B's own Current Status block and Lane B's own hand-off line only**, per 7(d)

⚠️ **`docs/feature-backlog.md` IS HELD BY BOTH LANES RIGHT NOW, DELIBERATELY, AND THE REGIONS ARE NAMED SO
THE OVERLAP IS A MERGE AND NOT A COLLISION.** Lane A's live M20 claim takes the three rows under the
**Design system** heading of the merge-gate review. This row takes **`:1099`** and the `minor` directly
below it, and appends the three new findings above. Disjoint by inspection, in a file that appends rather
than restructures — the same reasoning 7(d) already applies to `PROGRESS.md`. **A rebase before the push is
what proves it rather than assumes it.**

**⛔ NOT TAKEN, and each for a stated reason rather than by omission:** nothing in `app/`, `config/`,
`scripts/`, `resources/js/`, `packages/design-system/src/components/`, `ci.yml`, `phpunit.xml`. **No
`/api/v1` route is reached** — the guest draft channel's server half is untouched — so `openapi.json` must
come back **byte-identical**; if it moves, that is a finding, not a rebaseline. `MEMORY.md` is Lane A's.
`tests/e2e/` is claim-first and **is not claimed**: no selector this row can invalidate appears in it —
`public-runtime-offline.spec.ts` has **zero** matches for `draft`, `autosave`, `restore` or `Welcome`, and
its three reloads (`:52`, `:79`, `:225`) all sit inside one browser context, so `sessionStorage` survives
them and the visit id does not rotate. **Grepped, not assumed.**

**PAIRED FILES (7(b-bis)) — CHECKED, AND THE ONE IN RANGE IS ALREADY IN LANE A'S HAND, SO IT IS
DELIBERATELY *NOT* CLAIMED HERE.**
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` scans
`resources/public-runtime` (`SCAN_ROOTS`, `:39`) and asserts `KNOWN_UNGUARDED` (`:71-79`) by exact equality
at `:162`; the public-runtime entry is `RuntimeShell.vue`, which this row does not open. Lane A's M20 claim
says the same file *"is in range"* for them. **Two lanes cannot both hold it, so this design is built not
to move it**: the new component gives its root a `position: relative` if it carries any `clip: rect(0 0 0
0)` node at all, which is the exact fix M15 applied to `.sync-status`. ⛔ **If the list moves anyway, that
is a finding and a coordination event with Lane A — never a silent edit to a file the other lane is
holding.** The other two paired gates need a new `NotificationType` or ability key; this row mints neither,
which is checked and not assumed.

**Namespaces spent: NOTHING from either, and the reasoning is written down before the fact so the
measurement has something to disagree with.** **ADR `0022` STAYS FREE and stays Lane A's block-opener** —
this is ADR-0021's own decision applied to the second channel, and minting `0022` would spend the scarcer
namespace to restate an accepted decision, which is exactly M18's reasoning for not minting it either. No
migration: **`2026_08_17_000111` stays free**, because nothing server-side moves. `0010` stays reserved for
H1d; `#16` stays free; ADR-0016's next free sub-decision stays **`§D35`**.

**Baseline measured on THIS tree at `336d295`, not quoted:** Vitest `resources/public-runtime` **35 files /
782 tests passed**, exit 0, **no `Failed to start forks worker` lines** — the file count checked, per the
trap that fails green. Four host lint gates **97 · 113 · 31 · 113/121/0**, all exit 0, read unpiped.
⚠️ **The lint numbers disagree with the ones Lane A released after M19 (`97 · 111 · 31 · 111/119/0`) and
the difference is exactly M18's two migrations** — their line was written against a pre-M18 tree. Recorded
rather than reconciled quietly, because a gate number moving on a diff that cannot move it is the standing
tell for the other lane's merge.

---

## RELEASED — M18, the SSO email-domain trust anchor (merged as PR #209, `1eae4fc`, 6/6)

**Every claimed file was edited except three, and all three were claimed on a stated prediction that they
might not be.** `app/Support/Tenancy/ConstraintBoundaries.php`, `app/Support/Tenancy/TenantExtractColumns.php`
and `tests/Feature/Sso/SsoStepUpWebTest.php` are absent from the diff — the claim said in words that they were
taken *"on a prediction, not a plan… because a file that might get written to is a file another lane must not
be holding"*, the M17 `DnsRecordBlock.vue` precedent. **Three released untouched by design is the claim
working, not three wasted lines.** The step-up suite needed nothing because that arm never provisions, which
the claim had already reasoned out; the two registries needed nothing because of schema decisions taken
*after* the claim and recorded in its first extension.

**The claim was extended TWICE, each time as its own pushed commit before the file was opened** (`1a14569`,
`a9286f1`): once for `tests/Feature/Tenancy/TenantExtractColumnDriftTest.php`, found by **reading the gate**;
once for `SsoAuthFailureLogTest` and `SsoSettingsWebTest`, found by **running the suite**. The second
extension also records a path deviation rather than absorbing it quietly: the command shipped flat as
`app/Console/Commands/SsoDomainCommand.php`, matching the three commands already there, not in the
subdirectory the claim reserved.

⛔ **ALL EIGHT CITATIONS HELD — THE FIRST ROW IN NINE TO BE RIGHT ABOUT ITS OWN EVIDENCE — AND IT WAS STILL
WRONG IN TWO DIRECTIONS.** The eight-for-eight streak of finding the row's framing wrong ends on the
citations and continues on the substance, which is a distinction worth keeping: *verifying the file:line
claims is not the same as verifying the row.*

**(1) It understated its severity.** *"NOT LIVE — both known exploits are closed"* was too generous. JIT is
permitted to CREATE, and `SsoUserProvisioner::createUser()` stamps `email_verified_at`, defending it as
*"the IdP's claim rather than a convenience"*. **`users` is a DEPLOYMENT-WIDE table**, so that line wrote a
platform-global identity fact from a per-workspace trust root: a paying SSO tenant could mint a `users` row
for any unregistered address carrying a **forged mailbox-control claim**. It did not stay local —
`TenantMembershipService::identityIsEstablished():877` reads that exact column, so the forged stamp fed
**M8's own predicate**, and the address's true owner, invited later by their real employer, was refused the
password-setting arm and handed a sign-in-then-accept hop they could not complete. **Squatting, plus a denial
of the recovery path M8 built.** That docblock is amended in place rather than left reading as settled — its
sentence is true again, **and only because a guard elsewhere now runs first**, which is stated as the
dependency it is.

**(2) It missed a second defect entirely, which the fix's ORDERING closes for free.** The failures panel
renders `existing_account_not_member` as *"Address already has an account elsewhere"* and `jit_disabled` as
*"Nobody here matches that address"* — so an SSO-entitled admin could assert **any** address and read back,
from their own settings page, whether it has an account **anywhere in the deployment**, having proven nothing
about the domain. §D19's uniform 404 was intact the whole time; **the panel was the surface that leaked**, and
§D26 built it precisely because that audience is entitled to answers. ⚠️ **This is the M15 shape recurring:
the row named a disclosure and missed a worse one on the same surface.**

✅ **THE "grandfathering call" THE ROW CALLED *"the part that makes it a decision rather than a feature"* WAS
DISSOLVED RATHER THAN ANSWERED.** It priced a per-connection mode column or a backfill from members'
addresses; **neither is built and neither is needed.** The check sits after the `Active` early return, so **an
active membership IS the grandfather** — no live deployment loses a member on deploy, no public-mailbox
exclusion list is maintained, and no column can be left in the wrong state. ⚠️ **That rests on an ENUMERATED
fact rather than a reasoned one**: the four writers of `TenantUserStatus::Active` were listed and each asked
what it demanded of the person. **Neither rejected alternative would have survived contact** — the backfill
hands a workspace with `gmail.com` members authority over the whole of `gmail.com`, and the "enforce only once
a domain is verified" shape is opt-in security whose switch is held by the threat actor.

⚠️ **THE FIXTURE BLAST RADIUS WAS THE CONTROL WORKING, AND IT MADE M1's AND M9's OWN CASES STRONGER.** Seven
cases across three suites went red because their workspaces had verified nothing — **the same thing that will
happen to every real deployment.** Verifying the fixture domain in `beforeEach` means those cases now certify
*"even inside a domain the workspace has proven it controls, single sign-on will not adopt an existing
account"*. `evil.test` is left unverified deliberately, so the attacker fixture has two independent reasons to
be refused. **The prediction that flagged "a fourth registry" as the most likely error was right about the
SHAPE and wrong about the PLACE** — there was no fourth registry; `TenantScopedTables` and the extract census
were the whole set, and the misses were test fixtures. The tell was exactly as written down: a red gate naming
a file the diff does not touch.

✅ **THREE SCHEMA DECISIONS WERE FORCED BY A GATE AND ARE RIGHT ON THE MERITS, WHICH IS THE GOOD CASE.**
`ConstraintBoundaryDriftTest` pins two censuses by exact equality, so: the table is scoped to the **TENANT**
with no FK to `sso_connections` (a workspace controls a domain, and re-importing IdP metadata must not destroy
the proof); its unique key is **`(tenant_id, domain)`**, so **two workspaces may each verify the same domain**
on their own token — a global unique would have let whichever claimed first deny the other, turning a control
designed to stop squatting into a squatting primitive; and there is **no unique index on the token**, because
nothing looks a row up by one, so it would have been a boundary-crossing key buying nothing. All three
censuses stayed still, as the first claim extension predicted.

⚠️ **THE ORPHANED `docker exec` TRAP FIRED TWICE, THE SECOND TIME ON PHPSTAN RATHER THAN PEST.** The tool's
two-minute timeout kills the host side, the container process keeps running, and the redirect's stream is
already severed — so the result is unrecoverable and the host `ps` count reads **0** while the container reads
**1**. Both times the container probe is what found it. **Every long run went to the background afterwards**,
which is the actual remedy rather than a faster check.

✅ **PINT WAS PROVED NOT BLIND BEFORE IT WAS BELIEVED** — a deliberately misformatted probe under `app/`
returned FAIL with exit 1 and was deleted before the real run, which then passed on 1410 files. **And PHPStan
caught six real errors of mine before CI could**: missing `Collection` generics on `forTenant()`, three
optional-offset reads the analyser cannot prove, and two redundant `?->` on the left of `??`. **Not one was a
`property.notFound` phantom** — which is exactly why the local baseline is measured by FILE LIST and never by
count, and the final run came back at 18 with none of my files in it.

⛔ **WHAT WAS DELIBERATELY NOT BUILT, EACH FILED THE MOMENT THE DECISION WAS TAKEN** (the J4b1 rule, whose
whole point is that a deliberately-unfixed finding recorded only in prose is invisible to a backlog search):
the tenant-facing `/settings/sso` domains card — **a LANE A row**, and the reason `php artisan sso:domains`
exists and is tested; periodic re-verification, where **the demotion rule is the hard half, not the lookup**;
`MemberController::invite()`'s missing domain check, a product decision rather than a cleanup; and
self-registration as the weakest of the four `Active` doors. **Residual 32 is rewritten from "nothing verifies
this" to those three**, so nobody reads M18 as partial.

---

## SUPERSEDED CLAIM (kept for the record) — M18 (`m18-sso-domain-verification`)


Opened: 2026-08-26, cut from `origin/main` at `eb45eb9`. **Numbered M18**: M16 and M17 are Lane A's and
both merged; Lane A holds no claim, so the next free increment number is mine.

Row: `docs/feature-backlog.md:1514` — *"`major` · Nothing verifies that a workspace controls the email
domain its identity provider asserts."* Carried as `docs/security-threat-model.md` **residual 32** and
named as ADR-0016 §D33's revisit trigger.

**Files.**
*New:* `database/migrations/2026_08_17_000109_create_sso_verified_domains_table.php` ·
`database/migrations/2026_08_17_000110_recreate_sso_auth_failures_reason_check_for_unverified_domain_refusal.php` ·
`app/Models/SsoVerifiedDomain.php` · `app/Services/Sso/SsoDomainService.php` ·
`app/Console/Commands/Sso/SsoDomainsCommand.php` · `database/factories/SsoVerifiedDomainFactory.php` ·
`tests/Feature/Sso/SsoDomainVerificationTest.php`.
*Edited:* `app/Services/Sso/SsoUserProvisioner.php` · `app/Services/Sso/SsoAuthenticationException.php` ·
`app/Enums/SsoFailureReason.php` · `app/Support/Tenancy/TenantScopedTables.php` ·
`app/Support/Tenancy/ConstraintBoundaries.php` · `app/Support/Tenancy/TenantExtractColumns.php` ·
`config/saml.php` · `tests/Feature/Sso/SsoAcsWebTest.php` ·
`tests/Feature/Sso/SsoLoginCompletionWebTest.php` · `tests/Feature/Sso/SsoStepUpWebTest.php` ·
`docs/adr/0016-sso-saml-federation.md` · `docs/security-threat-model.md` · `docs/feature-backlog.md` ·
`docs/data-dictionary.md` · `docs/claims/lane-b.md` · `PROGRESS.md` (Lane B's block only).

⚠️ **EXTENDED 2026-08-26, BEFORE THE FILE WAS OPENED — `tests/Feature/Tenancy/TenantExtractColumnDriftTest.php`.**
A new tenant-scoped table joins `TenantScopedTables::STRICT`, which is the list the P2b extractor walks, so its
`EXTRACTED_COLUMN_CENSUS` gains an entry the moment the table exists — and that constant lives in the **test
file**, not in `TenantExtractColumns`. **Found by reading the gate rather than by CI reddening**, and it is
exactly the shape this claim's own prediction flagged as most likely wrong: a registry the `sso_auth_failures`
grep did not reach.

⚠️ **EXTENDED AGAIN 2026-08-26 — two more suites, and this time CI's local proxy found them rather than a
read of the gate: `tests/Feature/Sso/SsoAuthFailureLogTest.php` and `tests/Feature/Sso/SsoSettingsWebTest.php`.**
Four cases across them refuse with `domain_not_verified` where they used to refuse (or succeed) for their
own reason — three of M1's and M9's failure-panel cases, plus P1a's whole-round-trip canary. **Every one is
the control working on a fixture that has not verified a domain, which is the same thing that will happen to
every live deployment**, and the fix is the `SsoAcsWebTest` one: verify the suite's fixture domain in
`beforeEach` so each case keeps certifying what it was written to certify. `evil.test` in the failure-log
suite is deliberately left UNVERIFIED — it is an attacker fixture and now has a second reason to be refused.

⛔ **AND A PATH DEVIATION, RECORDED RATHER THAN QUIETLY ABSORBED: the artisan command shipped as
`app/Console/Commands/SsoDomainCommand.php`, not the `app/Console/Commands/Sso/SsoDomainsCommand.php` this
claim reserved.** All three existing commands in this repository sit flat in `app/Console/Commands/`, so a
one-file subdirectory would have been a new convention introduced by accident. The claim's substantive
property — that no other lane could be holding it — held either way, since the whole directory is
unassigned and Lane A has no console work. Corrected here because a claim nobody can trust to be accurate
is worse than no claim, which is M16's own lesson about a stale one.

✅ **THE PREDICTION THAT SAID THIS WOULD HAPPEN WAS RIGHT ABOUT THE SHAPE AND WRONG ABOUT THE PLACE.** It
named "a fourth registry" as the likely miss and said the tell would be a red gate naming a file the diff
does not touch. There was no fourth registry — `TenantScopedTables` and the extract census were the whole
set — and the misses were **test fixtures** instead. The tell was exactly as described.

✅ **AND THE SAME READ SETTLED TWO SCHEMA DECISIONS, SO `ConstraintBoundaries.php` AND ITS DRIFT TEST STAY
UNTOUCHED — BY DESIGN NOW, NOT BY ASSUMPTION.** `ConstraintBoundaryDriftTest` pins two censuses by exact
equality: composite FKs whose key contains `tenant_id`, and unique indexes on a `tenant_id`-carrying table whose
key does **not**. So (a) the table is scoped to the **TENANT** and carries **no** FK to `sso_connections` — a
workspace controls a domain, an IdP does not, and a metadata re-import must not destroy the proof of control —
which keeps the FK census still; and (b) its unique key is **`(tenant_id, domain)`**, never a global unique on
`domain`, which keeps the second sweep still. ⚠️ **(b) has a product consequence worth stating rather than
discovering: two workspaces may each verify the same email domain**, each with its own token. That is correct —
one controller can legitimately run two workspaces — and a global unique would have let whichever claimed first
deny the other.

⚠️ **THE THREE `app/Support/Tenancy/` REGISTRIES AND `SsoStepUpWebTest.php` ARE CLAIMED ON A PREDICTION,
NOT A PLAN** — a new tenant-scoped table may oblige all three registries, and the step-up suite may not
need touching at all because that arm never provisions. **Claimed anyway, because a file that might get
written to is a file another lane must not be holding**, whatever my current intention for it (M17's
`DnsRecordBlock.vue` precedent, released untouched by design).

**Shared artefacts taken:** four `docs/` files and `PROGRESS.md` (own block only). **`openapi.json` is NOT
taken** — no `/api/v1` route is reached, so it must stay byte-identical and that is an assertion rather
than a regeneration. **`ci.yml` and `phpunit.xml` are NOT taken.** **No `tests/e2e/*.spec.ts`** — grepped:
nothing there drives an SSO assertion.

**Paired files taken: NONE, and that is verified rather than assumed.** Neither 7(b-bis) PHP gate is
reachable: no `NotificationType` moves and no ability key moves (an artisan command carries no policy).
The third gate cannot fire — no `clip: rect(0 0 0 0)` is added or removed. ⚠️ **The one that looked like a
paired file is not one**: this row adds a `SsoFailureReason` case, and `resources/js/components/sso/types.ts:88`
types `SsoFailureRow.reason` as a plain `string` with `reason_label` and `hint` **composed server-side**,
its docblock saying in words that a second map in that package is forbidden. `cards.test.ts` uses two
string literals and enumerates nothing. **Grepped both trees: there is no parity gate on this enum.**

**Namespaces spent:** migration block `2026_08_17_000109` **AND** `2026_08_17_000110` — both, so the next
free prefix is `2026_08_17_000111`. ADR-0016 sub-decision **`§D34`** is **SPENT**. ⛔ **No new ADR number:
`0022` STAYS FREE and stays Lane A's block-opener.** This is an SSO trust decision inside ADR-0016's own
scope, and minting `0022` for it would spend the scarcer namespace to say the same thing. `0010` stays
reserved for H1d; `#16` stays free.

### What is already measured, so the plan is not built on the row's own framing

**All eight of the row's citations HOLD, which is the first time in nine rows.** `SsoUserProvisioner::provision()`
carries exactly the two refusals named, at `:110-126`; `app/Support/Tenancy/DnsTxtResolver.php` and
`CustomDomainService::verify()` are real and reusable; the grep for
`verified_domain|domain_verified|assertEmailDomain|domainOwn` returns **zero hits — not merely in the two
SSO directories the row names, but across all of `app/`, `database/` and `config/`**; residual 32 and
ADR-0016 §D33's revisit trigger are both present and say what the row says they say.

⛔ **BUT THE ROW UNDERSTATES ITS OWN SEVERITY, AND THE SWEEP FOUND TWO THINGS IT DOES NOT NAME.** It says
*"NOT LIVE — both known exploits are closed"*. Two consequences survive M9 and are live today:

1. ⚠️ **THE SSO DOOR WRITES A PLATFORM-GLOBAL IDENTITY FACT FROM A TENANT-SCOPED TRUST ROOT.**
   `SsoUserProvisioner::createUser():207` stamps `email_verified_at => now()`, and its docblock defends
   that as *"the IdP's claim rather than a convenience… the assertion is signed by the tenant's own trust
   anchor and names this address."* **That reasoning is exactly what this row attacks**: a trust anchor the
   asserting workspace installed vouches for nothing about a domain that workspace does not control. So a
   paying SSO tenant can mint a global `users` row for **any** unregistered address with a **forged
   mailbox-control claim** on it. And `TenantMembershipService::identityIsEstablished():877` **reads that
   very column**, so the forged stamp feeds M8's own authentication predicate: the true owner, invited
   later by their real employer, is refused the password-setting arm and sent to a sign-in-then-accept hop
   they cannot complete. **Identity squatting plus a denial of M8's recovery path — filed nowhere.**
2. ⚠️ **A CROSS-TENANT ACCOUNT-EXISTENCE ORACLE ON THE FAILURES PANEL.** `existing_account_not_member`
   renders as *"Address already has an account elsewhere"* while `jit_disabled` renders as *"Nobody here
   matches that address"*. An SSO-entitled admin can therefore assert any address and read back, from
   their own settings page, whether it has an account anywhere in the deployment — having proven nothing
   about the domain. §D19's uniform-404 posture is intact on the wire and is simply not the surface that
   leaks.

✅ **THE ENFORCEMENT POINT IS SINGULAR, AND THAT IS GREPPED RATHER THAN ASSUMED.** `SsoIdentity` has exactly
two consumers: `SsoUserProvisioner::provision()` and `SsoStepUpService::matchSubject()`. The step-up arm is
already bound — it compares the resolved user against `sso_auth_requests.user_id`, written before the
redirect by an authenticated session — so it never lets an address *select* a person. `provision()` is the
whole seam, exactly as the audit said.

✅ **THE GRANDFATHERING CALL THE ROW CALLS "the part that makes it a decision rather than a feature" IS
DISSOLVED RATHER THAN ANSWERED, AND THE PREDICATE IS VERIFIED.** The gate sits **after** the `Active`
early-return, so **an Active membership IS the grandfather** — no backfill, no per-connection mode column,
no public-mailbox exclusion list, and **not one existing member of any live deployment is locked out.**
That rests on a claim I enumerated instead of reasoning about: the only writers of
`TenantUserStatus::Active` are `accept()` (needs the emailed token *and*, since M8, either a fresh identity
or the real person signed in as themselves), `joinOpenTenant()` (self-registration, which is a separate and
older door where nothing is forged), `joinViaGoogle()` (Google verified the mailbox) and `joinViaSso()`
(downstream of this gate). **No door mints an Active row for a stranger's address on an assertion alone.**
⛔ Rejected: *"enforce only once a connection has verified a domain"* — that is opt-in security whose switch
is held by the threat actor, and it closes nothing.

⚠️ **THE ORDER INSIDE `provision()` IS LOAD-BEARING, WHICH IS M12's LESSON POINTED AT A NEW METHOD.** The
domain check goes **before** both identity refusals, not after: that is what closes the oracle in (2), and
it makes the panel's answer the one the admin can act on. **The cost is stated rather than discovered — it
moves M1's and M9's own regression cases**, which assert `existing_account_not_member` and
`established_identity_not_joined` against `@identity.test` addresses on an Acme connection. Those cases
gain a verified domain so they keep certifying their own refusal, which makes them assert the **stronger**
statement (*even with the domain verified, adoption is refused*), and new cases cover the gate firing ahead
of them.

✅ **THE FIXTURE DOMAINS ARE FIXED, NOT FAKER-RANDOM, SO THE M9 DICE-ROLL TRAP DOES NOT APPLY HERE.**
`FakeIdp::$nameId` is the literal `ada@acme.test` and `tests/Pest.php:157` builds committed identities as
`Str::random(12).'@identity.test'` — **the local part is random and the domain is not.** Two verified-domain
rows in the shared `beforeEach` therefore cover the 42-case ACS suite and the 22-case completion suite.

### Deliberately NOT in this PR, each filed rather than dropped

- **The tenant-facing `/settings/sso` domain panel.** It is `resources/js/**`, which is **Lane A's outright**
  since the 2026-08-25 widening, and splitting a paired change across two lanes is the one thing 7(b-bis)
  says cannot work. The interim surface is `php artisan sso:domains`, which is the `domains:activate`
  precedent in ADR-0012 §D6. **Filed as a Lane A backlog row.**
- **Periodic re-verification of a verified domain.** `CustomDomainService::sweep()` re-reads on a cadence as
  its dangling-DNS control; this table gets `verification_checked_at` so the column is there, but no sweep
  job, because `routes/console.php` records that nothing runs the scheduler on the production box and trust
  decay is a product call rather than a defect. **Filed, with the residual named.**
- **`MemberController::invite()`'s missing domain check**, which is the same root on the invitation door and
  is named in this row's own chain. Out of scope here and filed separately.

### Prediction, written before the run so the measurement has something to disagree with

- **CI Pest MOVES, and it is the only gate that should.** ~4515 → ~4545 (+25 to +35 new cases), with the
  existing 4515 all still passing; 2 pre-existing warnings unchanged.
- **PHPStan CI `[OK]`; local 18 = baseline BY FILE LIST, not by count** — a new model brings new
  `property.notFound` phantoms local PHPStan invents and CI does not, so the count may rise and the file
  list is the measurement.
- **Four host lint gates: 97 controllers unchanged · 111 → 113 migrations · 31 jobs unchanged ·
  constraint-boundary 111/119/0 MOVES** (a new tenant-scoped table adds constrained columns), read without
  a pipe.
- **`openapi.json` byte-identical** — asserted from the diff, no `/api/v1` route is reached.
- **Vitest 130 files / 2,213 and axe 42 / 299 unmoved, and ASSERTED rather than re-measured**, because no
  `.vue`, no `packages/` source and no `resources/` file is in the diff. **E2E 551 passed + 10 skipped, no
  flaky line.**
- ⚠️ **The prediction I most expect to be wrong: that the three `app/Support/Tenancy/` registries are the
  complete set a new tenant-scoped table must join.** I found `TenantScopedTables::STRICT` and three drift
  tests by grepping for `sso_auth_failures`, which is a floor and not a census — the same limitation
  7(b-bis)'s own sweep records about itself. **If a fourth registry exists, the first red gate will name a
  file this diff does not touch, and the rule is to treat that as structural rather than as a flake.**

## RELEASED — M15, the respondent-scoped device outbox (merged as PR #207, `f052dd5`, 6/6)

Every claimed file was edited except one: `resources/public-runtime/components/SubmissionOutbox.vue` was
claimed and **not touched**, because scoping `rows` upstream means the list simply receives fewer — which
is also why every selector `tests/e2e/public-runtime-offline.spec.ts` locates survived untouched. The claim
was **not extended** mid-build; nothing outside it was opened.

**Both halves of the 7(b-bis) paired file moved in the one PR**, which is what that rule exists to force:
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` lost its `SyncStatus.vue`
entry in the same commit as `.sync-status` gained `position: relative`. `RuntimeShell.vue` stays listed —
untouched here, and a containing block added without a look at the running app is what that file refuses.

**Namespaces:** ADR **`0021`** is **SPENT**. Next free overall is **`0022`**; `0010` stays reserved for
H1d and `#16` stays free; ADR-0016's `§D34` and the migration block `2026_08_17_000109` are **UNSPENT** —
M15 touched no PHP and added no migration.

**Prediction vs measurement, since the point of writing one first is to be measured against it.**
The file counts were exact and the test count was under-predicted: predicted `public-runtime` 35 files /
~765 and a repo total of 130 / ~2,196; **measured 35 / 782 and 130 / 2,213**. `design-system` 35/545,
`resources/js` 60/886, Pest **4515 / 19,161 with 2 warnings**, E2E **551 passed + 10 skipped**, axe,
the four lint gates, the byte-identical `openapi.json` and the absence of a Dexie version bump all held
exactly as predicted. **One prediction was wrong in a useful direction**: I expected to have to fix
`sync-outbox.test.ts:194-195` and `outbox.test.ts:191-203`, because their fixtures build rows with no
session. In the event **one** existing assertion in the whole suite needed changing — `listSubmissions
honours its limit`, an options-object signature change — because an unscoped driver deliberately answers
the pre-M15 device-wide numbers, so every existing case kept meaning what it meant.

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
