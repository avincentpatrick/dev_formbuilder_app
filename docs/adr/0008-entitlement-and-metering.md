# ADR-0008: Entitlement & Metering Substrate (plans, subscriptions, usage counters — admin-assigned, no Cashier)

## Status

**Accepted — 2026-07-23.** Phase 3 (Increment H) ships twelve feature verticals, and nine of them are tier-gated by the pricing matrix (XLSForm, offline sync, save-and-resume, OCR, webhooks, native connectors, custom domains, advanced analytics, branding). Every one of those Phase-2 features that already exists today **ships ungated** — `PROGRESS.md` records the deliberate "gate them when the entitlement layer lands" debt three times (G7/G9a/G9b). There is no plan, subscription, quota, feature-flag, or metering row anywhere in the codebase: `plans`/`subscriptions`/`usage_counters` are `data-dictionary.md` §16–18 intent with no migration, and the enums (`PlanTier`, `UsageMetric`, `BillingInterval`) do not exist in `app/Enums/`. **Decision: build the entitlement model now, as an *admin-assigned* plan system with no Cashier and no Stripe dependency; resolve a tenant's entitlements through the plan of its active `subscriptions` row (never a `tenants.plan_id`); express feature access as a `plans.feature_flags` boolean map and quota limits as a `plans.quotas` metric→number map read by one `EntitlementService`; meter usage in period-bounded `usage_counters` rows; and split quota enforcement into hard-blocked, never-blocked, and rate-limitable classes so a respondent's submission is never rejected over the tenant's billing status.** This ratifies the gating decisions the pricing matrix left open (grandfather policy, connector/branding tiers, Business-tier hold-from-sale) and unblocks **H5a**, the entitlement model, which every gated Phase-3 vertical depends on so it is gated **from birth**.

<!-- pipeline: id=payments-checkout title="Embedded payments and self-serve billing — Cashier, Stripe Checkout, Stripe Tax" phase=4 state=held size=XL blocker="user: needs a Stripe account; cut from Phase 3 by decision 2026-07-21" -->

