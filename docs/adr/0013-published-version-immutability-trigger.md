# ADR-0013: Published-Version Immutability at the Database (the schema's first trigger, deny-by-default, UPDATE only)

## Status

**Accepted — 2026-08-05.** Authored inside its own code increment (**H25**), on the ADR-0012/H22a precedent. An ADR rather than prose because ADR-0002 is what made *"Row-Level Security is this schema's one DB-level-guard idiom"* load-bearing, and `docs/form-versioning-schema-migration.md` §2 then resolved this specific guard onto RLS **explicitly rejecting a trigger**. Narrowing a ratified decision belongs in a numbered record, not in a paragraph someone has to find. This closes **Risk R5**, open since the architecture document was first written and one of only two risks in the §9 register still marked *Still OPEN*.

- **Deciders:** Founding engineering (architecture owner).
- **Related ADRs:** **ADR-0002** (shared-DB RLS) — §D2's *"the application should get this right, but the database enforces it regardless"* is the posture this ADR extends, and `FORCE ROW LEVEL SECURITY` is the property §D12 below is honest about not having. **ADR-0004** (build the rendering engine) — its AST cache is keyed on `form_version_id` *"once a version is published, its expressions never change"*, which is this invariant relied upon. **ADR-0006** (geo) — `:120` records the geo projection as inheriting "the version-immutability invariant"; after this ADR that inheritance is enforced rather than assumed. **ADR-0010 remains reserved for H1d** (the OCR provider bake-off, still blocked on the user's filled paper-form samples).
- **Related docs:** `docs/form-versioning-schema-migration.md` §2 (the decision this ADR narrows, amended to v1.1 in the same increment); `docs/architecture/technical-architecture.md` §6.2 point 4, §9 rows **R5** (now resolved) and **R12** (opened by §D2 below), Appendix A item 5; `docs/data-dictionary.md` §3.

---

## Context

1. **A published version's CONTENT was already frozen in Postgres; its own ROW was not.** Increment D shipped two RLS shapes. `draftChildGuard` gates every write on `form_sections` / `form_fields` / `form_field_validations` behind `EXISTS (form_versions fv WHERE fv.status = 'draft')`, so the authored content of a published version is unwritable. `formVersionGuard` covers the parent table — but its UPDATE policy is **deliberately status-blind**, because the publish transaction has to flip `draft → published → superseded`, and its own docblock says so: *"the version ROW is mutable, its published CONTENT is frozen by the child guard."*

2. **The consequence, stated plainly.** Any connection holding ordinary tenant context could rewrite `schema_snapshot`, `checksum`, `version_number`, `title`, `description`, `change_summary`, `published_at` or `published_by` on a live published version. `submissions.form_version_id` points at that row and is never repointed, so rewriting it silently changes the meaning of every response already collected against it — *"recreating exactly the bug this architecture exists to fix"* (R5).

3. **The worst single write was not a content column.** `UPDATE form_versions SET status = 'draft'` on a published row **re-opens every child row beneath it**, because `draftChildGuard` keys on precisely that value. One statement converts a frozen historical artifact back into an editable draft, with the RLS child guard still nominally in place and reporting nothing.

4. **RLS structurally cannot close it, and this is not a preference.** A policy's `USING` clause sees only the OLD row and `WITH CHECK` only the NEW one. **No clause can compare them.** A per-column immutability rule *is* an OLD-vs-NEW comparison. A CHECK constraint cannot see OLD either. R5's own mitigation text hedged — *"a DB-level guard (trigger or check constraint)"* — but only one of those two can express the invariant.

5. **The corpus contradicted itself, and both statements were live.** `docs/form-versioning-schema-migration.md` §2 says *"Resolved: extend the existing Row-Level Security policies, not a trigger,"* with the rationale that a second mechanism means developers reasoning about two guard styles; `technical-architecture.md:422` restates it; the `form_versions` migration docblock, `TenantIsolation`'s docblock and `FormVersionRlsTest`'s "anti-trap" comment all echo it. Meanwhile R5, Appendix A item 5 and `docs/workflow-branching-design.md` §1 all call H25 **a trigger**. A reader of §6.2 alone would have concluded the guard was already built.

6. **R5 lost its forcing moment, deliberately and on the record.** It had been folded into H21a because workflow branching was expected to make version semantics load-bearing. The 2026-07-21 decision chose relevance-derived step-skipping over a stored step graph, so branching added no new versioned artifact. R5 was reassigned to its own hardening PR — *"operation- and column-scoped so it never blocks publish, archive or supersede"* — and recorded in four places so it could not drop silently.

