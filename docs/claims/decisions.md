# Decisions queue

**This file exists so that no lane ever idles on a question.** Standing Rule 5 already says a
design decision the user has not made is not automatically a blocker — propose one, recommend,
and proceed. This is where that becomes mechanical for the cases that genuinely *are* the user's.

**How a lane uses it.** On reaching a real product call: append the question, the two or three
**real** options, and **your own recommendation** — then take the next row **in the same turn**.
Never wait. The user answers in batches.

**What does NOT belong here.** A residual you simply chose not to fix goes in
`docs/feature-backlog.md`, filed **the moment you decide not to fix it** — not here, and not in
`PROGRESS.md` prose only, which is how four live defects stayed invisible from J4b1 until J6.

**Decisions already taken are decisions of record — do not re-ask them:** drop `sortable` on the
two server-paginated tables (2026-08-18) · fail **open** on an unseeded plan catalog (2026-08-18) ·
password policy min-12 + HIBP + classes (2026-08-09) · Google-only social login (2026-08-09) ·
gamification last (2026-08-09) · the held list stays held until the user signals, and they said
*"not yet, ask again later"* on 2026-08-18 · **a flaky e2e result fails CI** (2026-08-26, D2 below) · **the M-series ends at zero open
`major` rows plus three consecutive increments filing none** (2026-08-28, D5 below).

---

## OPEN

### D1 — Should the sixteen synchronous dispatch listeners become `ShouldQueue`?

**Filed 2026-08-25.** Moved here out of `docs/feature-backlog.md` § *Connectors & webhooks*, where
it sat as a `minor` row. It is **not** a defect with a known fix; it is an undecided question, and
it was also the only row an audit of all 62 open merge-gate rows confirmed as genuinely
cross-cutting.

**The facts, verified on `origin/main`.** Sixteen listeners — eight `app/Listeners/Webhooks/Dispatch*`
and eight `app/Listeners/Connectors/Dispatch*` — run synchronously inside the request, and nothing
has ever decided whether they should. `ConnectorEventDispatcher` already wraps `fanOut()` in
`TenantContext::runFor()`, so tenant context is not the obstacle.

**What makes it more than a one-line change.** `scripts/job-payload-lint.php` scans all of `app/`
in pass 1 and trips rule R1 — *"extends neither `TenantAwareJob` nor `MaintenanceJob`"* — on any
listener implementing `ShouldQueue`. Its only escape is an `EXEMPT_JOBS` entry **inside that
script**, because a listener cannot extend `TenantAwareJob`: that class's `handle()` is `final` and
it demands an abstract `$tenantId` payload hook. Separately,
`tests/Feature/Connectors/ConnectorFanOutTest.php:163` **hard-asserts** these listeners are *not*
`ShouldQueue`, so the current behaviour is deliberately pinned and whoever changes it changes an
assertion on purpose rather than discovering it.

**Options.**
1. **Leave them synchronous and say so in writing** — add the rationale to the fan-out docblock and
   close the row. Cheapest; the pinning test already encodes it, it just does not explain itself.
2. **Queue them**, adding `EXEMPT_JOBS` entries and re-pinning the test. Removes webhook/connector
   dispatch latency from the request, at the cost of weakening what R1 guarantees about `app/`.
3. **Queue only the connector eight**, leaving webhooks synchronous — same script cost, half the
   benefit, and two conventions where there is currently one.