- **Deciders:** Founding engineering (architecture owner) + product.
- **Related ADRs:** **ADR-0002** (multi-tenancy via shared DB + RLS — `plans` is the one deliberate exception to its "every table carries `tenant_id`" rule, a global RLS-exempt catalog; `subscriptions`/`usage_counters` are strict-RLS tenant tables under its §D1). **ADR-0007** (async execution — its `MaintenanceJob` cross-tenant shape is the only correct home for the usage-counter rollup, and its §D9 fairness limiter already carries the plan-tier override hook this ADR feeds). **ADR-0009 (payments) was cancelled** — payments are deferred out of Phase 3 to Phase 4 (`PROGRESS.md` decision 2), which is *why* this ADR is admin-assigned rather than Cashier-backed.
- **Related docs:** `docs/data-dictionary.md` §16 (`plans`, `:581-606`), §17 (`subscriptions`, `:610-635`), §18 (`usage_counters`, `:639-661`), the enum catalog (`:45-47`), and the `tenants` billing columns (`:103-106`, `:122-123`); `docs/pricing-feature-gating-matrix.md` (the tier×feature and tier×quota matrices this ADR ratifies and extends); `docs/audit-compliance-logging-spec.md` (the H4 audit ledger this ADR's admin-assign writes to); `docs/multi-tenancy-rbac-design.md` §9 (super-admin console); `docs/non-functional-requirements.md` §2 (SLA target) and `docs/observability-incident-response.md` §8 (per-tier SLA).

---

## Context

Phase 3's spine (`PROGRESS.md`, the H-map) lands entitlements **early** (H5), so every gated feature is gated from birth and the Phase-2 retro-gate happens **once** (H5c) behind a ratified grandfather policy — rather than the "ship everything ungated, retro-gate later" pattern the tracker already regrets. Before the code (H5a) can be written, three of its most consequential parameters are undecided design questions, which is exactly why this ADR precedes it.

1. **The whole billing model was designed Cashier-backed, and Cashier is not installed.** `data-dictionary.md` §16 calls `plans` "Cashier-backed" and §17 calls `subscriptions` "the Cashier billable"; both carry Stripe columns (`stripe_price_id`, `stripe_customer_id`, `stripe_subscription_id`, `stripe_status`). But neither `laravel/cashier` nor `stripe/stripe-php` is in `composer.json`/`composer.lock`, no webhook handler syncs `stripe_status`, and **payments are deferred to Phase 4** by product decision. Building H5a as a Cashier integration would land a self-serve billing flow against infrastructure that does not exist and a payment provider the project has decided not to wire this phase. The dictionary itself flags this at `:583-584`: "Design intent, not near-term work … nothing consumes this column yet."

2. **The pricing matrix leaves three gating forks open** (`docs/pricing-feature-gating-matrix.md`, cross-referenced by the H-map's open questions). It pins quota *numbers* per tier and a feature-flag matrix, but it does **not** decide: (a) what happens to existing tenants on retro-gate day, when a live tenant that has been using ungated XLSForm/offline-sync/templates suddenly falls under a Free-tier flag set; (b) which tier the native connectors (Slack/Sheets/Airtable) belong to — the matrix has no connector row; (c) whether tenant branding is bundled with custom domains (Business) or unbundled. All three block H5a's seed data and H5c's retro-gate.

3. **The Business tier gates features whose SLA the platform cannot yet honour.** `pricing-feature-gating-matrix.md` §4 makes Business the first tier where the 99.5 % availability target (`non-functional-requirements.md` §2) becomes a *stated contractual commitment*. But the production host (Track B, the single Windows Server box) is deliberately **not stood up** (`PROGRESS.md`), and a single box is a single point of failure. Selling a contractual-SLA tier against an unbuilt, un-redundant host would be a commitment the platform cannot keep.

4. **A tenant's current plan has no obvious single source of truth.** `data-dictionary.md:122` states the deliberate design: **no `plan_id` column on `tenants`** — the current plan is "the plan of the tenant's active subscription," so billing state has one home (`subscriptions`), not two. And `tenants.status` (`TenantStatus`: active/suspended) is deliberately coarser than `subscriptions.stripe_status` (the finer Stripe vocabulary): the former answers "can this org sign in," the latter "what is its billing state." An `EntitlementService` must resolve through the subscription, and must define what "active" means and what an *unsubscribed* tenant gets.

5. **Metering must never become a data-collection gate.** The quota metrics (`data-dictionary.md:47`) include `submissions_count`. A respondent — often an anonymous guest with no visibility into the tenant's billing status — must never have their completed submission rejected because the *tenant* exceeded a monthly quota. So enforcement cannot be one uniform "block at limit" rule; it must distinguish resource-provisioning limits (safe to hard-block) from data-collection (never block) from rate-limitable API/webhook traffic.

**The core tension:** the inherited design is a coherent Cashier-backed self-serve billing system — installed nowhere, and gating features (Business SLA, payments) the platform has decided not to ship this phase. The austere alternative — ship every Phase-3 vertical ungated and "gate later" — is the exact debt the tracker regrets and would require retro-gating twelve features at once against no model. This ADR chooses a substrate that is **buildable and CI-verifiable this phase** (an admin-assigned plan model, no external dependency), gates every vertical from birth, keeps the schema Cashier-shaped so Phase 4 can adopt Cashier without a migration, and states honestly which parts of the inherited design are deferred rather than delivered.

---

## Options on the table

**Billing engine:**
1. **Admin-assigned plans, no Cashier** — a super-admin assigns a plan to a tenant through the existing platform console; no Stripe, no self-serve checkout, no webhook sync. Zero new external dependency; CI-verifiable today. Keeps the Stripe columns dormant so Phase 4 adopts Cashier as an additive change.
2. **Cashier + Stripe now** — the inherited design. Self-serve checkout, webhook-synced `stripe_status`, Stripe Tax. But it is a Phase-4 commitment pulled forward, adds two composer packages and an inbound-webhook ingress class with its own RLS-bootstrap problem, and cannot be exercised in CI without Stripe test fixtures.
3. **A bare feature-flag config file, no tables** — hard-code tier→flag maps in `config/`. Cheapest, but has no per-tenant assignment, no metering, no audit trail, and no path to the Cashier model the dictionary already specifies.

**Plan source of truth:**
1. **`tenants.plan_id` column** — one join hop, but two sources of truth for billing state (the column and the subscription) and a schema change the dictionary explicitly rejects (`:122`).
2. **The active `subscriptions` row's plan** — one home for billing state, matches the dictionary, and is what Cashier itself does. Requires defining "active."

**Quota enforcement model:**
1. **Uniform hard-block at limit** — simple, but rejects a guest respondent's submission over the tenant's billing status: an avoidable, serious harm.
2. **A three-way split** — hard-block resource-provisioning limits, never-block data collection, rate-limit API/webhook traffic — matching `pricing-feature-gating-matrix.md` §2's already-stated policy.

---

## Decision drivers

- **CI-verifiability outranks feature completeness.** Every increment is gated on six CI jobs. A Cashier integration CI cannot exercise (no Stripe fixtures) would regress silently — the same failure mode that left the current substrate as prose.
- **Nothing may depend on software that is not installed.** Cashier/Stripe are absent and payments are Phase 4; an admin-assigned model needs neither, and the schema stays Cashier-shaped so the eventual adoption is additive.
- **Gate from birth, retro-gate once.** Entitlements must exist before the gated verticals so each is born gated; the one-time Phase-2 retro-gate (H5c) must not regress any live tenant, which forces a ratified grandfather policy now.
- **A respondent's data is sacrosanct.** The tenant boundary protects the tenant; the *respondent* is protected by never letting a tenant's billing status reject a completed submission. Enforcement semantics follow from that, not from uniformity.
- **One source of truth for billing state.** The current plan is derived, never stored on the tenant — no second copy to drift.
- **Deferrals are labelled.** The corpus's standing problem is documents asserting built capability. Every Stripe column, the Business SLA, the metering rollup, and the grandfather storage are labelled as seams/deferred, not delivered.

---

## Decision

> **Plans are a global, RLS-exempt `plans` catalog assigned to tenants by a super-admin — no Cashier, no Stripe, no self-serve checkout this phase, with every Stripe column present but dormant so Phase 4 adopts Cashier additively. A tenant's entitlements are resolved by one `EntitlementService` through the plan of its active `subscriptions` row (`stripe_status ∈ {active, trialing}` and `ended_at IS NULL`), defaulting to the `free` tier when there is no active subscription. Feature access is a `plans.feature_flags` boolean map; quota limits are a `plans.quotas` metric→number map (null = unlimited); usage is metered in period-bounded `usage_counters` rows with a denormalized `limit_snapshot`. Enforcement (H5b) splits quotas into hard-blocked, never-blocked, and rate-limitable classes. Existing tenants are grandfathered indefinitely at the H5c retro-gate via a per-tenant legacy override. Business is built and gated but held from sale until Track B exists; the super-admin plan-assign is audited through the H4 `AuditLogger` as the first new consumer of that substrate.** This ADR is docs-only; H5a builds the model, H5b builds enforcement, H5c builds the retro-gate.

| Concern | Choice |
|---|---|
| Billing engine | **Admin-assigned plans, no Cashier** (§D1). Stripe columns present but dormant; Phase-4 Cashier adoption is additive. |
| Plan source of truth | **The active `subscriptions` row's plan** (§D2). No `tenants.plan_id`; unsubscribed ⇒ `free`. |
| Entitlement resolution | One `EntitlementService` reading `plans.feature_flags` + `plans.quotas` (§D3). Read-only in H5a. |
| Quota enforcement | **Hard-block / never-block / rate-limit** split (§D4); enforcement is H5b. |
| Grandfather policy | **Indefinite, per-tenant legacy override** applied at H5c (§D5). |
| Business tier | **Build + gate, hold from sale** until Track B; no contractual SLA clause in Phase 3 (§D6). |
| Connector / branding tiers | `native_connectors` = **Starter+**; branding unbundled → **Starter+**; `custom_domain` stays **Business** (§D7). |
| Metering | **Period-bounded `usage_counters`** + `limit_snapshot`; cross-tenant rollup is a `MaintenanceJob` (§D8, §D9). |
| Audit | Super-admin plan-assign audited via the **H4 `AuditLogger`** (§D10). |

### The ten sub-decisions

- **D1 — Admin-assigned plans, no Cashier.** A super-admin assigns a plan to a tenant through the platform console (`SuperAdminService`); there is no Stripe, no self-serve checkout, no `stripe_status` webhook sync this phase. `plans`/`subscriptions` keep **every** Cashier-shaped column (`stripe_price_id`, `stripe_customer_id`, `stripe_subscription_id`, `stripe_status`) but **nothing consumes them** — they are dormant so that when payments land in Phase 4, adopting `laravel/cashier` is an additive change (populate the columns, add a webhook handler) rather than a migration. This is a **deliberate, recorded deviation** from the Cashier-backed design intent of `data-dictionary.md` §16–17, not an oversight; the dictionary already anticipates it at `:583-584`. `stripe_status` remains **free text with no CHECK and no PHP enum** — the one flagged exception to the enum-everywhere rule (`data-dictionary.md:24`, `:760`) — because Stripe owns that vocabulary and can extend it outside the app's release cycle; a local CHECK would risk hard-rejecting a legitimate future status. For admin-assign, the console writes `stripe_status = 'active'`.

- **D2 — Current plan = the plan of the active subscription; no `tenants.plan_id`.** Billing state has exactly one home (`data-dictionary.md:122`). "Active" is defined here as **`stripe_status ∈ {active, trialing}` AND `ended_at IS NULL`**, newest by `created_at` — a definition that is meaningful both now (admin sets `active`) and after Cashier (webhook-synced statuses). **A tenant with no active subscription resolves to the `free` tier**: `free` is a real seeded plan, so "unsubscribed" and "on Free" are the same entitlement set, and there is no null-plan branch anywhere downstream. `tenants.status` (active/suspended) stays orthogonal — it answers "can this org sign in," not "what is its plan."

- **D3 — Entitlement resolution is read-only jsonb lookup through one service.** `EntitlementService` is the single interpreter of a tenant's entitlements (the `ResourceGrantResolver` precedent — one resolver so policy and UI cannot diverge). It answers: current plan/tier (via §D2), `feature(key): bool` from `plans.feature_flags`, `quota(metric): ?int` from `plans.quotas` (**null = unlimited**), and `usage(metric): int` from `usage_counters`. `feature_flags`/`quotas` are JSONB maps with **no DB CHECK on their keys** — validated at the application layer against the feature-flag key set and `UsageMetric::values()`. H5a ships the **read API and a fail-closed read-only Inertia prop only**; it writes no enforcement. The service exposes a **legacy-override seam** (§D5) that resolves *ahead of* the plan flags, returning empty in H5a.

- **D4 — Quota enforcement splits three ways (ratified from `pricing-feature-gating-matrix.md` §2; consumed by H5b).** Not one uniform rule:
  - **Hard-blocked** — `forms_count`, `storage_bytes`, `active_seats`: resource-provisioning limits, safe to block (cannot create/upload/invite past the limit until upgrade or freeing capacity). No data-loss risk.
  - **Never hard-blocked** — `submissions_count`: **a respondent's completed submission is never rejected because the tenant exceeded quota** (especially for anonymous guests with no control over the tenant's plan). Overage triggers a tenant notification/upsell, never a data-collection gate.
  - **Rate-limitable** — `api_requests`, `webhook_deliveries`: throttled (`429`/backoff, per `api-specification.md` §2.5 and the webhook retry ladder), never destroyed. H5b binds these; the per-tenant *burst* ceiling reuses ADR-0007 §D9's fairness limiter, and the per-*month* quota is the tier-metered value.

- **D5 — Grandfather indefinitely, via a per-tenant legacy override** (ratifies the H-map's answered open-question 3a). Tenants existing at the H5c retro-gate merge keep **every** ungated Phase-2 feature (`xlsform_export`, `offline_sync`, form-templates, field-library, `api_access`) **permanently** — zero merge-day regression. The override is a **per-tenant** grant that `EntitlementService::feature()` consults **before** the plan flags, so a grandfathered tenant sees `true` for a feature its plan would deny. H5a ships the **seam** (`legacyOverrides()` returning empty); **H5c** ships the storage and the merge-day backfill that stamps every then-existing tenant. Chosen over grandfather-with-sunset (a support and comms burden with a forced future downgrade) and gate-immediately (a merge-day regression for paying-in-attention customers who never agreed to a plan). *(As-built, H5c: the storage is the per-tenant `legacy_overrides` table (data-dictionary §18a); the backfill is `LegacyOverrideBackfill` run on the privileged connection from `2026_07_23_000005`, grandfathering `xlsform_export`/`offline_sync`/`form_templates`/`field_library`/`api_access` — the last two being new flag keys the retro-gate introduced. The paired `api_requests` monthly-quota 429 (§D4) also lands in H5c; a grandfathered tenant's Free `api_requests` quota of 0 is treated as unbounded, so the granted `api_access` is not hollow.)*

- **D6 — Business tier: build + gate, hold from sale** (ratifies answered open-question 4). Business's gated features (custom domains, advanced analytics) are **built and gated** in Phase 3, but Business is **not offered for signup** and **no 99.5 % contractual-SLA clause is published**, because the production host (Track B) is a single un-redundant box that is deliberately not stood up. Concretely, `business` (and `enterprise`, whose features are Phase 4) are **seeded `is_active = false`** — `is_active` governs *self-serve selection*, so they cannot be bought, but a super-admin **can** still assign them (the console is not bound by `is_active`), which is how a design-partner or internal tenant exercises the gated paths before sale. When Track B exists and redundancy/SLA is real, flipping `is_active` opens Business for sale with no code change.

- **D7 — Connector and branding tiers** (ratifies answered open-questions 3b/3c). `native_connectors` (Slack/Sheets/Airtable) = **Starter+ uniformly** — one `native_connectors` feature-flag key, not a per-connector matrix, since the connector framework is one capability. Tenant **branding** is **unbundled** from custom domains and moves to **Starter+** (a `branding` flag) — branding is a broad differentiator that should not require the Business-tier `custom_domain` purchase. `custom_domain` **stays Business**. These are new rows the pricing matrix did not have; this ADR adds them to `pricing-feature-gating-matrix.md` §3 in the same increment (doc reconciliation below).

- **D8 — Period-bounded metering.** `usage_counters` aggregates per **`(period_start, period_end)`** window (dates, not a single rolling counter) with a **denormalized `limit_snapshot`** (the plan quota that applied when the row last updated). Period bounds keep mid-cycle upgrades/downgrades and historical usage reporting accurate; `limit_snapshot` keeps a historical report correct even after the plan's quota changes. The unique key is `(tenant_id, metric, period_start)`. The `id` is a **`bigint identity`** (not uuidv7) — these are pure internal aggregation rows, never addressed externally (`data-dictionary.md:645`). `UsageMetric` is flagged (`:47`) as the enum most likely to graduate to a lookup table if per-integration/per-feature metering grows; recorded so it is not a surprise later.

- **D9 — The usage-counter rollup is cross-tenant → a `MaintenanceJob`** (ADR-0007 §D3), never a single-tenant `applyLocal` job. A per-tenant metering reset/rollup that used the single-tenant pattern would **silently no-op on every tenant but the one whose context happened to be set** — the exact hazard ADR-0007 names. The rollup fans out one tenant-scoped child per active tenant. This is a **forward reference**: H5a ships the `usage_counters` table + the read side (counters read as 0 when unmetered); the increment logic and the rollup job land with H5b enforcement.

- **D10 — The super-admin plan-assign is audited through the H4 `AuditLogger`** — the **first new consumer** of the audit substrate. It records `event = Updated` / `auditable_type = 'subscription'`, written **under the affected tenant's adopted context on the app connection** (the as-built H4 `SuperAdminService::changeStatus` pattern — one transaction, adopt the tenant's context so the strict INSERT/UPDATE and the audit INSERT both pass, restore in `finally`), **not** through an elevated connection or an RLS bypass. The H-map's literal "via `elevated()`" wording is superseded, exactly as H4 dropped its own planned elevated INSERT bypass as unused: `elevated()` is reserved for cross-tenant *reads* (a platform billing console listing every tenant's plan is a deferred later increment, as `SuperAdminService`'s own docblock already records). It records the acting super-admin's `user_id` (a human acted; `is_system_action = false`); the tenant sees an opaque actor uuid its own `users` RLS cannot resolve to a name.