7. **One reachable production path was already exercising the hole.** `FormService::updateMetadata()` writes `title`/`description` to `FormVersion::whereKey($form->draft_version_id)` using an **in-memory** `$form`, with no lock and no re-read. If that model was loaded before a concurrent publish, `draft_version_id` names the version publish just froze — so an ordinary form-settings save could silently rewrite a published version's title. Closed in the same increment with a `status = 'draft'` predicate, which degrades the stale write to a no-op.

---

## Decision

The schema gains **one** trigger: a `BEFORE UPDATE … FOR EACH ROW` guard on `form_versions`, gated on the row not being a draft, that refuses any change to any column outside a four-name lifecycle allowlist and pins the one lawful status transition. It is an **integrity guard**, not a security boundary, and it says so.

### The twelve sub-decisions

- **D1 — The boundary, stated once so it never has to be re-litigated: row-scoped invariants stay RLS; OLD-vs-NEW invariants get a trigger.** `docs/form-versioning-schema-migration.md` §2's resolution **stands, for the three content child tables**, where it is built and tested. It cannot be extended to the parent row for the reason in Context 4. This ADR does not open a general licence for triggers: an invariant qualifies only when it is a comparison between the old and the new row, and today `form_versions` immutability is the only one in the schema that is. Anything expressible as a predicate over a single row version remains RLS or a CHECK, with no exception.

- **D2 — UPDATE only. There is no DELETE trigger, and the residual becomes Risk R12.** A `BEFORE DELETE` row trigger fires on referential actions too, and `form_versions.form_id → forms` and `.tenant_id → tenants` are both `ON DELETE CASCADE` — so one would turn tenant hard-delete and form hard-delete into an error rather than a cascade, a behaviour change far outside a hardening PR. Direct DELETE of a non-draft version is already a silent zero-row no-op under `formVersionGuard`. What is therefore **not** closed: a hard-delete of a `forms` or `tenants` row wipes published versions, their frozen content and every submission FK'd to them, because referential actions bypass RLS; and `TRUNCATE` bypasses row triggers entirely. Production has no hard-delete path today (`Form` uses `SoftDeletes`, `FormController` exposes `archive()` and no `destroy()`), so the exposure is a future code change — which is what a risk row is for. **The guarantee this ADR ships is "a published version cannot be EDITED", never "cannot be destroyed",** and that wording is deliberate everywhere it appears.

- **D3 — Deny by default: the guard diffs the WHOLE ROW, and carries no list of frozen columns.** A hand-written frozen list is fail-**open** for the future — the day someone adds a column to `form_versions` it is silently mutable on a published row, and nothing in CI notices, because `scripts/migration-lint.php` early-returns on alter-only migrations and no other gate reads this table. The function instead compares `to_jsonb(OLD)` against `to_jsonb(NEW)` per key, skipping only the names in §D5. Every other column, including every column a future migration adds, is frozen the moment it exists. The corollary is intended, not incidental: a backfill that must touch a frozen column has to disable the trigger inside its own migration (§D12), which is loud and reviewable. A feature test adds a column inside the test transaction and proves it is already frozen.

- **D4 — The per-key comparison is on `::text`, not on jsonb values.** jsonb equality is numeric-aware, so `'1'::jsonb = '1.0'::jsonb` is TRUE. A scale-only rewrite of `schema_snapshot` would therefore pass a jsonb `=` guard while silently invalidating `checksum`, which is SHA-256 over the canonical **text** and is the offline-sync cache-busting key. jsonb's text output is itself canonical — key order normalised, whitespace normalised, duplicate keys dropped at parse time — so `::text` keeps the no-false-positive property (a snapshot re-encoded by Laravel's `array` cast in a different key order still reads as unchanged) while catching the case jsonb equality would wave through. Both halves are pinned by one test.

  The projection is also **NULL-safe by construction**, which is not a nicety: a SQL `NULL` column becomes the jsonb value `null`, never SQL NULL, so the comparison always returns a definite verdict. Seven of the frozen columns are nullable — including `created_at`, since `timestampsTz()` emits both timestamps nullable — and the suite routinely produces published rows with a NULL `checksum`. A guard written with `<>` would compare NULL to a value, yield NULL, and let the write through. **No `<>` appears anywhere in the guard**, and a unit test asserts that absence.

- **D5 — Exactly four columns are exempt from the row diff, and three of them are re-guarded individually.** `MUTABLE_COLUMNS = status, superseded_at, updated_at, published_by`. `updated_at` is exempt outright — every Eloquent `save()` bumps it, so freezing it would block the one legitimate published-row write. The other three are exempt from the *diff* only because their lawful movement is a **transition** rather than an ordinary write, and each gets its own rule (§D6, §D7). The four rules are **independent, not chained**: a bare `updated_at` touch on a superseded row must pass, or the guard would be stricter than the invariant it encodes and brittle against ordinary maintenance.

