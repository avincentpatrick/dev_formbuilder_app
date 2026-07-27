# Audit & Compliance Logging Spec

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — formalizes the scope and redaction rules for the `Auditable` trait / `audits` table already specified in `docs/data-dictionary.md` §13 (carried forward from legacy's confirmed-good `app/Traits/Auditable.php` mechanism) and referenced throughout `docs/PRD.md` Feature #12, `docs/multi-tenancy-rbac-design.md` §9, and `docs/security-threat-model.md`. This document does not redesign the mechanism — it specifies precisely *what* gets audited and *what gets redacted before it's written*, which no prior doc has pinned down exhaustively.

---

## 1. Scope — Which Models Are Auditable

`docs/data-dictionary.md` §13 gives `audits.auditable_type` examples ("e.g. `form`, `form_version`, `submission`, `webhook_endpoint`, `subscription`") without an exhaustive list. `docs/PRD.md` Feature #12 states the coverage principle: *"not limited to form/submission data: every settings change, every form publish/archive action, every role/permission change, and every billing-plan change is tracked."* This section makes that principle a definitive, checkable list:

| `auditable_type` | Audited events | Rationale |
|---|---|---|
| `form` | `created`, `updated`, `archived`, `deleted`, `restored` | Core business object. |
| `form_version` | `published` | The single most consequential event this schema tracks (`docs/form-versioning-schema-migration.md`) — every publish is audited, not just form-level metadata edits. |
| `submission` | `created`, `updated` (status transitions: approve/return; **and deliberate post-submission answer edits** via `submissions.edit`, fast-follow — see note), `deleted`, `restored`, `exported` | Review-workflow accountability; `exported` covers a reviewer/admin pulling submission data out of the system. |
| `settings` | `created`, `updated` | Per PRD Feature #12's explicit callout; both tenant-level and (when actor is super-admin) platform-level rows. |
| `webhook_endpoint` | `created`, `updated`, `deleted` | Configuration changes to an outbound data-sharing surface. |
| `connection` *(H15a)* | `created` (OAuth grant completed), `updated` (re-connected, or marked dead after a refused refresh / rejected credential), `deleted` (disconnected) | The platform's OAuth grant on a tenant's third-party workspace (ADR-0009). Higher stakes than a webhook endpoint: the credential lets the platform act *inside* that workspace, so its whole lifecycle is on the ledger. Both token columns are unconditionally redacted (§2). |
| `connection_subscription` *(H15a)* | `created`, `updated`, `deleted` | Which events go to which destination on a connection — the routing half, holding no credential. |
| `subscription` | `updated` | Billing-plan changes, per PRD Feature #12. |
| `tenant_users` | `created` (invite sent), `updated` (status transitions), `deleted`/`removed` | Membership lifecycle (`docs/multi-tenancy-rbac-design.md` §7). |
| role grant/revoke — recorded against `auditable_type = users`, `auditable_id = <affected user's uuid>`, with the granted/revoked role captured in `old_values`/`new_values` | `permission_changed` | Every role grant/revoke — the RBAC doc's own `tenant.ownership.transfer` flow explicitly logs this event type. The assignment is audited against the affected `users` row rather than the `model_has_roles` pivot itself, because that pivot has a composite PK with no surrogate `id` (RBAC §4), and `audits.auditable_id` is a single `uuid` that cannot address a composite-key row. |
| `resource_grants` | `created` (access granted), `deleted` (access revoked) | Per-resource access changes (`docs/multi-tenancy-rbac-design.md` §8). Renamed from `form_collaborators` in Increment G10a, which generalized it: a grant now names either a form or a `scope_nodes` subtree, so the audit entry must record `scopeable_type`/`scopeable_id` and `includes_descendants` — a subtree grant can confer access to many forms at once, and the entry is unreadable without its reach. Grants are hard-deleted, so this trail is the ONLY record that a revoked grant ever existed. |
| `users` | `updated`, specifically limited to `is_super_admin` changes | The single highest-blast-radius flag in the schema (ADR-0002 §D3) — any change to it is audited regardless of how unrelated-looking the rest of the `users` update is; ordinary profile edits (name, theme preference) are **not** audited (see §2's noise-reduction principle). |
| `tenant` | `updated`, specifically limited to `status` changes (suspend/reactivate) and `owner_user_id` changes (ownership transfer) | Platform-level and ownership-level changes, not every cosmetic tenant-settings edit. |
| `feedback_reports` | `updated` (status transitions by platform support) | Support-team accountability for how a tenant's feedback report was handled. |

**Deliberately NOT audited** (noise-reduction principle, stated once rather than implied by omission): `form_sections`/`form_fields`/`form_field_validations` row-level edits **while still part of a draft** are not individually audited — a builder's draft is expected to be edited many times before publish, and per-keystroke-level audit noise on mutable, pre-publish content would drown out the events that actually matter. The moment those rows are frozen into a **published** `form_version`, the *publish itself* is the audited event (`form_version.published`), which is what matters for historical accountability — the content is captured in `schema_snapshot`, not in a pile of intermediate draft-edit audit rows. `submission_answers`/`submission_answer_index` are also not directly audited row-by-row — the owning `submission`'s status-transition events are what's tracked; individual answer values are covered by the submission's own lifecycle, not a separate audit stream. **One deliberate exception**: a *permissioned post-submission answer edit* (a Form Editor/Admin correcting a mis-keyed value via `submissions.edit`, the fast-follow feature) **is** audited as a `submission.updated` event capturing which answer keys changed in `old_values`/`new_values` (subject to §2 PII redaction) — because a human deliberately altering already-collected data is exactly the provenance an audit trail exists to preserve, distinct from not auditing the respondent's original keystroke-level fill.

---

## 2. Redaction Rules

**Principle**: `audits.old_values`/`new_values` record *that something changed and roughly what changed*, never a field's raw sensitive content — this is what `docs/data-privacy-gdpr-compliance.md` §9 depends on to reconcile "audits are append-only, never deleted" with GDPR erasure obligations elsewhere in the schema.

**Always redacted, unconditionally** (a fixed, code-defined list — not tenant-configurable, since these are platform-level secrets/credentials, not tenant content):
- `users.password`, `users.remember_token`
- `users.two_factor_secret`, `users.two_factor_recovery_codes` (Fortify 2FA credentials, PRD Feature #14)
- `webhook_endpoints.secret` (and `secret_previous`, H13b's rotation grace copy)
- `connections.access_token`, `connections.refresh_token` (H15a / ADR-0009 §D10 — third-party OAuth credentials, encrypted at rest and never returned by any API in any form). The audit snapshot deliberately *includes* them so the redactor is what strips them and records the strip in `redacted_fields`; a snapshot that quietly omitted them would leave no evidence the ledger ever saw a credential-bearing write.
- `tenant_users.invite_token`
- Any Sanctum token value

> The list is hand-maintained by construction, keyed by the §1 auditable-type alias. A new credential-bearing
> alias that is not registered here is silently un-redacted and **nothing detects it** — so registering it
> belongs in the same increment that creates the table, not a follow-up.

**Redacted based on the Data Dictionary's own PII/sensitivity flags** (dynamic, not a fixed list, since these are tenant-declared or content-dependent):
- Any column the Data Dictionary marks **PII = Yes** or **PII = Yes (conditional)** — e.g., `submissions.guest_ip`/`guest_user_agent`/`guest_contact_email`, `attachments.original_filename`, `feedback_reports.remarks`/`browser_info`.
- Any `submission_answers.answers` key corresponding to a `form_fields.is_pii = true` or `is_sensitive = true` field.

**Redaction replaces the value with a placeholder marker** (e.g., `"[REDACTED]"`), and the specific field names that were redacted are recorded in `audits.redacted_fields` (`docs/data-dictionary.md` §13) — a transparent record of *that* redaction happened, without defeating its own purpose by naming what the value *was*.

### 2.1 The erasure special case — a deliberate exception to normal diff behavior

**Normal audit behavior**: `old_values` shows the real prior content of a changed field (e.g., a form's `title` changing from "Draft Survey" to "2026 Health Survey" — both real strings, both legitimately useful for accountability).

**Erasure is different, and must be, on purpose**: when a GDPR erasure request scrubs a field via `submissions.pii_erased_at` (or the `users` anonymization flow, `docs/data-privacy-gdpr-compliance.md` §3), the resulting audit entry's `old_values` **must not** contain the pre-erasure raw value — logging it there would silently defeat the erasure the event is nominally recording. For an erasure-triggered update, **both** `old_values` and `new_values` show the redaction placeholder for the affected fields (not "old_values = real content, new_values = redacted" as a normal diff would), and `redacted_fields` lists them as erased. This is the one case in this schema where an audit diff's "before" side is deliberately not the real prior value, and it is called out explicitly here so a future implementer doesn't "fix" it back to showing real content under the mistaken assumption that audit diffs should always be literal.

---

## 3. Access & Export

- **Viewing**: the Audit Log screen is visible to **Owner/Admin roles only** (`docs/multi-tenancy-rbac-design.md` §5's `audit_log.view` permission) — not re-litigated here, restated for completeness.
- **Exporting**: reuses the existing chunked/streamed export infrastructure (architecture plan §2.5) — no new export mechanism. Exporting the audit log is itself an auditable action: it would be logged as a `submission.exported`-style event if it's a submission export, or is itself worth a dedicated audit entry when it's the audit log being exported (a self-referential, but not infinitely-recursive, case — exporting the audit log logs one new "audit log exported" entry, it does not re-trigger for every historical row it contains).

---

## 4. Retention of Audit Rows

Audit rows are append-only and never deleted (`docs/data-dictionary.md` §13: "no `updated_at`/`deleted_at`: audit rows are immutable and never deleted, by design") — this document does not change that. `docs/data-privacy-gdpr-compliance.md` §9 already explains why this is GDPR-compatible: because of §2's redaction discipline, the audit log was never storing raw PII to begin with, only metadata about *what* changed.

---

## 5. Out of Scope / Deferred

- The Audit Log screen's UI/filtering/search design → Doc #19 (UI/UX Design System Reference) and `docs/PRD.md` Feature #12 already cover the user-facing shape; this document is schema/redaction-scope only.
- Whether super-admin actions taken *against* a tenant appear in that tenant's own Audit Log — an explicit open question already flagged in `docs/multi-tenancy-rbac-design.md` §9, not resolved here.
- Long-term audit-log archival/cold-storage strategy if the table grows very large over years of operation → Doc #22 (Deployment & Infrastructure Doc).