**Method note.** This ADR is a **source audit plus design**, not a spike: no prototype was built and nothing was measured, so — like ADR-0007 and unlike ADR-0006 — it carries **no weighted scorecard**, because there is nothing measured to score and a rubric here would manufacture precision that does not exist. What is *evidence* is the as-is inventory in §Context: every "X exists / does not exist" claim was checked against source at authoring time (no Cashier in `composer.lock`; no `Plan`/`Subscription`/`UsageCounter` model; no `PlanTier`/`UsageMetric`/`BillingInterval` enum; no `plan_id`/billing column on `tenants`; `plans` has no `tenant_id` per `docs/data-dictionary.md` §16 (`plans`)). What is *reasoning* is everything downstream: the admin-assigned choice, the derived-plan resolution, the three-way enforcement split, and the tier assignments — the last of which are **product decisions ratified here**, not measurements. The quota *numbers* are `pricing-feature-gating-matrix.md`'s explicitly-unpinned planning defaults and are labelled as such in the seed data.

---

## Consequences (chosen path: **admin-assigned plans + `EntitlementService` + period-bounded metering**)

### Positive
- **Every Phase-3 vertical can be gated from birth.** The nine tier-gated verticals get a real feature-flag/quota model to check before they ship, so the Phase-2 "ship ungated" debt stops growing and is retired exactly once (H5c).
- **No new external dependency and no CI-unverifiable path.** No Cashier, no Stripe fixtures; the whole model runs on the existing Postgres and is exercisable in CI today.
- **The schema stays Cashier-shaped.** Phase-4 payment adoption is additive (populate dormant Stripe columns + add a webhook handler), not a migration or a redesign.
- **A respondent's data is protected by construction.** The never-block class means no guest submission is ever rejected over a tenant's billing status.
- **One source of truth for plan state**, resolved through the active subscription, with one `EntitlementService` so UI gating and server enforcement cannot diverge.
- **The H4 audit substrate gains its first new consumer**, proving the audit seam is reusable beyond the six sites H4 retired.