- **D6 — `status` may only move `published → superseded`; `superseded` is terminal; nothing ever returns to `draft`.** This is the rule that answers Context 3. `superseded_at` rides that transition exactly once — writable only by the statement performing the flip and only out of NULL — so it can never be re-dated or cleared afterwards.

- **D7 — `published_by` may be CLEARED but never re-pointed, and this carve-out is mandatory rather than generous.** `published_by` is `ON DELETE SET NULL` against `users`, and PostgreSQL executes that referential action as an ordinary UPDATE through SPI: it bypasses RLS but **not** user triggers. Freezing the column outright would make user hard-deletion — and GDPR erasure with it — permanently impossible, and would raise a form-version error from an unrelated tenant's row on a statement about `users`. Losing the attribution when the person is erased is the whole intent of that foreign key; re-pointing a publish at somebody else is the falsification the rule refuses. A feature test hard-deletes a publisher and asserts the version survives with a null attribution.

- **D8 — The gate keys on `OLD.status`, lives in the `WHEN` clause, and lives nowhere else.** Not on `published_at`: a draft row can legitimately carry one (`ConnectorFanOutTest` writes exactly that shape), so a `published_at IS NOT NULL` gate would break it *and* miss a frozen row that was never published. `IS DISTINCT FROM 'draft'` rather than `<> 'draft'`, because a `WHEN` clause evaluating to NULL is treated as false and the trigger does not fire — equivalent today since `status` is `NOT NULL`, fail-open the day that changes. The predicate is **not** repeated inside the body: two copies invite drift, and the drift direction where `WHEN` fires but the body early-returns is fail-open. Keeping it in the `WHEN` clause also puts it in `pg_get_triggerdef()`, which is what the structural test pins — the device `CustomDomainSchemaTest` already established for a partial index.

- **D9 — Refusals raise `restrict_violation` (SQLSTATE 23001), and there is deliberately no exception renderer.** 23001 is unambiguous in this schema: PostgreSQL core never emits it (a `RESTRICT` foreign key raises 23503) and nothing else in the codebase does, so a test or a log filter keying on it is exact — and it is what proves the *trigger* refused a write rather than the RLS policy, whose `WITH CHECK` failure is 42501 (a BEFORE ROW trigger runs first). `bootstrap/app.php` gains **no** arm for it: reaching this guard means a code path bypassing the service layer, and a loud 500 is the correct outcome — mapping it to a friendly domain error would normalise exactly what R5 exists to surface. The one path that could reach it from production is closed at source (Context 7). Accepted cost: the resulting `QueryException` message carries the offending SQL and bindings, so a refused `schema_snapshot` write would land in logs; acceptable only because the path is unreachable by design.

