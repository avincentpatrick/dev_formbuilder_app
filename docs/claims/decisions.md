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
*"not yet, ask again later"* on 2026-08-18 · **a flaky e2e result fails CI** (2026-08-26, D2 below).

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

---

## ANSWERED

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
