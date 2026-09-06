# Data Privacy & GDPR/Compliance Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — builds directly on `docs/data-dictionary.md`'s already-comprehensive per-column PII classification methodology and `submissions.pii_erased_at` erasure mechanic; does not re-derive either, only operationalizes subject-rights fulfillment, retention, residency, and the DPA's factual inputs on top of them.
**Legal disclaimer**: this document specifies the **technical and operational mechanisms** this platform provides. It is not itself legal advice, and the actual DPA (§7), breach-notification procedure (§8), and any tenant-specific regulatory obligation (e.g., a Data Protection Impact Assessment) require review by qualified counsel before being relied upon in a customer-facing or regulatory context — consistent with this project's existing practice of flagging non-technical, non-pinned claims explicitly (e.g., the founding architecture plan's own pricing-figures caveat) rather than presenting them as settled.

---

## 1. Controller / Processor Roles — the Framing Everything Else Depends On

**The tenant is the Data Controller for the data their forms collect; this platform is the Data Processor.** A tenant (an NGO, research team, or business) decides *what* data to collect, from *whom*, and *why* — that is a controller decision under GDPR, made by the tenant, not by this platform. This platform's obligations are a processor's: provide the technical means for the controller (the tenant) to fulfill *their* subjects' (respondents') rights, keep the data secure, and never process it for purposes the tenant hasn't directed.

**Practical consequence**: this platform does not, and should not, unilaterally decide *whether* a specific tenant's use of the product requires a Data Protection Impact Assessment (DPIA), what lawful basis a tenant relies on to collect a respondent's health data, or whether a specific respondent's consent was properly obtained — those are the tenant's own legal responsibilities as controller. This platform's job is to make the *mechanisms* those decisions depend on (classification, consent capture, export, erasure, retention config, audit trail) available, correct, and auditable.

For the platform's **own** tenant-facing users (the staff who log in and build forms) and platform-billing data, the platform *is* the controller in the ordinary SaaS sense (account data, billing records) — this dual role is normal for a B2B SaaS platform and is called out explicitly so it isn't conflated with the tenant/respondent relationship above.

---

## 2. Data Classification (references, does not restate, the Data Dictionary)

`docs/data-dictionary.md`'s "PII classification methodology" (top-of-document Conventions section) already classifies every column **Yes** / **No** / **Yes (conditional)** — this document adds one more axis on top of it: **special-category data** (GDPR Art. 9 — health, biometric, genetic, racial/ethnic origin, political opinion, religious belief, sexual orientation, trade-union membership), which requires a *higher* bar than ordinary PII (explicit consent or another narrow Art. 9 exception, not just a lawful basis).

