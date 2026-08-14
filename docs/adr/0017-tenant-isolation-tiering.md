# ADR-0017: Tenant Isolation Tiering — schema-per-tenant as the named target, database-per-tenant rejected, and the default unchanged

## Status

**Accepted — 2026-08-14.** Authored alongside its own code increment (**P2a**), on the ADR-0012/H22a, ADR-0013/H25, ADR-0014/H23a1, ADR-0015/I7a and ADR-0016/P1a precedent: the open decisions here are small enough to belong with the code and large enough that a decision made in a service comment is a decision nobody can find later.

This is the ADR **ADR-0002 promised**. Its Related-ADRs block has said since 2026-07-03 that "a future ADR (unnumbered at time of writing) will cover *Dedicated-Database Tenancy for Enterprise Tenants*, scoped to Phase 4", and its "Future migration path" section defers three named deliverables to it: per-tenant export/import, connection-swapping configuration, and a per-tenant migration runner. **This ADR does not build them, and explains why building them now would deliver a noun rather than a capability.**

- **Deciders:** Product owner (2026-08-14, on a written comparison of four options: build the mechanism, fold extraction into this increment, guards-and-decision only, or the recommended correct-guard-decide). Founding engineering (architecture owner).
- **Related ADRs:** **ADR-0002** (shared-DB RLS) — amended, not superseded; §D1 below leaves its model in force, and this ADR corrects its `stancl/tenancy` version claim and discharges its deferral. **ADR-0005** (self-hosted Windows Server) — the binding constraint: one box, one PostgreSQL instance, which is what makes "dedicated database" and "data residency" different promises. **ADR-0008** (entitlement & metering) — §D6 seeds Enterprise `is_active = false`, so nothing here is purchasable; §D5 below inherits `dedicated_db`/`data_residency` rather than coining keys. **ADR-0011** (analytics substrate) — §D9's "hide, do not prompt" posture for a tier nobody can buy, applied to a surface that was doing the opposite. **ADR-0016** (SAML SSO) — Context §1's heuristic that a seeded entitlement key with no consumer marks where the work is; here it marked something else.
- **Related docs:** `docs/data-dictionary.md` §1 (the `data_residency_region` correction), `docs/data-privacy-gdpr-compliance.md` §5 (the residency qualification), `docs/architecture/technical-architecture.md` §5 layer 13 and risk R9, `docs/pricing-feature-gating-matrix.md` §3.

---

## Context

Numbered facts about this codebase and this deployment, each measured rather than recalled.

1. **The console has been telling operators that dedicated-DB tenancy is live.** `TenantDetailPresenter::features()` builds its table by iterating every key in `plans.feature_flags` and labelling unknowns with `ucfirst(str_replace('_', ' ', $key))`. `EntitlementService::feature('dedicated_db')` resolves **true** on Enterprise, `ToggleableModules` does not list it, so the row rendered `Dedicated db | Plan grants: Yes | In effect: Yes | Note: —` beside capabilities that genuinely work. Because the rows are *generated*, the search a reader runs before concluding "nothing consumes this key yet" — `grep dedicated_db` — returns a seeder, a docblock and one negative unit test, and never this surface.

2. **Identity is resolved before any tenant exists, and that forecloses database-per-tenant.** Fortify registers `/login` with `domain => null`, and `RlsAwareUserProvider` reads on the `pgsql_auth` connection precisely because the join-shape RLS on `users` fails closed with no context. At that moment there is no subdomain and therefore no tenant database to look a person up in. Independently: one person belongs to several workspaces, so a per-tenant `users` table means N password hashes, N TOTP secrets and N reset flows for one human.

3. **Three RLS policies subquery a neighbouring table, and PostgreSQL has no cross-database subquery.** `users_users_visibility` reads `tenant_users`; `resourceGrantGuardSql` reads `forms`/`scope_nodes`; `draftChildGuardSql` reads `form_versions`. Each is expressible only while its referent is co-located. A policy whose referenced relation is absent does not fail closed — it raises `relation ... does not exist` on **every** SELECT.