### Negative / accepted trade-offs
- **No self-serve billing.** A tenant cannot buy or change a plan themselves this phase; every plan change is a super-admin action. Acceptable — payments are Phase 4 — and recorded, not hidden.
- **The Stripe columns are dead weight until Phase 4.** Present, dormant, and a small standing "why is this here" cost, mitigated by the §D1 rationale in the schema notes.
- **Business is built but unsellable**, so gated Business features are exercised only via admin-assign until Track B exists — deliberate (§D6), not an omission.
- **Metering is read-only in H5a.** `usage_counters` read as 0 until H5b wires increments and the rollup; the read-model shape ships now so the UI and enforcement have a stable contract.
- **The grandfather override is a seam, not a mechanism, in H5a.** If H5c slips, no tenant is grandfathered yet — but no tenant is gated yet either (enforcement is H5b/H5c), so there is no window of regression.

### Risks & Mitigations
| Risk | Mitigation |
|---|---|
| A gated vertical ships before H5a and re-creates the "ungated" debt | H5a precedes every gated vertical in the H-map; the pricing matrix + this ADR name each vertical's tier so the gate is known before the feature is built |
| The retro-gate (H5c) regresses a live tenant | §D5 grandfathers indefinitely via a per-tenant override backfilled for every then-existing tenant on merge day; zero merge-day regression is the acceptance test |
| A respondent's submission is blocked by a quota | `submissions_count` is in the never-block class (§D4); H5b's submission path must not consult a hard-block; a test pins that a guest submission succeeds past the tenant's quota |
| Business sold before the SLA can be honoured | `business` seeded `is_active = false` (§D6); no contractual-SLA clause published in Phase 3; opening for sale is a deliberate later flip gated on Track B |
| The usage rollup silently no-ops on all-but-one tenant | It is a `MaintenanceJob` fan-out (§D9), never a single-tenant job; ADR-0007's `MaintenanceJob` contract and its tests are the guard |
| `plans` given `tenant_id`+RLS by reflex, hiding the global catalog per-tenant | §D1/§Method note and `docs/data-dictionary.md` §16 (`plans`) state `plans` is RLS-exempt with no `tenant_id`; migration-lint short-circuits on a table with no literal `tenant_id`, and adding an `EXEMPT_TABLES` entry is explicitly wrong |
| A local CHECK on `stripe_status` hard-rejects a future Stripe status | `stripe_status` is deliberately free text, no enum, no CHECK (§D1) |