`form_fields.is_sensitive` (`docs/data-dictionary.md` §5) is already the mechanism: a **tenant-declared** flag ("does this field collect special-category/health data?") that already triggers encryption-at-rest for linked attachments and stricter audit redaction (see Doc #13). **Given this product's health-survey/M&E lineage**, tenants building health-related forms should be guided (via builder UX copy, not a database constraint) to mark fields like symptom checklists, diagnoses, medication, disability status, or biometric captures as `is_sensitive = true` — this platform cannot infer special-category status from a field's `label` text alone (a `long_text` field could ask anything), so **the accuracy of this classification is fundamentally a tenant/controller responsibility**, not something the platform can silently guarantee.

---

## 3. Subject Rights — Mechanisms

| Right | Mechanism | Notes |
|---|---|---|
| **Right of access** (export "everything about me") | A subject-data export tool: for an **authenticated respondent** (`submissions.respondent_user_id` set), pulls every `submissions`/`submission_answers` row where they're the respondent, plus their `users`/`user_ui_preferences`/`feedback_reports` rows. Uses the same streamed/chunked export infrastructure already specified for ordinary data exports (architecture plan §2.5) — no new export mechanism needed. | **Honest limitation for guest respondents**: a guest has no stable identity — only `guest_token` (opaque, session-scoped, not meant as a durable identifier), `guest_ip`, `guest_user_agent`, and optionally `guest_contact_email` if the form collected one. A guest subject-access request can only be *reliably* fulfilled by matching `guest_contact_email` (if collected) or by the tenant's own manual investigation; this platform cannot guarantee finding every submission from an anonymous guest with no persistent identifier, and this limitation should be stated plainly to tenants, not silently assumed away. |
| **Right to erasure** ("right to be forgotten") | Already partially specified: `submissions.pii_erased_at` scrubs `guest_ip`/`guest_user_agent`/`guest_contact_email` and any `is_pii`-flagged answer content **in place**, preserving the submission's aggregate/statistical shape (`docs/data-dictionary.md` §7). This document extends the mechanism to every other PII-bearing table: `attachments` rows flagged `is_pii = true` have their underlying object-storage file deleted (not just the DB row soft-deleted) and the row's `original_filename` cleared; `feedback_reports.remarks`/`browser_info` are scrubbed the same way as submission answers. | **`users` rows are anonymized, never hard-deleted** — given how extensively `users.id` is referenced (`forms.owner_user_id`/`created_by`, `submissions.respondent_user_id`/`validated_by`, `audits.user_id`, and more, per `docs/multi-tenancy-rbac-design.md` §12's FK summary), a true row deletion would break referential integrity across most of the schema. Erasure for an authenticated user replaces `name`/`email` with a redacted placeholder (e.g., `"Deleted User"` / a non-routable synthetic address) and clears any personal fields, while the row's `id` remains stable so every FK reference stays valid — the same "preserve structure, scrub content" philosophy `submissions.pii_erased_at` already established, applied consistently to a second table. |
| **Right to rectification** | Ordinary user self-service (edit your own profile/answers where the product allows it) — not a special mechanism; flagged here only to confirm it isn't a gap. |
| **Right to data portability** | The same export mechanism as "right of access" above, in a machine-readable format (JSON or CSV) — already implied by the existing streamed-export infrastructure, no new format-specific work needed beyond ensuring the export includes every column, not just a human-readable subset. |

> ⚠️ **`tenants:extract` IS NOT THIS, AND THE DISTINCTION IS WORTH STATING BEFORE SOMEBODY ASSUMES OTHERWISE (added 2026-08-14, P2b / ADR-0018).** Phase 4 built a **per-tenant** extraction command: `php artisan tenants:extract <tenant>` writes one workspace's record as NDJSON plus a manifest. Its subject is a **controller's workspace**, not a **data subject**, and the two artefacts differ in every respect that matters here. The tenant extract deliberately **withholds nine `users` columns** (four are credentials for a *central* identity — one human, N workspaces — and `last_active_tenant_id` is another workspace's UUID), deliberately **excludes `user_ui_preferences`** because it follows the person rather than the workspace, and deliberately **contains every member of the workspace**, which is the opposite of what a subject request may disclose. The subject-data export tool specified in the two rows above **has not been built**; nothing in `app/` implements it today. Reaching for `tenants:extract` to answer an Article 15 or Article 20 request would over-disclose (other people's rows) and under-disclose (the requester's own preferences, and their rows in workspaces other than the one extracted) at the same time.
| **Right to restriction of processing / right to object** | **Lighter-touch, support-case-driven for Phase 1** — rather than a fully automated self-service toggle (which would require modeling a "processing restricted" state across every table this data touches, a materially larger scope than currently justified), these are handled as a manual support-team action in Phase 1, using the same `settings`/super-admin tooling `docs/multi-tenancy-rbac-design.md` §9 already describes. Flagged as a Phase 2+ candidate for self-service automation if volume ever justifies it — an honest scope decision, not a silent gap. |

<!-- pipeline: id=gdpr-subject-export title="Build the subject-data export for Article 15 access and Article 20 portability" phase=4 state=ready size=L -->
<!-- pipeline: id=gdpr-erasure-execution title="Give submissions.pii_erased_at a writer — the erasure mechanic is documented and has none" phase=4 state=ready size=M -->
<!-- pipeline: id=gdpr-legal-posture title="The GDPR, legal and pricing posture decisions" phase=4 state=held size=S blocker="user: the GDPR, legal and pricing calls are the user's own" -->

---

## 4. Retention

**Default: indefinite retention, opt-in per-tenant automatic deletion** — deliberately not defaulting to auto-deletion, because this product's dual audience (`docs/PRD.md` §2) includes M&E/research tenants for whom multi-year historical data is the *point* of the product, alongside business tenants who may have a genuine compliance reason to auto-purge after a fixed window (e.g., job-application forms under a 1-year retention policy). A one-size-fits-all default would be wrong for at least one of these audiences.

**Mechanism**: reuses the existing tenant-scoped `settings` key-value table (`docs/data-dictionary.md` §20) rather than introducing a new table — a new settings key, e.g. `retention.submission_retention_days` (integer, `NULL` = retain indefinitely, the default). A scheduled job, run per-tenant, transitions `submissions` older than the configured window into `status = 'archived'` (the terminal state `docs/data-dictionary.md` §7's `SubmissionStatus` enum already added "for retention workflows") and/or triggers the same PII-erasure mechanic as §3, at the tenant's configured choice — **which of the two (archive vs. erase) a given retention policy performs is a tenant-configurable choice**, not a single hardcoded behavior, since "stop actively processing" and "destroy the personal data" are legally distinct actions with different implications.

<!-- pipeline: id=retention-sweep-job title="Build the per-tenant retention sweep and its settings key — both documented, neither exists" phase=4 state=ready size=M -->

---

## 5. Data Residency

**Current (Phase 1–3) state, stated plainly**: all tenant data lives in one shared region — whatever the chosen hosting platform's primary region is (ADR-0003 defers the specific region to implementation time). There is no per-tenant region selection today.

> ⚠️ **Corrected 2026-08-14 (P2a).** This paragraph said `tenants.data_residency_region` (`docs/data-dictionary.md` §1) was "an explicitly-reserved, currently-unused column". **That column has never existed** — it is in no migration and has zero references in the codebase. The distinction is not pedantry: "reserved" implies a later increment need only populate it, and that is precisely the weight this section was putting on it. Nothing about the *commitment* below changes; what changes is that the groundwork it implied is not in place.

**Phase 4**: data residency becomes possible via the same dedicated-database-per-tenant escape hatch ADR-0002 already reserves for compliance-driven enterprise tenants (its "Future migration path" section) — a tenant requiring, e.g., EU-only data storage would be migrated to an isolated database provisioned in the required region. This document does not build that mechanism; it only confirms the plan for it already exists and residency is not silently promised before Phase 4.

> ⚠️ **Qualified 2026-08-14 (ADR-0017).** The mechanism above is necessary and **not sufficient**, and the gap is in the last five words: *"provisioned in the required region."* Production is a single self-hosted box running a single PostgreSQL instance (ADR-0005), so a second database on it is in the same region, the same failure domain and the same backup window as the first. **Dedicated-database tenancy can deliver isolation; it cannot deliver residency until a second region exists**, which is a hosting decision (ADR-0005's revisit triggers) rather than a schema one. `dedicated_db` and `data_residency` are separately-grantable Enterprise keys precisely because they are separate promises — and nothing currently enforces that the second is not sold on the strength of the first.

**Cross-border transfer**: if the eventual chosen hosting region sits outside the EU while EU-based tenants/respondents are onboarded before Phase 4's residency option exists, Standard Contractual Clauses (SCCs) or an equivalent transfer mechanism will be needed in the tenant-facing DPA (§7) — flagged as a follow-up once the actual hosting region is pinned (ADR-0003), not resolved here since it depends on a fact not yet fixed.

---

## 6. Consent Capture

Two genuinely distinct consent surfaces, per this document's general discipline of not conflating adjacent concepts:

- **Respondent consent** (a form asking its own respondents for consent to be surveyed, e.g., for health data) — **no new schema construct needed.** This is achieved the same way Kobo/ODK-tradition forms have always modeled it: an ordinary required field (`yes_no` or `single_select`) authored by the tenant as part of their form design, stored as a normal answer like any other. Recommending a dedicated "consent" field type or table would add complexity this already-established XLSForm-tradition pattern doesn't need.
- **Platform user consent** (an authenticated tenant staff member accepting this platform's own Terms of Service / Privacy Policy at signup) — **recommended small addition**: two nullable `timestamptz` columns, `tos_accepted_at` and `privacy_policy_accepted_at`, on the `users` table (`docs/multi-tenancy-rbac-design.md` §6) — a small, well-justified addition to that already-written doc's table (adopted in this same batch, see the accompanying update).

---

## 7. Data Processing Agreement (DPA) — Factual Inputs, Not Legal Text

Per this document's opening disclaimer, the actual binding DPA language must come from qualified counsel. This section specifies the **operational facts** a DPA needs to accurately state, so legal drafting has a correct, current factual basis rather than guessing at the architecture:

- **Sub-processors** (per `docs/architecture/technical-architecture.md`'s C4 Context diagram's "External Systems"): Stripe (billing), the S3-compatible object storage provider, the OCR provider (Google Cloud Vision or equivalent), the transactional email provider — each is a genuine sub-processor a DPA must disclose.
- **Tenant-enabled sub-processors (native connectors, ADR-0009)**: a tenant that connects Slack — and, later, Google Sheets or Airtable (H16) — causes submission *metadata* (form name, event, timestamp, and a link back into this platform) to be transmitted to that provider on its behalf. Two facts a DPA must state precisely: (a) these are **elective** — no data reaches them unless the tenant completes an OAuth connection and configures a subscription, so the disclosure is conditional in a way the four sub-processors above are not; and (b) the connector envelope is **metadata-by-default, not answer content** (`docs/webhook-integration-design.md` §3's default-exclude-sensitive-content principle), so the personal data reaching a connector today is whatever a form *name* and a respondent-free event envelope contain. If a future per-subscription "include answers" opt-in ships, that changes the category of data disclosed and this bullet must be revised with it. **Revised in H6a, as that increment required — and the answer is that the trigger did NOT fire.** H6a shipped the template grammar, the publish gate and the `mrkdwn` **escaping** fix, but deliberately did *not* make the connector message author-editable: `SlackMessageFormatter` still emits a fixed Block Kit layout, and no answer content leaves the platform through it. So the category of data disclosed to a connector is **unchanged** — still form name, event, timestamp and a link back — and `include_answers` stays deferred. The trigger now has a *later* owner: whichever increment makes the connector message template-driven (H16 is the likely one, with `SlackMessageFormatter` as the named seam). **That increment must revise this bullet in itself, not after it**: at that point the connector envelope stops being metadata-by-default and the personal data reaching the provider becomes whatever the template's author chose to pipe. Custody of the credential itself (encryption at rest, revocation) is ADR-0009 §D1/§D7; the security posture is `docs/security-threat-model.md` §5.

  > **⚠ REVISED IN H16a (2026-08-05) — THE TRIGGER HAS NOW FIRED, and it fired from a different direction than predicted.** The forecast was that message *templating* would be what carried answer content to a provider. What actually did it is the **Google Sheets connector**, whose entire purpose is to put submission data in a tenant's spreadsheet: a row of identifiers would be a feature nobody would switch on. **So the "metadata-by-default, not answer content" statement above is no longer true of every connector, and the DPA must now distinguish them.**
  >
  > **What is disclosed, precisely.** For **Slack** nothing has changed: a fixed Block Kit layout carrying form name, event, timestamp and a link back — still metadata only. For **Google Sheets**, each matching submission causes **the answer values the tenant's own column mapping binds** to be transmitted to Google and written into their spreadsheet. The set is **author-chosen per rule** (only mapped columns are sent, and the tenant may map none of the sensitive ones), **elective** in the same conditional way as before (nothing flows without an OAuth connection *and* a configured rule), and **not retroactive** (only submissions arriving after the rule exists).
  >
  > **Three properties the DPA can rely on, each structural rather than promised.** (a) **The delivery ledger is not a second copy**: `webhook_deliveries.payload` still stores only the metadata envelope, and the answers are resolved at delivery time and never persisted outside the submission itself. ⚠️ **THIS IS A PROPERTY OF THE WHOLE ROW, NOT OF `payload` ALONE, AND SAYING SO IS WHAT MAKES IT CHECKABLE** (M4, 2026-08-19): `response_body_excerpt` is bound by it too, and a successful Airtable delivery broke it for four increments precisely because this clause named only the other column. An adapter's SUCCESS excerpt is therefore a fixed marker (`'ok'`), never the provider's response body — several providers echo the record just written. (b) **Deletion propagates by not-sending, not by recall** — a submission deleted before delivery is reported as `blocked` and nothing is written; a row *already* in the tenant's spreadsheet is in the **tenant's own Drive**, under their control, and this platform has no ability to reach back into it (`drive.file` grants per-file access, not a right to erase history). That asymmetry is a genuine erasure-request consideration and §3 should be read with it. (c) **Google is a sub-processor for the tenants who enable it**, on the same conditional footing as Slack.
  >
  > **The template trigger this bullet originally named is still OPEN.** H16a did not make any connector message author-editable; `SlackMessageFormatter` still emits a fixed layout. Whichever increment makes templating real must revise this bullet again, for Slack.

  > **⚠ REVISED AGAIN IN H16c (2026-08-13) — a SECOND answer-carrying connector, and one disclosure that is genuinely new rather than a repeat.** **Airtable** joins Google Sheets in the answer-carrying category, on identical terms: only the fields the tenant's own mapping binds are transmitted, per rule, electively, and not retroactively. All three structural properties above hold unchanged — the ledger still stores only the metadata envelope, deletion still propagates by not-sending (a record already in the tenant's base is in *their* Airtable workspace and this platform cannot reach back into it), and Airtable is a sub-processor only for the tenants who enable it.
  >
  > **What is NOT a repeat, and belongs in the DPA in its own words: writing a record can ADD A SINGLE-SELECT OPTION to the tenant's table.** Deliveries send `typecast: true`, without which a text answer mapped onto a Number, Date or Single-select field is refused and the rule pauses on its first real submission. Airtable's coercion, when an answer does not match an existing option of a single-select field, **creates that option**. So this connector can make a small, additive change to the *structure* of a tenant's base — and it can do so on the data scope alone, even though **`schema.bases:write` was deliberately NOT requested** (ADR-0009 §D8). Stated here rather than left to be discovered: it is a side effect of writing their data, it is confined to select-field options, it never deletes or renames anything, and it is the price of the connector working at all against a normally-typed table.
  >
  > **The scopes, for the DPA's processing-description.** `schema.bases:read` (list the tenant's bases; read a table's field names, which is what drift detection compares) and `data.records:write` (add the record). No `data.records:read` — the connector appends and never reads back stored records — and no `user.email:read`.
  >
  > **The template trigger remains OPEN and still belongs to Slack alone.**
- **Data location**: whatever ADR-0003's eventually-pinned hosting region is (§5).
- **Retention**: per §4's tenant-configurable policy — a DPA should state that retention is *tenant-controlled*, not a single platform-wide number.
- **Security measures**: summarized from `docs/adr/0002-multi-tenancy-shared-db-rls.md` (tenant isolation) and `docs/security-threat-model.md` (the fuller security posture) — a DPA typically wants a plain-language summary of these, not the full technical detail.
- **Sub-subject-rights fulfillment**: §3's mechanisms are what the platform (as processor) commits to supporting on the controller's (tenant's) behalf.

---

## 8. Breach Notification

GDPR Art. 33 requires notifying the relevant supervisory authority within 72 hours of becoming aware of a breach (and Art. 34 may require notifying affected subjects directly, depending on risk). **This document sets the target; Doc #23 (Observability & Incident Response) owns the actual detection/response runbook** that would make "becoming aware" happen quickly enough to meet that window in practice — not re-derived here.

---

## 9. Interaction with the Audit Trail

`docs/multi-tenancy-rbac-design.md` §9 and `docs/security-threat-model.md` establish that `audits` rows are append-only and never deleted — this is compatible with GDPR erasure **only because** `docs/data-dictionary.md`'s redaction methodology (`audits.old_values`/`new_values` redacted per sensitive-field rules, tracked in `redacted_fields`) means the audit log was never storing raw PII values to begin with, just metadata about *what* changed. Doc #13 (Audit & Compliance Logging Spec) formalizes this redaction precisely — this document only states the legal reasoning that makes an append-only audit log GDPR-compatible: audit/compliance logging is itself a recognized legal basis (GDPR Art. 17(3)(b) exempts erasure where processing is necessary for compliance with a legal obligation), and the redaction-at-write-time discipline is what keeps that exemption from becoming a backdoor that defeats erasure elsewhere in the schema.

---

## 10. Out of Scope / Deferred

- The exact DPIA process/template a tenant should follow for their own use of the platform — the tenant's own legal responsibility as controller (§1).
- Detailed audit redaction rules (which specific fields, which specific placeholder text) → Doc #13.
- Breach detection/response mechanics → Doc #23.
- Plan-tier-specific data-retention/export quotas (if any) → Doc #24.
- The actual, counsel-reviewed DPA and Privacy Policy documents themselves — this document supplies inputs, not final legal text (§7).