4. **Production is one box with one PostgreSQL instance** (ADR-0005; `docs/deployment-infrastructure.md` §1). Two databases on it share a failure domain, a page cache, a connection pool and a backup window. A dedicated database there is a real blast-radius and operational boundary; it is **not** physical isolation and it is **not** a region.

5. **The application role cannot create a database, by design.** `meridian_app`, `meridian_auth` and `meridian_superadmin` are all `NOSUPERUSER NOBYPASSRLS NOCREATEDB`. Only `meridian` (SUPERUSER, CREATEDB, BYPASSRLS) can, and `config/database.php` records that request-path use of that connection "is a bug" with exactly one allowlisted exception. PostGIS is per-database and superuser-only to install.

6. **`domains` carries `tenant_id NOT NULL` and has no RLS at all** — `relrowsecurity = false`, zero policies — which is correct (it is the table read to decide which tenant a request is, so scoping it by tenant is circular) and is exactly the case `scripts/migration-lint.php` denied existed: its `EXEMPT_TABLES` comment asserted "None of these declare a bare `tenant_id`."

7. **Column nullability does not imply the widened policy shape.** Eight tables carry a nullable `tenant_id`; only six have the `OR tenant_id IS NULL` SELECT disjunct. `audits` and `personal_access_tokens` are strict despite the nullable column — deliberately, since widening `audits` "would leak it" (its own policy generator says so) and would hand a tenant the operator's platform rows.

8. **`tenants.data_residency_region` does not exist**, and `docs/data-privacy-gdpr-compliance.md` §5 was reasoning from its existence.

9. **`tenants:migrate` is a green no-op.** `config/tenancy.php` points `--path` at `database_path('migrations/tenant')`, a directory that does not exist; the migrator globs it to `[]` and exits 0 per tenant. **Pointing it at a real directory while `bootstrappers` is `[]` would run the tenant schema into the CENTRAL database, record it in the central `migrations` table, and report success.**

10. **ADR-0002's premise about its own dependency was wrong.** It says `stancl/tenancy` **v4** in five places; `composer.lock` pins **v3.10.0**. The "one abstraction, so the switch is configuration" claim rested on it.

---

## Decision

**Adopt isolation *tiering* as a recorded capability with the runtime default unchanged: name schema-per-tenant as the target topology should a driver appear, reject database-per-tenant for this schema on the record, and build now only the guards that any future mechanism must stand on — while stopping the product from claiming a capability it does not have.**

### The sub-decisions

**§D1 — The runtime default does not change, and ADR-0002 is amended rather than superseded.** No bootstrapper is enabled, no connection is repointed, no `tenants` column is added. Every tenant remains in the shared database under RLS. `ConnectionTopologyTest` now states the four-connections-one-database invariant as an executable assertion, because it had never been written down and repointing one connection is the first thing any future multi-database work does — and, per Context §3, it is a change to what the isolation model can *express*, not a configuration tweak.

**§D2 — The named target is schema-per-tenant in the same instance. Database-per-tenant is rejected for this schema.** Schema-per-tenant dissolves the blocker that kills the database variant: cross-*schema* subqueries and foreign keys are expressible, a policy created under `search_path = public` binds its referents by OID permanently, PostGIS lives in `public` and stays reachable, and `meridian_app` **can** `CREATE SCHEMA` in a database it owns while it can never `CREATE DATABASE` (Context §5). One physical database also means one `RefreshDatabase` transaction still covers the 310-file Feature suite.