---

## Alternatives Considered
- **Cashier + Stripe now** — the inherited design and the eventual Phase-4 destination. Rejected *for now*: payments are deferred to Phase 4, it adds two composer packages and an inbound-webhook ingress class with an RLS-bootstrap problem, and it cannot be exercised in CI without Stripe fixtures. Named as the additive upgrade path in §D1, not a rejection of the schema — which is kept Cashier-shaped precisely so the adoption is cheap.
- **A `tenants.plan_id` column** — rejected per §D2: two sources of truth for billing state and a schema change the dictionary explicitly forbids (`:122`).
- **A bare `config/` feature-flag map, no tables** — rejected: no per-tenant assignment, no metering, no audit trail, and no path to the Cashier model the dictionary specifies. It would have to be thrown away in Phase 4.
- **Uniform hard-block at every quota** — rejected per §D4/§Context 5: it rejects a guest respondent's completed submission over the tenant's billing status, an avoidable and serious harm.
- **A `SubscriptionStatus` PHP enum with a DB CHECK** — rejected per §D1: Stripe owns that vocabulary and extends it outside the app's release cycle; a CHECK would hard-reject a legitimate future status. `stripe_status` stays free text — the one deliberate exception to enum-everywhere.
- **Grandfather-with-sunset, or gate-immediately** — rejected per §D5: sunset is a support/comms burden ending in a forced downgrade; immediate gating is a merge-day regression for tenants who never chose a plan.

