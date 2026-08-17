# ADR-0020: The Gamification Awarding Substrate — what records an achievement, and what may never rewrite one

## Status

**Accepted — 2026-08-17.** Authored alongside its own code increment (**K1a**), on the ADR-0012/H22a, ADR-0013/H25, ADR-0014/H23a1, ADR-0015/I7a, ADR-0016/P1a, ADR-0017/P2a and ADR-0018/P2b precedent.

This is the substrate decision for the **full gamification engine** — the 2026-08-09 decision of record's *very last increment before deployment* (`docs/PRD.md:128`), scheduled last on purpose so it is designed **once against the finished feature set** rather than retrofitted per feature. The engine's own specification is `docs/gamification-design.md` (doc #28); this ADR records only the choices that would be expensive to reverse.

⚠️ **`0010` was not the next number even though the sequence skips it** — `0011:8`, `0012:8` and `0013:8` each reserve it for **H1d**, the OCR provider bake-off, having declined to fill it on purpose. `0020` was claimed in `PROGRESS.md` and committed **before this file existed**, per Standing Rule 7(g): reading the current maximum is not a reservation, and that is exactly how two ADRs came to share `0017`.

- **Deciders:** Founding engineering (architecture owner), with three product forks escalated to and answered by the product owner on 2026-08-17 (§D6, §D7, and the backfill in §D5).
- **Related ADRs:** **ADR-0002** — §D4 uses the `append_only` RLS variant this codebase built for `audits`, and §D3's single-column FK into `users` is §D5's global-table exemption. **ADR-0007** — §D2's listeners obey its synchronous-listener rule and its scalar-payload rule. **ADR-0008** — §D6 places `gamification` in the plan catalog without making it a commercial gate. **ADR-0011** — the analytics substrate this deliberately does *not* reuse (§D1).
- **Related docs:** `docs/gamification-design.md` (#28), `docs/data-dictionary.md` §31, `docs/audit-compliance-logging-spec.md` (§D1 rejects coupling to it), `docs/multi-tenancy-rbac-design.md` §5 (§D7 mints no permission).

---

## Context

1. **Nothing existed, and that was measured rather than assumed.** A sweep for *points / score / badge / achievement / streak / leaderboard / rank / xp / reward / milestone* across `app/ database/ resources/ packages/ config/ docs/` returned **zero** implementation hits before K1a. Every apparent match was a false positive: `MdsBadge` is the status pill, `score` is `FieldType::likert_matrix`'s `{row:score}` payload, `points` is GeoJSON vertices and `webhook_endpoints`.

2. **⚠️ There is no historical event stream to replay.** `App\Events\DomainEvent` subclasses are **in-memory Laravel events only** — nothing persists them. `webhook_deliveries` is an *outbound delivery* ledger that exists only where a tenant happened to subscribe an endpoint. An engine attached solely to listeners therefore starts **every** workspace at zero, including the seeded demo tenants the product owner tests on.

3. **`audits` is the one ledger that survives.** Append-only, tenant-scoped, carrying `user_id`, `event`, `auditable_type` and `created_at`, with an index its own migration describes as *"one actor's actions"* — which is literally a streak query. It is also **deliberately noise-reduced**: `docs/audit-compliance-logging-spec.md` §1 excludes ordinary profile edits, so "activity" there is not "everything a person did".

4. **J5's progress concept is 100% derived and cannot be built on.** `GettingStartedChecklist` recomputes done-ness per request from live KPI counts; the entire feature persists **one byte** (`tenant_users.onboarding_dismissed_at`). *When* a step became true is unrecoverable from it.

5. **Half the scorable acts are unbounded.** Review, edit, invite and publish can each be repeated indefinitely by one person against one object. Any scoring scheme without an exactly-once rule makes churning the queue the most efficient way to top the ladder.

6. **The production box does not run the scheduler.** `routes/console.php:40-41` records that `deploy.ps1` provisions no Task Scheduler task, which is why the SSO services trim on the write path. Any design here that depended on a nightly job would silently never run.

---

## Decision

**Record achievements as an append-only ledger of individual awards, written synchronously by listeners on signals that already exist, keyed so that every act can be awarded exactly once, and never rewritable — including by us.**

### The sub-decisions

**§D1 — An award LEDGER, not a derived read-model and not a maintained balance.** Three shapes were available. *Derive everything from `audits` on read* (J5's shape) works for points and streaks and was genuinely tempting — it needs no new write path and it is retroactive for free. It is rejected because it makes the **compliance** ledger load-bearing for a game: `audits` is noise-reduced by design (Context §3), so scores would quietly mean "what we happened to audit", and every future decision about what to audit would become a scoring decision made by someone not thinking about scoring. *A maintained `points` balance column* is rejected on `EntitlementService::countGauge()`'s own recorded argument — a running aggregate drifts, and unlike a usage gauge there is no authoritative table to reconcile it back from. The ledger keeps the good half of the derived approach: totals, streaks and standings are all `SUM`/`DISTINCT` over an indexed `(tenant_id, user_id, awarded_at)`, so nothing can disagree with anything.

**§D2 — Consume the existing signals; mint NO `DomainEventType` case.** `DomainEventType` is not a catalog — it is the webhook and native-connector **subscription vocabulary** and it feeds `openapi.json`, so a case there is a four-file act *with a published contract attached forever*. Gamification is an internal read-model; widening a public integration surface for it would be paid once and owed permanently. Seven of the eight listeners attach to events that already exist. The eighth act — form creation — had no event at all, so K1a adds `App\Events\FormCreated` as a **plain `Dispatchable`** on the `MemberJoined` precedent (`app/Events/MemberJoined.php:18-27` argues this exact trade). **The cost, stated rather than discovered: a tenant cannot fire a webhook when somebody starts a form.** Promoting it later is additive and breaks nothing.

**§D3 — The idempotency key is `(tenant_id, user_id, rule, subject_type, subject_id)`, every part NOT NULL, enforced by a unique index and honoured with `ON CONFLICT DO NOTHING`.** Two details are load-bearing and neither is obvious. **(a) No part may be nullable**, because in PostgreSQL a UNIQUE index treats NULLs as *distinct* — a nullable subject would let the same act be awarded repeatedly while the index looked like it was preventing exactly that. Every rule therefore has a real subject, including `member.invited`, whose subject is a **SHA-256 of the normalized email** (the event carries an address and no user id, and an address has no business in a scoreboard table). **(b) `ON CONFLICT DO NOTHING` rather than catching 23505**, because in PostgreSQL a constraint violation aborts the *whole* transaction: a caught duplicate would leave the caller's transaction poisoned. That is not hypothetical — `FormService::create()` is itself called from `TemplateService::instantiate()` inside an outer transaction, so a duplicate award raised there would take out a template instantiation that had nothing to do with scoring.

**§D4 — `append_only` RLS, and the weight is COPIED at award time.** `point_awards` gets the `audits` policy shape: SELECT and INSERT only, no UPDATE or DELETE policy at all, so FORCE RLS denies mutation to every role including the table's owner. Combined with `points` being written from `PointRule::points()` at award time rather than joined at read time, this makes one property structural: **re-weighting a rule moves future awards only, and the ledger keeps saying what it said.** A leaderboard that silently rewrites its own history the day somebody edits a constant is not a record of anything. It is also what lets the Settings → Modules card promise that switching gamification off *deletes nothing* as a fact about the schema rather than as an intention.

**§D5 — Streaks are DERIVED; the only stored history is the award ledger, which is BACKFILLED once from `audits`.** A streak is `DISTINCT awarded_at::date` walked backwards — nothing to drift, nothing to reconcile, and critically **no nightly job**, which Context §6 says would never run in production anyway. `awarded_at` is therefore a real column distinct from `created_at`: the K1c backfill writes rows *today* for acts that happened *months ago*, and a streak computed off `created_at` would show every historical workspace with one enormous single-day streak on install day. **Product decision 2026-08-17: backfill.** The alternative — everyone starts at zero — leaves the demo tenants empty and the feature untestable on arrival. ⚠️ The backfill must **not** run inside a migration: a migration executes with FORCE RLS and no tenant GUC, so `INSERT…SELECT` there writes zero rows. It is an operator command fanning out one `TenantAwareJob` per tenant.

**§D6 — `gamification` is a plan feature key granted on EVERY tier, including Free.** It is in `PlanCatalog::FEATURE_KEYS` only so the Settings → Modules card can render its toggle and the admin console can show `tenant_disabled` — `ToggleableModules::KEYS` may contain nothing the catalog does not, and `SettingsVocabularyTest` pins that. **Product decision 2026-08-17: not a commercial gate.** It is one of the six standing principles (PRD §3.7), and a tier ladder would make the principle invisible to precisely the tenants a first-run experience exists for. The consequence is that the **tenant's own toggle is the only control anyone has over it** — which is the intent, and which is why §D4's "nothing is deleted" guarantee matters more here than for any other toggleable module.

**§D7 — Leaderboard visibility reuses the org/own split; NO thirtieth permission key is minted.** **Product decision 2026-08-17.** Every member sees their own points, streak and standing ("4th of 12"); the **named** ranked list is gated on the existing `dashboard.org.view`. The 29-permission catalog (`RolePermissionSeeder::PERMISSIONS`) is closed and every addition has been argued individually; this one has no argument, because "who may see workspace-wide numbers about other people" is a question `dashboard.org.view` already answers for the dashboard and for submissions. Minting a key would also mean deciding which of five roles hold it — re-litigating a matrix that already encodes the answer.

**§D8 — A `guest` submission credits nobody.** `submission.collected` is awarded only where `submissions.respondent_user_id` is set — a member who encoded, synced or scanned it. The tempting alternative, crediting the form's owner, would turn the per-member ladder into a popularity contest decided by whoever published the busiest public link, which is not what *"who collected the most data"* means. Workspace-level "team progress" counts every submission. This is the **enumerator collection streak** the decision of record actually names.

---

## Consequences

**Good.** Scores cannot drift from their evidence, because there is only one representation. The exactly-once rule is enforced by an index rather than by care, so the four unbounded acts are safe by construction rather than by review. Nothing about scoring can break a real user action: awarding is post-commit where post-commit is available, cannot raise, and swallows-and-logs. The engine adds **no** public API surface, no webhook contract, no permission key and no scheduled job — so the deployment checklist does not grow.

**Bad, and accepted.** A tenant cannot subscribe an integration to `form.created` (§D2). Points totals are computed rather than stored, so a leaderboard over a very large ledger is an aggregate query rather than a lookup — acceptable at any plausible per-tenant volume, and the shape that would fix it (a rollup table on the `usage_counters` precedent) is additive. Re-weighting a rule produces a ledger where two awards for the same act are worth different amounts; that is §D4 working as intended, but it will look like an inconsistency to anyone who has not read this ADR, which is why `PointRule`'s own docblock repeats the argument.

**⚠️ One inherited wart, recorded rather than fixed.** `RequireFeature` raises `FeatureGateException`, whose copy is *"Your plan doesn't include X. Upgrade your plan to use it."* Because §D6 grants `gamification` on every tier, the **only** way that gate can fire for this key is a tenant that switched the module off itself — for whom the sentence is simply wrong. The wrong-copy-on-self-disable shape is pre-existing and shared with all eleven toggleable keys; K1a adds the label arm (so no 402 renders a raw snake_case key) and **K1d must gate the gamification surfaces on the module toggle rather than mounting `feature:gamification` as route middleware.**

---

## Alternatives considered

- **Derive everything from `audits` on read** — rejected in §D1. Retroactive for free, but couples scoring to a compliance ledger whose contents are decided by a different set of concerns.
- **A `points` balance column on `tenant_users`** — rejected in §D1 on `countGauge()`'s recorded drift argument, and because it cannot express "earned on".
- **A `gamification_events` table mirroring `DomainEventType`** — rejected as a second event vocabulary; §D2's whole point is that the acts already emit.
- **Storing streaks in their own table, maintained nightly** — rejected in §D5: derivable exactly, and the nightly job would not run (Context §6).
- **A `gamification.view_leaderboard` permission** — rejected in §D7.

---

## When to Revisit

- **A tenant asks to integrate on form creation.** Promote `FormCreated` to a `DomainEventType` case (§D2) — additive, four-file, breaks nothing.
- **A leaderboard query becomes slow on real data.** Add a rollup on the `usage_counters` precedent; the ledger stays authoritative and the rollup is reconcilable from it, which is precisely the property §D1 preserved.
- **The weights need to become tenant-configurable.** §D4 already makes this safe — historical awards keep their recorded value — but the rule catalog would graduate from a PHP enum to a table, the same graduation `UsageMetric`'s docblock anticipates for itself.
- **Someone proposes an UPDATE or DELETE policy on `point_awards`.** That is not a refinement; it is the ledger becoming rewritable. `PointAwardRlsTest` asserts the *absence* of those policies for this reason.