**Recommendation: option 1 unless request latency is a measured problem.** Nobody has measured
that it is, the sixteen are cheap dispatchers rather than workers, and option 2 spends a real
structural guarantee (R1's coverage of `app/`) to buy something unquantified. If it is ever
measured and found to matter, option 2 is the right shape — not option 3.

---

### D3 — ADR-0020 §D7 approves *"4th of 12"* for every member. Three other surfaces withhold the twelve. Which moves?

**Filed 2026-08-26 by Lane B, during `M26`.** Proceeding on the recommendation below rather than
waiting — Standing Rule 5. If the answer comes back the other way, the revert is one commit and it
is named at the bottom.

**The collision, in one payload.** `AchievementsController::__invoke()` emits
`progress.standing.of` — the workspace's active-member count — with **no permission at all**
(`:103`), and two fields later withholds `scoreboard` behind `can('viewAny', PointAward::class)`
(`:115-120`), whose gated `team.active_members` is **the same quantity**. The same controller's own
docblock (`:49-60`) argues at length that serving `team.active_members` ungated would be *"a
widening of an existing permission, performed by a new page"*. Both sentences are in one file and
they cannot both be right.

**It is a real disclosure, not theatre.** A Form Editor has no other route to that number:
`/dashboard`'s Members tile is nulled without `dashboard.org.view`
(`DashboardMetricsService:55,60`), `/members` needs `tenant.members.invite`
(`routes/tenant.php:409-410`), and the member search arm refuses the same three roles
(`MemberSearchArm:88-94`, docblock `:79`). It is also reachable off the page: every role may mint a
`read:gamification` token (`GamificationApiTest:104`) and `GET /api/v1/gamification/me` returns it.

**Why it happened — worth reading before choosing.** §D7's criterion is **names**: it gates *"the
**named** ranked list"*. K1e explicitly **replaced** that criterion for `team`, on the grounds that
plain workspace-wide counts are the sensitive thing, not just names. `standing.of` is the same
number under the replacement criterion and was never re-walked against it. So this is **not** a
disagreement with §D7 — it is §D7's line having moved once already, in a direction the product
chose, with one field left behind.

**Option 1 — withhold `of` from readers without `dashboard.org.view`; the label degrades to
"4th". (RECOMMENDED.)** No new permission key: it reuses the check already resolved two fields
away, so the 29-key catalog stays closed and both cross-lane parity gates stay still. `rank`
survives untouched, so §D7's actual grant — *a member sees their own position* — is honoured in
full. Cost: three of five roles see *"4th"* instead of *"4th of 12"*, and one existing test that
asserts the current behaviour for a `form_editor` changes.

**Option 2 — ratify: declare the headcount non-sensitive.** Honest about the fact that team size
is not much of a secret, and it costs no UX. But to be coherent it must **un-gate the other two**:
`kpis.members` on the dashboard and `team.active_members` on the achievements page. That is a
deliberate widening of `dashboard.org.view` across three surfaces to preserve one label, and it
contradicts the reasoning three separate increments wrote down.

**Option 3 — mint a `gamification.view_headcount` key.** Rejected in advance; §D7 rejected the
same shape for the same reason, and a thirtieth key means re-litigating which of five roles hold
it.

**Recommendation: option 1.** Every surface that ever *considered* this number withheld it; the two
that disclose it did so without deciding to. Option 1 aligns the outlier with the three, option 2
would move three to match the outlier, and only option 1 leaves §D7's actual promise intact.

⚠️ **The `/dashboard` half is NOT part of this question and is fixed either way.**
`DashboardController:124` emits `of` (and `rank`) into every dashboard payload, and `Dashboard.vue`
renders **neither** — they are declared at `:91` and never read. That is dead wire-level disclosure
with no product value, so it is deleted regardless of how D3 is answered.

**If the answer is option 2**, the revert is: restore `of` unconditionally in
`AchievementsController` and `MemberProgressResource`, drop the two negative tests, regenerate
`openapi.json`, and open a follow-up row to un-gate `kpis.members` and `team.active_members` — the
dashboard deletion above still stands.

### D4 — An archived webhook envelope has no form to be scoped to. Which permission reads it?

**Filed 2026-08-26 by Lane B, during `M33`.** Proceeding on the recommendation below rather than
waiting — Standing Rule 5. The revert is one enum arm and it is named at the bottom.

**Why the question exists at all.** `M33` closes the row that `AttachmentPolicy::view()` is flat where
`SubmissionPolicy::view()` is scoped: `GET /attachments/{attachment}` read any stored object in the
tenant by id with no per-form check, so `form_editor` and `reviewer` — the two roles holding
`submissions.view` without `dashboard.org.view` — could read media on forms they had never
collaborated on, **and on forms they had been removed from.** For four of the six live kinds the fix
is mechanical: resolve the owner, apply the submission's own scope. **One kind has no owner the
scope means anything for.**

`WebhookPayloadArchive::archive()` writes an attachment owned by a `webhook_delivery`. A delivery
belongs to a tenant-configured endpoint, and **its envelope is the full outbound payload of whatever
form fired it** — so it does not belong to one form, it crosses every form boundary at once. There
is nothing to scope it *to*. Under the pre-M33 policy it was readable by **all five seeded roles**
on `submissions.view`, and it is servable: the row is written `ScanStatus::Skipped`, which
`servable()` admits, **under a comment at `WebhookPayloadArchive.php:67` asserting these bytes are
"never served to a browser."** The route makes that comment false, which is how the kind was found.

**The options, all three real.**

1. **`webhooks.manage` — Owner/Admin.** The authority that configures the endpoint the payload was
   sent to is the authority that reads what was sent. Narrows from five roles to two.
2. **`submissions.view` unchanged — all five roles.** Treat the envelope as submission data. Keeps
   today's behaviour, and keeps a tenant-wide cross-form read available to `form_editor` and
   `reviewer` — the precise pair the rest of `M33` exists to scope.
3. **`audit_log.view` — Owner/Admin.** Treat the envelope as a forensic record rather than
   configuration. Same audience as (1) today, but it would bind envelope access to the audit
   permission if those two ever diverge.

**Recommendation, and what is implemented: (1) `webhooks.manage`.** It is the smallest permission
whose holders are already trusted with the whole of what an envelope contains — a `webhooks.manage`
holder can already read every outbound payload by reconfiguring the endpoint, so this grants no new
authority to anybody. (2) is rejected because it leaves the increment's own defect open for one kind
while closing it for four. (3) is rejected as a coincidence of the current role matrix rather than an
argument: `audit_log.view` is about the audit trail, and an envelope is not in it.

⚠️ **This is a NARROWING, which is the class of change the J2d precedent says belongs to the user** —
recorded here for exactly that reason rather than decided silently in a policy file. It is
implemented rather than deferred because the alternative is leaving a live tenant-wide cross-form
read open while the question waits, and Standing Rule 5 exists to stop that trade.

**If the answer comes back the other way**, the revert is one arm of the `match` in
`app/Policies/AttachmentPolicy.php` — change `AttachmentKind::WebhookPayloadArchive` from
`$user->can('webhooks.manage')` back to `$user->can('submissions.view')` — plus the two cases in
`tests/Feature/Attachments/AttachmentPolicyTest.php` that name it (*refuses an archived webhook
envelope to a collaborating form_editor* and its owner-side positive control), and the §D10
paragraph in `docs/adr/0015-feedback-screenshot-capture.md`. No migration, no data, no client
contract: `openapi.json` is untouched by `M33`.

---

### D6 — The corpus names a real third-party client and publishes an audit of its weaknesses, on a public repository. Redact?

**Filed 2026-08-28 by Lane A, during `M38`.** **Moved** here out of `docs/feature-backlog.md`
§ *Documentation & specs*, where it sat as a `major` row — moved rather than copied, because two
copies of one question drift apart. That is the defect Standing Rule 7(b) records about the lane
boundary and `docs/gate-baselines.md` records about gate numbers, and this file's own header names
the class.

⛔ **THIS IS THE ONE ITEM AN AUTOMATED LOOP MAY NEVER TOUCH.** Every increment adds documents and
history, so a faster loop makes this **strictly worse** — more sites, more commits carrying them.
It is sequenced first in the realignment for exactly that reason, and no unattended run may take it.

**The facts, measured on the merged tree rather than read off the row.** The row cites **6** sites;
M37's census, which re-validated all 68 open rows, said **"11+"**. The actual figure is **17
occurrences of `dev_pk_new` / "Purok Kalusugan" across 9 tracked files**:

| File | Hits |
|---|---|
| `PROGRESS_ARCHIVE.md` | 3 |
| `docs/PRD.md` | 2 |
| `docs/domain-glossary.md` | 2 |
| `docs/competitive-feature-parity-matrix.md` | 2 |
| `docs/adr/0001-postgresql-over-mysql.md` | 2 |
| `docs/adr/0002-multi-tenancy-shared-db-rls.md` | 2 |
| `docs/adr/0003-hosting-laravel-cloud.md` | 2 |
| `docs/architecture/technical-architecture.md` | 1 |
| `docs/feature-backlog.md` | 1 |

⚠️ **THE ROW UNDERSTATES ITSELF BY NEARLY THREE TIMES — AND SO DID THE CENSUS THAT RE-VALIDATED IT.**
That is worth more than the count: M37's whole finding was that rows understate their *scope*, and
this row is a case of the census reproducing the very failure it was measuring.

What is published, on a repository confirmed `"visibility": "PUBLIC"`: the client is named as a
**Philippine Department of Health** project, and the corpus describes its `users.id === 1` god-mode
convention (*"duplicated across four code layers, silently transferable if user #1 were ever deleted
and the ID reused"* — `docs/adr/0002:373`), its missing form versioning, its CI gaps, and a
repository-state audit in ADR-0003.

⛔ **THE ROW'S OWN DEFERRAL IS SPENT, WHICH IS WHY THIS IS NOT SIMPLY LEFT WHERE IT WAS.** It filed
itself against *"the merge as the natural last moment to make redaction a conscious decision rather
than a default"*. That merge — PR #179 — landed **2026-08-18**. The deadline passed ten days ago and
nothing acted on it, so the default won by silence. **A deferral whose deadline expires unnoticed is
not a deferral; it is a decision taken by not deciding.**

**Option 1 — redact to a non-identifying description. (RECOMMENDED.)** Replace the client name and
project name with something like *"a prior government health-sector project"* across the 9 files,
and keep **every technical lesson intact**. The lessons are what ADR-0001 and ADR-0002 cite as their
rationale, and not one of them needs the client's identity to work: *"an `id === 1` super-admin
convention duplicated across four code layers"* is exactly as strong an argument for
`is_super_admin` without a name attached. Cost: one increment, ~17 replacements, and
`PROGRESS_ARCHIVE.md` is history so its three hits are a judgement call of their own.

**Option 2 — ratify: leave it as it is.** Defensible on the grounds that the legacy audit *is* the
provenance for this project's architecture decisions, and that anonymising a citation weakens the
chain a reader follows to check the reasoning. It is also the status quo, so it costs nothing. What
it accepts is that a named third party's security weaknesses stay published, indefinitely and
indexed, on a repository nobody outside this project has asked to be a party to that.

**Option 3 — make the repository private.** Closes the exposure completely and immediately, and
costs no editing. Rejected as an answer *to this question*: it is a much larger decision about the
project, it would silently disarm the free-Actions-minutes premise several CI decisions rest on, and
it treats a documentation problem with an infrastructure lever.

**Recommendation: option 1.** The identification buys the corpus nothing — every argument survives
the redaction word-for-word — and it is the only option that separates *keeping the engineering
lesson* from *publishing a named organisation's weaknesses*. Option 2 is coherent but it is the one
that has effectively been in force by default since the deferral expired, and it should be chosen on
purpose if it is chosen at all.

⚠️ **GENUINELY THE USER'S CALL AND NOT BEING PROCEEDED ON.** Unlike D3 and D4, there is no
revert-in-one-line here: a redaction rewrites the provenance of two ADRs, and a wrong guess in
either direction is expensive to undo. Standing Rule 5 still applies to everything *else* — the
series does not stall waiting for this.

---

### D7 — Should `main` get branch protection, with the repository owner as a bypass actor?

**Filed 2026-08-28 by Lane A, during `M38`.** Filed rather than decided because it **changes settings
on a public repository**, which is the class of change the J2d precedent and D4's narrowing note both
put with the user. It gates the realignment's later increments — the merge-verdict script and the
Rule 7 rewrite both assume an answer.

**Why it comes up now.** Every merge in this series is a self-merge on a green run, and the check
that the run was really green is **the model parsing `gh` output**. That has already failed once, in
a way nobody caught at the time: I5 merged during a GitHub Actions major outage with four of six jobs
never having acquired a runner, reporting `steps: []` — a **vacuous success**. Every hand-off since
has carried *"parse each job's step count individually"* as prose, and prose has to be remembered.

**The facts, verified rather than assumed.** `gh api repos/:owner/:repo/branches/main/protection`
returns **`404 Branch not protected`** and `.../rulesets` returns **`[]`**. There is nothing to amend;
this would be a net-new control. `main` currently accepts a direct push from anyone with write access,
which is also **exactly how the claim protocol works** — Rule 7(g) requires `git push origin HEAD:main`
for the claim commit *before* any file is opened, so blanket protection would break the one mechanism
that makes concurrent work safe.

⚠️ **THE CONTEXT COUNT IS SIX, NOT FIVE — CORRECTED HERE BECAUSE THE PLAN THAT PROPOSED THIS SAID
FIVE.** Read from a real run: `Static analysis, style & security` · `Tests (Pest on PostgreSQL)` ·
`Frontend build & type-check` · `Design system a11y (axe)` · `Contract tests (OpenAPI)` ·
`E2E (Playwright + axe)`. A ruleset written to the wrong number leaves one gate non-blocking, which is
the failure it was built to prevent.

**Option 1 — a ruleset requiring all six contexts, with bypass actor = repository owner.
(RECOMMENDED.)** GitHub refuses the merge until every required context reports `success`, so the
`steps: []` trap **disappears mechanically**: a required check that never acquired a runner is
*pending*, not *passed*, and nothing merges. The owner bypass keeps Rule 7(g)'s direct claim push
working. Cost: the bypass is a real hole — it is exactly as strong as the owner's discipline about
using it, and it must be used for claim commits only.

**Option 2 — no protection; keep today's convention-only discipline.** Costs nothing and changes
nothing. It leaves the merge verdict with the model, which is where it was during I5, and the
mitigation stays "the hand-off reminds you to parse step counts."

**Option 3 — full protection with no bypass.** Strongest, and it breaks the claim protocol: claims
would need a PR each, which turns a one-commit lock into a multi-minute round trip and removes the
property that makes it a lock at all. Rejected unless the claim protocol changes first.

**Recommendation: option 1.** It retires a known, measured failure mode with a mechanism instead of a
reminder — the same move Rule 8 made for the local checks — and it is the only option that does so
without breaking Rule 7(g). ⚠️ **It is not being proceeded on**: it alters repository settings that
are visible publicly and that this project cannot un-ring by editing a file, so it waits for an
explicit yes.

---

## ANSWERED

### D5 — What bar ends the M-series? **Zero open `major` rows, plus three consecutive increments filing no new `major`.**

**Filed 2026-08-28 by Lane A during `M36`; answered 2026-08-28 (user decision); recorded by Lane A
during `M38`.** The series ran M1 → M37 with no exit criterion at all, so the honest description of
the plan was *"until the backlog is empty"* — and the backlog does not drain monotonically. M29 closed
one row and filed six; M36 closed one and filed three; M37 closed none.

**As decided:** the series ends when **no `major` remains open** *and* **three consecutive increments
have gone by without a new `major` being filed.** The second clause is what makes it an exit rather
than a moment: the first clause alone is satisfiable at any instant by an increment that has not yet
been verified, and this project's own record is that a row's verification is where the next row comes
from.

⚠️ **THIS IS NOT THE RECOMMENDATION THAT WAS FILED, AND THE ENTRY SAYS SO RATHER THAN RETRO-FITTING
ONE.** D5 recommended **option 2** — a *category* bar, ending on correctness and security and moving
style/docs/ergonomics rows to a standing backlog. The answer is closer to **option 1**, a severity
bar, with a stability clause option 1 did not have. The difference is real and worth keeping visible:
a category bar would have ended the series with `major` documentation-parity rows still open, and
**eight of the twelve open majors are exactly that** — documentation asserting things the code does
not do. The answer keeps them in scope.

⛔ **AND THE BAR IS NOT MEASURABLE TODAY. THIS IS MEASURED, NOT ESTIMATED.**

| | |
|---|---|
| Rows carrying the `major` marker | **12** |
| Of those, actually open *defects* | **11** — the twelfth is the disclosure row, which became `D6` in this same increment and is a decision, not a defect |
| Of the twelve, naming the increment that filed them | **1** (the `M32` fan-out row) |

⚠️ **THE FIRST CLAUSE IS NOT EVEN CLEANLY COUNTABLE, AND `M38` ITSELF IS THE PROOF.** A moved row keeps
its original bullet — that is `D1`'s established convention, so its reasoning survives for the reader —
which means `grep -c` still returns **12** the moment after one of them stopped being a defect. The
honest number is **11**, and nothing mechanical can tell them apart today.

**And the second clause cannot be evaluated at all.** *"Three consecutive increments with no new
`major`"* requires knowing which increment filed each `major` — and **eleven of the twelve do not record
it**. Provenance across the file appears in at least **15 distinct free-text shapes** (`Filed <date> by
<M>`, `Filed <date> from <J>`, `Found by <M>`, `Filed by **<M>**`, `(found by P3a, filed by K1c)`, and
more), so there is no single form to parse.

**What must land before this bar can be evaluated:** provenance normalised to one parseable form
across `docs/feature-backlog.md`, with a lint gate holding it there. Until then the exit condition is
recorded but not operable, and **saying so is the point** — a bar that cannot be measured is a bar
that will be declared met by whoever wants to stop.

⚠️ **THE SERIES DOES NOT STALL ON THIS AND NEVER DID.** Standing Rule 5 is unchanged: the next row is
taken under Rule 7(f) and built. This answers *when to stop*, not *whether to continue*.

---

### D2 — May an axe violation be retryable at all? **No. A flaky e2e result now fails CI.**

**Asked and answered 2026-08-26 (user decision), by Lane A while taking the share-panel row.** The
backlog row at `:1461` delegated this explicitly — *"whoever fixes (1) should also decide whether an
axe violation may be retryable at all"* — so it is recorded here rather than left in a PR body.

**Why it needed deciding.** `playwright.config.ts` sets `retries: process.env.CI ? 1 : 0`. That is
what turned a **deterministic** WCAG AA failure into a line that reads as noise: the same test, the
same rule and the same element (`builder-axe.spec.ts:198` › *share panel, live link*,
`color-contrast` on `footer > .mds-button--primary`) failed first-attempt and passed on retry in run
`32250476088` (2026-08-19) and again in run `32711202891` (2026-08-24) — five days apart, on two
unrelated diffs, neither of which touched a `.vue` or a design-system file. Both merged green. The
passed count drops by one while the total is unchanged, which reads exactly like a test having been
silently dropped.

**As decided:** keep `retries: 1` — a retry still rescues a genuine infrastructure hiccup and is what
produces the `trace: 'on-first-retry'` artefact — but add **`failOnFlakyTests: !!process.env.CI`**
(Playwright 1.61; the flag's own documentation uses that exact expression). A result that needed a
retry is now red. Rejected: dropping retries to 0, which would lose the trace on the first real
infrastructure flake and give nothing back.

⚠️ **THIS TIGHTENS MERGES FOR BOTH LANES, WHICH IS WHY IT IS HERE AND NOT ONLY IN A PR.** Any test
that passes only on a second attempt now blocks a merge, Lane B's included. The last two merge runs
read `551 passed + 10 skipped` with no flaky line at all. **If a flaky test does appear, it is a real
defect that was previously invisible; fix it, do not re-run it.**

⚠️ **AND THE FIRST DRAFT OF THIS ENTRY SAID "the cost is believed to be zero — the only flake on
record is the one the same PR fixes", WHICH WAS WRONG AND IS CORRECTED HERE RATHER THAN QUIETLY.**
`PROGRESS.md:1436` records a **second** flake in the same spec file — *"Builder — empty canvas
(dark)"* at what was then `builder-axe.spec.ts:170` — and `support/axe.ts` describes this file's
"standing reputation for contrast flakes at mobile+dark" as a known and until-then unmeasured
artifact. **There is therefore a real chance this flag reddens a run that would previously have gone
green, and that is the flag working rather than failing.** The scan-timing fix shipping beside it is
the most likely cure for that one too — both are mid-transition sampling — but "likely" is not
"measured", and the honest position is that the first such red run is information, not an obstacle.

⛔ **WHAT TO DO IF IT FIRES ON LANE B'S ROW:** it is almost certainly not Lane B's change. Read the
failure before touching anything, and check `support/axe.ts`'s incident notes first.

✅ **THE FLAG IS PROVED NOT BLIND, WITH A CONTROL — because the merge run could not prove it.** PR
#206's E2E job read `551 passed + 10 skipped` with **no flaky line at all**, which means
`failOnFlakyTests` was never exercised: a green run is exactly as green with the flag as without it,
so "CI passed" is no evidence the flag does anything. That is this project's standing *"a gate nobody
can tell is blind is a gate nobody is running"* shape, and Pint's probe is the precedent. So a
throwaway spec that fails on attempt 0 and passes on attempt 1 was run against **the real
`playwright.config.ts`**, imported rather than copied:

| | reported | exit |
|---|---|---|
| `CI=1` (flag active) | `1 flaky` | **1 — RED** |
| no `CI` (control, `--retries=1`) | `1 flaky` | **0 — GREEN** |

Same test, same retry, **the same `1 flaky` line in both** — and the flag alone is the difference
between red and the laundering this decision exists to stop. ⚠️ **Note what the control demonstrates
second: `1 flaky` printed above a zero exit code is what every one of those green merge runs actually
looked like.** The reporter was never hiding anything; nobody was reading the line.

⛔ **Ordering is load-bearing:** the flag lands in the *same* PR as the scan-timing fix and *after*
it, so CI is never red on the way through.