## When to Revisit
- **When payments land in Phase 4** — adopt `laravel/cashier`, populate the dormant Stripe columns, add the `stripe_status` webhook-sync handler, and open self-serve checkout; the schema needs no migration, and §D9's plan-tier fairness hook becomes live.
- **When Track B (the production host) is stood up with real redundancy** — flip `business.is_active` to open it for sale and publish the contractual-SLA clause (§D6).
- **On first real metering traffic** — validate the `pricing-feature-gating-matrix.md` §2 quota numbers against measured usage and replace the "planning default" label with data.
- **If per-integration or per-feature metering grows** — promote `UsageMetric` from a code enum to a tenant/admin-configurable lookup table (`data-dictionary.md:47` flags this).
- **When the platform billing console is built** — a cross-tenant read of every tenant's plan/subscription requires `elevated()` + a `superadmin_bypass` SELECT policy (deferred here, like H4 deferred cross-tenant audit search).

## Related Decisions
- **ADR-0002** (multi-tenancy, shared DB + RLS) — `plans` is the one deliberate exception to its "every table carries `tenant_id`" rule (a global RLS-exempt catalog); `subscriptions`/`usage_counters` are strict-RLS tenant tables under its §D1, and the super-admin plan-assign honours its §D3 "route through the one service."
- **ADR-0007** (async execution) — the usage-counter rollup uses its `MaintenanceJob` cross-tenant shape (§D3), and this ADR's per-tenant rate-limit binding (H5b) reuses its §D9 fairness limiter and the plan-tier override hook it already left for exactly this.
- **ADR-0009 (payments) — cancelled**; payments deferred to Phase 4. This ADR's admin-assigned model is the in-phase stand-in, and its Cashier-shaped schema is the forward seam.
- **H4 (audit + one event catalog)** — the super-admin plan-assign is the first new consumer of its `AuditLogger`; `AuditEvent::Updated` + `auditable_type = 'subscription'` need no change to the H4 catalog.
- **Doc #16/#17/#18 — Data Dictionary** — `data-dictionary.md` §16–18 describe the intended schema; H5a builds it as-built (admin-assigned, no Cashier) and annotates those sections in the same increment.
- **Doc — Pricing & Feature-Gating Matrix** — updated by this increment to add the `native_connectors` and `branding` rows (§D7) and to confirm `custom_domain` stays Business and embedded payments is Phase 4.

