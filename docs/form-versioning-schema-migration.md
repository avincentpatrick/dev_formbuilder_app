# Form Versioning & Schema Migration Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft **v1.1** — written against the already-ratified table shapes in `docs/data-dictionary.md` §2–§6 and the already-established lifecycle in `docs/architecture/technical-architecture.md` §6. **v1.1 (2026-08-05, Increment H25):** §2's resolution is amended — it stands for the three CONTENT CHILD tables, and is narrowed to them, because it cannot cover the `form_versions` row itself. See §2's amendment note and **ADR-0013**.
**Purpose:** This is the dedicated doc the architecture plan calls for "since this is legacy's single most consequential gap" (plan §4 item 8). It does **not** re-decide the versioning model — `docs/architecture/technical-architecture.md` §6 already fixed the state machine, the publish transaction's shape, and the "rollback is forward-only" policy. This document's job is to **resolve the two decisions that doc explicitly left open for here**, and to specify the operational mechanics (concurrency, schema-change classification, restore, archiving) precisely enough to implement against.

---

## 1. What's Already Decided Elsewhere (not re-litigated here)

| Already decided | Doc | Summary |
|---|---|---|
| Lifecycle state machine (Draft ⇄ Published ⇄ Archived) | `docs/architecture/technical-architecture.md` §6.1 | A form starts as a mutable Draft; Publish is transactional (snapshot + version increment); editing after publish opens a **new** draft, the published row is never touched; archiving a form discards its unpublished draft but retains all published/superseded versions. |
| Publish transaction shape | Same doc, §6.2 points 1–3, 5 | Snapshots into `schema_snapshot`, sets `status = published`, assigns the next monotonic `version_number`, stamps `published_at`/`published_by`, updates `forms.current_published_version_id`, **clones the just-published structure forward into a brand-new draft** so editing continues immediately. Submissions FK to `form_version_id`; cross-version analytics align on `form_fields.key`. |
| Rollback is forward-only | Same doc, §6.2 point 6 | "Rolling back" publishes an old snapshot forward as a *new* `version_number` — old version numbers are never resurrected or reused. |
| Forms are soft-deleted only | Same doc, §6.2 point 7 | Hard deletion is blocked while any submission references any of the form's versions. |
| Table-level column shapes | `docs/data-dictionary.md` §2–§6 | `forms`, `form_versions`, `form_sections`, `form_fields`, `form_field_validations` — not repeated here. |

**Two decisions were explicitly flagged in `docs/architecture/technical-architecture.md` §6.2 as unresolved and assigned to this document**:
1. Point 4: whether the published-version immutability guard is enforced by a database trigger, or by extending the existing RLS policies with an additional predicate — **resolved in §2 below**, and **amended there in v1.1**: the answer is RLS for the three content child tables *and* a trigger for the `form_versions` row itself, because the two are not the same guard and only one of them is expressible as a policy.
2. Point 8: a version-diff view is flagged as a "Phase 2/3 UX enhancement candidate, not load-bearing" — this document works out what Phase 1 *can* still deliver without that UI (§5) and what genuinely waits.

**This document introduces zero new tables and zero new columns.** Everything below operates entirely within the already-ratified `forms`/`form_versions`/`form_sections`/`form_fields`/`form_field_validations` schema — no Data Dictionary changes accompany this doc.

---

## 2. Decision: Database-Level Immutability Guard

**Resolved: extend the existing Row-Level Security policies, not a trigger** — *for the three CONTENT CHILD tables, which is what the rest of this section is about. The `form_versions` row itself is covered by a trigger; see the v1.1 amendment at the end of this section.*

`docs/adr/0002-multi-tenancy-shared-db-rls.md` already established RLS as this schema's one, consistent idiom for "the application should get this right, but the database enforces it regardless." Introducing a second enforcement mechanism (a PL/pgSQL trigger) for a second invariant would mean developers reasoning about two different DB-level guard styles instead of one. Extending the RLS `WITH CHECK`/`USING` predicates that `form_sections`, `form_fields`, and `form_field_validations` already carry (for tenant isolation) with one additional `EXISTS` clause keeps this schema's DB-level-guard story singular.

Concretely, each of `form_sections`/`form_fields`/`form_field_validations`'s `INSERT`/`UPDATE`/`DELETE` policies (never `SELECT` — reading a published or superseded version's rows must always work, for rendering historical submissions and the public runtime) gains a second condition:

```sql
CREATE POLICY form_fields_write_requires_draft ON form_fields
    FOR ALL
    USING (
        tenant_id = current_setting('app.current_tenant_id', true)::uuid
        AND EXISTS (
            SELECT 1 FROM form_versions fv
            WHERE fv.id = form_fields.form_version_id
              AND fv.status = 'draft'
        )
    )
    WITH CHECK (
        tenant_id = current_setting('app.current_tenant_id', true)::uuid
        AND EXISTS (
            SELECT 1 FROM form_versions fv
            WHERE fv.id = form_fields.form_version_id
              AND fv.status = 'draft'
        )
    );
```

> **Note**: `FOR ALL` here only governs write operations in practice because Postgres RLS's `SELECT`-side behavior is governed by a separate `SELECT` policy (already the standard tenant-isolation policy from ADR-0002, unmodified) — a table can have multiple policies of different `FOR` clauses simultaneously, and Postgres combines same-command policies permissively (`OR`) but this is the *only* `INSERT`/`UPDATE`/`DELETE` policy on these three tables, so it is fully restrictive for writes. Confirm this composition behavior against the installed Postgres version at implementation time, consistent with ADR-0002's own "verify current version-specific behavior" caveat.

This means: even if an application-layer bug attempts to update a `form_fields` row belonging to a `published` or `superseded` version, Postgres itself rejects the write — the exact "trust, but verify" posture ADR-0002 established for tenant isolation, now extended to version immutability.

**Accepted cost**: this adds a subquery to every write against these three tables, on top of the tenant-equality check already present. Consistent with how `docs/multi-tenancy-rbac-design.md` §6 flagged its own new, heavier `users` RLS shape as "should be benchmarked during the Phase 0 spike, not treated as free" — the same applies here. In practice, writes against these tables only ever happen against the one active draft per form, so the working set `EXISTS` has to search is small (at most one non-superseded, non-published row per form), keeping the realistic cost low.

### 2.1 Amendment (v1.1, 2026-08-05, Increment H25 / ADR-0013): this section covers the CHILD tables; the parent row needs a trigger

Everything above is **as built and stays**. `TenantIsolation::draftChildGuardSql()` emits exactly this shape for `form_sections` / `form_fields` / `form_field_validations`, and the composition caveat the note above asked to verify was verified: it is the only write policy per command on those tables, so it is fully restrictive.

What this section never covered — and, read as a general resolution, wrongly implied — is **the `form_versions` row itself**. Its own RLS shape (`formVersionGuard`) leaves `UPDATE` deliberately status-blind so the publish transaction can flip `draft → published → superseded`, which means every column of a published version was freely rewritable: `schema_snapshot`, `checksum`, `version_number`, `title`, `published_at` — and `status` back to `'draft'`, which **re-opens every child row**, because the guard above keys on precisely that value.

**RLS cannot close it, and that is a property of RLS rather than a preference.** A policy's `USING` clause sees only the OLD row and `WITH CHECK` only the NEW one, and no clause can compare them. A per-column immutability rule *is* an OLD-vs-NEW comparison. A CHECK constraint cannot see OLD either. A row trigger is the only tool in PostgreSQL that can express it.

So the boundary, stated once so it does not have to be re-litigated per invariant:

> **Row-scoped invariants stay RLS. OLD-vs-NEW invariants get a trigger.** Today `form_versions` immutability is the only invariant in the schema that qualifies, and ADR-0013 §D1 says so explicitly. This is a narrowing of §2's "one idiom" rationale, not an abandonment of it: anything expressible as a predicate over a single row version remains RLS or a CHECK, with no exception.

As built (H25): `form_versions_published_immutable_trg`, a `BEFORE UPDATE … FOR EACH ROW` guard gated `WHEN (OLD.status IS DISTINCT FROM 'draft')`, which diffs the whole row (deny-by-default — a column added by a future migration is frozen the moment it exists) against a four-name lifecycle allowlist, permits only the `published → superseded` transition, and permits `published_by` to be cleared but never re-pointed, so the `users` `ON DELETE SET NULL` referential action still fires. It raises SQLSTATE 23001, so a refusal is a thrown exception rather than the silent zero-row no-op every RLS refusal in this schema produces. Scope is **UPDATE only**: a DELETE trigger would fire on the `forms`/`tenants` FK cascade and turn hard-deletion into an error, so the cascade hole is recorded as Risk **R12** instead. Full reasoning, threat model and break-glass procedure: **`docs/adr/0013-published-version-immutability-trigger.md`**.