> ⚠️ **It is not "one uncommented line", and the config comment that implies otherwise should not be trusted.** `config/tenancy.php` carries a commented-out `PostgreSQLSchemaManager`, but the stock implementation does `$baseConfig['search_path'] = $databaseName` — it **replaces** rather than prepends, dropping `public` and with it the `geometry` type, `users`, `tenants`, `jobs`, `job_batches`, `sessions` and the `migrations` table itself. A custom manager that prepends is required, and Context §9's no-op migrator is a second prerequisite. This ADR names the target; it does not pretend the target is cheap.

**§D3 — Table classification is a decision, held as data, pinned by drift in both directions.** `TenantScopedTables` carries four lists with a rationale beside every exception, and `TenantTableClassificationDriftTest` asserts the constant and the catalog agree forward (everything listed is genuinely protected) and backward (everything carrying `tenant_id` is listed). Derivation was considered and rejected: `domains` is unprotected because somebody *reasoned about it*, and no query distinguishes that from somebody forgetting — while a derived rule would have to string-match `pg_get_expr` output, which is normalised, free to change across major versions, and drifts **open**. This is the `ToggleableModules::KEYS` ↔ `PlanCatalog::FEATURE_KEYS` idiom, for the same reason.

> The reverse direction earned its keep on first run: it found `sso_auth_failures`, a table P1c added, which the author's own catalog sweep had missed because the sweep ran against a dev database that was never migrated past it.

**§D4 — Two preconditions of an RLS-filtered read are code, not convention.** Reading tenant data with no `where` clause is the right shape — a hand-written predicate puts the guarantee back in PHP where ADR-0002 spent a whole decision removing it — but it relocates the filter into two things the calling code neither states nor can see. `ExtractionGuard::assertRlsSubjectRole()` refuses a `rolsuper`/`rolbypassrls` connection, because "RLS is the filter" makes the filter a property of the **role**: on `meridian`, a tenant-scoped read returns every tenant's rows at exit code 0 with nothing anywhere to notice. `ExtractionGuard::assertContextEstablished()` reads the GUC back, because `applyLocal()` outside a transaction is a silent `SET LOCAL` no-op that turns every policy into zero rows and every run into a clean, empty success. Four docblocks in this repo already warn about the second; a fifth would not have helped.

**§D5 — `dedicated_db` and `data_residency` stay unconsumed, and the product stops claiming otherwise.** No `feature:` gate, no `FeatureGateException::forKey()` arm, no settings card, no module toggle (`SettingsVocabularyTest` pins them out of `ToggleableModules::KEYS`, and that stays). What changes is the reporting: the console feature table now reports them `In effect: No` with the note *"Included in the plan; not provisioned"*, and `dedicated_db` gains the label "Dedicated database" rather than the fallback's "Dedicated db". `embedded_payments` joins them for the same reason and is not a scope decision about payments. ADR-0011 §D9's posture holds throughout — Enterprise is `is_active = false`, so the honest surface states a fact and offers no upgrade path to a plan nobody can buy.

**§D6 — Residency is a hosting decision, not a schema one, and this ADR separates the two promises.** `docs/data-privacy-gdpr-compliance.md` §5 says residency arrives "via the same dedicated-database-per-tenant escape hatch… provisioned in the required region". The mechanism is necessary and not sufficient: on a single-instance deployment (Context §4) every database is in the same region by construction. The two feature keys are separately grantable, which is correct — they are separate promises — and nothing currently stops the second being sold on the strength of the first. Recorded here rather than left for the next row to discover.

---

## Consequences

**Accepted:**

