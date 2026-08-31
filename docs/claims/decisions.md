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

### D8 — A tracker surgery triggers no post-merge run at all. Which way should `ci.yml` regain the trunk observation?

**Filed 2026-08-31 by Lane A, during `M49`.** Filed rather than decided because every option trades
**CI minutes against gate coverage**, and `M39` removed those minutes deliberately after measuring
the cost. The row it comes from stays open in `docs/feature-backlog.md` until this is answered.

**The defect, stated once.** `ci.yml`'s `push` filter ignores `PROGRESS.md`, `PROGRESS_ARCHIVE.md`,
`docs/claims/**`, `docs/gate-baselines.md` and `docs/backlog-triage.md`, and GitHub evaluates it over
**every** file in the push. A pure permutation of the two tracker files — which is precisely what a
well-executed surgery is — therefore **cannot trigger CI on `main` at all.** The PR run still gates
the merge, so this is not a hole in the merge gate; what it removes is the **post-merge observation on
the trunk**, which is the only place a squash body's form can be verified. `M48` escaped it by
accident of scope, because a `scripts/` ratchet landed in the same commit.

⛔ **ONE OF THE TWO CANDIDATES THE ROW NAMED CANNOT BE WRITTEN, AND THAT IS MEASURED RATHER THAN
ARGUED.** *"Exempt a commit whose message carries the marker"* has nowhere to be expressed: a
workflow's `paths-ignore` is evaluated by GitHub **before a run exists**, over the pushed file paths
and nothing else. It has no access to a commit message. So the real field is three, not two.

**Option 1 — a second, tiny workflow: `tracker-lint` only, on `push` to `main`, no path filter.
(RECOMMENDED.)** Roughly one minute against the full pipeline's ~18, so `M39`'s measured cost is not
re-incurred; the trunk arm becomes reachable for exactly the diff shape it guards; and it is additive,
so nothing about the existing pipeline changes. Cost: a second workflow file to keep in step with the
first, and one more run appearing in `gh run list` — which anything counting *"six completed checks"*
must not mistake for a seventh required context.

**Option 2 — drop `PROGRESS.md` and `PROGRESS_ARCHIVE.md` from the filter.** One line, no new file,
and the post-merge run is the real pipeline rather than a slice of it. Cost: **every close-out queues
the full ~18-minute pipeline again**, which is what `M39` removed after measuring six cancelled runs;
a close-out pushes documentation two or three times per increment, so this is the expensive option and
it re-opens the `deploy.yml` trigger question `M39` closed.

**Option 3 — a process rule: a surgery must deliberately touch one non-`paths-ignore`d file.** Costs
nothing and changes no configuration. It is also **a reminder rather than a mechanism**, which is the
class this project has repeatedly found insufficient — Rule 7(g)'s stale ADR number survived
twenty-three increments as prose. It would work, right up until the increment that forgets.

**Recommendation: option 1.** It buys the observation for about a minute a push and does not disturb
a filter that was added for a measured reason. ⚠️ **Not proceeded on**: it adds a workflow to a public
repository and changes what runs on the trunk, and `D7`'s branch-protection question may make the
required-contexts count matter — so the two are better answered together than separately.

---

### D9 — Should the legacy client's identity be rewritten out of git history as well? **RECOMMENDED AGAINST.**

**Filed 2026-08-31 by Lane A during `M51`, unconditionally and without being asked**, because `D6`'s
answer redacts the **working tree** and the repository is public. A redaction that reduces an exposure
without closing it must say so and must name the remaining question, or the next reader will assume the
material is gone. `D6`'s original defect was a deadline that expired unnoticed and let the default win
by silence; **closing it with wording that implied the material had been erased would be that same
defect pointing the other way.**

**The facts.** `M48`'s secret scan read the repository's whole history — hundreds of commits — which is
how it produced 818 findings on its first real run. That is direct evidence the history is readable, and
it is the reason this entry exists rather than an inference about GitHub. The redacted strings remain in
every commit that ever carried them, reachable by anyone who clones.

**Option 1 — leave history alone. (RECOMMENDED.)** The working-tree redaction is what a reader, a search
engine and a casual clone see; the history requires deliberate archaeology. Three costs make the
alternative a bad trade:

1. ⛔ **A force-push across the whole repository is the largest possible instance of the
   mechanical-operation class this project already gates.** `R7` exists because one splice deleted 1,086
   lines and merged green; `mutate.php` exists because a restore that looked right was not. A history
   rewrite is that class at maximum blast radius — **and no gate here would catch it going wrong**,
   because every gate compares against a history the operation has just replaced.
2. ⛔ **It changes every sha, and three separate mechanisms are keyed to shas.** `state.php`'s
   merged-pull-request-title cross-check — the *second, independent* source for the increment number —
   is resolved against commits; `R7`'s evidence is blob sizes and commit messages at specific shas; and
   `.gitleaksignore`'s fingerprints are **commit-scoped**, so every one of them silently stops matching
   and the secret scan starts reporting findings that were already adjudicated. Two of those three are
   the machinery this series spent `M48`, `M49` and `M50` building.
3. **The exposure is not live-exploitable.** The material is architectural criticism of a legacy project
   **the owner owns** — a schema audit and a deployment-posture inventory — not credentials, tokens or
   personal data. Nothing in it can be used against a running system, and the secret scan found no real
   secret.

**Option 2 — rewrite history (`git filter-repo`), then force-push.** Closes the exposure completely.
Costs all three of the above, plus: every existing clone and fork diverges permanently, open pull
requests are invalidated, and the operation is **irreversible in practice** once collaborators fetch.

**Option 3 — make the repository private.** Closes the exposure without touching history. Rejected here
for the same reason `D6` rejected it: it is a much larger decision about the project, and it would
silently remove the free-Actions-minutes premise several CI decisions rest on.

**Recommendation: option 1**, and it is **not being proceeded on in either direction** — nothing is
rewritten and nothing further is redacted until this is answered. ⚠️ **The honest framing is that this
is a cost/benefit call, not a security emergency.** If the answer is option 2, it should be taken as a
deliberate, scheduled operation with the three keyed mechanisms re-derived afterwards — not folded into
an increment.

---

## ANSWERED

### D7 — Should `main` get branch protection, with the repository owner as a bypass actor? **Yes.**

**Filed 2026-08-28 by Lane A during `M38`; answered 2026-08-31 (user decision); applied by Lane A during
`M51`.** It was filed rather than decided because it changes settings on a public repository, which is
the class of change that stays with the user.

**As decided — option 1, exactly as recommended.** A ruleset on `main` requiring **all six** status
checks, with the **repository owner as the sole bypass actor**.

**What it retires, and it is a measured failure rather than a hypothetical.** Every merge in this series
is a self-merge on a green run, and the check that the run was really green was *the model parsing `gh`
output*. That failed once already: `I5` merged during a GitHub Actions outage with four of six jobs
never having acquired a runner, reporting `steps: []` — a **vacuous success**. Every hand-off since has
carried *"parse each job's step count individually"* as prose, and prose has to be remembered. Under the
ruleset a required check that never acquired a runner is **pending, not passed**, and nothing merges.
The trap disappears mechanically.

**Why the owner bypass is not a loophole grudgingly accepted but a requirement.** Rule 7(g) makes a
claim a **pushed commit** — `git push origin HEAD:main` *before* the first file is opened. Blanket
protection would turn that one-commit lock into a pull request round trip and destroy the property that
makes it a lock. The bypass is exactly as strong as the discipline about using it, and it is for claim
commits and close-outs only.

⛔ **THE SIX CONTEXTS WERE READ FROM A REAL RUN, NOT FROM THIS ENTRY.** `D7` itself records that the plan
proposing it said **five**, and a ruleset built on the wrong number leaves a gate non-blocking — which is
the precise failure it exists to prevent. Taken from run `33398663198`, the post-merge run on `main` for
`M50`: `Static analysis, style & security` · `Tests (Pest on PostgreSQL)` · `Frontend build & type-check`
· `Design system a11y (axe)` · `Contract tests (OpenAPI)` · `E2E (Playwright + axe)`.

⚠️ **AND THE APPLICATION WAS SEQUENCED SO THAT IT TESTS ITSELF.** The ruleset was created **after**
`M51`'s pull request merged and **before** its close-out, so this increment's own close-out — a direct
`git push origin HEAD:main` — is the live exercise of the owner bypass rather than an assumption about
it. A protection rule whose bypass has never been used is a protection rule that has never been tested.

---

