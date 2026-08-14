# ADR-0018: Per-Tenant Extraction — what leaves, what stays, and what the artefact says about itself

## Status

**Accepted — 2026-08-14.** Authored alongside its own code increment (**P2b**), on the ADR-0012/H22a, ADR-0013/H25, ADR-0014/H23a1, ADR-0015/I7a, ADR-0016/P1a and ADR-0017/P2a precedent.

This ADR builds **the first of the three deliverables ADR-0002's "Future migration path" deferred** — per-tenant export — and answers the two entry criteria ADR-0017 "When to Revisit" wrote down rather than guessed at. The other two (connection-swapping configuration, a per-tenant migration runner) remain deferred and are not made easier or harder by this.

> ⚠️ **EVERY "ADR-0017" IN THIS DOCUMENT MEANS `0017-tenant-isolation-tiering.md`.** Two ADRs currently carry the number 0017: this one's predecessor, and `0017-first-party-google-sign-in.md` (Lane A, J3c2 #151). Neither lane erred — each checked the sequence before starting and each correctly saw 0016 as the highest — and the collision is recorded in `PROGRESS.md` along with the decision not to renumber in a tail-end sweep. **0018 is unambiguous; the next free number is 0019.** Until the renumber happens, cite the FILENAME rather than the bare number. The generalisable rule the collision teaches: **a globally-numbered artefact is a shared-namespace allocation and cannot be chosen safely from inside one lane** — the same session also produced a duplicate migration prefix.

