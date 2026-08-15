# ADR-0002: Multi-Tenancy Isolation Model — Shared Database, Shared Schema, `tenant_id` Discriminator, Reinforced with PostgreSQL Row-Level Security

## Status

**Accepted** — 2026-07-03. **Narrowed (not superseded) by ADR-0013, 2026-08-05:** this ADR made RLS *the* database-level guard idiom, and `docs/form-versioning-schema-migration.md` §2 read that as "never a trigger." ADR-0013 draws the boundary explicitly — **row-scoped invariants stay RLS; an invariant that must compare the OLD row to the NEW one gets a trigger, because a policy sees only one of them** (`USING` = OLD, `WITH CHECK` = NEW). Exactly one invariant qualifies today (published form-version immutability, H25). Everything in §D2 below is unchanged and remains the default for every tenant-scoped table.

- **Deciders**: Founding engineering (architecture owner), with product sign-off on the roadmap implications (Phase 4 dedicated-DB deferral).
- **Supersedes**: N/A (greenfield decision; no prior ADR exists for this project).
- **Related ADRs**:
  - ADR-0001 — *Database Engine: PostgreSQL over MySQL* (prerequisite — this decision is only possible because Postgres was already selected; MySQL has no equivalent to Postgres's native Row-Level Security).
  - ADR-0003 — *Hosting Platform Selection* (downstream — hosting choice must support Postgres RLS, session-scoped `SET LOCAL` semantics under connection pooling, and per-tenant backup granularity discussed below).
  - A future ADR (unnumbered at time of writing) will cover *Dedicated-Database Tenancy for Enterprise Tenants*, scoped to Phase 4 — this ADR deliberately defers that decision rather than making it now (see [Consequences → Future Migration Path](#future-migration-path-out-of-scope-today-but-must-remain-possible)).
- **Scope note**: This ADR consolidates two closely related decisions the architecture plan lists separately — the *tenancy isolation model* and *RLS adoption* — into a single ADR, because in this project they are one decision: the isolation model **is** "shared schema + RLS as backstop," not two independently-choosable things.

---

## Context

### Why this decision exists at all

This is a brand-new, greenfield multi-tenant SaaS product (a form-builder / data-collection platform, in the spirit of KoboToolbox and Fillout.com). It must support many independent customer organizations ("tenants") — NGOs, research teams, government units, and general business users — each with their own forms, submissions, users, and billing, from day one.

Critically: **the legacy system this project draws feature inspiration from (`dev_pk_new`) was never actually multi-tenant.** It was built single-org, for a single government department, with no `tenant_id` concept anywhere in its schema, no tenant-scoped authorization model, and no precedent — good or bad — for how to isolate one customer's data from another's inside a shared application. Every other subsystem in this project (submission pipeline, form versioning, audit trail) had a legacy implementation to audit, learn from, and consciously improve. Multi-tenancy has none. This ADR is being written on a blank slate, which raises the bar for rigor rather than lowering it — there is no "at least it's better than what we had" fallback here, because there was no "what we had."

### What is at stake

Tenant data isolation is the single highest-blast-radius correctness property this system has. A bug in form validation, versioning, or the expression engine produces a bad answer, a rejected submission, or an incorrect calculated field — bounded, recoverable, and visible to at most one tenant. A bug in tenant isolation produces **Tenant A reading or writing Tenant B's health survey data, form schemas, or submission records** — a category of failure that:

- Is often silent (the query "succeeds," it just returns or mutates the wrong tenant's rows),
- Can affect every tenant simultaneously depending on where the mistake sits,
- Is a direct breach of customer trust and, for tenants collecting health/personal data, a plausible data-protection/compliance incident (relevant given this product's DOH/M&E lineage and its planned GDPR-oriented data handling — see the plan's Documentation Artifact #12, *Data Privacy & GDPR/Compliance Doc*),
- Cannot be meaningfully "patched around" after the fact — the only real fix is architectural discipline applied *before* the first tenant-scoped table is migrated.

This ADR treats tenant isolation as a security control, not merely a data-modeling convenience, and is written accordingly: defense-in-depth over any single point of enforcement.

### Constraints and inputs already fixed by the architecture plan

The following were already decided in the approved architecture plan and are treated as fixed inputs to this ADR, not re-litigated here:

- **Backend**: Laravel 11/12, PHP 8.3+.
- **Database**: PostgreSQL (ADR-0001), specifically *because* it offers native Row-Level Security and JSONB+GIN, neither of which MySQL provides at parity.
- **Multi-tenancy package**: `stancl/tenancy`, described in the plan as the "2026 production-standard package" for Laravel multi-tenancy, which natively supports both a single-database (shared-schema, discriminator-column) mode and a multi-database (one physical database per tenant) mode under one abstraction. *(This ADR said "v4" here and in four other places; **v3.10.0** is what is installed — corrected 2026-08-14 by P2a, see the ⚠️ note under "Future migration path".)*
- **RBAC**: Spatie Laravel-Permission, tenant-scoped via its "teams" feature (a separate, complementary concern to isolation — RBAC governs *what a user in Tenant A is allowed to do*; this ADR governs *whether Tenant A can touch Tenant B's rows at all*, regardless of role).
- **Team size/stage**: a small team building toward an MVP launch, not a funded platform team standing up per-tenant infrastructure from day one. Cost and operational simplicity at this stage are real, first-class constraints, not excuses.
- **Expected tenant profile at launch**: a modest number of tenants (low tens to low hundreds) growing over time; most tenants are expected to be cost-sensitive SMB/NGO/research customers, with a smaller number of larger, possibly compliance-driven (government/enterprise) tenants anticipated later rather than at launch. This assumption is a concrete extrapolation from the plan's phase roadmap (dedicated-DB tenancy and SSO/SAML/data-residency are explicitly Phase 4 items) rather than a number stated verbatim in the plan, and is noted here as such.
- **Current (2026) industry guidance**, as cited in the plan: shared-database/shared-schema with a tenant discriminator is "right for ~90% of SaaS at this stage," with migration of high-value/compliance-driven tenants to isolated databases *later, only if justified* — i.e., the plan already points at a specific answer; this ADR's job is to make that answer concrete, complete, and enforceable, not to re-open it.

### Options on the table

Three well-established isolation models exist for multi-tenant relational data, and all three were considered:

1. **Shared database, shared schema, `tenant_id` discriminator column** — all tenants' rows live in the same tables, distinguished by a column.
2. **Shared database, schema-per-tenant** — one Postgres schema (namespace) per tenant, same physical database/instance.
3. **Database-per-tenant** — a fully separate physical database (potentially a separate connection, credential set, and even separate server) per tenant.

---

## Decision

**Adopt shared database, shared schema, with a `tenant_id` discriminator column on every tenant-scoped table — implemented via `stancl/tenancy` in single-database mode — and reinforce it with PostgreSQL Row-Level Security (RLS) policies on every tenant-scoped table as a database-enforced backstop.** Tenant context is treated as a system-wide constraint enforced independently at every architectural layer, not solely as an application-query concern.

This is a "trust, but verify" architecture: the application is expected to get tenant scoping right (global scopes, tenant-aware base models, middleware), and the database is configured to make it structurally impossible to serve cross-tenant rows even if the application layer fails to.

### D1. Isolation model

- Every tenant-scoped table carries a non-nullable `tenant_id` column: **UUID (v7, time-ordered)**, foreign-keyed to `tenants.id`. This is a concrete choice beyond what the plan states explicitly (the plan specifies "shared schema + `tenant_id` discriminator" but not the column's data type), made for these reasons:
  - UUIDs avoid sequential-ID enumeration risk on any code path that might ever leak an identifier (URLs, exports, API responses, error messages) — a materially worse outcome for a `tenant_id` than for most other IDs, since guessing one gives an attacker a concrete target to probe.
  - UUIDv7 (time-ordered) is used rather than UUIDv4 (fully random) to preserve reasonable B-tree index locality/insert performance, since `tenant_id` is the leading column of most composite indexes in this schema.
  - UUIDs are what a future dedicated-database migration (Phase 4, see below) will need anyway to merge/export/import tenant data without collision risk — choosing them now avoids a painful retrofit later.
- `tenant_id` is always the **first column in composite indexes** on tenant-scoped tables (e.g., `(tenant_id, form_id)`, `(tenant_id, created_at)`), since virtually every query in the system filters by tenant first.
- Non-tenant-scoped tables are explicitly exempted and enumerated, not left ambiguous:
  - The central `tenants` table itself (and stancl/tenancy's own bookkeeping tables — domains, tenant metadata).
  - Genuinely global reference/lookup data intended to be identical for every tenant (e.g., the field-type catalog, if modeled as a table rather than a backed enum — per the plan, field types are native PHP backed enums, so this case mostly does not arise, but any future shared lookup table follows this same explicit-exemption rule).
  - Platform-operational tables (queue job records prior to tenant resolution, system-wide feature flags, plan/pricing catalog rows shared across tenants — as opposed to `subscriptions`/`usage_counters`, which *are* tenant-scoped) — and the Spatie global-to-global `role_has_permissions` mapping, which has no tenant dimension at all (Multi-Tenancy & RBAC Design Doc §4).
  - **Laravel/Fortify framework-internal plumbing** — `sessions`, `password_reset_tokens`, and equivalent tables the framework manages directly (added on review of the Multi-Tenancy & RBAC Design Doc, `docs/multi-tenancy-rbac-design.md`): these run under framework-internal sweeps/garbage-collection with no per-request tenant context established, so forcing RLS on them risks silently breaking that maintenance machinery rather than adding a meaningful isolation guarantee — the actual sensitive identity data these tables reference already lives on `users`, which does carry its own RLS policy (see the Multi-Tenancy & RBAC Design Doc §6 for that policy's shape).
  - Every table not on this exemption list is assumed tenant-scoped by default, and CI enforces that assumption (see [D6](#d6-verification--ci-gates)).

### D2. Reinforcement via Postgres Row-Level Security

For every tenant-scoped table:

```sql
ALTER TABLE submissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE submissions FORCE ROW LEVEL SECURITY; -- applies even to the table owner / app DB role

CREATE POLICY tenant_isolation_select ON submissions
    FOR SELECT
    USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid);

CREATE POLICY tenant_isolation_write ON submissions
    FOR INSERT
    WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid);

CREATE POLICY tenant_isolation_update ON submissions
    FOR UPDATE
    USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
    WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid);

CREATE POLICY tenant_isolation_delete ON submissions
    FOR DELETE
    USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid);
```

> Note: this illustrates the pattern using `submissions` (the ratified table name — Data Dictionary §7). An earlier version of this ADR used the pre-rename legacy name `form_submissions` here; that was a leftover from before the data model's renaming pass, not an intentional reference to the legacy schema.

Key concrete decisions here, beyond what the plan states (the plan says "Postgres RLS...driven by a per-request session variable" without specifying the mechanism — the following is this ADR's concretization, flagged as such):

- **`FORCE ROW LEVEL SECURITY` is mandatory**, not optional. Laravel's application database connection typically runs as a single Postgres role that *owns* the tables (as the migration runner). By default, Postgres RLS does not apply to a table's owner — `FORCE ROW LEVEL SECURITY` closes that gap, which would otherwise silently defeat the entire backstop.
- The session variable is `app.current_tenant_id`, set via `SET LOCAL app.current_tenant_id = '<uuid>';` **inside the same transaction/connection-lease as the request**, not via a separate `SET` call that could leak across pooled connections. Concretely: a dedicated middleware (running immediately after stancl/tenancy's tenant-identification middleware, before any controller or model code executes) wraps tenant context establishment, and every request runs its database work inside a transaction (or, at minimum, re-issues `SET LOCAL` at the start of every pooled-connection checkout) so that connection pooling (PgBouncer or Laravel's own persistent connections) can never let one request's leftover session variable leak into the next request served on a reused connection. This connection-pooling hazard is a well-known sharp edge of session-variable-based RLS and is called out explicitly here so it is designed for from day one rather than discovered in an incident.
- `current_setting('app.current_tenant_id', true)` uses the two-argument form (`missing_ok = true`) so that a connection with no tenant context set returns `NULL` rather than erroring — and `tenant_id = NULL` matches **zero rows**, i.e., the fail-safe default is "no tenant context ⇒ no rows visible," never "no tenant context ⇒ all rows visible." This fail-closed property is the entire point of the backstop and is treated as non-negotiable.
- RLS policies are generated by a **reusable migration helper/trait** (e.g., a `TenantScopedMigration` base or a `withTenantIsolation($table)` helper invoked at the end of every tenant-scoped table's migration), not hand-written per table — reducing the chance any single migration author forgets a policy.
- **Named exception — nullable-`tenant_id` "global" rows**: a reusable pattern, not a one-off. Five tables so far — `field_library`, `form_templates` (Data Dictionary #11–12), `settings` (Data Dictionary #20, added after this ADR was first written), and `roles`/`permissions` (Multi-Tenancy & RBAC Design Doc §4, `docs/multi-tenancy-rbac-design.md`, added as adopters when that doc's fixed, platform-defined role catalog was written) — use `tenant_id IS NULL` to mean "platform-provided, visible to every tenant" (e.g. the onboarding template gallery, a platform-wide setting like whether new tenant signup is open, or the closed role/permission catalog every tenant shares). The strict-equality policy above never matches `NULL`, so **any table adopting this pattern** widens its `SELECT` policy to `USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid OR tenant_id IS NULL)`; `INSERT`/`UPDATE`/`DELETE` policies stay strict (a tenant-scoped connection can never write a `NULL`-tenant row — only the elevated platform-admin/seeder role in D3 does). This is the **only** deviation from "one flat policy shape, no exceptions" anywhere in this schema. It is deliberately called out by name here (and cross-referenced from every adopting table's own Design Notes) precisely so it doesn't get silently "fixed" back to the strict form by someone applying the migration helper without reading this ADR — and so that a *future* table adopting the same nullable-`tenant_id`-means-global convention is recognized as reusing an established, reviewed pattern rather than inventing a fourth ad hoc variant. Note that `roles`/`permissions`' *own* per-tenant *assignment* rows (`model_has_roles`/`model_has_permissions`) are the opposite case — strictly tenant-scoped, not nullable — only the role/permission *definitions themselves* are global; see the RBAC doc §4 for the full distinction.
- **A table using this pattern is not automatically exempt from other RLS considerations** — `user_ui_preferences` (Data Dictionary #19) looks superficially similar (it also lacks a populated `tenant_id`) but is a genuinely different case: it has **no `tenant_id` column at all**, because personal UI preferences belong to a user across all of that user's tenant memberships, not to any one tenant. Its RLS policy is keyed on `user_id = current_setting('app.current_user_id', true)::uuid` instead — a "belongs to me" policy, not a "belongs to my tenant, or nobody's" policy. Do not conflate the two exceptions: nullable-`tenant_id`-means-global (this bullet) is about rows every tenant can *read*; no-`tenant_id`-at-all (the next paragraph up, one level of RLS reasoning removed) is about rows scoped to a person instead of an organization. A **third, related but distinct case**: the RBAC doc's `users` table has no `tenant_id` either, but its RLS policy is neither of the above two shapes — it must be visible both to itself *and* to anyone who currently shares an active tenant membership with it, which needs a join through `tenant_users`, not a flat equality check. This is a fourth policy shape, not a variant of the two established here; see `docs/multi-tenancy-rbac-design.md` §6 for the concrete SQL, deliberately not duplicated into this ADR.

### D3. Layer-by-layer enforcement

Tenant context is established and/or re-validated independently at every layer the plan calls out, so that no single missed check anywhere in the stack results in a leak:

| Layer | Enforcement mechanism | Notes |
|---|---|---|
| **Routing** | Tenant resolved from subdomain (`{tenant}.appdomain.com`) via `stancl/tenancy`'s early tenant-identification middleware, running before any route-specific middleware/controller code. | Custom-domain resolution (a Phase 3 paid-tier feature per the roadmap) uses the same middleware pipeline, just a different domain-to-tenant lookup. |
| **Application/session context** | Immediately after tenant identification, a dedicated `EstablishTenantDatabaseContext` middleware issues `SET LOCAL app.current_tenant_id` — and, for an authenticated request, also `SET LOCAL app.current_user_id` (left unset/NULL for guest requests, which never read `users`/`user_ui_preferences`) — on the active DB connection for the remainder of the request/transaction. | This is the bridge between "the app resolved a tenant" and "the database will enforce it" — without this step, RLS has nothing to check against. The `app.current_user_id` variable is **required** by the `users` (Multi-Tenancy & RBAC Design Doc §6) and `user_ui_preferences` (D2) RLS policies, which key on it rather than on `tenant_id`; omitting it makes those tables fail closed so a logged-in user cannot read their own row or theme. |
| **Data access (ORM)** | An Eloquent global scope (`BelongsToTenant` trait applied to every tenant-scoped model) automatically injects a `where tenant_id = ?` predicate into every query built through Eloquent. | Deliberately treated as a convenience/performance layer and a first line of defense — **never relied upon alone**, per the plan's explicit instruction. RLS is what catches the cases this layer misses (raw `DB::` queries, a forgotten trait on a new model, a report-building query that intentionally drops scopes for aggregation and gets the tenant filter wrong). |
| **Database (backstop)** | Postgres RLS policies (D2), `FORCE ROW LEVEL SECURITY`, fail-closed on missing session variable. | The layer this ADR is centrally about. Independent of application code correctness. |
| **Jobs / queue** | `tenant_id` is serialized into every queued job's payload. **As-built mechanism, per ADR-0007 §D2** (this row previously described a `TenantAware` trait/middleware and stancl-managed tenant context; neither was ever built, and stancl runs with `bootstrappers => []` so it establishes no DB context at all): the `TenantAwareJob` base class re-establishes context as the first act of `handle()`, by calling `TenantContext::applyLocal()` **inside `DB::transaction`** — `applyLocal` is transaction-scoped, and a worker has no ambient request GUC, so the work must run within that transaction or RLS scopes it to zero rows. It also sets Spatie's permissions team id, which the HTTP middleware sets and no job did. | A job that lacks a `tenant_id` in its payload fails fast (explicit exception) rather than running with no tenant context (which, thanks to D2's fail-closed default, would simply see zero rows rather than the wrong tenant's rows — but failing loudly is still preferred so the bug surfaces immediately in monitoring rather than as silently-skipped work). **This fail-fast guarantee is delivered by ADR-0007 §D2's runtime uuid assertion — until 2026-07-21 it was aspirational**, and worse, §D4 documents a live leak this row's phrasing concealed: `TenantContext`'s PHP static is process-lifetime and was never reset between jobs, so a *second* job could inherit the previous tenant's id in `BelongsToTenant` while the DB GUC read NULL. ADR-0007 §D4's queue-event flush listener closes it. |
| **Object storage (S3-compatible)** | Keys namespaced `tenants/{tenant_id}/...`. Signed URLs / upload policies are generated server-side from the authenticated request's resolved tenant — **never** trusted from a client-supplied path segment or form field. | Applies to the polymorphic `attachments` table (plan §2.2) uniformly, replacing legacy's ad hoc path-column sprawl. |
| **Cache (Redis)** | Cache keys prefixed by `tenant_id` (e.g., `tenant:{tenant_id}:dashboard:kpis`). | This is *logical* namespacing within one shared Redis instance, not physical isolation — documented as an accepted trade-off consistent with the shared-DB posture; revisit only alongside a Phase 4 dedicated-infrastructure decision, not independently. |
| **Guest / public submission endpoints** | These bypass normal session-based auth entirely, so tenant resolution cannot come from a logged-in user's session. Instead, the form's signed share token (bound to a specific `form_version_id`, per the plan's guest-response design) is validated server-side first, the tenant is derived from the token's underlying form record, and *then* the same `SET LOCAL app.current_tenant_id` + RLS machinery is engaged before any tenant-scoped table is touched — a guest request is never allowed to reach a query with no tenant context established. | This is the layer most likely to be overlooked (it's the one authenticated-request-shaped reasoning doesn't naturally cover), which is exactly why the plan calls it out by name and why it is enumerated explicitly here rather than assumed to be "covered by routing." |
| **Realtime (Reverb)** | Private/presence channel names are namespaced by tenant (e.g., `private-tenant.{tenant_id}.dashboard`), and channel authorization callbacks re-verify the requesting user's tenant membership server-side — a client cannot simply subscribe to another tenant's channel name by guessing it. | Not called out as a separate bullet in the plan's §2.1 list, but a direct consequence of "enforce tenant context as a system-wide constraint" applied to Reverb, which the plan elsewhere selects for live dashboards/builder presence/sync triggers. Flagged as a concrete extension made here. |
| **Super-admin** | An explicit `is_super_admin` boolean on `users` (never a hardcoded/positional ID convention, per the plan). Legitimate cross-tenant operations (support tooling, billing reconciliation, platform analytics) go through a narrow, explicitly-audited service layer that uses a **separate, elevated Postgres role** with its own carve-out policy (`CREATE POLICY superadmin_bypass ON submissions USING (current_setting('app.is_superadmin_context', true) = 'true')`), rather than ever disabling RLS wholesale on the main application role. | Concrete mechanism decision beyond the plan's language, made because "just disable RLS for super-admins" would reintroduce exactly the single-point-of-failure risk this ADR exists to avoid. Every use of the elevated role is itself logged via the carried-forward `Auditable` trait. |

**Amendment (Increment I7b, 2026-08-08) — a carve-out MAY carry additional conjuncts, and on some tables it must.**
The gate shown in the Super-admin row above is a **default, not a contract.** It is correct on a table whose
base shape is the nullable-`tenant_id` "global" exception in D2 — the platform slice is already readable by
every tenant there, so widening the elevated role to "all rows" grants it nothing it could not already
reach. It is **wrong** on a table whose base shape is strict or append-only, where "all rows" means every
tenant's complete history. `audits` is the first such table: the unrestricted form would have handed the
platform operator every form title, every reviewer's `returned_reason` and every membership change in the
deployment, bought in order to display a handful of platform-settings rows. It therefore takes the narrowed
form — `current_setting('app.is_superadmin_context', true) = 'true' AND tenant_id IS NULL`
(`2026_08_08_000001_apply_platform_audit_read_to_audits.php`) — which reaches the PLATFORM slice and nothing
else, so **a tenant still cannot read the operator's actions and the operator cannot read a tenant's.**

D2's "generated by a reusable migration helper … not hand-written per table" rule is honoured rather than
excepted: the narrowed shape is a NAMED generator, `TenantIsolation::platformRowsBypass()` /
`platformRowsBypassSql()`, whose emitted SQL is pinned in `tests/Unit/TenantIsolationSqlTest.php` exactly as
its unrestricted sibling's is. `tests/Feature/Audit/PlatformAuditRlsTest.php` additionally asserts the LIVE
policy's `qual` from `pg_policies` — the only such assertion in the suite — so a dropped conjunct is a
merge-blocking failure rather than a review miss. (Mutation-verified: swapping the generator for
`applySuperAdminBypass()` reddens three cases, including the one that proves the operator cannot read a
tenant row.)

The policy is named `{table}_platform_{command}`, deliberately OUTSIDE the `{table}_superadmin_%` prefix the
bypass migrations sweep in their `down()`. A shared prefix would let an unrelated rollback drop it while its
`GRANT` survived, and the reading page would then render zero rows with no error under copy stating that
nobody had ever performed a platform action — a plausible lie on a compliance surface. It also keeps
`SELECT policyname FROM pg_policies WHERE policyname LIKE '%_superadmin_%'` an honest answer to "where does
the operator hold an unrestricted read".

### D4. `stancl/tenancy` configuration specifics

- Deployed in **single-database mode** (tenant identification + scoping only), not its multi-database/connection-swapping mode — the multi-database mode is explicitly reserved as the mechanism for the future Phase 4 dedicated-DB option (see below), not enabled now.
- The **central app** (marketing site, tenant self-registration/signup, billing portal) runs outside any tenant context, on its own domain (e.g., `app.example.com` or a root marketing domain), consistent with stancl/tenancy's central/tenant app split.
- **Tenant apps** resolve via subdomain (`{tenant}.example.com`) for MVP; custom-domain support is deferred to Phase 3 (already gated there in the roadmap as a paid-tier feature), reusing the same middleware pipeline with a domain-to-tenant lookup table instead of subdomain parsing.
- One shared set of migrations, run once, applies to every tenant equally (no per-tenant migration runner in single-database mode) — a direct operational simplicity benefit of this model, expanded on under Consequences.

### D5. Schema and query conventions (concrete, project-wide rules)

- No foreign key, unique constraint, or index may span tenant boundaries. Every unique constraint that is logically "unique per tenant" (e.g., a form's `public_slug`, a section's `name` slug) is declared as a **composite unique constraint including `tenant_id`** (`unique(tenant_id, public_slug)`), never a bare global-unique constraint — both because global uniqueness across tenants is rarely the actual business rule, and because a global constraint would become a real obstacle to the future per-tenant extraction path described below.
- Every raw/`DB::` query (reports, bulk operations, analytics) must explicitly include the tenant predicate in code and is flagged in code review as a location where the ORM global-scope convenience layer does *not* apply — RLS is the actual guarantee for these, which is precisely why RLS exists rather than treating "always use Eloquent" as sufficient policy.
- Tenant-scoped Eloquent models extend a common base model / apply a common `BelongsToTenant` trait, so the global scope is structural (inherited), not something each new model author must remember to add manually.

**Amendment (Increment P2c, 2026-08-14) — the rule is now measured, and the as-built schema does not satisfy it.**
Nothing had ever checked the first clause. `App\Support\Tenancy\ConstraintBoundaries` holds the census with a
rationale per entry; `tests/Feature/Tenancy/ConstraintBoundaryDriftTest.php` asserts it against `pg_index` and
`pg_constraint` in both directions, and `scripts/constraint-boundary-lint.php` names the migration line that
wrote each one. Measured on a freshly migrated `meridian_testing` (56 base tables):

| | Total | Crossing the boundary | Recorded |
|---|---|---|---|
| Unique, non-primary indexes | 43 | 13 on a `tenant_id`-carrying table without it in the KEY | 13 |
| Foreign keys | 113 | 29 whose TARGET carries `tenant_id` while the key does not | 29 |
| Composite `(tenant_id, x_id)` FKs | 9 | — | the compliant shape |

⚠️ **The two TOTALS in this table were wrong when first written, and the way they were wrong is worth more than
the numbers.** They said 42 and 112, taken from a `psql` census run against a `meridian_testing` that was two
migrations stale — J3c2's `google_auth_requests` (2 unique indexes, 1 FK) and `users.google_id` (1 unique index)
were simply not in it. The **crossing** counts were right throughout, because those came from the drift test,
which runs under `RefreshDatabase` and therefore against a schema migrated seconds earlier. **A number
measured against a convenient database and a number measured against a migrated one are different kinds of
claim**, and only the second belongs in a document. P2a paid for this once already, sweeping a `meridian`
three migrations behind and reporting every total one short.

⚠️ **The FK crossing count moved too, and NOT for that reason — it went 26 → 29 when the predicate was
corrected**, not when the database was refreshed. The first sweep asked "do both tables carry `tenant_id`",
which silently drops every FK whose *source* has none: `role_has_permissions`'s two and
`tenants.logo_attachment_id`. Recorded separately from the staleness lesson above so that a predicate bug is
not laundered as a data-freshness one — they are different mistakes and only one of them is about
databases.

⚠️ **The unique half is mostly principled and the foreign-key half is mostly not.** Of the 13 indexes, **9**
are values that are globally unique by definition (a hostname), matched before any tenant is resolved (an
OAuth state on the central host, a SAML `InResponseTo`), or secrets where a cross-tenant collision would
itself be the bug — and **4 are merely UUID-anchored**, which is a weaker claim: they are safe because
guessing a UUIDv7 is infeasible, not because global uniqueness is the intent. *(An earlier draft said 11 and
2. It promoted into the principled bucket the two entries whose own recorded reason says "what makes this
safe is UUID unguessability, NOT referential integrity" — making the unique half look better founded than
the catalog supports.)* ⚠️ **One of the 13 is not safe by intent at all**: `permissions_name_guard_name_unique`
is `(name, guard_name)` where its sibling `roles_tenant_id_name_guard_name_unique` leads with the tenant, and
`permissions_tenant_insert` permits a tenant-owned row today — so the asymmetry is a live defect recorded
rather than fixed, with its own revisit trigger.

The 29 foreign keys are different in kind: mostly the Phase 0–1 core schema, written before the convention
was applied consistently. ⚠️ **But not "all of them", and the tidy version of that sentence did not survive
checking**: `usage_counters_subscription_id_foreign` (07-23), `tenants_logo_attachment_id_foreign` (08-05)
and `feedback_reports_screenshot_attachment_id_foreign` (08-07) were all authored *after* `scope_nodes`
introduced the composite shape on 2026-07-20 — the last of them eighteen days after. **This is still a gap
the ADR did not know it had rather than a set of decisions it made**, but the gap was being widened while
nothing measured it, which is the argument for the gate rather than for a convention.

⚠️ **RLS is not a backstop for either.** PostgreSQL documents that "referential integrity checks, such as
unique or primary key constraints and foreign key references, always bypass row security". So a unique index
without `tenant_id` can refuse a tenant a value because of a row it cannot see, and a foreign key without it
will accept a reference to a neighbour's row and act on the `ON DELETE` clause — on 20 of the 29, `CASCADE`.
`docs/data-dictionary.md` already states this for `sso_auth_requests`, whose FK is composite for exactly this
reason; P2c is that reasoning applied to the rest of the schema.

**The rule's third noun — "or index" — is discharged by D1's ordering rule, not here.** A non-unique index
constrains nothing: it cannot refuse a write or cascade a delete, so it cannot span a boundary in any sense
this ADR can enforce. Ten plain indexes on tenant-scoped tables do not lead with `tenant_id` (the PostGIS
`submission_geo_index_geom_gist` and the polymorphic morph indexes among them); they are a query-planning
matter under D1 and are listed in `docs/feature-backlog.md` rather than gated, because putting ten entries
with no isolation consequence into the constant would dilute a list whose whole value is that every entry has
one.

**Remediation is deliberately NOT part of this increment.** It requires `(tenant_id, id)` unique indexes on
eight parent tables that lack them, 26 FK drop/recreate pairs preserving each `ON DELETE` action, and a
circular `forms` ↔ `form_versions` pair untangled — a schema increment with its own risk profile, filed in
`docs/feature-backlog.md`. Three of the 29 cannot be remediated that way at all: `role_has_permissions`'s two
and `tenants.logo_attachment_id` have no `tenant_id` column on the SOURCE to put in a key.

### D6. Verification & CI gates

Given the stated stakes, verification is treated as a first-class deliverable of this decision, not an afterthought:

- **Migration linter**: a CI check that fails the build if a new migration creates a table containing a `tenant_id`-shaped column without a corresponding RLS-policy migration (via the shared helper from D2), and separately fails if a new tenant-scoped table is created without appearing on either the tenant-scoped list or the explicit exemption list from D1.
- **Automated cross-tenant fuzz test suite**: for every tenant-scoped Eloquent model, an automated test seeds two tenants with data, authenticates as Tenant A, and asserts that every standard read/write operation against Tenant B's rows fails (returns zero rows / raises a policy violation), run in CI on every change to models or migrations. This is a concrete addition beyond the plan's general language, justified directly by the plan's own framing that this is "the highest-blast-radius mistake possible."
- **Job payload audit**: a static check (or a runtime assertion in the base job class) that every queued job class handling tenant-scoped data declares/consumes a `tenant_id`, catching the queue-layer leak point named explicitly in the plan. **Discharged by ADR-0007 (2026-07-21), which delivers both halves rather than choosing between them**: the §D2 runtime uuid assertion in `TenantAwareJob`, *plus* a `scripts/job-payload-lint.php` gate in `composer run quality` asserting every `ShouldQueue` class either extends that base or is explicitly annotated cross-tenant. Until then neither existed — `scripts/controller-gate.php` targets only `app/Http/Controllers`, and there was no base job class. Built in H2.
- These verification obligations are referenced from, and satisfy, Documentation Artifact #9 (*Multi-Tenancy & RBAC Design Doc*) and Documentation Artifact #21 (*Testing Strategy Doc*) in the broader documentation plan; this ADR records the decision, those documents record the full operational detail.

---

## Consequences

### Positive

- **Cheapest and fastest path at MVP/early scale.** One database to provision, migrate, back up, monitor, and tune. Onboarding a new tenant is inserting one row into `tenants` (plus seed data), not running a schema-creation or database-provisioning workflow — directly consistent with the plan's "start simple, add complexity only when justified" theme and its lean hosting posture (§1).
- **Database-enforced backstop independent of application-code correctness.** Even if a global scope is missing on a newly added model, a raw query forgets its tenant predicate, or a future engineer unfamiliar with the convention makes a mistake, Postgres itself refuses to return or accept cross-tenant rows. This directly addresses the "no internal precedent, highest blast radius" context this ADR opened with.
- **Matches current (2026) industry consensus** for SaaS at this stage, as cited in the plan's own research — avoids paying for isolation guarantees (physical database separation) the business does not yet need and cannot yet afford to operate.
- **Uniform operational tooling.** One connection pool, one migration history, one backup/PITR procedure, one place to tune indexes and monitor slow queries — versus N schemas or N databases each needing their own operational attention.
- **Efficient platform-level operations.** Billing usage aggregation, super-admin analytics, and support/debugging queries run naturally across tenants (via the audited super-admin path) without cross-database federation, replication, or a separate analytics pipeline.

### Negative (with mitigations)

- **Larger blast radius if RLS is misconfigured or a policy is missing.** In the worst case, a single defect (a missing policy, a forgotten `FORCE ROW LEVEL SECURITY`, a session-variable leak across a pooled connection) can in principle expose many tenants at once — a materially worse worst case than schema-per-tenant or database-per-tenant, where a comparable mistake is contained to one tenant.
  *Mitigation*: this is precisely why the decision is "shared-schema **reinforced with RLS**" rather than shared-schema alone, and why D6's CI gates and fuzz-test suite exist as mandatory, not optional, companions to this ADR — defense-in-depth across routing, ORM scope, and RLS means no single missed check is sufficient to cause a leak.
- **"Noisy neighbor" resource contention.** One tenant's unusually large dataset or heavy query load can affect query latency for others sharing the same database instance.
  *Mitigation*: acceptable at anticipated MVP scale (tens to low hundreds of tenants); revisit with read replicas, per-tenant rate limiting, or connection-pool quotas before it becomes a real production problem, and ultimately via the dedicated-DB escape hatch below for any tenant whose usage genuinely warrants it.
- **Compliance/data-residency ceiling.** Some future customers — plausibly government or enterprise customers, given this product's DOH/M&E-adjacent positioning — may contractually require physically isolated or geographically pinned storage that a shared database cannot satisfy, regardless of how well RLS is configured.
  *Mitigation*: this is exactly the driver named in the plan's Phase 4 ("dedicated-DB tenancy option, data-residency options") — not a gap in this ADR, but a deliberately deferred decision (see below).
- **RLS carries real query-time overhead and a real operational/cognitive cost.** Every row touched pays a policy-predicate evaluation; every engineer must understand `SET LOCAL app.current_tenant_id` and the pooled-connection hazard; every migration author must remember the policy step; every raw query must still be written with the tenant predicate in mind even though RLS would also catch a mistake there.
  *Mitigation*: tenant-context establishment is centralized in one middleware/service (D3), never left to individual controllers or job classes to reimplement; the migration helper (D2) removes the "remember to write the policy" burden from individual authors; the overhead itself is expected to be small relative to typical query cost at this scale and is not currently a measured concern (no benchmark has been run yet — flagged here as an assumption to validate during the Phase 0 spike, not a settled fact).
- **A single Postgres outage or corruption event affects all tenants simultaneously**, whereas database-per-tenant would contain such an event to a subset of tenants.
  *Mitigation*: accepted as a standard shared-infrastructure trade-off at this stage; addressed operationally (not architecturally) via standard Postgres high-availability, automated backups, and point-in-time-recovery practice, to be detailed in Documentation Artifact #22 (*Deployment & Infrastructure Doc*) rather than re-litigated here.

### Future migration path (out of scope today, but must remain possible)

The plan explicitly reserves a **dedicated-database tenancy option** for Phase 4, alongside SSO/SAML and data-residency options, for enterprise/compliance-driven tenants. This ADR does not build that option now, but it **does** commit to not foreclosing it:

- `stancl/tenancy` supports both single- and multi-database modes under one abstraction specifically so that a future switch is a *configuration and data-migration* exercise (extract one tenant's rows, provision a fresh isolated database, point that tenant's record at the new connection, backfill/replay) rather than a schema or package redesign.

  > ⚠️ **Corrected 2026-08-14 (P2a).** This bullet and §D4 below both said **v4**. The installed version is **v3.10.0** (`composer.json` pins `^3.10`; `composer.lock` agrees), and v4 has never been installed. The correction matters because the "one abstraction, so the switch is configuration" claim is the load-bearing premise of this whole section, and it was resting on a package version nobody had checked. ADR-0017 re-examines the claim against v3.10 as actually installed and finds it **partly false**: the stock `PostgreSQLSchemaManager` *replaces* `search_path` rather than prepending `public`, which would take PostGIS's `geometry` type, `users`, `tenants`, `jobs` and the `migrations` table out of scope in one config edit. The door this ADR promises to leave open is still open; it is not one line wide.
- This is workable *only* because of decisions locked in now: `tenant_id` as a UUID (not an auto-increment integer that could collide across tenants once split into separate databases), no cross-tenant foreign keys or unique constraints (D5), and every tenant-scoped table already self-contained per `tenant_id` partition.

  > ⚠️ **Corrected 2026-08-14 (P2c). "No cross-tenant foreign keys or unique constraints" was not true of this schema, and had never been checked.** There are **29 foreign keys whose target carries `tenant_id` while the key does not**, against 9 that use the composite shape — measured in D5's amendment above. The clause is offered here as a property that makes per-tenant extraction workable, so the error is load-bearing rather than cosmetic: a reader planning that work would have taken the hardest part as already done.
  >
  > **What the bullet gets right is the part that actually carried P2b.** UUID keys and per-`tenant_id` self-containment are real and are why `tenants:extract` was not hard to write. Cross-tenant references do not currently *exist* in the data — they are unreachable in practice because every write path resolves its parent under RLS first, and reaching a neighbour's row would additionally require guessing a UUIDv7. What is false is that the SCHEMA prevents them: PostgreSQL bypasses row security for referential-integrity checks, so the constraint layer would accept one, and on 20 of the 29 the `ON DELETE` action is `CASCADE`.
  >
  > **The same failure class as P2a's `Dedicated db | In effect: Yes` and P2b's `0700` directory mode** — a property asserted in prose that the platform does not provide. It is recorded rather than quietly fixed because the fix is a schema increment (see D5's amendment), and because the pattern is worth naming: all three were found by measuring something a document had only ever stated.
- Building the actual extraction tooling (per-tenant export/import, connection-swapping configuration, a per-tenant migration runner for the multi-database mode) is explicitly **not** part of this decision and is deferred to a dedicated Phase 4 ADR once a specific customer or compliance driver justifies the investment — consistent with the plan's "migrate high-value tenants later, only if justified" guidance. This ADR's obligation is narrower and non-negotiable: do not design today's shared schema in a way that makes that future extraction hard.

  > ✅ **Partially discharged 2026-08-14 (P2b, ADR-0018).** The **export** half of the first deliverable is built: `php artisan tenants:extract` writes one tenant's record as NDJSON plus a manifest. **The obligation above was met** — the extraction was not hard, and the reason is exactly the properties this section names: uuid keys, no cross-tenant FKs, every tenant-scoped table self-contained per `tenant_id`. *(⚠️ Amended by P2c, 2026-08-14: "no cross-tenant FKs" is inherited from the bullet above and is false as written — there are 29 the constraint layer would accept. It held for P2b because none has ever been **exercised**, which is a fact about the write paths rather than about the schema. The distinction is the one the correction above draws, and it does not change P2b's outcome: `tenants:extract` already reports an unresolvable reference rather than dropping it, which is the behaviour a cross-tenant row would need.)* What it was NOT is automatic. Two things had to be decided rather than derived, and both are the places the shared schema's convenience becomes a hazard at the boundary: **`users` is central**, so RLS there is a join and the extract is a roster with nine columns withheld; and **six tables' SELECT policies are deliberately wider than one tenant**, so RLS alone returns the platform catalog too. The **import** half, the connection swap and the per-tenant migration runner all remain deferred — see ADR-0018 "When to Revisit".

---

## Alternatives Considered

| Model | Isolation strength | Ops cost at MVP scale | Onboarding cost per tenant | RLS still useful? | Verdict |
|---|---|---|---|---|---|
| **Shared DB, shared schema, `tenant_id` + RLS** (chosen) | Strong, application-independent (database-enforced) | Lowest — one DB, one migration set | Lowest — one row insert | Yes — this *is* the backstop | **Accepted** |
| Shared DB, schema-per-tenant | Stronger than bare shared-schema, weaker than DB-per-tenant | Moderate-high — N schemas to migrate/manage, `search_path` juggling per request | Moderate — schema-creation step per tenant | Partially — still worth it inside each schema, but adds complexity on top of complexity | Rejected for now |
| Database-per-tenant | Strongest (physical isolation) | Highest — N databases to provision/migrate/back up/monitor, connection-pool exhaustion risk as N grows | Highest — full DB provisioning per tenant | Largely redundant — isolation is already physical | Rejected for MVP; **revisit explicitly at Phase 4** |
| Shared DB, shared schema, **no RLS** (ORM scope only) | Weak — depends entirely on every code path (including raw queries, jobs, future engineers) applying the scope correctly, with no database-level check | Lowest | Lowest | N/A — this is the alternative *to* RLS, not a variant of it | Rejected as insufficient given the stated stakes |

### Schema-per-tenant — rejected

One Postgres schema per tenant, same database instance, offers meaningfully better isolation than a bare shared-schema-without-RLS approach (a query that forgets its tenant filter simply can't see another schema's tables at all, versus silently matching rows in the same table). It was seriously considered as a middle ground. It was rejected because:

- Migrations must run across N schemas (either a looped migration runner or per-schema migration state tracking), which is real, ongoing operational complexity absent from the shared-schema model, and grows linearly with tenant count rather than staying flat.
- Laravel/Eloquent's tooling has comparatively weak native support for dynamic per-request `search_path`/schema switching relative to a simple global-scope-plus-column approach — this would be swimming against the framework's grain rather than with it.
- Postgres schema count becomes a genuine operational concern at scale (system catalog bloat, `pg_dump`/backup/vacuum overhead across thousands of schemas) — a ceiling this product would eventually hit if it succeeds, meaning schema-per-tenant would likely require *another* migration later anyway, without buying the compensating simplicity benefit that shared-schema has today.
- It does not match `stancl/tenancy`'s most mature, best-documented mode (single-database), nor the plan's explicit instruction ("shared database, shared schema, `tenant_id` discriminator").
- It remains a theoretically valid option to revisit *if* a specific future compliance driver demands schema-level isolation without the full cost of database-per-tenant — but no such driver is currently anticipated, so it is not being built for speculatively.

### Database-per-tenant — rejected for MVP, explicitly revisited at Phase 4

The strongest isolation option: blast radius from any bug is contained to a single tenant's physical storage, backup/restore/residency/compliance guarantees are the cleanest of any option, and it is the natural foundation for a future enterprise tier. It was rejected for the current phase because:

- It is by far the highest operational cost option for a small team at pre-product-market-fit stage: provisioning, migrating, monitoring, and backing up N databases instead of one, with connection-pool sizing/exhaustion risk growing as tenant count grows (each tenant potentially wanting its own pool).
- RLS provides little additional value here, since isolation is already physical — meaning this option would forgo the specific "cheap, database-enforced backstop on top of a cheap shared model" value proposition this ADR is built around, without a compensating need for it at this stage.
- Tenant onboarding becomes a full database-provisioning step rather than a single row insert — directly working against the plan's MVP-speed priorities and lean hosting posture (§1).
- Cross-tenant platform-operational queries (billing usage, support debugging, platform-wide analytics) become materially harder, requiring cross-database federation or a separate analytics/ETL pipeline instead of a single audited query path.
- This option is **not** rejected permanently — it is explicitly named in the plan's own Phase 4 roadmap ("dedicated-DB tenancy option") as the answer for future enterprise/compliance-driven tenants, once the business has revenue and specific customer requirements that justify the ops investment. The decision here is to defer it deliberately, not to foreclose it — which is why D1/D5's schema conventions (UUID tenant IDs, no cross-tenant constraints) are chosen specifically to keep that door open.

### Shared schema without RLS (ORM scope alone) — rejected as insufficient

Named explicitly as the alternative *to* the RLS reinforcement half of this decision, since "just use Eloquent's global scopes" is the natural lighter-weight option someone might reasonably propose. Rejected because it concentrates all of the isolation guarantee in application code — every current and future query, including raw `DB::` calls, artisan commands, queue jobs, and reporting/analytics code that may deliberately bypass ORM scopes for aggregation, must independently get the tenant filter right, with zero enforcement if any one of them doesn't. Given this ADR's opening premise — no internal precedent for multi-tenancy exists in this codebase's history, and the failure mode is the highest-blast-radius one the system has — relying solely on consistent application-code discipline was judged an unacceptable concentration of risk. The incremental cost of adding RLS (one policy set per table, one session variable, one middleware) is small relative to the reduction in worst-case blast radius it buys, which is the central trade this ADR makes.

---

## References

- *Form-Builder SaaS — Documentation & Architecture Plan* (source of truth for this ADR): §1 (Recommended Tech Stack — Multi-tenancy row, Database row), §2.1 (Multi-Tenancy), §3 (Phase 0 and Phase 4 roadmap items), §5 (Best Practices — tenant scoping and `is_super_admin` items), Documentation Artifacts #9, #11, #21.
- Legacy schema audit (`dev_pk_new`) — confirms the negative case directly: no `tenant_id` concept, no tenant-scoped authorization, and the specific cautionary precedent of the `users.id === 1` super-admin convention (duplicated across four code layers, silently transferable if user #1 were ever deleted and the ID reused) that this ADR's explicit `is_super_admin` boolean decision (D3) is designed to avoid repeating.
- `stancl/tenancy` documentation — single-database vs. multi-database tenancy modes (external reference; verify current package documentation at implementation time rather than treating any specific API detail above as pinned).
- PostgreSQL documentation — `CREATE POLICY`, `ALTER TABLE ... FORCE ROW LEVEL SECURITY`, `SET LOCAL` and `current_setting()` semantics under connection pooling (external reference; verify current version-specific behavior at implementation time).