### D6 — The corpus names a real third-party client and publishes an audit of its weaknesses, on a public repository. Redact? **Yes — the working tree. History is NOT rewritten.**

**Filed 2026-08-28 by Lane A during `M38`; answered 2026-08-31 (user decision); applied by Lane A during
`M51`.**

**As decided:** the client identification and the published audit of that legacy system's weaknesses are
removed from the tracked files. **Every architectural lesson is kept** — it is the naming plus the
vulnerability detail that goes.

⛔⛔ **THE EXPOSURE IS REDUCED, NOT CLOSED, AND THIS ENTRY SAYS SO IN TERMS.** **History is not
rewritten, and that is a deliberate limit rather than an oversight.** The repository is public and its
full history is readable — `M48`'s secret scan proved exactly that by reading hundreds of commits to
produce 818 findings. Every redacted string therefore remains in the commits that carried it and is
reachable by anyone who clones. What changed is what a reader, a search engine and a casual clone see.
**Whether to rewrite history is filed as its own decision, `D9`, unconditionally and recommended
against.** This row's original defect was a deadline that passed and let the default win by silence;
recording it as *"the material is gone"* would be that same defect pointing the other way.

**The count was re-derived and disagreed with all three prior figures — and the unit turned out to be
the finding.**

| Source | Figure |
|---|---|
| The original backlog row | 6 sites |
| `docs/backlog-triage.md`'s census | "11+" |
| This entry's own table, when the row moved here | 17 occurrences across 9 files |
| **Measured during `M51`** | **26 occurrences across 11 files — or 20 lines carrying at least one** |

⚠️ **`grep -c` counts LINES and `grep -o | wc -l` counts OCCURRENCES, and on this corpus they differ by
six.** None of the three earlier figures says which it is, so *"the count grew"* was partly drift and
partly a change of unit. Some of the growth is also this project writing about its own redaction: three
of the eleven files were the decision record, the backlog row and the claim ledger.

⛔ **AND A REDACTION SCOPED TO THE LITERAL STRINGS IS THE WRONG SCOPE — THREE SITES WERE INVISIBLE TO
IT.** The corpus identified its subject in at least four ways: the system name, the project name, the
client's name, and a national geography standard named by acronym. Only the first two are greppable as a
unit. `docs/backlog-triage.md` contained **neither name** and identified the client by description,
inside its own summary of this very decision; `docs/domain-glossary.md` and `docs/PRD.md` each carried
one more. **A name-scoped search reports those files as clean.**

⚠️ **AND ONE FALSE-POSITIVE CLASS WOULD HAVE MADE A BLIND SUBSTITUTION DESTRUCTIVE.** `PROGRESS_ARCHIVE.md`
matched an acronym search **55 times** and **not one was the client** — every occurrence is the
developer's own Windows username inside a plan-file path. A substitution run on the obvious pattern
would have corrupted 55 paths and redacted nothing. The occurrences were read before they were replaced.

**Where the line was drawn between "lesson" and "audit", stated so the diff can be judged against it:**

- **Kept, in full:** every decision's rationale. `ADR-0001`'s MySQL-shaped gaps, `ADR-0002`'s absent
  tenant concept, and the **`id`-based super-admin convention repeated across several code layers** that
  `ADR-0002` §D3 exists to avoid. A decision whose provenance is deleted is a decision nobody can check,
  and this entry's own option 1 argued that the lesson is exactly as strong without a name attached.
- **Removed:** the exploitation mechanic spelled out beside that convention, and `ADR-0003`'s itemised
  inventory of the legacy system's repository and CI posture. Both read as a security and operations
  report on somebody else rather than as a reason for a choice here.
- **Checked and deliberately kept:** `app/Models/ScopeNode.php`, its migration and
  `docs/multi-tenancy-rbac-design.md` illustrate the **scope-tree feature** with three unrelated
  examples. They describe a customer's data, not the legacy client.

⚠️ **THE ANSWER IS BROADER THAN THIS ENTRY'S OWN RECOMMENDATION, AND THE ENTRY SAYS SO RATHER THAN
RETRO-FITTING ONE.** Option 1 as filed kept *"every technical lesson intact"* and stripped only naming.
The decision also removed the vulnerability detail. The boundary between the two is a judgement call; it
was taken toward keeping each decision's rationale, and it is recoverable in the removed-too-much
direction precisely because history was left alone.

---


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