## References
- `docs/data-dictionary.md` §16 (`:581-606`), §17 (`:610-635`), §18 (`:639-661`), enum catalog (`:45-47`), `tenants` billing (`:103-106`, `:122-123`) — the intended schema this ADR rules on.
- `docs/pricing-feature-gating-matrix.md` — the tier×feature and tier×quota matrices ratified (§D4, §D6) and extended (§D7).
- `docs/adr/0002-multi-tenancy-shared-db-rls.md` §D1/§D3; `docs/adr/0007-async-execution-substrate.md` §D3 (cross-tenant `MaintenanceJob`), §D9 (fairness + plan-tier override hook).
- `docs/audit-compliance-logging-spec.md`; `app/Support/Audit/AuditLogger.php`, `app/Services/Admin/SuperAdminService.php` — the H4 audit substrate and the adopt-context pattern §D10 reuses.
- `docs/multi-tenancy-rbac-design.md` §9 (super-admin console), `docs/non-functional-requirements.md` §2 (SLA target), `docs/observability-incident-response.md` §8 (per-tier SLA).
- `composer.json`/`composer.lock` — verified to contain **no** `laravel/cashier` or `stripe/stripe-php` at authoring time (the premise of §D1). Re-verify at implementation time per this project's standing caveat on fast-changing external facts.
