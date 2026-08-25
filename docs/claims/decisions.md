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
that passes only on a second attempt now blocks a merge, Lane B's included. The cost is believed to
be zero today — the only flake on record in this suite is the one the same PR fixes, and the last two
merge runs read `551 passed + 10 skipped` with no flaky line at all. **If that belief is wrong, the
next flaky test to appear is a real defect that was previously invisible; fix it, do not re-run it.**

⛔ **Ordering is load-bearing:** the flag lands in the *same* PR as the scan-timing fix and *after*
it, so CI is never red on the way through.