---

## 3. Draft Creation & Publish Mechanics

### 3.1 Initial draft (form creation)

A form cannot exist without something to build against. At `forms` row creation: a `form_versions` row is created in the same transaction with `status = 'draft'`, `version_number = 1`, empty `schema_snapshot` (`'{}'`), and `forms.draft_version_id` is set to point at it. `forms.current_published_version_id` stays `NULL` until the first publish — a form can be `forms.status = 'draft'` (never published) indefinitely.

### 3.2 Publish transaction (elaborating technical-architecture.md §6.2 point 2)

One transaction, in this order:
1. **Validate** the draft's current shape (§4's structural checks; §5's classification is computed here too, not blocking, just recorded).
2. **Snapshot**: flatten the draft's `form_sections`/`form_fields`/`form_field_validations` rows into `schema_snapshot` (JSONB). **This step already freezes the whole Phase-3 step model** — per-section `relevant_expression`, `sequence`, `is_repeatable` and instance bounds, plus each field's `section_key` and sequences — so workflow branching adds no snapshot key and no additional freeze step (Doc #27 §6).
3. **Checksum**: compute `checksum` over a **canonical serialization** of `schema_snapshot` — keys sorted lexicographically, no incidental whitespace, before SHA-256 — never over Postgres's own internal JSONB byte representation, which is not guaranteed stable across Postgres versions or even across logically-identical inserts. This is the concrete algorithm the offline client and export tooling's drift-detection (`docs/data-dictionary.md` §3, §8) rely on being deterministic.
4. **Auto-populate `change_summary`** from §5's classification (a plain-text bullet list: fields added, removed, or reclassified) — the publisher's own free-text note, if any, is appended after this generated summary, never replacing it.
5. Set this version's `status = 'published'`, assign `version_number` (already reserved when this draft was created — see §3.3), stamp `published_at`/`published_by`.
6. If a prior version was `published`, transition it to `superseded` and stamp its `superseded_at`.
7. Update `forms.current_published_version_id` to this version; recompute `forms.capability_flags` (already specified in `docs/data-dictionary.md` §2 as "recomputed on every publish").
8. **Clone the just-published structure forward into a brand-new draft**: new `form_versions` row (`status = 'draft'`, `version_number` = the just-published number + 1, empty `change_summary`), and new `form_sections`/`form_fields`/`form_field_validations` rows copied from the just-published version's rows — **same `key` values, new row `id`s, new `form_version_id`**. `forms.draft_version_id` is updated to point at this new draft.
9. Emit `AuditEvent.published` and the `form.published` webhook event (already in `docs/data-dictionary.md`'s `WebhookEventType` catalog).

### 3.3 Version-number reservation

`form_versions.version_number` is `NOT NULL` from row creation (`docs/data-dictionary.md` §3), so it cannot be assigned lazily at some later point — it is fixed the moment a draft row is created; step 8 above is precisely when the *next* draft's number gets reserved (as "just-published number + 1"), immediately, inside the same transaction as the publish that necessitated it. There is never a "gap" in this scheme: a draft's number is only ever consumed (made permanent) by that same draft eventually publishing, or freed (available for immediate reuse) if that draft is instead discarded (§6) — and since at most one draft exists per form at any time, there is no scenario where two drafts compete for the same reserved number.

### 3.4 Concurrency guard

Form creation, publish, draft-discard, and version-restore (§6) all acquire a row lock on the owning `forms` row (`SELECT ... FOR UPDATE` on `forms.id` at the start of the transaction) for their full duration. This serializes concurrent attempts at any of these four operations against the *same* form — e.g., two editors both clicking "Publish" within the same second resolve to one succeeding and the second either blocking briefly then reading the now-updated state, or erroring cleanly, rather than racing to double-reserve a `version_number` or double-clone a draft. This does not serialize ordinary field-level edits from different collaborators (§8 covers that separately, at finer grain) — only these four form-level lifecycle transitions.

---

## 4. Structural Validation Gate (must pass before publish is allowed)

- Every `form_field_validations` row satisfies its `docs/data-dictionary.md` §6 `CHECK` (exactly one of `expression`/`rule_type` populated) — already DB-enforced, re-verified here as a pre-publish sanity gate rather than only discovered as an insert-time failure mid-edit.
- Every `related_form_field_id` and `form_field_id` referenced by a `form_field_validations` row belongs to the **same** `form_version_id` being published — a validation rule can never reference a field from a different version (which would be a dangling/nonsensical reference the moment that other version is edited independently).
- Every `form_fields.form_section_id`, when set, belongs to the same `form_version_id`.
- Every `is_queryable = true` field has a non-null `indexed_data_type` (already a data-dictionary-stated requirement, checked here at the point it actually matters).
- **Every template-bearing text value parses and resolves (Doc #26 §6) — SHIPPED in H6a as `App\Services\Forms\TemplateValidationGate`.** Once a label can contain a `${key}` piping hole, respondent-facing prose becomes a third reference-bearing medium alongside expressions and validation rows, and it needs the same gate. Three clauses, applied to the base value **and every `{column}_translations` variant** independently: the value parses under the template grammar (Doc #26 §2); every hole's key resolves to a field of the version being published **whose type is pipeable** (Doc #26 §3.1 — object-valued geo/media/grid types and the answer-free `note`/`page_break` are not); and every hole satisfies the ordering and repeat-scope predicates (Doc #26 §3.3 — backward reference only, same repeat instance, no cross-repeat, and a *section* key is never a template hole even though it is a legal `count()` operand). Document order is **(the owning section's `form_sections.sequence`, the field's own `form_fields.sequence`)**, compared strictly, with a section-less field leading and a section's own heading ahead of its members — Doc #26 amendment A2, which corrected the drafted `(section_sequence, sequence)`: `form_fields.section_sequence` is a nullable *order-within-section* column that is null for nearly every row, so the drafted tuple both inverted the hierarchy and sorted mostly-nulls. The template-bearing columns are the closed list in Doc #26 §6; `form_fields.default_value` is deliberately **not** among them, since it already carries an expression mode via `default_value_is_expression`. This gate runs in §3.2's step 1, **before** the step-3 snapshot freeze — though be precise about why that matters: the publish is one transaction, so the no-rejected-template-in-a-committed-`schema_snapshot` guarantee comes from the ROLLBACK, not the ordering (Doc #26 amendment A5). What the ordering buys is that step 1 stays the single validation phase and a doomed publish is never serialized.

- **A `hidden` field can never produce a respondent-facing error, and its prefill config resolves — SHIPPED in H7.** Four clauses, all on `hidden` fields only. Three of them enforce one rule: nobody can see, focus, or repair a hidden field, so an error on one is a submit that fails forever with no repair available (and, in the SPA, an error-summary entry addressing a row that does not render). So `is_required` must be `optional` (`hidden_field_required`), the field must own **no** `form_field_validations` row (`hidden_field_has_validations`), and it may not sit in a **repeatable section** — neither prefill source can address one instance out of N, since a URL carries one value for the whole form and an authored literal is the same for every row. The fourth is config sanity: a `url`-sourced field's `config.url_param`, when set, matches `/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/` (`prefill_param_invalid`); blank falls back to the field's own `key`. An author who needs a guaranteed value uses the `fixed` source, which is always present because the server writes it. **This gate is not retroactive**, which is why both engines *also* skip a hidden field in the Stage-3 error pass — a version published before H7 can already carry the unfixable shape, and the runtime narrowing is what makes it harmless (pinned by `tests/golden/validation/hidden.json` in PHP and TS). Note the division of labour with §5: this is a *publish* gate, while the byte cap and source authority on the answer itself are enforced at *submit*, in Stage 1 (`docs/data-dictionary.md` §5's H7 Design Note).

- **Workflow branching adds WARNINGS to this step, and deliberately no refusal — planned by Doc #27 §6 for H21a.** Three checks, all decided from the two `relevant_expression` columns plus the reference list the expression gate already extracts, and all **non-blocking**: a section whose relevance names a field in a *later* section (legal, and Doc #27 §3.1 explains why the piping ban does not transfer — a forward piping hole is permanently empty and provably useless, while a forward relevance reference is merely late); a **cycle** in the relevance dependency graph, discriminated from a legitimate chain (the `chained_fixed_point` golden vector pins such a chain as correct, and a detector that cannot tell them apart warns on correct forms); and a form that is **empty at open**, i.e. no step is relevant under an empty answer context, decided by running the semantic validator over an empty answer map. **The reason none of them refuses is the retro-gate shape, and it is counter-intuitive enough to state once here:** a publish gate is non-retroactive, so the already-published version keeps serving and no respondent is affected — but §3.2's step 8 clones the just-published structure forward into the next draft, so a form that is live and collecting data becomes **un-editable**, with an error naming a rule its author wrote before the rule existed. There is no grandfather mechanism for publish gates (H5c's entitlement override seam does not reach here). The non-throwing seam this follows already exists: `TemplateValidationGate::confirmationMessageViolations()` is the returning twin of its throwing check, added by Doc #26 amendment A3 for exactly this warn-don't-refuse purpose. If a check must ever bite, the rule is **warn where the currently-published version already violates it, refuse only a violation introduced in the draft** — and even that is deferred out of H21.

A form failing any of these is not publishable — the builder UI surfaces the specific violation, not a generic failure. A form merely *warned* by the branching checks above publishes normally.

---

## 5. Schema-Change Classification

Every publish computes a classification by diffing the draft's fields/sections (keyed by `key`) against the currently-published version's fields/sections (or against "nothing," for a first-ever publish). This classification is what auto-populates `change_summary` (§3.2 step 4) and is stored for future use even before any visual diff UI exists (`docs/architecture/technical-architecture.md` §6.2 point 8 explicitly scopes that UI to Phase 2/3 — this section defines what Phase 1 gets *without* it).

> **Open gap recorded by Doc #27 §10 (H1f), not closed here.** The classifier diffs fields and sections by `key`; it does **not** diff `relevant_expression`. So adding, removing or repointing a section's relevance — which changes *which respondents are asked what*, and under Phase-3 branching is the single highest-stakes edit an author can make — currently classifies as ordinary non-breaking metadata, alongside a label typo. Doc #26 §6.1 deferred hole-diffing on the same grounds ("display-only today"); this case is strictly higher-stakes, and it is written down here so the next change to this section starts from the right list rather than rediscovering the gap.

**Hard rule, stated once**: a `key` is never renamed. `docs/data-dictionary.md` §4/§5 already establish `key` as stable "assigned once, copied forward into each new draft unless explicitly forked" — this document makes the *fork* mechanic explicit: forking means assigning a brand-new `key` to what is, conceptually, a replacement field, deliberately breaking cross-version analytics continuity because the builder has decided the old and new questions are no longer the same question. The old key's field may optionally be removed from the new draft; its historical submissions remain queryable under that old key against the versions that had it.

| Category | Examples | Effect |
|---|---|---|
| **Non-breaking** (recorded, never warned) | Label/hint/placeholder text changes **that neither add, remove, nor repoint a `${key}` piping hole** (Doc #26); reordering fields or sections (`sequence`); adding a new optional field or section; adding a non-required validation rule; widening a constraint (e.g., increasing `max_length`); moving a field between sections (`form_section_id` change) | Fields/sections keep full cross-version comparability under their shared `key`. |
| **Breaking-but-permitted** (recorded, Phase 2/3 UI will surface a warning; Phase 1 permits silently, since blocking-UI is explicitly deferred) | Changing `field_type` under an existing `key`; making an optional field required; narrowing a constraint; removing a field/section that has a populated `key` (its historical data isn't destroyed, but the new version simply stops asking it); changing `is_queryable`/`indexed_data_type` | Cross-version aggregation under that `key` becomes questionable for the fields affected — e.g., "age" changing from `integer` to `short_text` between v2 and v3 means a dashboard averaging "age" across all versions must not naively concatenate v2's numeric values with v3's text values. §7 shows how a query should account for this using the stored classification. |
| **Fork** (a new field, not a version of the old one) | Assigning a brand-new `key` to represent what the builder considers a genuinely different question | No cross-version relationship implied at all; this is the deliberate escape hatch from the "breaking-but-permitted" row above, for when a builder actively wants a clean break rather than a flagged-but-comparable change. |

**Phase 1 scope, precisely**: the classification is computed and stored (feeding `change_summary`'s auto-generated text) on every publish. **No blocking or warning modal exists yet** — consistent with `docs/architecture/technical-architecture.md` §6.2 point 8's existing framing of the interactive diff experience as a Phase 2/3 enhancement, not a Phase 1 architectural requirement. Phase 1 gets a real, immediately useful artifact (an accurate, auto-written changelog) without needing the richer UI.

---

## 6. Version Restore ("Rollback"), Elaborated

Operationalizes `docs/architecture/technical-architecture.md` §6.2 point 6 ("rollback is forward-only"). "Restore version N" is **not** a special-cased publish path — it is a **draft-population convenience** that reuses the ordinary publish mechanics unchanged:

1. The user selects an old (`published` or `superseded`) version N from the form's version history.
2. If the current draft has any content that diverges from the currently-published version (i.e., someone has been mid-edit), the user is warned that restoring will **overwrite the current draft's content** — this is a destructive-to-draft, not destructive-to-history, operation; every previously published version remains untouched and fully addressable regardless.
3. On confirmation, the current draft's `form_sections`/`form_fields`/`form_field_validations` rows are replaced (deleted and re-inserted, or diffed and upserted — an implementation choice, not an architectural one) with copies of version N's rows, preserving their `key` values exactly.
4. The user reviews the now-restored draft like any other draft and explicitly hits **Publish** — going through the full §3.2 sequence, including its own validation gate (§4), classification (§5), checksum, and `version_number` assignment (the next sequential number after whatever is currently published, **never** version N's own old number, per the forward-only rule).

Reusing the standard publish path (rather than inventing a second "republish" code path) means every invariant §2–§5 establishes automatically applies to a restore too, with no risk of a second, subtly-divergent implementation drifting out of sync over time.

---

## 7. Cross-Version Analytics Alignment

`docs/data-dictionary.md` §9 already denormalizes `field_key` onto `submission_answer_index` specifically so aggregation across versions doesn't require an extra join purely to resolve the stable identifier. A representative cross-version query, aggregating a field by its `key` across every version of one form:

```sql
SELECT sai.field_key, sai.value_number
FROM submission_answer_index sai
JOIN submissions s ON s.id = sai.submission_id
WHERE s.form_id = :form_id
  AND sai.field_key = 'age';
```

This works unmodified across every version *unless* `age`'s `indexed_data_type` changed between versions (a "breaking-but-permitted" change per §5) — in which case `value_number` is populated for the versions where `age` was numeric and `value_text` for versions where it was text, and naively `AVG(value_number)`-ing across all rows silently drops (rather than errors on) the non-numeric-typed rows. **This is exactly the scenario §5's stored classification exists to make discoverable**: a dashboard or reporting feature querying across versions should consult the classification for the `key` in question before assuming type-uniformity, and Phase 2/3's diff UI (once built) is the natural place to surface this to a human builder before they're surprised by an analytics artifact quietly excluding older data.

> **Now normative — the reporting feature exists (ratified 2026-08-03, ADR-0011 §D4).** The advice above was written for a feature that had not been built yet; H24a is that feature, so the "should consult" becomes a rule it must obey. **A cross-version aggregate partitions by the *populated* value column, and coercion across partitions is forbidden.** Where a key's `indexed_data_type` changed between versions, the surface reports the drift and names the version at which it happened — *"this question changed type in version 4; values before it cannot be averaged with values after"* — rather than returning a number computed from whichever rows happened to survive the type filter. A silently-partial average is the failure mode this section predicted, and it is now refused rather than merely documented. Two related properties of the same table are recorded in `docs/data-dictionary.md` §9's design notes: the projection is opt-in and never backfilled, so a key is comparable only from the version its field was first flagged queryable; and rows can be dropped *within* a version by a type mismatch, so a per-key count under-reports against `submissions` independently of any version change.

---

## 8. Concurrent Multi-Editor Draft Editing

`docs/multi-tenancy-rbac-design.md` §8 permits multiple Form Editors to collaborate on the same form via `resource_grants` (which replaced `form_collaborators` in Increment G10a, and can now grant a whole `scope_nodes` subtree at once — widening rather than narrowing this scenario), which makes concurrent draft editing a real scenario this doc must address (not merely a hypothetical):

- **Presence awareness**: a Reverb private channel per form (`private-tenant.{tenant_id}.form.{form_id}.presence`) broadcasts which collaborators currently have the builder open — consistent with the architecture plan's own stated use of Reverb for "builder presence." This is informational only (avatars/indicators), not a locking mechanism.
- **Optimistic concurrency, not locking**: each `form_sections`/`form_fields`/`form_field_validations` row's existing `updated_at` timestamp (already present on every table per the Data Dictionary's timestamp convention — no new column needed) serves as the concurrency token. A client's edit request includes the `updated_at` value it last read; if the server's current `updated_at` for that row has since moved, the write is rejected with a `409 Conflict` and the client re-fetches before retrying — the same "409 for a genuine conflict" pattern the architecture plan already applies to offline-sync replay (§2.4), reused here rather than inventing a second conflict-resolution idiom.
- Two editors working on **different** fields/sections within the same draft never conflict at all, since each row's concurrency token is independent — conflicts only arise when two people edit the literal same field/section row concurrently, which presence awareness helps editors avoid in the first place.
- **Explicitly out of scope**: real-time, character-level collaborative co-editing (Google-Docs-style simultaneous typing in the same field) is **not** designed here and is not currently planned — nothing in any existing doc requests it, and the architecture plan's only CRDT mention is for offline *submission* sync, explicitly gated "if needed" (§2.4), not for form-building itself. If concurrent-editing conflicts prove common enough in practice to justify it, that would be a future, separately-scoped design effort, not a retrofit of the mechanism above.

---

## 9. Archiving & Hard Deletion

Elaborating `docs/architecture/technical-architecture.md` §6.1's `Archived` state and §6.2 point 7:

- **Archiving** (`forms.status → 'archived'`) discards the form's currently-active draft (its `form_sections`/`form_fields`/`form_field_validations` rows are deleted, consistent with the Data Dictionary's cascade-behavior summary for draft rows) but **never touches** any `published`/`superseded` version — those, and every submission collected against them, remain fully intact and queryable indefinitely. `forms.draft_version_id` is cleared; `forms.current_published_version_id` is untouched (an archived form's last-published version remains addressable for historical reporting).
- **Hard deletion** of a `forms` row is blocked while any `submissions` row references any of that form's versions (directly or via the denormalized `forms.id` convenience FK) — in practice, this means hard deletion is only ever actually available for a form that was created and archived/abandoned before ever collecting a single real submission. This is a safety rail, not an expected everyday operation.

---

## 10. Interaction with Offline Sync (cross-reference only)

Because submissions pin to a specific, immutable `form_version_id`, an offline client that downloaded version 3 keeps collecting safely against version 3's schema and expression definitions even after the tenant publishes version 4 — already stated in the architecture plan §2.4 and restated in `docs/architecture/technical-architecture.md` §6.2 point 3. The full offline-sync mechanism (manifest format, `checksum`-based cache-busting the client uses to detect it should re-download a form's schema, conflict/idempotency handling) is Doc #18's job (Offline-First Sync Design Doc) — not re-derived here.

---

## 11. Out of Scope / Deferred

- The interactive, field-by-field **visual version-diff/comparison UI** — explicitly a Phase 2/3 enhancement per `docs/architecture/technical-architecture.md` §6.2 point 8; §5 above defines the underlying classification it will consume, not the UI itself.
- XLSForm import's precise effect on draft/version creation (does an import always create a new form, or can it target an existing form's draft?) → Doc #16 (XLSForm Interop Spec).
- Full real-time, character-level collaborative co-editing → explicitly not planned (§8).
- Form-template instantiation mechanics (cloning a `form_templates` blueprint into a brand-new form + initial draft) → already specified in `docs/data-dictionary.md` §12, not re-derived here.
- OCR-linelist's interaction with versioning (an OCR-linelist batch always submits against whatever version is currently published, by definition, same as any other channel) → Doc #17 (OCR Pipeline Design Doc) for the OCR-specific mechanics.

<!-- The pipeline markers below are DELIBERATELY at end-of-file. A marker inserted mid-document
     shifts every line beneath it, and this repository cites documents as `path:N` — 25 such
     citations point into the files that carry markers. End-of-file shifts nothing. -->
<!-- pipeline: id=version-diff-ui title="PRD Feature #8 — the interactive field-by-field version-diff view" phase=3 state=ready size=L -->