- **No tenant gets a dedicated anything from this increment.** The row is named "dedicated-database tenancy"; what ships is the decision, the guards and the retraction of a false claim. Stated plainly because the alternative — shipping a `tenants.isolation_tier` column whose CHECK permits one value, a gate nothing can gate, and a badge for a capability with no mechanism — would have *looked* like more progress while adding a second thing to keep true.
- **The three deliverables ADR-0002 deferred remain deferred**, and two of them now have written entry criteria (below) rather than being merely unbuilt.
- **`config/tenancy.php`'s dead `database` block stays.** It supplies `tenancy.database.central_connection`, which stancl's revert path reads; deleting it would make that path resolve to a connection named `central` that does not exist.
- **`tenants:migrate` remains a no-op** and is now documented as one. Fixing the path without a bootstrapper is worse than leaving it (Context §9).
- **`docs/security-threat-model.md` still carries no SSO rows and no isolation-topology rows.** Inherited from P1a–P1c and untouched here; it is a genuine gap and it is not this increment's.
- **`created_at`/`updated_at` were missing from `Tenant::getCustomColumns()`**, so every save copied them into the `data` blob and every read served the copy — `$tenant->updated_at` returned the previous save's value (measured: column `19:54:38`, blob `19:54:11`). Fixed, with a data migration for existing rows, because the whitelist alone does not heal them: the decode loop iterates the keys *present in* `data` regardless of what the whitelist says.

**Rejected alternatives:**

- **Whole-stack database-per-tenant.** Context §2 — login is central by construction, and per-tenant identity forks the human.
- **Database-per-tenant with a central identity plane.** Survivable, and it still breaks the super-admin console: `listAllUsers()`, `listFeedback()` and `listPlatformAudits()` each paginate **one** result set, which is not expressible across N databases. It also drops every FK from tenant data into `users`, including the `ON DELETE SET NULL` on `form_versions.published_by` that ADR-0013 §D2 relies on for erasure.
- **Enabling `DatabaseTenancyBootstrapper` now.** One array element. `DatabaseManager::connectToTenant()` mutates `config('database.default')` globally, while the other three connections keep pointing at the central database — so the tenant GUC lands on one connection and the reads happen on another, and the result is zero rows with no error. The worst failure shape this architecture has, reachable by a config edit.
- **Deriving the table classification from the catalog** (§D3).
- **Adding `tenants.data_residency_region` to make the documentation true.** The doc is what was wrong. A column with no consumer reproduces the `dedicated_db` situation one layer down.

---

## When to Revisit

- **A second PostgreSQL instance exists.** This is the trigger for §D2's target to be worth building, and it is checkable — unlike "when a customer asks". Until then a dedicated database buys a blast-radius boundary and not the thing the word implies.
- **A second hosting region exists.** §D6's separation collapses the moment residency is deliverable, and `data_residency` stops being a promise the infrastructure cannot keep.
- **`roles` acquires its first tenant-owned row.** ADR-0002 §D1 calls `role_has_permissions` "the global-to-global mapping, which has no tenant dimension at all" — true today only because all five `roles` rows are platform rows. `roles_tenant_insert` permits a tenant-scoped role, and the moment one exists that pivot leaks cross-tenant with no policy to stop it and two CASCADE edges into tenant-carrying tables. Nothing enforces the invariant the sentence depends on.
- **Anything that repoints a connection at a different database or schema.** `ConnectionTopologyTest` will redden; that is the point. Per Context §3 the correct response is to re-examine the three cross-table policies, not to update the test.
- **A real extraction requirement — a tenant offboarding, a GDPR Article 20 request, or a customer with an isolation clause.** That is P2b, and it has two entry criteria this increment could not answer:
  1. **What does a tenant's extract contain for `users`?** Under a tenant-only GUC the join-shape policy returns *active members only*, so a workspace with three memberships and one outstanding invite yields **one dangling foreign key**. The rows it does return carry `password`, `two_factor_secret`, `two_factor_recovery_codes`, `is_super_admin` and `last_active_tenant_id` — the last being another tenant's UUID. Simultaneously under- and over-extracted; a product decision, not a bug to fix in code.
  2. **Are platform-shared rows in or out?** RLS returns a **superset** on the widened six (§D3), so today an extract would include the platform template catalog, the question library, all 29 permissions and all 5 roles. `TenantScopedTables::rlsReturnsSuperset()` exists so that question has somewhere to be answered rather than being decided by omission.