- **Deciders:** Founding engineering (architecture owner). No product decision was escalated: the two open questions turned out to have answers that follow from decisions already on the record (ADR-0002's isolation model, ADR-0017 Context §2's central identity, ADR-0009's token custody), and both are recorded here as reversible.
- **Related ADRs:** **ADR-0002** — discharges the first of its three deferrals; §D2 below is the one place this codebase deliberately writes the `where` clause ADR-0002 spent a decision removing, and the exception is argued rather than assumed. **ADR-0017** — supplies `TenantScopedTables` and `ExtractionGuard`, and its two entry criteria are §D3 and §D2 here. **ADR-0009** — §D3 withholds the OAuth token columns on its custody argument. **ADR-0005** — one box, one instance, which is why the artefact is a directory on that box and not a transfer.
- **Related docs:** `docs/data-privacy-gdpr-compliance.md` §3 (the subject-export distinction), `docs/data-dictionary.md`, `docs/deployment-infrastructure.md` §8 (operator runbooks).

---

## Context

1. **ADR-0017 declined to build this and said exactly why**: two questions had no answer, and "a product decision, not a bug to fix in code" is not a thing an implementation can quietly assume its way past. Both are answered below.

2. **`users` is central, and a tenant extract is not.** `users` carries no `tenant_id`; its RLS is a JOIN — `id = app.current_user_id OR EXISTS (an ACTIVE co-tenancy)`. Under the tenant-only context an extract runs in, that returns **the workspace's active members and nobody else**. Measured on the dev tenant: 2 rows returned against 3 `tenant_users` rows.

3. **RLS over-selects on six tables.** The `NULLABLE_GLOBAL` six widen their SELECT policy with `OR tenant_id IS NULL`, so a no-predicate read returns the tenant's rows *plus* the platform catalog. Measured: 10 `form_templates`, 6 `field_library`, 29 `permissions`, 5 `roles`.

4. **`domains` has no RLS at all** and `tenants` is not RLS'd either — the first resolves which tenant a request is, the second is what it resolves to. Both are unambiguously the tenant's own record and neither is protected by the database.

5. **The driver's type mapping was not what this repository believed.** Measured on PHP 8.4.24 / pdo_pgsql 8.4.24 with Laravel's default `ATTR_EMULATE_PREPARES = false`: `boolean` returns a PHP bool and every integer width a PHP int, because a native prepared statement carries the column's type OID. Four comments in this repo assert the `'t'`/`'f'` string behaviour, which holds only under emulated prepares. `numeric`, `json`, `jsonb` and every timestamp *do* return as strings.

6. **There is no subject-data export tool**, and `docs/data-privacy-gdpr-compliance.md` §3 specifies one. A tenant extract is a different artefact with a different subject, and conflating them would reproduce exactly the failure ADR-0017 Context §1 corrected.

---

## Decision

**Build the extraction mechanism as an operator command over the guards P2a established; hold "which columns may leave" as reasoned data rather than as behaviour; and make the artefact describe its own limits, including the references it could not resolve.**

### The sub-decisions

**§D1 — An operator command, and deliberately no HTTP surface.** `tenants:extract` on `ActivateCustomDomainCommand`'s precedent. A route would need a new permission key — an authorization widening, which is the user's decision and not engineering's — and would put a whole-workspace read behind whatever the weakest session on that route turns out to be. It would also have to answer "where does the artefact live afterwards, and who may fetch it", which is a retention question nobody has asked. Requiring shell access is not friction here; it *is* the authorization model.

**§D2 — RLS is the filter only where RLS returns exactly the tenant's rows. Everywhere else the predicate is written, and the manifest says which.** ADR-0002 argues at length against hand-written tenant predicates, and this is the one place that argument is knowingly set aside — for six tables where the policy is *deliberately* wider than the extract's subject, and for two that have no policy at all. `ExtractFilter` names the four modes and `filter_is_database_enforced` is reported per table, so a reader can tell which parts of an artefact rest on application code. **`domains` and `tenants` rest on one `where` clause and nothing else**; that is stated in the enum, in the manifest and in a test whose mutation is exactly its deletion.

> **This is the answer to ADR-0017's second entry criterion: platform-shared rows are OUT, and counted.** They are the *product* — identical for every tenant, meaningless to re-import — and the count is a second query rather than `total − extracted`, because arithmetic would agree with the extract by construction and so could never disagree with it, including in the one case worth catching.

**§D3 — What a column is allowed to be is a decision, held as a withheld-list with a reason beside every entry.** The allowlist shape was rejected on a measured basis: ~700 entries across 41 tables is maintained by find-and-replace, and a reviewer facing 700 lines cannot distinguish the one that matters from the 699 that do not. So the reasoning is small enough to read, and `TenantExtractColumnDriftTest` holds the full per-table census, failing on any added, dropped or renamed column. Three kinds of thing are withheld and they are not interchangeable: **live credentials**, **facts about a subject that is not this tenant**, and **derived columns**.

> **This is the answer to ADR-0017's first entry criterion: `users` is a membership roster.** Nine columns withheld. Four are credentials for a **central** identity (Context §2 — one human, N workspaces), so extracting them hands one tenant material for an account still live in workspaces it has nothing to do with. `last_active_tenant_id` is *another workspace's uuid*, which is the single cross-tenant fact this whole architecture exists to withhold. `is_super_admin` describes the platform's staff. `tos_accepted_at` and `privacy_policy_accepted_at` record an agreement between the person and the platform operator, to which a tenant is not a party.

**§D4 — A reference the extract cannot resolve is reported, never dropped, and never chased through a wider context.** ADR-0017 called the dangling foreign key a defect of the design; it is better understood as a *fact about the isolation model* that the artefact has to state. Three shapes land here legitimately — an outstanding invitation, a removed or suspended member whose authored rows remain, and the platform operator named by `impersonation_tokens.operator_id`, who belongs to no tenant at all. The reconciliation derives the 34 `users`-referencing columns **from `pg_constraint`** rather than from a list, because a list is what goes stale when a later migration adds `forms.archived_by`.

> ⚠️ **The rejected alternative is the one that looks helpful**: widening the read to `app.current_user_id` or to a privileged connection so the roster is "complete". That redefines the GUC (every RLS policy is written against *the authenticated user*) and would resolve the operator, who is staff. J3c1 refused the same widening for the same reason. **An extract that quietly fetched rows RLS declined to return would not be more complete; it would be a different artefact with no filter.**

**§D5 — The artefact is NDJSON per table plus a manifest, and the manifest is measured rather than asserted.** One JSON document must be complete in memory to write and complete again to read; the extract is produced exactly when a tenant is largest. `snapshot.role` and `snapshot.isolation_level` are **read back out of the session**; `rows` is counted as lines are written. The manifest is written **last**, so a run that dies mid-table leaves an unambiguously failed directory rather than a description of rows it does not contain.

**§D6 — One REPEATABLE READ transaction, and the level is reported, not promised.** 41 tables read outside one snapshot describe 41 different instants, and a submission whose answers arrive without its parent version is not a smaller extract but a corrupt one. `SET TRANSACTION ISOLATION LEVEL` must be the transaction's first statement, so it is skipped when the extractor runs nested — which in practice means under `RefreshDatabase`. The manifest therefore reports `read committed` in the test suite and `repeatable read` in production, **because it reports what it read back**. Hard-coding the requested level would have made the field a decoration in precisely the case where it was wrong.

---

## Consequences

**Accepted:**

- **Attachment blobs are not copied.** The `attachments` rows are extracted in full — disk, path, checksum, size, PII flag — and the objects are left where they are. Blob transfer has its own failure modes (a partial copy that still produces a manifest), and the checksum column makes a separate, verifiable copy possible without this command pretending it did one.
- **`user_ui_preferences` is not extracted.** It is keyed on a person and follows them across every workspace. It *is* named in the GDPR doc's subject-export row, correctly — that is a different artefact.
- **There is no import.** ADR-0002 deferred "export/import"; this is the export half. An importer has to answer id-collision, FK-ordering and platform-catalog-mapping questions that only have answers once a destination exists.
- **`tenants:extract` produces an artefact containing personal data, and its retention is not this ADR's.** It is written `0700` into a directory the operator names; what happens next is a runbook question, flagged for `docs/deployment-infrastructure.md` rather than invented here.
- **A tenant extract is NOT a GDPR Article 20 subject export**, and the distinction is now written into `docs/data-privacy-gdpr-compliance.md` §3 rather than left to be inferred. Article 20 is about one data subject; this is about one controller's workspace, and the subject-export tool that document specifies still does not exist.
- **The boolean coercion in `ExtractRowEncoder` is inert on the current stack** (Context §5) and is kept anyway, because the mapping is a property of a connection option: re-enabling emulated prepares would turn every `false` in every artefact into the string `"f"`, which a destination trusting JSON types reads as truthy. `DriverTypeMappingTest` pins the measured mapping so that change is loud.

**Rejected alternatives:**

- **An allowlist of extracted columns** (§D3) — the review-fatigue argument, measured at ~700 entries.
- **Deriving the withheld set from a naming rule** (`*_token`, `*_secret`, `password*`). It would have caught `invite_token` and missed `two_factor_recovery_codes`, `is_super_admin` and `last_active_tenant_id` — none of which is a credential by name, and two of which are not credentials at all.
- **Widening the context so the roster is complete** (§D4).
- **`SELECT *` with post-hoc unset in PHP.** The withheld values would be read into the process, and the difference between "never left the database" and "was removed from an array" is the difference between a guarantee and a habit.
- **A queued job.** An extract is a one-off operator action whose output is a local directory; a queued version would have to answer where the worker writes and how the operator gets it, and `TenantAwareJob`'s context establishment is a second path to keep correct for no gain.

---

## When to Revisit

- **A destination exists for an extract to be imported into.** That is when the import half becomes definable, and it is also when the platform-catalog exclusion (§D2) needs a mapping story rather than a count.
- **The subject-data export tool of `data-privacy-gdpr-compliance.md` §3 is built.** It shares this mechanism's chunking but not its subject, its filter or its column policy — and the temptation to reuse `TenantExtractColumns` for it should be resisted: a subject export deliberately *includes* `user_ui_preferences` and excludes other tenants' members.
- **`ATTR_EMULATE_PREPARES` changes, or the PHP/pdo_pgsql major version does.** `DriverTypeMappingTest` reddens; the correct response is to read `ExtractRowEncoder` before touching the test.
- **A new column lands on any extracted table.** `TenantExtractColumnDriftTest` reddens by design. Pasting the new census in without deciding is the one failure mode this design has.
- **`attachments` blob transfer is required by a real offboarding.** The row-level metadata is already there; what is missing is a verified copy, and the checksum column is the thing to build it on.