- **D10 — A companion `form_versions_status_chk` ships in the same migration.** `status` was a bare `varchar(20)` with a default and no CHECK, so an arbitrary string was storable. That looseness predates this ADR, but the trigger **turns it into a trap**: the guard treats anything that is not `'draft'` as frozen, so one typo'd status would freeze a row permanently in a state no code can interpret. Pinning the vocabulary in DDL is the guard's own fail-closed precondition, not scope creep. Deliberately still **not** closed: `draft → superseded` directly. The trigger is silent on draft rows by construction (§D2's operation scoping), so a buggy caller could skip `published`; that is a lifecycle bug, not an immutability breach — nothing was ever published — and closing it would mean firing on draft rows.

- **D11 — `CREATE OR REPLACE FUNCTION` is mandatory, and the drop order is load-bearing.** `migrate:fresh` runs `db:wipe`, which drops tables, views and types — **never routines**. `DROP TABLE … CASCADE` takes the trigger; the function survives. A bare `CREATE FUNCTION` therefore dies on the **second** `migrate:fresh` on any database with `42723 function already exists` — and CI cannot catch it, because every CI job provisions a brand-new database, so it would ship green and break only developers on the loop the tracker documents as standard. `down()` drops the trigger **first**, then the function, both `IF EXISTS`, never `CASCADE` (which would silently take the trigger with it — a bad habit to establish in the schema's first function). A unit test pins both.

- **D12 — The threat model is stated honestly, and the escape hatch is written down rather than built in.** `meridian_app` **owns** `form_versions`, so the app role can `ALTER TABLE … DISABLE TRIGGER` or `DROP TRIGGER`; a superuser can additionally `SET session_replication_role = 'replica'`. That is the crucial asymmetry with RLS, where `FORCE ROW LEVEL SECURITY` was chosen *precisely because it binds the owner*. **This guard stops application bugs and raw queries — the R5 threat, whose own wording is "accidentally mutates" — and does not pretend to stop a holder of the app credentials.**

  What it *does* add over RLS: it binds `pgsql_privileged` (a real superuser bypasses RLS but never a trigger — asserted by its own test pack), and it fires on referential actions, which bypass RLS entirely.

  **No GUC escape hatch**: a custom `app.*` GUC is settable by any role, so it would be no obstacle to the very threat this guard addresses. A deliberate repair instead runs `ALTER TABLE form_versions DISABLE TRIGGER form_versions_published_immutable_trg;` … `ENABLE TRIGGER` inside its **own** migration, on the ordinary connection — loud, reviewable, and in version control. H8 is the precedent that this need is real *and* the precedent for the better answer where one exists: it put its retroactive correction on `forms.capability_flags` rather than on the frozen version.

  The trigger stays in default origin mode (`tgenabled = 'O'`), **not** `ENABLE ALWAYS`, because `ALWAYS` would also fire during logical-replication apply and break a future replica-based restore legitimately replaying a supersede.

### Naming — the schema's first trigger

There was no trigger, trigger function, `DB::unprepared` or `plpgsql` anywhere in the repository, so this increment names the convention, extending the established `_chk` / `_idx` / `_fk` / `{table}_{suffix}` set:

| object | name | bytes (`NAMEDATALEN` = 63) |
|---|---|---|
| trigger function | `form_versions_published_immutable_fn` | 36 |
| trigger | `form_versions_published_immutable_trg` | 37 |
| check constraint | `form_versions_status_chk` | 24 |

**`_trg` for a trigger, `_fn` for its function, the same base name for the pair, and the table name prefixing the function** — function names are schema-global where trigger names are namespaced per table. Over 63 bytes PostgreSQL truncates *silently*, which is how two objects collide with no error, so a unit test asserts the lengths.

---

## Consequences

**Good.**

- R5 closes. The invariant every downstream design leans on — ADR-0004's per-version AST cache, ADR-0006's geo projection, the offline client that keeps filling version 3 after version 4 publishes, and every submission's pinned meaning — is now enforced by the database rather than promised by a service layer.
- The single most damaging statement available in the schema (`status = 'draft'` on a published row, which re-opens all its content) is refused.
- Coverage extends to places RLS never reached: the superuser connection, and referential actions.
- Deny-by-default means the guard does not decay. A column added in Phase 4 is frozen without anyone reading this ADR.
- Two live defects closed in passing: the `updateMetadata` race (Context 7), and a false docblock claiming `FormBuilderService::rowVersion()` has microsecond precision when the column is `timestamp(0)`.

**Costs and narrowings, accepted.**

- The schema now has **two** DB-level guard idioms, which is exactly what §2 wanted to avoid. §D1 is the mitigation: the boundary is stated, narrow, and testable, and today it admits exactly one member.
- A refusal is a **thrown exception**, where every RLS refusal in this schema is a silent zero-row no-op. That difference is deliberate (§D9) but it is a difference, and any new test that trips the guard aborts its transaction — so a throwing assertion must be the last database interaction in its test.
- `TRUNCATE`, a cascade delete, `DISABLE TRIGGER` and `session_replication_role` all bypass it. Three are recorded here; the cascade is Risk R12.
- The migration is alter-only, so `scripts/migration-lint.php` skips it and its CI green is **vacuous**. The only things standing over this guard are `tests/Unit/PublishedVersionGuardTest.php` and the two feature packs in `tests/Feature/Forms/`.
- **Unverified in production.** `CREATE FUNCTION` needs `CREATE` on schema `public`. `meridian_app` owns the database in the docker init and in all three CI jobs, so it succeeds there; `docs/deployment-infrastructure.md` §8 provisions `meridian_app` as a non-superuser and Track B is not stood up, so this is a deploy-time check, not a discharged one.

---

## When to Revisit

- **A second OLD-vs-NEW invariant appears.** §D1 admits it; the shared machinery (a `*Sql()` generator with a text-pinning unit test) is already in `App\Support\Migrations`. Two members is still a boundary; five means the boundary was drawn in the wrong place and RLS-vs-trigger should be reconsidered wholesale.
- **A hard-delete path reaches production** — a tenant purge, a GDPR account-deletion tool, a retention reaper. That converts Risk R12 from theoretical to live, and the DELETE half of this guard (with the purge seam it requires) has to be designed rather than deferred.
- **A repair genuinely needs to rewrite a published version.** If the break-glass migration (§D12) is reached more than once, the answer is not a GUC — it is that something upstream is writing a derived value onto a frozen row, and it belongs on `forms` instead, per the H8 precedent.
- **`form_versions` gains a legitimately mutable column.** Deny-by-default will refuse it on day one, loudly, in a feature test. Add it to `MUTABLE_COLUMNS` **and** decide whether it needs its own transition rule; the unit test pins the list precisely so that widening is a reviewed decision rather than one quiet word in a constant.
- **Logical replication or a read replica is introduced.** Re-read §D12's `tgenabled = 'O'` choice before anything replays a supersede.
