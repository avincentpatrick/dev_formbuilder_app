# Data Dictionary

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — written against the approved architecture plan, before any migration is written (per the plan's "Next Steps," this doc precedes code).
**Scope:** Every entity in the plan's §2.2 "Core Form/Submission Data Model": `tenants`, `forms`, `form_versions`, `form_sections`, `form_fields`, `form_field_validations`, `submissions`, `submission_answers`, `submission_answer_index`, `attachments`, `field_library`, `form_templates`, `audits`, `webhook_endpoints`, `webhook_deliveries`, `plans`, `subscriptions`, `usage_counters` — plus three tables added later at the user's explicit request to back PRD Features #9–#11: `user_ui_preferences`, `settings`, `feedback_reports` — and two added in the Phase-0-readiness review to back PRD Feature #13 (Notifications): `notifications`, `notification_preferences`.
**Out of scope:** `users`, `roles`, `permissions`, `model_has_roles`/`model_has_permissions`, `password_reset_tokens`, `sessions`, and other Laravel/Fortify/Spatie-Permission auth tables are **not** part of the plan's §2.2 model and are therefore not detailed here — they belong to the Multi-Tenancy & RBAC Design Doc (plan doc #9, `docs/multi-tenancy-rbac-design.md`). Where a column below is a foreign key into `users`, it is noted as "external — see RBAC doc" rather than fully specified. The one exception worth flagging now because it is load-bearing for this document's PII methodology and for `audits`/`submissions`/`webhook_endpoints`: `users.is_super_admin` is the explicit boolean called out in plan §2.1, replacing legacy's `id === 1` convention — it lives on `users`, not on `tenants`. Also out of scope by the same reasoning: `tenant_users` (tenant membership/invite lifecycle) and, since Increment G10a, `resource_grants` + `scope_nodes` (per-resource access scoping and the tenant-defined hierarchy it can target — these replaced the original `form_collaborators`, and are keyed off the RBAC doc's role catalog) — all introduced by that document, not this one.

This document is the source of truth for column-level shape; it will be kept in sync with migrations in the same PR that changes them (plan §5, "docs-as-code discipline").

---

## Conventions Used Throughout This Document

**Database engine:** PostgreSQL (plan §1), so all types below are Postgres types (`uuid`, `jsonb`, `timestamptz`, `inet`, `numeric`, etc.), not MySQL equivalents.

**Primary key strategy:** every table's `id` is a `uuid` generated with Postgres's native `uuidv7()` (PostgreSQL 18+; if the launch target is PG 17 or earlier, generate UUIDv7 client-side in the application before insert, e.g. via a Symfony/Ramsey UUIDv7 helper, and remove the DB-side default) — chosen over `uuidv4`/`gen_random_uuid()` for better B-tree index locality (time-ordered inserts avoid the random-insert page-split churn that pure v4 causes at scale), and over auto-increment `bigint` because: (a) IDs are exposed in the public API and public share links, where sequential integers leak cross-tenant record counts and are trivially enumerable; (b) the offline PWA client (plan §2.4) must be able to generate a valid, collision-safe primary key **before** it ever reaches the server, which auto-increment cannot do. Two tables deliberately deviate from this and use `bigint identity` instead — `submission_answer_index` and `usage_counters` — because they are pure internal aggregation/projection rows, never addressed externally, generated only server-side in high volume, where a narrower key measurably helps index size at scale; this is called out again in each table's own Design Notes.

**Tenant scoping:** every table except `plans` (a global, platform-owned pricing catalog — see its Design Notes) carries a `tenant_id` column, even where it could technically be derived through a join, because plan §2.1 requires Postgres Row-Level Security on every tenant-scoped table driven by a per-request session variable — RLS policies need `tenant_id` present on the row itself, not two joins away. Some tables denormalize `tenant_id` onto rows that already have another path to it (e.g. `form_fields.tenant_id` alongside `form_version_id`) specifically so the RLS policy can be a single flat `tenant_id = current_setting(...)` check on every table, with no exceptions to remember.

**PII classification methodology:** a column is marked **PII = Yes** if it can, by itself or trivially combined with other columns on the same row, identify or single out a natural person (respondent, guest, or platform user) — direct identifiers (email, IP, filenames referencing a person), device/behavioral fingerprints (user agent, device id), and free-text/JSONB content whose meaning is defined by a tenant's own form design rather than by this schema. That last category — `submission_answers.answers`, `submission_answer_index.value_*`, `webhook_deliveries.payload`, `audits.old_values`/`new_values` — is marked **PII = Yes (conditional)**: the column's *schema* is platform-owned and contains no PII of its own, but its *content* is tenant-defined and data-dependent, so it must be treated as PII-bearing by default for GDPR purposes (plan doc #12) unless a specific tenant's form is known not to collect personal data. Foreign keys that merely *identify an actor* (`created_by`, `updated_by`, `owner_user_id`, `respondent_user_id`, `uploaded_by`, `validated_by`) are marked **No** — the UUID itself is not personal data — but are still in-scope for GDPR subject-access/erasure tooling by virtue of the relationship they encode; this is a deliberate convention, not an oversight, stated once here rather than repeated on every row.

**Enum & vocabulary strategy (stated once, per plan §5 "one consistent enum strategy"):** the default for every fixed, code-controlled vocabulary in this schema is a **native PHP 8.1+ backed enum**, stored as a plain `varchar` column with a Postgres `CHECK` constraint mirroring the enum's cases, and cast to the PHP enum via Eloquent's `casts()` — this fixes legacy's mixture of magic-number columns, lookup tables with no Eloquent model, and stray free-text columns living next to FK-backed siblings. Every such column is marked in its table with **"PHP enum: `EnumName`"** in the Type cell, and the full value list is documented once below rather than repeated in every table. This document introduces **no new lookup tables** for vocabulary beyond what the plan itself already specifies (`field_library`/`form_templates` are reusable *content*, not vocabulary; `plans` is the platform's *pricing catalog*, not a vocabulary lookup, even though it plays a similar structural role to one — see its Design Notes) — every closed vocabulary in the schema fits comfortably in an enum, and legacy's own lookup tables that had "no Eloquent model" (`form_submission_statuses`, `submission_methods`) are exactly the pattern being eliminated. One deliberate, explicitly-flagged exception exists: `subscriptions.stripe_status` is stored as free text, not a PHP enum, because it mirrors Stripe's own status vocabulary verbatim as synced by Cashier — Stripe controls and can extend that vocabulary outside this app's release cycle, so constraining it locally would risk silently rejecting a legitimate future Stripe status. This is called out again in that table's Design Notes.

**PHP backed enum catalog** (all values in `snake_case`, matching each enum's Postgres `CHECK` constraint):

| Enum | Backing column(s) | Values |
|---|---|---|
| `TenantStatus` | `tenants.status` | `trial`, `active`, `suspended`, `cancelled` |
| `FormStatus` | `forms.status` | `draft`, `published`, `archived` |
| `FormVersionStatus` | `form_versions.status` | `draft`, `published`, `superseded` |
| `FieldType` | `form_fields.field_type`, `field_library.field_type` | 31 values across 8 categories (carried forward from legacy's 8-category catalog as the starting set, plan §2.2, with one deliberate addition flagged here rather than silently folded in): **Text** — `short_text`, `long_text`, `email`, `phone`, `url`; **Numeric** — `integer`, `decimal`, `calculated`; **Date/Time** — `date`, `time`, `datetime`, `duration`; **Choice** — `single_select`, `multi_select`, `dropdown`, `yes_no`, `cascading_select`; **Likert** — `likert_scale`, `likert_matrix` (a Likert-scale grid — every cell is a score on the same scale); **Geographic** — `geopoint`, `geotrace`, `geoshape`; **Media** — `file_upload`, `image_capture`, `audio_capture`, `video_capture`, `signature`; **Structural** — `note`, `page_break`, `hidden`, `matrix` (a **generic** grid/table question — legacy's distinct "Matrix (grid)" type, each cell independently typed/answered, not score-only like `likert_matrix`; added as its own case here because it was missing from this document's first pass, not because it's a new capability — see plan's original 30-type legacy catalog) |
| `RequiredMode` | `form_fields.is_required` | `required`, `optional`, `conditional` (carried forward from legacy's tri-state int, now named) |
| `ValidationRuleType` | `form_field_validations.rule_type` | `min_value`, `max_value`, `min_length`, `max_length`, `pattern`, `required_if`, `required_with`, `skip_if`, `skip_with`, `greater_than_field`, `less_than_field` (11 values, mirrors legacy's 11-row `rule_types` lookup) |
| `ComparisonOperator` | `form_field_validations.operator` | `gt`, `lt`, `eq`, `neq`, `is_null`, `contains` (6 values, mirrors legacy's 6-row `rule_formulas` lookup) |
| `LogicOperator` | `form_field_validations.logic_operator` | `and`, `or` |
| `SubmissionStatus` | `submissions.status` | `draft`, `submitted`, `under_review`, `approved`, `returned`, `archived` (adapted from legacy's 5-value lookup: `under_review` replaces "Pending Validation", `archived` is a new terminal state added for retention workflows — reasonable, noted extension) |
| `SubmissionSource` | `submissions.source` | `manual`, `guest`, `ocr_single`, `ocr_linelist`, `offline_sync`, `api_import` (the six channels named explicitly in plan §2.2/§2.4) |
| `IndexedDataType` | `form_fields.indexed_data_type` | `text`, `number`, `boolean`, `date`, `datetime` |
| `AttachmentKind` | `attachments.kind` | `submission_file`, `ocr_source_scan`, `field_media_sample`, `signature_capture`, `avatar`, `branding_logo`, `export_artifact`, `webhook_payload_archive` |
| — | — | **H17 note:** `export_artifact` has a writer for the first time — the submission PDF (`SubmissionPdfStorage`). It uses a DETERMINISTIC storage key, `tenants/{tenant}/export_artifact/submissions/{submission}.pdf`, rather than the `{yyyymm}/{uuid}` leaf the two older writers use, so regenerating a PDF overwrites in place and one submission can only ever hold one. That is deliberate: `storage_bytes` is a hard-block gauge summed over non-trashed `attachments` rows and NOTHING in the codebase reclaims storage, so an accumulating key would charge a tenant permanently for every click of "Regenerate". |
| `ScanStatus` | `attachments.virus_scan_status` | `pending`, `clean`, `infected`, `skipped` |
| `AuditEvent` | `audits.event` | `created`, `updated`, `deleted`, `restored`, `published`, `archived`, `exported`, `permission_changed` (extends legacy's 4 base events with 4 domain events the plan's versioning/export/RBAC model needs tracked — noted extension) |
| `WebhookEndpointStatus` | `webhook_endpoints.status` | `active`, `paused`, `disabled` |
| `WebhookEventType` | `webhook_deliveries.event_type`, entries inside `webhook_endpoints.event_types` | `submission.created`, `submission.updated`, `submission.approved`, `form.published`, `form.archived`, `subscription.updated` — an explicitly **starter** catalog (plan §2.5: "phase in more — not 50 at once"); expected to grow across phases as new events are wired into the pipeline, at which point new cases are added to the enum (each new webhook-emitting event is a code change anyway, so this vocabulary stays code-controlled, not data-controlled) |
| `WebhookDeliveryStatus` | `webhook_deliveries.status` | `pending`, `delivering`, `succeeded`, `failed`, `dead_lettered` |
| `PlanTier` | `plans.code` | `free`, `starter`, `professional`, `business`, `enterprise` (schema supports 5 tiers; plan §3 Phase 1 ships with 2–3 active) |
| `BillingInterval` | `subscriptions.billing_interval`, entries inside `plans.billing_interval_options` | `monthly`, `yearly` |
| `UsageMetric` | `usage_counters.metric`, keys inside `plans.quotas` | `submissions_count`, `storage_bytes`, `api_requests`, `webhook_deliveries`, `active_seats`, `forms_count`, `exports_count` — flagged as the one enum most likely to eventually need promotion to a real lookup table if per-integration/per-feature metering grows large and needs to be tenant- or admin-configurable rather than code-defined; not needed yet, called out here so it isn't a surprise later |
| `ThemeMode` | `user_ui_preferences.theme_mode` | `light`, `dark`, `system` (added for PRD Feature #9) |
| `FontSizeScale` | `user_ui_preferences.font_size_scale` | `standard`, `large`, `extra_large` (added for PRD Feature #9 Phase 2) |
| `AccentToken` | `user_ui_preferences.accent_token` | `blueprint`, `teal` (PRD Feature #9 Phase 2). **Unlike every other enum here it has no backing DB `CHECK`** — §19 rules one out deliberately, so this enum *is* the application-layer whitelist rather than a mirror of a database constraint. **AMENDED H23a3:** the domain is now `{NULL, 'blueprint', 'teal'}`. `blueprint` is stored EXPLICITLY and **NULL means "no opinion"** — inherit the tenant's brand ramp if one is active (ADR-0014), otherwise fall back to the product default. Before H23a3 Blueprint was stored AS NULL, which collapsed the two readings and left a member of a branded tenant unable to ask for the product blue back. Existing NULL rows render identically: a tenant with no brand has nothing to inherit. |
| `FeedbackStatus` | `feedback_reports.status` | `new`, `reviewed`, `resolved`, `wont_fix` (added for PRD Feature #11) |
| `NotificationType` | `notifications.type`, `notification_preferences.notification_type` | `submission_received`, `submission_returned`, `submission_approved`, `review_requested`, `export_ready`, `member_invited`, `webhook_failed` (added for PRD Feature #13; **still entirely unbuilt as of H17** — there is no `notifications` table, no `NotificationType` enum in `app/`, no bell and no polling anywhere, so H17 deliberately did NOT wire `export_ready` and reports its outcome by email plus a card on the submission page instead; grows as new notifiable events are wired in — same code-controlled posture as `WebhookEventType`) |
| `NotificationChannel` | delivery channels a `NotificationType` supports | `in_app`, `email` (added for PRD Feature #13) |

**Timestamp convention:** every table has `created_at timestamptz not null default now()` and `updated_at timestamptz not null default now()` (maintained by Eloquent) unless otherwise noted; these two are omitted from the "Design Notes" prose per table to avoid repetition but are always present in the column table itself.

**Soft-delete convention:** tables holding tenant business records that benefit from an "undo"/trash grace period carry `deleted_at timestamptz nullable` (Eloquent `SoftDeletes`). This is distinct from **GDPR erasure**, which does not rely on soft-delete at all — erasure scrubs specific PII-bearing columns in place (see `submissions.pii_erased_at`) so that aggregate/statistical shape survives a subject-erasure request even though the personal data does not.

---

## Table of Contents

1. [tenants](#1-tenants)
1a. [domains](#1a-domains)
2. [forms](#2-forms)
3. [form_versions](#3-form_versions)
4. [form_sections](#4-form_sections)
5. [form_fields](#5-form_fields)
6. [form_field_validations](#6-form_field_validations)
7. [submissions](#7-submissions)
8. [submission_answers](#8-submission_answers)
9. [submission_answer_index](#9-submission_answer_index)
10. [attachments](#10-attachments)
11. [field_library](#11-field_library)
12. [form_templates](#12-form_templates)
13. [audits](#13-audits)
14. [webhook_endpoints](#14-webhook_endpoints)
15. [webhook_deliveries](#15-webhook_deliveries)
16. [plans](#16-plans)
17. [subscriptions](#17-subscriptions)
18. [usage_counters](#18-usage_counters)
19. [user_ui_preferences](#19-user_ui_preferences) *(added for PRD Feature #9)*
20. [settings](#20-settings) *(added for PRD Feature #10)*
21. [feedback_reports](#21-feedback_reports) *(added for PRD Feature #11)*
22. [notifications](#22-notifications) *(added for PRD Feature #13)*
23. [notification_preferences](#23-notification_preferences) *(added for PRD Feature #13)*
24. [connections](#24-connections) *(added for H15a / ADR-0009)*
25. [connection_subscriptions](#25-connection_subscriptions) *(added for H15a)*
26. [saved_report_views](#26-saved_report_views) *(added for H24a / ADR-0011)*
27. [Foreign Key Relationship Summary](#foreign-key-relationship-summary)

---

## 1. `tenants`

The durable record for one customer organization — the root of every tenant-scoped table and the unit Row-Level Security policies key off (plan §2.1).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key; also the value stamped into the RLS session variable and into every queued job payload (plan §2.1). |
| `name` | `varchar(150)` | No | — | No | Organization display name. |
| `slug` | `varchar(100)` | No | — | No | Unique, used to resolve the tenant from a subdomain (plan §2.1 "tenant resolved from subdomain via early middleware"). |
| `domain` | `varchar(255)` | Yes | `NULL` | No | ⚠️ **This column does not exist — corrected 2026-07-21.** The row previously claimed it "exists from day one so the schema doesn't need a later migration"; the create-`tenants` migration declares `name`/`slug`/`status` only. Domain resolution is served by the separate **`domains`** table (RLS-exempt, driven by stancl's `HasDomains` on `App\Models\Tenant`). **The migration that row predicted has now landed — H22a, 2026-08-04; see §1a.** Do not reinstate this column: two places to look up a host is the defect the correction was about. |
| `status` | `varchar(20)` — PHP enum: `TenantStatus` | No | `'trial'` | No | Lifecycle state of the whole account. |
| `owner_user_id` | `uuid` | No | — | No | FK to `users.id` (external — see RBAC doc). The account's primary owner/billing contact. |
| `billing_email` | `varchar(255)` | Yes | `NULL` | **Yes** | Invoice/billing contact address; may differ from any individual user's login email. |
| `billing_country` | `varchar(2)` | Yes | `NULL` | No | ISO 3166-1 alpha-2 country for sales-tax/VAT determination (Stripe Tax); captured from billing details at checkout (PRD Feature — VAT/tax handling). |
| `tax_id` | `varchar(50)` | Yes | `NULL` | **Yes** | Customer VAT/GST/tax-registration number when provided (EU reverse-charge etc.); synced to Stripe as a customer tax ID. |
| `is_tax_exempt` | `boolean` | No | `false` | No | Marks a tax-exempt customer (e.g. a qualifying NGO/institution); suppresses tax calculation via Stripe Tax. |
| `timezone` | `varchar(64)` | No | `'UTC'` | No | IANA timezone name, used for dashboard date bucketing and scheduled-form timing (Phase 3). |
| `default_locale` | `varchar(10)` | No | `'en'` | No | Default locale for new forms created under this tenant. |
| `supported_locales` | `jsonb` | No | `'["en"]'` | No | Array of locale codes the tenant has enabled for form translations. |
| `logo_attachment_id` | `uuid` | Yes | `NULL` | No | FK to `attachments.id` (`ON DELETE SET NULL`); tenant branding logo. **BUILT in H23a2.** The attachment's `attachable_type` is the alias `tenant`, deliberately NOT registered in the global morph map — see `BrandingMorphAliasTest`. |
| `primary_color` | `varchar(7)` | Yes | `NULL` | No | The `#RRGGBB` the tenant TYPED. **BUILT in H23a2.** It is an INPUT, not a rendered value: ADR-0014's engine keeps the hue, caps chroma and re-derives lightness per role, so what renders is `brand_ramp` below and the two are frequently different. Kept so the admin card can show the submitted colour beside the derived one (the snap disclosure). Applies to the guest runtime **and** the authenticated app — the reach was widened from this row's original wording by the 2026-08-05 product decision. |
| `brand_ramp` | `jsonb` | Yes | `NULL` | No | The twelve derived hexes (6 roles × light/dark), their 17 measured §4.1 ratios, and the `engine_version` that produced them (H23a2, ADR-0014 §D8). **Stored rather than re-derived on read** because three consuming surfaces cannot resolve a CSS custom property at all — mail clients strip `<style>`, and dompdf supports neither custom properties nor `oklch()`. Written only together with `primary_color`, only by `TenantBrandingService`; a row with one and not the other is a bug. |
| `data_residency_region` | `varchar(20)` | Yes | `NULL` | No | Reserved for Phase 4 data-residency options (plan §3 Phase 4); unused until then. |
| `settings` | `jsonb` | No | `'{}'` | No | Catch-all tenant-level configuration not otherwise modeled as a column. |
| `trial_ends_at` | `timestamptz` | Yes | `NULL` | No | End of trial period, if on `status = 'trial'`. |
| `suspended_at` | `timestamptz` | Yes | `NULL` | No | Set when an admin or billing failure suspends the account. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft-delete; tenant offboarding keeps a grace period before hard deletion/anonymization jobs run. |

> **Design Notes**
> - `users.is_super_admin` (plan §2.1's explicit fix for legacy's fragile `id === 1` convention) lives on the out-of-scope `users` table, not here — a tenant has no "super admin" concept of its own, super-admin is a platform-wide flag on a person.
> - No `plan_id` column here on purpose: current plan/tier is derived through `subscriptions` (a tenant's *current* plan is "the plan of its active subscription"), avoiding two sources of truth for billing state.
> - `status` is intentionally coarser than `subscriptions.stripe_status` — this column answers "can this org sign in and use the app," Stripe's finer billing states live one table over.

---

## 1a. `domains`

**Added to this document 2026-08-04 (H22a).** The table has existed since Increment A and had never been
documented here — the omission the `tenants.domain` correction above was really about.

The host → tenant lookup. It is read **before** any tenant context exists, because it is what *decides*
which tenant a request belongs to, so it is deliberately **RLS-EXEMPT** (`scripts/migration-lint.php`
`EXEMPT_TABLES`) — scoping it by the tenant would be circular. There is therefore **no database backstop
on queries against it**: every application query that acts on a tenant's behalf must carry its own
`where('tenant_id', …)`, which is why all of them live in `App\Services\Tenancy\CustomDomainService`.

**One table, two kinds of row, discriminated by the DOT** (ADR-0012 §D3 — an exact partition of the two
producers, not a heuristic, and pinned by a CHECK constraint):

| `domain` value | What it is |
|---|---|
| `acme` | The tenant's platform **subdomain label**. What the subdomain arm of tenant identification looks up (stancl passes the first label only). Always resolvable; never verified; never swept. |
| `forms.acme.com` | A **custom domain**. Resolvable only once *live* — DNS-verified **and** activated by an operator who has installed a certificate (see `deployment-infrastructure.md` §8.1). |

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `integer` | No | auto-increment | No | Primary key. The one non-uuid PK in the tenant-facing schema, inherited from stancl's own migration. Not used as a route key — the API addresses a domain by hostname. |
| `domain` | `varchar(255)` | No | — | No | The subdomain label or the FQDN. **Globally UNIQUE**, which is what makes a claim a reservation: no two tenants can hold the same hostname, even while one is still pending. Lowercased on save by stancl's `ConvertsDomainsToLowercase`. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`, cascade on update and delete. |
| `verification_token` | `varchar(64)` | Yes | `NULL` | No | 256-bit CSPRNG hex, per row, random and never derived. Published by the tenant in public DNS, so it is **deliberately not a secret and deliberately not in `AuditRedactor::SECRETS`** (ADR-0012 §D11). NULL on subdomain rows; a partial unique index makes reuse a database error. |
| `token_issued_at` | `timestamptz` | Yes | `NULL` | No | When the challenge was minted. With the claim TTL, this is what expires an abandoned claim. |
| `verified_at` | `timestamptz` | Yes | `NULL` | No | Control of the hostname was proven. **Does not imply the domain serves anything.** |
| `activated_at` | `timestamptz` | Yes | `NULL` | No | An operator put it into service, by policy only after installing its certificate. **This is the only column that makes a custom domain routable**, and only `php artisan domains:activate` writes it — there is no API endpoint (ADR-0012 §D6). |
| `verification_checked_at` | `timestamptz` | Yes | `NULL` | No | Last DNS lookup. Drives both the sweep's fair ordering and its re-check cadence. |
| `verification_failure_reason` | `varchar(40)` — PHP enum: `DomainVerificationFailure` | Yes | `NULL` | No | `not_found` / `mismatch` / `lookup_failed`. The last split is load-bearing: `lookup_failed` means *we* could not ask (SERVFAIL/timeout) and is never evidence about the tenant, so it never demotes a verified domain. |
| `is_primary` | `boolean` | No | `false` | No | **H22b.** Which of the tenant's LIVE custom domains its respondent-facing links are built on (`TenantUrl::publicHost()` orders `is_primary DESC` ahead of the pre-existing oldest-activated tiebreak). A **preference, not state** — unlike every column above it, nothing about it is derivable, because both candidate rows are equally live. `false` is safe as a default precisely because it is the fail-**closed** value: it means "no preference expressed", so the fallback applies and rows that predate the column emit exactly the host they emitted before. Set by the tenant (`POST /domains/{domain}/primary`, web or API) and by the FIRST activation of a tenant's first live domain; **cleared by `deactivate()`**, which it must be — see the CHECK below. |
| `created_at` / `updated_at` | `timestamptz` | No | `now()` | No | |

**Constraints and indexes beyond the PK/FK:** `UNIQUE(domain)` (global, from the original migration);
`domains_custom_requires_token_chk` — a dotted row must carry a token, so a hand-written or mass-assigned
FQDN cannot exist in a state the verification service never minted; `domains_verification_token_unique`
(partial, `WHERE verification_token IS NOT NULL`); `domains_custom_sweep_idx` (partial, custom rows only,
ordered `verification_checked_at NULLS FIRST` to match the sweep's access path);
**`domains_primary_requires_live_chk`** — `is_primary = false OR activated_at IS NOT NULL`, which is what
keeps H22b's tenant-reachable write off the activation path: a tenant may choose **among** hosts an
operator put into service and can never produce one (ADR-0012 §D6, amended). Its direct consequence is that
`deactivate()` has to clear `is_primary` in the same write, or taking a host out of service becomes a
constraint violation rather than a deactivation. **`domains_primary_per_tenant_unique`** (partial,
`WHERE is_primary`) — one primary per tenant, enforced by the database rather than by a service that could
race two concurrent clicks into two winners; the clear-then-set pair therefore runs in one transaction.

> **Design Notes**
> - **State is derived from three nullable timestamps, not a `status` column** (ADR-0012 §D4): every
>   possible default for such a column would be fail-open, and there is no sensible default for "has this
>   hostname been proven".
> - **`App\Models\Domain` carries a global scope that hides any custom domain which is not live.** That
>   single scope is what makes "an unverified row cannot be routed to and cannot appear in any link" a
>   property of the model rather than a rule five call sites have to remember — including stancl's own
>   resolver and `ConnectorRedirector`, neither of which was edited. Use `Domain::unscopedQuery()` only in
>   the service and the sweep, which exist to act on rows that are not yet usable.
> - **`$guarded = []` is inherited from stancl and deliberately not narrowed** (sixty test fixtures depend
>   on it), so anything writing here must build its attribute array explicitly rather than passing request
>   data through.

---

## 2. `forms`

The durable, logical form record — the stable identity that a `public_slug` and dashboard listing point to. It is deliberately thin: almost all form *content* lives versioned in `form_versions`/`form_sections`/`form_fields`.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. |
| `current_published_version_id` | `uuid` | Yes | `NULL` | No | FK to `form_versions.id`; the version respondents currently fill. `NULL` until the form's first publish. |
| `draft_version_id` | `uuid` | Yes | `NULL` | No | FK to `form_versions.id`; the single mutable draft being edited right now. Kept as an explicit pointer rather than derived by querying `form_versions WHERE status='draft'`, so "which draft is live" is never ambiguous under concurrent builder sessions. |
| `title` | `varchar(255)` | No | — | No | Denormalized copy of the current draft's title, kept in sync on every draft save, so form lists/search can read it without touching JSONB (see Design Notes). |
| `description` | `text` | Yes | `NULL` | No | Denormalized, same rationale as `title`. |
| `status` | `varchar(20)` — PHP enum: `FormStatus` | No | `'draft'` | No | Top-level lifecycle: `draft` (never published), `published` (has an active published version and may be accepting responses), `archived` (owner retired the form regardless of version state). |
| `public_slug` | `varchar(120)` | Yes | `NULL` | No | Unique per tenant; the `/f/{slug}`-style public URL segment (legacy pattern, carried forward per plan §Main Features #3). `NULL` until guest access is first enabled. |
| `allow_guest_submissions` | `boolean` | No | `false` | No | Capability flag — Main Feature #3. |
| `allow_manual_encoding` | `boolean` | No | `true` | No | Capability flag — Main Feature #7. |
| `allow_ocr_single` | `boolean` | No | `false` | No | Capability flag — Main Feature #1 (Phase 3; column exists from Phase 0 per the plan's "structural, not retrofitted" principle). |
| `allow_ocr_linelist` | `boolean` | No | `false` | No | Capability flag — Main Feature #2. |
| `allow_api_import` | `boolean` | No | `true` | No | Capability flag for the `api_import` submission source. |
| `allow_offline_sync` | `boolean` | No | `true` | No | Whether this form may be downloaded into the offline PWA client (plan §2.4). |
| `single_page_mode` | `boolean` | No | `false` | No | Carried forward from legacy as a confirmed-good flag (plan §2.2); toggles single-page vs. multi-step respondent presentation (Doc #20). |
| `confirmation_message` | `text` | Yes | `NULL` | No | Migrated by `2026_07_30_000002_add_confirmation_message_to_forms` (Increment H6a, Doc #26 §6.2). Author-editable thank-you copy for the post-submit confirmation screen. Template-bearing, so a tenant can pipe the respondent's own answers into it (Doc #26). `NULL` keeps the built-in default copy, so the column is additive for every existing form. Deliberately on `forms`, not `form_versions` — it is therefore **not** frozen per version, and its holes are validated at publish against **the version being published** (Doc #26 amendment A3; "currently published" was backwards, since inside `PublishService` the draft is about to become it). Written only by `FormService::setConfirmationMessage()` and deliberately **absent from `Form::$fillable`**, so mass-assignment cannot reach a template-bearing column; `Form` has no `$hidden`/`$guarded`, so a fillable column would also serialize into every presenter payload by default. |
| `confirmation_message_translations` | `jsonb` | Yes | `NULL` | No | Migrated with the column above (H6a); cast `array`. `{locale: message}` map, the same `{column}_translations` sibling pattern; each variant is independently a template (Doc #26 §4), and reference-set parity across locales is NOT required. The first `*_translations` column in the schema to carry a request-level validation rule (`UpdateConfirmationMessageRequest`); the other four have no FormRequest ingress at all, so the publish gate is their only guarantee. |
| `default_locale` | `varchar(10)` | No | — | No | Defaults from the owning tenant's `default_locale` at creation time. |
| `supported_locales` | `jsonb` | No | `'[]'` | No | Locales this specific form has translations for. |
| `capability_flags` | `jsonb` | No | `'{}'` | No | Computed, explicit capability flags derived from form composition (e.g. `{"has_geofields": true, "ocr_compatible": false}`) — recomputed on every publish, generalizing legacy's OCR-compatibility guard (plan §5). Three keys: `has_geofields`, `has_media`, `ocr_compatible`. Written by `App\Services\Forms\CapabilityFlags::forSnapshot()` from the just-published version's `form_versions.schema_snapshot`, so the value describes the **currently published version** — not the form, and not any older version. Treat it as a display/list cache: it is stale until the next publish, so a gate must re-derive rather than read it (Increment H8). A specific version's value, including a superseded one, is recovered exactly from that version's own snapshot via `CapabilityFlags::isOcrCompatible()`; there is deliberately no per-version column. The `ocr_compatible` rule is `docs/ocr-pipeline-design.md` §2. |
| `theme` | `jsonb` | Yes | `NULL` | No | Per-form branding override (Phase 3 custom branding), falls back to tenant-level branding when `NULL`. |
| `owner_user_id` | `uuid` | No | — | No | FK to `users.id` (external). The form's business owner (dashboard scoping, plan §Main Features #4). |
| `scope_node_id` | `uuid` | Yes | `NULL` | No | FK → `scope_nodes` as a **composite** `(tenant_id, scope_node_id)` (ADR-0002 §D5), `ON DELETE SET NULL (scope_node_id)`. Which node of the tenant's own hierarchy this form belongs to — the basis for subtree-scoped access grants (`multi-tenancy-rbac-design.md` §8) and, per ADR-0011 §D6, the **only** grouping axis available to cross-form analytics. NULL means the form is reachable only by a direct grant, which is every form's state after the G10a backfill; assignment is a separate `PATCH /forms/{form}/scope` gated on `scopes.manage`. Added by `2026_07_20_000002_add_scope_node_id_to_forms`. *(Filed here 2026-08-03 — this row was previously misfiled under §1 `tenants`, though its own text said "this form"; `tenants` has no such column.)* |
| `save_and_resume` | `boolean` | No | `false` | No | Per-form opt-in for partial-submission save-and-resume (H10). The guest draft endpoint requires **both** this flag and the tenant's `save_and_resume` entitlement. Added by `2026_07_23_000010`. |
| `opens_at` | `timestamptz` | Yes | `NULL` | No | Scheduled-forms window start (H12a). Added by `2026_07_25_000001`. |
| `closes_at` | `timestamptz` | Yes | `NULL` | No | Scheduled-forms window end (H12a). |
| `timezone` | `varchar` | Yes | `NULL` | No | IANA zone the schedule window is authored in (H12a). |
| `max_responses` | `integer` | Yes | `NULL` | No | Response cap; enforced as a transactional COUNT-under-RLS, never a running aggregate (H12a). |
| `schedule_state` | `varchar(20)` | No | — | No | Resolved open/closed state flipped by the scheduled-forms sweep, which emits `form.opened`/`form.closed` (H12a). |
| `created_by` | `uuid` | No | — | No | FK to `users.id` (external). |
| `updated_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external). |
| `published_at` | `timestamptz` | Yes | `NULL` | No | Denormalized first-publish timestamp; authoritative per-version publish times live on `form_versions.published_at`. |
| `archived_at` | `timestamptz` | Yes | `NULL` | No | — |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft-delete. |

> **Design Notes**
> - **No `guest_tokens` table.** The plan's "guest identity tracked by a signed token scoped to one specific form version" (plan §Main Features #3) is implemented (Increment F5) as a **stateless, HMAC-signed token** minted at request time when a guest opens `public_slug` — it embeds **`{tenant_id, form_id, form_version_id, expiry}`** and is verified by signature + expiry, never persisted as a row. The fuller four-field payload (not just `{form_version_id, expiry}`) is what the architecture §5 layer 9 / §7.2 and the form-filling UX flow mandate, so the request's Postgres RLS tenant context is derivable from the token alone and cross-tenant/cross-form replay is signature-checked before any context is set. The token is reusable until expiry (24h default); a single-use flag would need persistence and is deferred. This avoids introducing an 18th entity beyond the plan's explicit list; it is called out here as a concrete, reasonable interpretation of that requirement.
> - `status` (form-level) and `form_versions.status` (version-level) look similar but answer different questions on purpose — see `form_versions` Design Notes for the full distinction.
> - `title`/`description` are a deliberate denormalization: the authoritative, versioned copy lives in `form_versions.schema_snapshot` (and in the normalized `form_sections`/`form_fields` for the live draft); these two columns exist purely so list/search/dashboard queries never need to parse JSONB.
> - **`owner_user_id` is attribution, not the sole access gate.** Who may actually edit or review this form is governed by the RBAC doc's `resource_grants` table (external — see RBAC doc, `docs/multi-tenancy-rbac-design.md` §8): Owner/Admin tenant roles get tenant-wide access regardless of this column, while a Form Editor/Reviewer needs a grant that covers this form — either naming it directly, or naming the `scope_nodes` node it is assigned to via `scope_node_id`. `owner_user_id` remains the form's primary point of contact and the default dashboard-scoping assignee, and its creator is expected to also receive an editor grant at creation time — but it no longer means "the only person who can edit."

---

## 3. `form_versions`

The immutable snapshot per publish — the single structural fix for legacy's core gap (plan §2.3): submissions FK here, never to the live, mutable `forms`/`form_fields` rows.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key; this is the value every `submissions.form_version_id` FKs to. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`; denormalized for flat RLS policies. |
| `form_id` | `uuid` | No | — | No | FK to `forms.id`. |
| `version_number` | `integer` | No | — | No | Sequential per form, starting at 1; unique together with `form_id`. |
| `status` | `varchar(20)` — PHP enum: `FormVersionStatus` | No | `'draft'` | No | `draft` (mutable, editable), `published` (immutable, currently live), `superseded` (immutable, replaced by a later publish — still addressable by historical submissions). |
| `title` | `varchar(255)` | No | — | No | This version's own title at the moment of its creation/publish (so a respondent's historical submission can show "what this form was called when I filled it," even after later renames). |
| `description` | `text` | Yes | `NULL` | No | — |
| `schema_snapshot` | `jsonb` | No | — | No | Denormalized, read-optimized cache of this version's own sections/fields/validations (see Design Notes) — the shape consumed by the public runtime and the offline PWA client. |
| `change_summary` | `text` | Yes | `NULL` | No | Optional publisher-entered changelog note for this publish. |
| `checksum` | `varchar(64)` | Yes | `NULL` | No | SHA-256 of `schema_snapshot`; used by the offline client and export tooling to detect drift/cache-bust without re-downloading the full snapshot. |
| `published_at` | `timestamptz` | Yes | `NULL` | No | Set once, when this version transitions `draft` → `published`. |
| `published_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external). |
| `superseded_at` | `timestamptz` | Yes | `NULL` | No | Set when a later version is published, transitioning this row `published` → `superseded`. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - **`schema_snapshot` is a cache, not a competing source of truth.** `form_sections`/`form_fields`/`form_field_validations` rows belong to a specific `form_version_id` and are the editable, queryable, referentially-integral source of truth while `status = 'draft'`. On publish (and kept in sync while still draft), the same version's rows are flattened into `schema_snapshot` — a denormalized read format that lets the public runtime and offline client render a whole form in one fetch instead of joining three normalized tables on every request. Once `published`, both the normalized rows and the snapshot are frozen together and never diverge again.
> - `forms.status` vs. `form_versions.status`: the former is a coarse, form-level rollup ("has this form ever been published, is it archived"); the latter tracks each individual version's own lifecycle. A form can be `forms.status = 'published'` while its current draft (`form_versions.status = 'draft'`) is mid-edit — these are independent state machines by design, matching the "exactly one mutable draft, N immutable published/superseded versions" model in plan §2.3.
> - Uniqueness: `(form_id, version_number)` is enforced with a unique index.
> - **"Immutable" became a DATABASE property here in H25 (ADR-0013), not just a description.** `form_versions_published_immutable_trg` is a `BEFORE UPDATE … FOR EACH ROW` trigger gated `WHEN (OLD.status IS DISTINCT FROM 'draft')`. Once a row leaves `draft` it is **deny-by-default**: the trigger diffs the whole row (`to_jsonb(OLD)` vs `to_jsonb(NEW)`, compared per key as `::text`) and refuses any change outside four exempt names — so **a column added to this table by a future migration is frozen the moment it exists**, and adding it to the exempt list is a deliberate, reviewed act. The four, and the rules that re-guard three of them:
>   - `updated_at` — freely writable (every Eloquent `save()` bumps it).
>   - `status` — the ONLY legal move off a non-draft row is `published` → `superseded`. `superseded` is terminal, and **nothing ever returns to `draft`**: that one statement would re-open every `form_sections`/`form_fields`/`form_field_validations` row beneath this version, because their RLS guard keys on exactly that value.
>   - `superseded_at` — writable only by the statement performing that flip, and only out of `NULL`, so it can never be re-dated or cleared.
>   - `published_by` — may be **cleared but never re-pointed**. Its `ON DELETE SET NULL` against `users` is executed by PostgreSQL as an ordinary UPDATE through SPI, which bypasses RLS but *not* a trigger; freezing it outright would make user hard-deletion, and GDPR erasure with it, permanently impossible.
>
>   Refusals raise SQLSTATE **23001** (`restrict_violation`) naming the offending columns, the row id and the status — so unlike every RLS refusal in this schema, which is a silent zero-row no-op, this one **throws**. The trigger binds every connection including the `pgsql_privileged` superuser. It is **UPDATE-only**: deletion of a non-draft version is still blocked only by the RLS DELETE policy, and an `ON DELETE CASCADE` from `forms`/`tenants` still removes published versions (Risk **R12**).
> - `form_versions_status_chk` (H25) pins `status IN ('draft', 'published', 'superseded')`. It is the trigger's own fail-closed precondition rather than tidiness: the guard treats anything that is not `'draft'` as frozen, so without the CHECK a single typo'd status would freeze a row permanently in a state no code can interpret.

---

## 4. `form_sections`

Was `indicator_groups`. Belongs to exactly one `form_version`; groups fields, optionally as a repeat group.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`; denormalized for RLS. |
| `form_version_id` | `uuid` | No | — | No | FK to `form_versions.id`, `ON DELETE CASCADE`. |
| `key` | `varchar(150)` | No | — | No | Stable slug preserved across versions of the same logical section (assigned once, copied forward into each new draft unless explicitly forked); used for cross-version analytics alignment (plan §2.3.5) and as the section reference inside `${...}` expressions. |
| `label` | `varchar(255)` | No | — | No | Display title. |
| `label_translations` | `jsonb` | Yes | `NULL` | No | `{locale: label}` map, legacy's confirmed-good `{column}_translations` sibling pattern (plan §2.2). |
| `description` | `text` | Yes | `NULL` | No | — |
| `description_translations` | `jsonb` | Yes | `NULL` | No | — |
| `sequence` | `integer` | No | `0` | No | Display order within the version. |
| `icon` | `varchar(50)` | Yes | `NULL` | No | Carried forward from legacy. |
| `color` | `varchar(7)` | Yes | `NULL` | No | Carried forward from legacy. |
| `is_repeatable` | `boolean` | No | `false` | No | Whether this section is a repeat group (Phase 2). |
| `min_instances` | `smallint` | Yes | `NULL` | No | Only meaningful when `is_repeatable = true`. |
| `max_instances` | `smallint` | Yes | `NULL` | No | Only meaningful when `is_repeatable = true`. |
| `relevant_expression` | `text` | Yes | `NULL` | No | Section-level XLSForm-style `relevant` condition (skip logic at section granularity) — a reasonable, plan-consistent extension beyond legacy, which only had per-field `relevant`; noted here as such since it's not explicitly in the source material. **This column is the Phase-3 branching primitive** (Doc #27 §3): a `form_sections` row **is** a step in multi-step presentation, `sequence` is the total order the step projection walks, and a "branch" is this predicate on a section node — never an edge between nodes, since no step/page/edge table exists or is planned. A forward reference (naming a field in a *later* section) is legal and stays legal, unlike a piping template hole, which is refused at publish; Doc #27 §3.1 gives the asymmetry's reason and §6 the retro-gate argument for warning rather than refusing. ~~**One live trap recorded at §3.3:** `count(${some_repeatable_section})` inside a `relevant_expression` always evaluates to 0, because the relevance settle context is intersected against top-level field keys only and the repeat array is pruned away before evaluation.~~ **FIXED in H21a** (Doc #27 amendment A2): the settle context now seeds every REPEATABLE section's key alongside the currently-relevant field keys, in both engines, so `count(${roster}) > 0` works as a branch condition — and it was fixed at BOTH scopes, since the identical trap existed inside a repeat *member's* own `relevant_expression`, whose context is built before the repeat arrays are merged in. Two properties are worth knowing when authoring against this column: `count()` here reads the **raw** instance array (repeats are processed after the settle loop, where `calculated` runs last and sees the relevance-pruned instances), and a section key that **collides with a field key** is deliberately not seeded — the two tables carry independent unique indexes, so a collision is reachable, and seeding one would re-admit the answer of a field relevance had just pruned (amendment A7). |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - `is_repeatable`/`min_instances`/`max_instances` are enforced at the application layer only (a `CHECK` that `min_instances <= max_instances` when both are set is added, but "must be a repeat group to hold repeat-instance answers" is enforced by the submission pipeline, not the database) — mirrors legacy's own enforcement level for the equivalent constraint, called out as a known, accepted gap rather than a silent one.
> - `key` vs. `label`: `key` never changes once assigned (expression/analytics stability); `label` is freely editable copy. This split did not exist as two separate concepts in legacy's single `name`/`group_name` pair and is a deliberate clarification for the versioned model.

---

## 5. `form_fields`

Was `indicators`. Belongs to exactly one `form_version`. The center of the schema — every other form-content table hangs off this one.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`; denormalized for RLS. |
| `form_version_id` | `uuid` | No | — | No | FK to `form_versions.id`, `ON DELETE CASCADE`. |
| `form_section_id` | `uuid` | Yes | `NULL` | No | FK to `form_sections.id`, `ON DELETE SET NULL`. `NULL` = ungrouped/top-level field. |
| `key` | `varchar(150)` | No | — | No | Stable slug carried across versions (same concept as `form_sections.key`); referenced in expressions as `${key}` — and, from Phase 3, in respondent-facing *text* as the same `${key}` piping hole under a separate template grammar (Doc #26) — and used to align cross-version analytics without rewriting historical submissions (plan §2.3.5). Unique per `form_version_id`. |
| `field_type` | `varchar(40)` — PHP enum: `FieldType` | No | — | No | See the full 31-value/8-category catalog in "Conventions" above. |
| `config` | `jsonb` | No | `'{}'` | No | Per-type settings: choice options (+translations), numeric min/max/precision, file accept-types/max-size, geo precision, Likert scale points, cascading-select hierarchy data, calculated-field formula reference, etc. |
| `label` | `varchar(500)` | No | — | No | Question text. |
| `label_translations` | `jsonb` | Yes | `NULL` | No | — |
| `hint` | `text` | Yes | `NULL` | No | — |
| `hint_translations` | `jsonb` | Yes | `NULL` | No | — |
| `placeholder` | `varchar(255)` | Yes | `NULL` | No | Input placeholder text. Template-bearing (Doc #26 §6). |
| `default_value` | `text` | Yes | `NULL` | No | Literal or, when `default_value_is_expression = true`, an expression string. **From Increment H7 it is also the fixed prefill value of a `hidden` field** (see the Design Note below) — the same literal meaning it always had, now with a reader. |
| `default_value_is_expression` | `boolean` | No | `false` | No | — |
| `is_required` | `varchar(20)` — PHP enum: `RequiredMode` | No | `'optional'` | No | Carried forward tri-state (legacy's int 1/2/3), now named. |
| `relevant_expression` | `text` | Yes | `NULL` | No | XLSForm-style `relevant` (conditional visibility/skip logic), evaluated by the expression engine (plan §2.2). |
| `appearance` | `varchar(60)` | Yes | `NULL` | No | XLSForm-style appearance hint (e.g. `vertical`, `autocomplete`, `minimal`). |
| `sequence` | `integer` | No | `0` | No | Global order within the version. |
| `section_sequence` | `integer` | Yes | `NULL` | No | Order within its section (was `group_sequence`). |
| `is_required_note` | — | — | — | — | *(intentionally omitted — superseded by `is_required` enum; listed here only to document that legacy's separate free-text "required note" concept was folded into `error_message` on `form_field_validations` instead of duplicated here).* |
| `is_pii` | `boolean` | No | `false` | No | Tenant-declared: does this field's **answer data** contain personal information? Drives the GDPR/PII classification workflow (Doc #12) for tenant-authored content the platform cannot infer semantically on its own. |
| `is_sensitive` | `boolean` | No | `false` | No | Tenant-declared: does this field collect special-category/health data (GDPR Art. 9)? Triggers encryption-at-rest for any linked `attachments` and stricter redaction in `audits`. |
| `is_queryable` | `boolean` | No | `false` | No | Whether this field's answers are projected into `submission_answer_index` for fast filtering/sorting/aggregation (plan §2.2's "selective typed projection"). |
| `indexed_data_type` | `varchar(20)` — PHP enum: `IndexedDataType` | Yes | `NULL` | No | Required when `is_queryable = true`; tells the projection job which typed column to populate. |
| `created_by` | `uuid` | No | — | No | FK to `users.id` (external). |
| `updated_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external). |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - `field_type` as a native PHP backed enum is called out explicitly in plan §2.2 ("fixes legacy's inconsistent magic-number enums"); the 31-value catalog above is a concrete, renumbered restatement of legacy's 30-row `input_types` lookup (plus one addition, `matrix`, distinct from the scored `likert_matrix` — see the "Conventions" enum table), grouped into the same 8 categories, but expressed as code (an enum with a `category()` accessor method), not a database table — no separate `field_types` lookup table exists in this schema.
> - `is_pii`/`is_queryable` interact deliberately: app-level validation should discourage (via a form-builder UI warning, not a hard DB constraint) marking a field both `is_sensitive = true` and `is_queryable = true`, since that would duplicate special-category data into a second table (`submission_answer_index`) purely for filtering convenience — minimizing where sensitive data physically lives is preferred over query convenience.
> - Cascading-select hierarchy data (legacy's `reference_categories`/`references`/`psgc_level_id`) is stored inline inside `config` (or sourced from a `field_library` blueprint) rather than as a new FK to an unlisted reference-data table — kept in scope of the plan's explicit 18 entities; a dedicated reference-dataset table is a reasonable future addition but is deliberately not introduced here.
> - **Increment G4a `config` schemas** (concrete keys for the newly-runtime-supported choice types):
>   - `likert_scale` — `config.options` = `[{value,label}]` (each option a scale point), exactly the choice-field shape; the answer is the single chosen scalar value.
>   - `cascading_select` — `config.levels` = `[{key,label}]` (broadest→most-specific) + `config.options` = `[{value,label,level,parent}]`; each option is pinned to a `level` and (below the root) carries the `parent` option value it belongs under. The answer is an ordered `list<string>` (one chosen value per level). Filtering is config-driven (a child shows iff its `parent` == the parent level's choice) — **no `choice_filter` expression / grammar change** (grammar stays v2.0).
>   - Publish integrity is enforced by `StructuralValidationGate` (choice options non-empty + distinct; cascading levels non-empty, every level populated, no dangling parent).
> - **Increment G4b `config` schemas** (the two object-valued grid types — the answer is a JSON object, never a scalar, and is NEVER routed through the scalar expression engine / typed answer index):
>   - `likert_matrix` — `config.rows` = `[{value,label}]` (each row a statement to rate) + `config.columns` = `[{value,label}]` (the shared rating scale, lowest→highest). The answer is `{row: score}` (one chosen column value per row); each row renders one radio group over the scale.
>   - `matrix` — `config.rows` + `config.columns` (as above) + `config.cells` = `[{value,label}]` (the shared per-cell choice pool). The answer is `{row: {col: cell}}` (each `(row × column)` intersection independently selects one cell value).
>   - Publish integrity (`StructuralValidationGate`): `rows`/`columns` (and, for `matrix`, `cells`) each non-empty with distinct, present values; a grid field may NOT sit in a repeatable section; and no expression (`relevant`/`constraint`/`calculate`/`required_*`) may reference a grid field (`ExpressionValidationGate` — a grid value has no valid scalar operand use and would drift between the PHP and TS engines). Grammar stays v2.0.
> - **Template-bearing columns (Increment H6a, Doc #26)** — and **the grammar still stays v2.0.** Answer piping lets respondent-facing *text* carry a `${key}` hole, but it does so under a **separate, separately-versioned template grammar**, so every "grammar stays v2.0" claim above remains literally true and both expression vector suites are untouched. The closed list of columns that may hold a template: `form_fields.label`/`label_translations`, `form_fields.hint`/`hint_translations`, `form_fields.placeholder`, `form_sections.label`/`label_translations`, `form_sections.description`/`description_translations`, plus the forward-spec `forms.confirmation_message`/`confirmation_message_translations` (§2). **`form_fields.default_value` is deliberately excluded** — it already has an expression mode via `default_value_is_expression`, and one column carrying two sub-grammars is how ambiguity gets built in. Not every field type may be a piping *source*: the object-valued grid/geo/media types and the answer-free `note`/`page_break` are excluded by a total, `default`-less classification (Doc #26 §3.1), the same forcing device `App\Enums\OcrFieldEligibility` uses. Every locale variant is validated independently at publish, and no `*_translations` column has *any* validation rule today — H6a owes them one.
> - **Increment H7 `config` schema + the hidden-field contract** (the only per-type config a `hidden` field carries, because where its value comes from is the only thing there is to configure about a field nobody fills in):
>   - `hidden` — `config.prefill_source` = `'fixed'` | `'url'` (absent ⇒ no source, the field holds nothing) + `config.url_param` (the query-parameter name for the `url` source; blank/absent falls back to the field's own `key`). The **fixed value reuses `default_value`** rather than adding a second column — it is already in the snapshot, already in the XLSForm round-trip, and Doc #26 §6 already excludes it from the template grammar, so it carries exactly one sub-grammar.
>   - **Source authority is the point, not ergonomics.** `App\Enums\PrefillSource` classifies the field, and `StructuralAnswerNormalizer` acts on it: a `fixed` field's value is written server-side from the authored literal (and INJECTED when the payload omits it — hence the second pass over the fields), and the submitted value is never inspected; a `url` field's submitted value is kept, scalar-only and capped at `PrefillSource::MAX_VALUE_BYTES` (2000, matching this column's own FormRequest cap) with `prefill_too_long` as the Stage-1 fault. Sourcing is **opt-in**: a field key is never an implicit query parameter. Uniform across all six ingest channels — a "trusted channel" carve-out would make the same form yield different data per channel.
>   - **A hidden field can never produce a respondent-facing error.** Nobody can see, focus or repair one, so `StructuralValidationGate` refuses at publish: `is_required` other than `optional` (`hidden_field_required`), any owned `form_field_validations` row (`hidden_field_has_validations`), placement inside a repeatable section (neither source can address one instance of N), and a `url_param` outside `/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/` (`prefill_param_invalid`). Because that gate is not retroactive, **both engines also skip a hidden field in the Stage-3 error pass** (the `calculated` early return, widened) — a narrowing, pinned by `tests/golden/validation/hidden.json` in PHP and TS.
>   - **Read/render asymmetry, deliberate.** The guest SPA renders a hidden field *not at all* (that is what the type means); the manual-encode page renders it, keyable only when `url`-sourced, because a keyer transcribing paper is the only possible source of an external value on that channel. Inbox and export were already correct — `SchemaValueFormatter::isDataField()` excludes only `note`/`page_break`. **As built (H21c):** the asymmetry now has a named home rather than an implicit one. Since the encode page mounts the guest runtime's step model, and `RENDERS_NOTHING` drops `hidden`, a section holding nothing but a `url`-sourced hidden gate can never BE a step — so those rows render in an explicit **"Reference fields — not shown to the respondent"** block outside the step list, while a hidden field sitting alongside ordinary questions still renders inline where its author put it. Doc #27 amendment C2 records why this was preferred to widening the projection with an audience axis: it keeps the two channels' step lists byte-identical, which is what makes the parity suite a clean equality.
> - **Semantic (Stage-3) error slugs added in G4a/G4b** (relevance-aware, run only on answered/relevant fields; skipped when a field declares no options, keeping earlier behaviour byte-identical): `choice_not_in_options` (single/multi/dropdown/likert_scale value not in `config.options`; **G4b** also a `likert_matrix` row score not in `config.columns` or a `matrix` cell not in `config.cells`), `cascading_choice_invalid` + `cascading_parent_mismatch` (per-level, addressed `field.<levelIndex>`), and **G4b** `field_required` on a per-cell address (a required grid demands every row (likert_matrix) / every `row × column` cell (matrix) be answered). All sub-field failures are addressed via the `SemanticError.cellPath` sub-axis — a cascading level index (`field.<i>`), a Likert-matrix row (`field.<row>`), or a matrix cell (`field.<row>.<col>`). Stage-1 (structural, relevance-unaware) additionally rejects a non-object grid (`expected_object`) or an undeclared row/column key (`unknown_row`/`unknown_column`).

---

## 6. `form_field_validations`

Was `indicator_validations`. Structured rules or an expression, modeled on XLSForm `relevant`/`constraint` (plan §2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`; denormalized for RLS. |
| `form_version_id` | `uuid` | No | — | No | FK to `form_versions.id`, `ON DELETE CASCADE`; denormalized for direct version-scoped queries. |
| `form_field_id` | `uuid` | No | — | No | FK to `form_fields.id`, `ON DELETE CASCADE` — the field this rule validates. |
| `related_form_field_id` | `uuid` | Yes | `NULL` | No | FK to `form_fields.id`, `ON DELETE CASCADE` — the cross-referenced field for comparison-style rules (`greater_than_field`, `required_if`, etc.). Replaces legacy's `related_indicator_value`, a documented "ghost column" whose meaning drifted after a data migration — this column has exactly one meaning, always. |
| `rule_type` | `varchar(30)` — PHP enum: `ValidationRuleType` | No | — | No | See the 11-value catalog above. |
| `operator` | `varchar(20)` — PHP enum: `ComparisonOperator` | Yes | `NULL` | No | See the 6-value catalog above; only meaningful for comparison-style `rule_type`s. |
| `rule_value` | `text` | Yes | `NULL` | No | Literal operand/threshold. |
| `expression` | `text` | Yes | `NULL` | No | Full XLSForm-style expression string. When present, **supersedes** `rule_type`/`operator`/`rule_value`/`related_form_field_id` entirely — see Design Notes. |
| `error_message` | `varchar(500)` | Yes | `NULL` | No | — |
| `error_message_translations` | `jsonb` | Yes | `NULL` | No | — |
| `logic_group` | `uuid` | Yes | `NULL` | No | Groups multiple rule rows under one compound condition. |
| `logic_operator` | `varchar(3)` — PHP enum: `LogicOperator` | Yes | `NULL` | No | Only meaningful when `logic_group` is set. |
| `sequence` | `integer` | No | `0` | No | Evaluation order within the field. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - **Precedence is explicit and enforced**, not an implicit convention like legacy: a `CHECK` constraint requires exactly one of `expression IS NOT NULL` or `rule_type IS NOT NULL` per row — a rule is either fully structured or fully expression-based, never a silent hybrid.
> - `related_form_field_id`'s single, unambiguous meaning is a direct, named fix for legacy's `related_indicator_value` "ghost column" (plan's Reference Files section explicitly calls this out as a cautionary example not to replicate).

---

## 7. `submissions`

Was `form_submissions`. FKs to the specific `form_version_id` collected against — the key structural fix in the whole plan (plan §2.3).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Server-assigned primary key — see Design Notes for why this is distinct from `client_submission_uuid`. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. |
| `form_id` | `uuid` | No | — | No | FK to `forms.id`; denormalized convenience for "all submissions for this form regardless of version" dashboard rollups. **Not** the structurally authoritative link — see `form_version_id`. |
| `form_version_id` | `uuid` | No | — | No | FK to `form_versions.id` — the specific, immutable version this submission was collected against. Never repointed on republish. |
| `respondent_user_id` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external). `NULL` for guest/anonymous submissions. |
| `status` | `varchar(20)` — PHP enum: `SubmissionStatus` | No | `'draft'` | No | See the 6-value catalog above. |
| `source` | `varchar(20)` — PHP enum: `SubmissionSource` | No | — | No | See the 6-value catalog above; the channel this submission entered through, all funneled through the one `SubmissionPipeline` (plan §2.2). |
| `client_submission_uuid` | `uuid` | Yes | `NULL` | No | Client-generated dedup/idempotency key (plan §2.2/§2.4), used by offline-sync replay; unique together with `tenant_id` when not null. Untrusted as row identity — see Design Notes. |
| `guest_token` | `varchar(128)` | Yes | `NULL` | No | Correlation handle for `source = 'guest'` submissions: the **SHA-256 fingerprint** (64 hex) of the share token the submission arrived through (the raw token exceeds this width). Ties a submission back to its link without persisting a replayable credential (Increment F5). |
| `guest_ip` | `inet` | Yes | `NULL` | **Yes** | Only populated for `source = 'guest'`. |
| `guest_user_agent` | `text` | Yes | `NULL` | **Yes** | Only populated for `source = 'guest'`; device/browser fingerprint. |
| `guest_contact_email` | `varchar(255)` | Yes | `NULL` | **Yes** | Only populated when the form is configured to collect a respondent email (e.g. for a "copy of your response" feature). |
| `device_id` | `varchar(100)` | Yes | `NULL` | **Yes** | Client device identifier for offline-sync provenance/debugging; treated conservatively as PII since combined with `guest_ip` it can contribute to re-identification. |
| `app_version` | `varchar(20)` | Yes | `NULL` | No | Client app/PWA build version at time of capture (support/debugging). |
| `locale` | `varchar(10)` | Yes | `NULL` | No | Locale the respondent filled the form in. |
| `source_batch_id` | `uuid` | Yes | `NULL` | No | Groups submissions created together in one pass — generalizes legacy's `batch_id` to cover linelist OCR (Main Feature #2) and bulk API import alike. |
| `validated_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external); reviewer who moved status to `approved`/`returned`. |
| `validated_at` | `timestamptz` | Yes | `NULL` | No | — |
| `returned_reason` | `text` | Yes | `NULL` | No | — |
| `remarks` | `text` | Yes | `NULL` | No | Internal reviewer notes (replaces legacy's `submission_comments` thread with a simpler single field at MVP; a full discussion-thread table is deferred as a later addition if reviewer collaboration proves to need more than one note — noted as a scope decision, not an oversight). |
| `submitted_at` | `timestamptz` | Yes | `NULL` | No | When the respondent finished/finalized (vs. `created_at`, when the draft row started — relevant for partial-save-and-resume, Phase 3). |
| `finalized_at` | `timestamptz` | Yes | `NULL` | No | Set on transition into a terminal state (`approved` or `archived`). |
| `pii_erased_at` | `timestamptz` | Yes | `NULL` | No | Set when a GDPR erasure request scrubs this row's PII columns (`guest_ip`, `guest_user_agent`, `guest_contact_email`, and any `is_pii`-flagged answers) in place, while preserving the submission's aggregate/statistical shape (Doc #12). |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft-delete/trash, distinct from `pii_erased_at`. |

> **Design Notes**
> - **`id` vs. `client_submission_uuid` are deliberately two different columns**, even though the plan mentions the latter singularly. The server-assigned `id` is the row's true, trusted identity (used in URLs, joins, the API). `client_submission_uuid` is a value supplied by an *untrusted* offline client purely as a dedup key — enforced unique per `(tenant_id, client_submission_uuid)` for idempotent replay (plan §2.4), but never treated as the canonical identifier. This also lets channels with no client-generated UUID at all (manual encoding, guest) simply leave it `NULL`.
> - `form_id` + `form_version_id` both exist on purpose: `form_version_id` is the structurally authoritative FK fixing legacy's core bug; `form_id` is a denormalization purely for "all responses to this form across every version" queries that would otherwise require a join through `form_versions` on every dashboard load.
> - `SubmissionStatus` renames legacy's "Pending Validation" to `under_review` and adds a new terminal `archived` state for retention workflows — a reasonable, noted extension beyond a literal 1:1 port of the 5-row legacy lookup.

---

## 8. `submission_answers`

One JSONB answer document per submission — the hybrid model's variable payload half (plan §2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `submission_id` | `uuid` | No | — | No | **Primary key and FK** to `submissions.id`, `ON DELETE CASCADE` — a strict 1:1 with `submissions`, so no separate surrogate `id` is introduced. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`; denormalized for RLS. |
| `form_version_id` | `uuid` | No | — | No | FK to `form_versions.id`; denormalized so the schema-interpretation layer can resolve field definitions for `answers` without joining through `submissions`. |
| `answers` | `jsonb` | No | `'{}'` | **Yes (conditional)** | The full answer document, keyed by each `form_fields.key` (or `form_sections.key` for repeat-group arrays — see Design Notes). Content-dependent on the tenant's own form design. |
| `answers_schema_checksum` | `varchar(64)` | Yes | `NULL` | No | Copy of the `form_versions.checksum` this document was captured against, for drift-detection tooling. |
| `answers_content_checksum` | `varchar(64)` | Yes | `NULL` | No | SHA-256 over the canonical serialization of the answer *content* (distinct from `answers_schema_checksum`, the *schema* hash). Set at persist time (Increment G8c); the Submission Pipeline compares it on a replayed `client_submission_uuid` to tell a byte-identical idempotent replay (→ 200 no-op) from a same-uuid different-content concurrent edit (→ 409 `submission_conflict`). `NULL` on rows created before G8c → treated as "cannot compare" (no false conflict). |
| `attachment_refs` | `jsonb` | No | `'[]'` | No | Denormalized list of `attachments.id` values embedded within `answers`, for fast "does this submission have files" checks without parsing the full document; `attachments.attachable_id` remains the authoritative ownership link. |
| `completeness_percent` | `smallint` | Yes | `NULL` | No | Cached progress indicator for partial-save/resume UX (Phase 3). **Denominator pinned by Doc #27 §5.2 (it was undefined here before): every top-level field whose `field_type` is not `note`/`page_break`/`calculated`/`hidden`, PLUS exactly one unit per repeatable section regardless of instance count** — a field inside a repeatable section folds into that section's single unit and is never counted on its own. The numerator is key *presence* in the Stage-1 normalized answers, not non-emptiness; a zero denominator yields 0. **Relevance-UNAWARE by design** (`App\Services\Submissions\DraftCompleteness`, a pure static class with no container, DB or expression engine): making it relevance-aware would require running the full semantic evaluator on every autosave and would prune answers the respondent is mid-way through, and it would turn the tenant's inbox column into "% of the path this respondent happens to be on", which is not comparable between two rows. It therefore measures **coverage of the authored form**, not progress along one respondent's branch — under Phase-3 branching those diverge visibly, and Doc #27 §5.2 drops the respondent-facing use of this figure while keeping it on tenant surfaces. |
| `draft_expires_at` | `timestamptz` | Yes | `NULL` | No | Increment H9a — when this draft is hard-reaped. Stamped **once**, at draft creation, from the tenant's `draft_ttl_days` (default 30) and deliberately **not** re-stamped on subsequent saves, so a long-abandoned draft cannot renew itself indefinitely; a tenant's TTL change therefore affects only future drafts. Cleared to `NULL` on promotion to a real submission. The reaper (`ReapTenantDraftsJob`) **force-deletes** rather than soft-deleting, because a tombstone would keep `client_submission_uuid` reserved under the partial unique index and collide on re-use. |
| `draft_current_step` | `varchar` | Yes | `NULL` | No | Increment H10 — the resume cursor: the guest SPA's current step key, carried through the draft save and replayed on the resume read so a cross-device resume lands where the respondent left off. Holds a `form_sections.key` **or the reserved synthetic lead-step sentinel** for the block of section-less fields. Deliberately **un-FK'd, un-enumerated and un-validated at write time** (Doc #27 §5.3): the key is typically valid at save and merely *irrelevant* at resume, so a write-time constraint would not catch the case that actually happens, while adding a failure path to an autosave that is fire-and-forget by design and breaking the version-drift resume, which legitimately carries a key from an older version. It is resolved at **read** time by membership in the respondent's visible step list; an unresolvable key falls back per Doc #27 §5.3 (nearest surviving predecessor, then the first incomplete step) rather than silently restarting at step 1. **As built (H21b):** the read-time resolution above is implemented in `useFormRuntime.goToStep()`, which now RETURNS how it resolved (`exact` / `nearest` / `first-incomplete` / `none`) so the welcome-back banner can explain a drifted landing instead of silently showing step 1. "Incomplete" there is the field-level has-a-value rule, deliberately **not** `DraftCompleteness`'s key-presence numerator — that one measures coverage of the authored form for a reviewer's column, this one answers a respondent's "where do I carry on". **Note:** the migration's own docblock and the SPA's `SaveDraftPayload` type used to claim single-page forms leave this null; both were wrong — the SPA sends it unconditionally and the key is seeded regardless of presentation mode — and both were corrected in H21b. |
| `last_saved_at` | `timestamptz` | Yes | `NULL` | No | Timestamp of the most recent partial save, prior to final submit. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - **No separate repeat-answers table.** Legacy kept repeat-group answers (`indicator_repeat_responses`) in a table physically separate from flat answers (`submission_values`) — confirmed-good conceptually because a single flat-cell table struggles to also model 1-to-many repeat rows. In this hybrid JSONB model that same concern is resolved differently: a repeat section's instances are stored as a native JSONB **array** under that section's `key` within the same `answers` document (e.g. `answers->'household_members' = [{...}, {...}]`), so one physical table cleanly serves both flat and repeated content without a second table or a forced one-row-per-cell schema.
> - `submission_answer_index` (next table) only ever projects **scalar, non-repeated** values — never reaches inside a repeat array. Cross-repeat aggregates (e.g. XLSForm's `count()`) are computed by the expression engine at evaluation time against `answers` directly, not pre-indexed; this is a deliberate scope boundary, not an oversight.
> - The redaction workflow for GDPR erasure (`submissions.pii_erased_at`) walks `answers` using each `form_fields.is_pii = true` field's `key` to redact just those keys, rather than destroying the whole document — preserving statistical shape for tenant-side reporting.

---

## 9. `submission_answer_index`

The selective typed projection for fields flagged `is_queryable` — avoids both legacy's fully-normalized-per-cell approach and a naive all-JSONB approach (plan §2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `bigint identity` | No | auto-increment | No | Primary key — `bigint`, not `uuid`, per the global PK strategy note: this is a pure internal projection row, never addressed externally, and a narrower key measurably helps index size at this table's expected volume (one row per queryable field per submission). |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`; denormalized for RLS. |
| `submission_id` | `uuid` | No | — | No | FK to `submissions.id`, `ON DELETE CASCADE`. |
| `form_version_id` | `uuid` | No | — | No | FK to `form_versions.id`; needed to resolve which `form_fields` row this projection belongs to even after later versions change the field's definition. |
| `form_field_id` | `uuid` | No | — | No | FK to `form_fields.id`, `ON DELETE CASCADE` — the specific `is_queryable = true` field. |
| `field_key` | `varchar(150)` | No | — | No | Denormalized copy of `form_fields.key`; lets filters/aggregations key on the stable identifier across versions (plan §2.3.5) without an extra join purely to get the key. |
| `value_text` | `text` | Yes | `NULL` | **Yes (conditional)** | Populated when `indexed_data_type = 'text'`. |
| `value_number` | `numeric` | Yes | `NULL` | **Yes (conditional)** | Populated when `indexed_data_type = 'number'`. |
| `value_boolean` | `boolean` | Yes | `NULL` | **Yes (conditional)** | Populated when `indexed_data_type = 'boolean'`. |
| `value_date` | `date` | Yes | `NULL` | **Yes (conditional)** | Populated when `indexed_data_type = 'date'`. |
| `value_datetime` | `timestamptz` | Yes | `NULL` | **Yes (conditional)** | Populated when `indexed_data_type = 'datetime'`. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

Unique constraint: `(submission_id, form_field_id)` — one projected row per queryable field per submission.

> **Design Notes**
> - **Five typed nullable columns instead of one polymorphic `value` column** is deliberate: it lets each type get a real, narrow B-tree index (`value_number`, `value_date`, etc.) instead of comparisons happening against a stringly-typed column with app-level casting — the exact anti-pattern the hybrid model exists to avoid (plan §2.2's EAV-vs-JSONB discussion).
> - Every value here is marked PII-conditional for the same reason `submission_answers.answers` is: the column's schema is platform-owned, but a specific projected value could be personal data if a tenant chooses to make a PII field queryable. As noted on `form_fields`, marking a field both `is_sensitive` and `is_queryable` is discouraged at the application layer to minimize where special-category data physically lives.
> - **The projection is opt-in, prospective-only, and never backfilled** (recorded 2026-08-03; ADR-0011 §D3 depends on it). A value is written only when `form_fields.is_queryable` is true — it defaults to `false` in the migration, in `FormBuilderService`, in every seeder, and is hard-coded `false` by `FieldLibrary::toBlueprintField()` — **and** the answer is a top-level scalar. `AnswerIndexProjector` runs only inside `SubmissionFinalizer`, i.e. at submit or at draft promotion, so **turning the flag on indexes future submissions only; historical answers are never projected.** Turning it on is also a breaking-but-permitted schema change (`SchemaChangeClassifier`, `'indexing changed'`), so it requires a new form version. Three consequences: drafts contribute nothing here, so a completion rate must come from `submissions.status`, never from this table; the publish gate checks only that a queryable field declares a type, so an author can flag a field that can never project (a grid, a multi-select, anything nested in a repeatable section) and get zero rows forever with no warning; and a value can be dropped *within* a flagged field — a non-numeric answer under a `number` index, an unparseable date — so a `COUNT(*)` here under-counts against `submissions` per field.
> - **Analytics must join `submissions`** (ADR-0011 §D2). These rows survive a soft-deleted submission, because the FK cascade fires on hard delete only — so an aggregate reading this table alone counts responses the inbox no longer shows. Two shape constraints for any such query: there is **no `form_id` column** (per-form aggregation joins out through `form_version_id`), and **`value_boolean` carries no B-tree** while the other four value columns do.

---

## 10. `attachments`

One unified polymorphic table replacing legacy's `image_path`/`file_path`/`excel_path`/`pdf_path` column sprawl (plan §2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. |
| `attachable_type` | `varchar(100)` | No | — | No | Polymorphic morph type (e.g. `submission`, `form_field`, `tenant`, `webhook_delivery`). |
| `attachable_id` | `uuid` | No | — | No | Polymorphic morph id — no hard FK constraint (standard, accepted trade-off of polymorphic associations; see Design Notes). |
| `kind` | `varchar(30)` — PHP enum: `AttachmentKind` | No | — | No | See the 8-value catalog above. |
| `disk` | `varchar(30)` | No | `'local'` | No | Laravel filesystem disk name. **Phase-1 deviation (Increment G6):** the column default is `'local'` (the initial on-server backing store, deployment §7), not the aspirational `'s3'`; the write path (`AttachmentStorageService`) sets `disk` per-write from `config('filesystems.default')`, so an S3 swap is config-only. |
| `path` | `varchar(500)` | No | — | No | Object storage key, namespaced `tenants/{tenant_id}/...` per plan §2.1. |
| `original_filename` | `varchar(255)` | Yes | `NULL` | **Yes** | May contain a respondent's or staff member's name (e.g. `john_smith_id_scan.jpg`). |
| `mime_type` | `varchar(150)` | Yes | `NULL` | No | — |
| `size_bytes` | `bigint` | Yes | `NULL` | No | Also feeds the `storage_bytes` usage metric. |
| `checksum_sha256` | `varchar(64)` | Yes | `NULL` | No | Integrity verification and dedup. |
| `width` | `integer` | Yes | `NULL` | No | Images only. |
| `height` | `integer` | Yes | `NULL` | No | Images only. |
| `duration_seconds` | `integer` | Yes | `NULL` | No | Audio/video only. |
| `is_encrypted_at_rest` | `boolean` | No | `false` | No | Set true automatically when the owning field is `form_fields.is_sensitive = true` (plan §5 "encryption at rest for sensitive attachments"). |
| `is_pii` | `boolean` | No | `false` | No | Denormalized copy of the owning field/submission's PII classification, for fast filtering by redaction/export jobs without joining back through `submission_answers` → `form_fields`. |
| `virus_scan_status` | `varchar(20)` — PHP enum: `ScanStatus` | No | `'pending'` | No | Files are not served to other users until `clean`. |
| `ocr_confidence_avg` | `numeric(5,2)` | Yes | `NULL` | No | Only populated for `kind = 'ocr_source_scan'` — average per-field confidence score from the OCR pipeline (Doc #17). |
| `uploaded_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external). `NULL` for guest-uploaded files. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft-delete; a trash grace period precedes the actual S3 object deletion job. |

> **Design Notes**
> - The polymorphic pattern uses plain UUID `attachable_type`/`attachable_id` columns (Laravel's `uuidMorphs`) with **no** database-level foreign key, since the target table varies by `attachable_type` — this is the standard, accepted trade-off of polymorphic associations, not the switch-statement anti-pattern the plan warns against (plan §2.1's warning is about *resolving which type's business logic applies* via a level-column switch, not about avoiding polymorphism itself, which is the correct idiom here — `attachable_type` is a `morphTo`, resolved by Eloquent's own map, never a hand-rolled switch).
> - `is_pii`/`is_encrypted_at_rest` are themselves plain classification booleans (marked PII = No) whose purpose is to flag that the *file content* is presumptively personal/sensitive data — the flags are metadata, not the data itself.

---

## 11. `field_library`

Reusable single-question blueprints — carried forward as a confirmed-good reuse mechanism (plan §2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | Yes | `NULL` | No | FK to `tenants.id`. `NULL` = platform-provided global item, available to every tenant; non-null = a tenant's own private library item (see Design Notes). |
| `name` | `varchar(150)` | No | — | No | Internal label shown in the builder's library picker (e.g. "Standard Age Question"). |
| `description` | `text` | Yes | `NULL` | No | — |
| `category` | `varchar(60)` | Yes | `NULL` | No | Free-text grouping for browsing (e.g. "Demographics", "Health"). |
| `field_type` | `varchar(40)` — PHP enum: `FieldType` | No | — | No | Same enum as `form_fields.field_type`. |
| `default_label` | `varchar(500)` | No | — | No | — |
| `default_label_translations` | `jsonb` | Yes | `NULL` | No | — |
| `default_hint` | `text` | Yes | `NULL` | No | — |
| `default_config` | `jsonb` | No | `'{}'` | No | Blueprint copied onto a new `form_fields.config` row when inserted from the library. |
| `default_validations` | `jsonb` | No | `'[]'` | No | Blueprint array of validation-rule shapes copied into new `form_field_validations` rows on insert. |
| `usage_count` | `integer` | No | `0` | No | Incremented each time the item is inserted into a form; carried forward from legacy. |
| `is_active` | `boolean` | No | `true` | No | Soft on/off switch, preferred over deletion so `usage_count` history/analytics survive retiring an item. |
| `created_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external); `NULL` for system-seeded items. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft-delete. |

> **Design Notes**
> - Nullable `tenant_id` is the mechanism distinguishing platform-seeded/global library items from tenant-authored private ones — a single table serves both without a separate "system items" table.
> - **RLS policy exception, cross-referenced with ADR-0002**: the standard `tenant_id = current_setting('app.current_tenant_id', true)::uuid` policy (ADR-0002 §D2) never matches a `NULL` row, so this table's `SELECT` policy is deliberately widened to `USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid OR tenant_id IS NULL)` — global rows stay readable by every tenant, while `INSERT`/`UPDATE`/`DELETE` policies remain the strict tenant-only form (a tenant can never write a `NULL`-tenant row; only a platform-admin/seeder process, running under the elevated role from ADR-0002 §D3, creates global rows). This is the one deliberate, named exception to the "single flat policy, no exceptions" posture stated in this document's "Conventions" section.

---

## 12. `form_templates`

Whole-form blueprints, instantiated by cloning into a brand-new form rather than by live-mutable reference (plan §2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | Yes | `NULL` | No | FK to `tenants.id`. `NULL` = platform/system template (the onboarding gallery, Doc #25); non-null = a tenant's own saved template. |
| `name` | `varchar(150)` | No | — | No | — |
| `description` | `text` | Yes | `NULL` | No | — |
| `category` | `varchar(60)` | Yes | `NULL` | No | e.g. "Health Survey", "Event Registration", "M&E / Research" — spans both use-case families the plan targets (§Context). |
| `cover_image_attachment_id` | `uuid` | Yes | `NULL` | No | FK to `attachments.id`. |
| `schema_blueprint` | `jsonb` | No | — | No | A version-shaped snapshot (same shape as `form_versions.schema_snapshot`); instantiating a template clones this into a brand-new `forms` row + an initial draft `form_versions` row + normalized `form_sections`/`form_fields` rows — the new form never shares or references the template's own rows going forward. |
| `source_form_version_id` | `uuid` | Yes | `NULL` | No | FK to `form_versions.id`. Traceability back to the form this template was captured from via "save as template," when applicable; `NULL` for platform-authored templates with no such origin. |
| `is_public` | `boolean` | No | `false` | No | Visible in the cross-tenant onboarding gallery vs. private to the owning tenant. |
| `usage_count` | `integer` | No | `0` | No | — |
| `created_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external). |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft-delete. |

> **Design Notes**
> - "Snapshot into version-shaped blueprints rather than live-mutable clones" (plan §2.2) is implemented by `schema_blueprint` deliberately reusing the *same shape* as `form_versions.schema_snapshot` — one denormalization format serves both "restore this version" and "instantiate this template" code paths.
> - **RLS policy exception**: same widened-`SELECT` pattern as `field_library` above (`OR tenant_id IS NULL`), for the same reason (the onboarding gallery's platform templates must be readable by every tenant) — see `field_library`'s Design Notes and ADR-0002 §D2 for the full rationale, not repeated here.

---

## 13. `audits`

Carried forward essentially as-is from legacy's confirmed-good `Auditable` trait + polymorphic `Audit` model (plan §2.2, verified in `app/Traits/Auditable.php`).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | Yes | `NULL` | No | FK to `tenants.id`. Nullable only for the rare platform-level action with no single-tenant context (e.g. a super-admin action spanning multiple tenants); every ordinary tenant-owned-model audit row sets this. |
| `auditable_type` | `varchar(100)` | No | — | No | Polymorphic morph type (e.g. `form`, `form_version`, `submission`, `webhook_endpoint`, `subscription`). |
| `auditable_id` | `uuid` | No | — | No | Polymorphic morph id. |
| `event` | `varchar(30)` — PHP enum: `AuditEvent` | No | — | No | See the 8-value catalog above. |
| `old_values` | `jsonb` | Yes | `NULL` | **Yes (conditional)** | Prior field values, redacted per sensitive-field rules before write (plan §5). |
| `new_values` | `jsonb` | Yes | `NULL` | **Yes (conditional)** | — |
| `redacted_fields` | `jsonb` | Yes | `NULL` | No | List of field names stripped from `old_values`/`new_values` by the redaction rules — a transparency record of what was withheld, not present in legacy. |
| `user_id` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external); `NULL` for automated/system actions. |
| `is_system_action` | `boolean` | No | `false` | No | — |
| `ip_address` | `inet` | Yes | `NULL` | **Yes** | — |
| `user_agent` | `text` | Yes | `NULL` | **Yes** | — |
| `created_at` | `timestamptz` | No | `now()` | No | Audits are append-only; no `updated_at`. |

> **Design Notes**
> - `event` extends legacy's 4 base cases (`created`/`updated`/`deleted`/`restored`) with 4 domain-specific events (`published`, `archived`, `exported`, `permission_changed`) the plan's versioning, export, and RBAC model need tracked explicitly — a noted, reasonable extension, not a literal 1:1 port.
> - `redacted_fields` formalizes the "sensitive-field redaction" requirement from plan §5 as a queryable record, rather than leaving redaction as a silent, unverifiable side effect.
> - No `updated_at`/`deleted_at`: audit rows are immutable and never deleted (append-only ledger), by design.

---

## 14. `webhook_endpoints`

Tenant-configured delivery targets (plan §2.5).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. |
| `form_id` | `uuid` | Yes | `NULL` | No | FK to `forms.id`. `NULL` = tenant-wide endpoint subscribing across all forms; non-null = scoped to one form. |
| `name` | `varchar(150)` | No | — | No | Admin-facing label. |
| `url` | `varchar(500)` | No | — | No | Destination URL. |
| `secret` | `text` | No | — | No | Signing secret. Not personal data, but a credential requiring the same at-rest protection — masked in the UI/API after creation, never returned in full again (see Design Notes). **H13a build note:** stored as `text`, NOT `varchar(255)` — the Laravel `encrypted` cast inflates even a short secret to ~290 chars (`base64(JSON{iv,value,mac})`), which the original `varchar(255)` (sized for plaintext) cannot hold. |
| `event_types` | `jsonb` | No | `'[]'` | No | Array of subscribed event-type values. **H13a build note:** these are **`DomainEventType`** values, NOT a separate `WebhookEventType` enum — `DomainEventType`'s own docblock instructs H13 to reference the one shared catalog (`submission.created`, `form.published`, `form.opened`, `form.closed`) rather than mint a second enum, so the webhook subscription vocabulary and the domain-event vocabulary cannot drift. |
| `status` | `varchar(20)` — PHP enum: `WebhookEndpointStatus` | No | `'active'` | No | — |
| `disabled_reason` | `varchar(30)` | Yes | `NULL` | No | Free-text-but-small reason code (`too_many_failures`, `manual`, `tenant_suspended`) — kept as constrained free text rather than a full enum since it is purely informational, never branched on programmatically. |
| `consecutive_failure_count` | `integer` | No | `0` | No | Drives the per-endpoint circuit breaker (plan §2.5). |
| `last_success_at` | `timestamptz` | Yes | `NULL` | No | — |
| `last_failure_at` | `timestamptz` | Yes | `NULL` | No | — |
| `signing_algorithm` | `varchar(20)` | No | `'hmac_sha256'` | No | — |
| `created_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external). |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft-delete. |

> **Design Notes**
> - `secret` should be encrypted at rest (Laravel's encrypted cast) and the API must never return it in full after the initial creation response — only a masked suffix, matching common secret-management practice for API keys/webhook secrets.
> - `form_id` nullable is the single mechanism covering both "one endpoint, one form" and "one endpoint, every form in the tenant" without two separate tables.

---

## 15. `webhook_deliveries`

Individual delivery attempts — queue-first ingestion, mandatory idempotency, exponential backoff (plan §2.5).

> **H15a — this is now the SHARED OUTBOUND LEDGER.** A row is owned by **either** a `webhook_endpoints` row (the tenant runs the receiver) **or** a `connection_subscriptions` row (a native connector — we hold an OAuth grant and post on their behalf), never both and never neither. One ledger means one retry ladder, one `webhook_deliveries` quota metric, and one delivery-log shape across both channels; the table keeps its name for continuity with §14 and the API surface. See ADR-0009.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. |
| `webhook_endpoint_id` | `uuid` | **Yes (H15a)** | `NULL` | No | FK to `webhook_endpoints.id`, `ON DELETE CASCADE`. Non-null on a webhook-channel row; NULL on a connector row. |
| `connection_subscription_id` | `uuid` | Yes | `NULL` | No | **H15a** — FK to `connection_subscriptions.id`, `ON DELETE CASCADE`. Non-null on a native-connector row; NULL on a webhook row. Exactly one of the two owner columns is set (`webhook_deliveries_owner_check`). |
| `event_id` | `uuid` | No | — | No | The idempotency key (plan §2.2: "`event_id` unique"), unique **per owner** — see Design Notes. |
| `event_type` | `varchar(60)` — PHP enum: `DomainEventType` (H13a; see `webhook_endpoints.event_types` note) | No | — | No | See the starter catalog above. |
| `payload` | `jsonb` | No | — | **Yes (conditional)** | The delivered event body; may embed submission answer data, so content-dependent PII exposure identical to `submission_answers.answers`. |
| `payload_attachment_id` | `uuid` | Yes | `NULL` | No | FK to `attachments.id` (`kind = 'webhook_payload_archive'`); used when a payload is too large to store inline and is archived to object storage instead. |
| `status` | `varchar(20)` — PHP enum: `WebhookDeliveryStatus` | No | `'pending'` | No | — |
| `attempt_count` | `integer` | No | `0` | No | — |
| `max_attempts` | `integer` | No | `10` | No | — |
| `next_retry_at` | `timestamptz` | Yes | `NULL` | No | Exponential backoff + jitter schedule (plan §2.5). |
| `last_attempted_at` | `timestamptz` | Yes | `NULL` | No | — |
| `response_status_code` | `integer` | Yes | `NULL` | No | — |
| `response_body_excerpt` | `text` | Yes | `NULL` | No | Truncated response body for delivery-observability debugging. |
| `response_time_ms` | `integer` | Yes | `NULL` | No | — |
| `signature` | `varchar(255)` | Yes | `NULL` | No | The HMAC signature actually sent, retained for support/debugging reproducibility. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - `event_id` uniqueness **is** the idempotency mechanism (plan §2.2/§5): a producer retrying a publish is safe to call again with the same `event_id`, the unique constraint rejects the duplicate insert. **H13a note:** the DB unique is on `(webhook_endpoint_id, event_id)` (one delivery per endpoint per event), which is what the fan-out `firstOrCreate` relies on. **H15a note:** with a second owner column both uniques became **partial** — `… (webhook_endpoint_id, event_id) WHERE webhook_endpoint_id IS NOT NULL` and the mirror for `connection_subscription_id`. They have to be: under Postgres NULL semantics a plain unique over a nullable column would let unlimited connector rows share an `event_id`, silently ending idempotency for half the ledger.
> - The event catalog (`DomainEventType`, H13a) is explicitly a starter set (plan §2.5's "phase in more — not 50 at once"); new events require a code change to emit anyway, so growing the enum over time (rather than making it data-driven) is intentional, not a limitation to work around later.
> - **H13a note — no distinct status for SSRF/quota outcomes.** The design doc names a `dns_rebinding_blocked` outcome and this increment adds a monthly-quota refusal; both are recorded as a `[marker]` prefix in `response_body_excerpt` (a delivery-time SSRF re-block → `[dns_rebinding_blocked]` `failed`; an over-monthly-quota first attempt → `[quota_exceeded]` `dead_lettered`) rather than as new `WebhookDeliveryStatus` values, keeping the pinned five-value status enum intact. A dedicated status/column is a H14 option if the delivery-log UI needs to filter on it.

---

## 16. `plans`

Platform-defined pricing tiers, Cashier-backed (plan §1/§2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `code` | `varchar(20)` — PHP enum: `PlanTier` | No | — | No | Unique. |
| `name` | `varchar(100)` | No | — | No | Display name. |
| `description` | `text` | Yes | `NULL` | No | — |
| `stripe_price_id` | `varchar(100)` | Yes | `NULL` | No | Cashier/Stripe linkage; `NULL` for a free tier with no Stripe price object. **Design intent, not near-term work (2026-07-21):** neither `laravel/cashier` nor `stripe/stripe-php` is installed, and **payments are deferred out of Phase 3 to Phase 4** by product decision, so nothing consumes this column yet. |
| `monthly_price_cents` | `integer` | Yes | `NULL` | No | — |
| `yearly_price_cents` | `integer` | Yes | `NULL` | No | — |
| `currency` | `varchar(3)` | No | `'usd'` | No | ISO 4217 code, plain string — open reference data, not a fixed vocabulary the app branches logic on, so it is not modeled as an enum (see "Conventions"). |
| `billing_interval_options` | `jsonb` | No | `'["monthly","yearly"]'` | No | Which `BillingInterval` values this plan offers. |
| `feature_flags` | `jsonb` | No | `'{}'` | No | Map of feature-gate keys to booleans (e.g. `{"offline_sync": true, "xlsform_export": true, "custom_domain": false}`) — Doc #24. |
| `quotas` | `jsonb` | No | `'{}'` | No | Map of `UsageMetric` keys to numeric limits. |
| `is_active` | `boolean` | No | `true` | No | Whether new subscriptions may select this plan; retiring a plan keeps it visible for existing subscribers rather than breaking their row. |
| `sort_order` | `integer` | No | `0` | No | Display order on the pricing page. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - ✅ **Built in H5a** (`2026_07_23_000001_create_plans_table`, ADR-0008) — as an **admin-assigned** catalog with **no Cashier**: `stripe_price_id` ships but is dormant until Phase 4 (nothing consumes it). Seeded by `PlanSeeder`/`PlanCatalog` (5 tiers, on the default connection since there is no RLS). `business`/`enterprise` seed `is_active = false` — built + gated, held from sale (ADR-0008 §D6). ADR-0007 §D3 names `plans` as a table a cross-tenant `MaintenanceJob` may read.
> - **`plans` has no `tenant_id` and is not RLS-scoped** — it is the platform's own global pricing catalog, shared read-only reference data across every tenant, structurally different from a tenant business record. **Note on the migration linter:** `plans` needs **no** `EXEMPT_TABLES` entry in `scripts/migration-lint.php`, and adding one would be actively misleading — the linter's isolation rule short-circuits on any table that declares no literal `tenant_id` column, so a table like this never reaches the check. The exemption list is for tables that *do* carry a tenant identifier while deliberately having no RLS policy (`jobs`/`job_batches`/`failed_jobs`, the framework/Fortify tables), which per ADR-0002 §D2 is the case requiring justification. It is *not* being used as a substitute "vocabulary lookup table" in the sense the enum-strategy section disclaims — its rows are admin-managed commercial content (price, quotas, feature flags) that changes independently of a code deploy, which is exactly the case where a real table (not an enum) is the right tool.
> - `feature_flags`/`quotas` are JSONB maps rather than rigid columns so new gates/metrics can be added without a migration, at the cost of losing a DB-level `CHECK` on their keys — accepted trade-off, validated at the application layer against the `UsageMetric`/feature-flag key enums instead.

---

## 17. `subscriptions`

A tenant's subscription to a plan, Cashier-backed (plan §1/§2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. The Cashier "billable". |
| `plan_id` | `uuid` | No | — | No | FK to `plans.id`. |
| `name` | `varchar(50)` | No | `'default'` | No | Cashier's internal subscription-name slot, supporting multiple concurrent subscriptions per tenant (e.g. add-ons) beyond the primary plan. |
| `stripe_customer_id` | `varchar(100)` | Yes | `NULL` | No | External billing-system identifier for the organization; not itself an individual's personal data (the individual-level contact is `tenants.billing_email`). |
| `stripe_subscription_id` | `varchar(100)` | Yes | `NULL` | No | — |
| `stripe_status` | `varchar(40)` | No | — | No | **Deliberately free text, not a PHP enum** — mirrors Stripe's own status vocabulary verbatim (`trialing`, `active`, `past_due`, `canceled`, `unpaid`, `incomplete`, `incomplete_expired`, `paused`) as synced by Cashier's webhook handler. See Design Notes for why this is the one flagged exception to the enum-everywhere rule. **No such handler exists (2026-07-21)** — Cashier is not installed, this table has no migration, and payments are deferred to Phase 4; the row records intended design. |
| `billing_interval` | `varchar(10)` — PHP enum: `BillingInterval` | No | — | No | — |
| `quantity` | `integer` | No | `1` | No | Seat count, where applicable. |
| `trial_ends_at` | `timestamptz` | Yes | `NULL` | No | — |
| `current_period_starts_at` | `timestamptz` | Yes | `NULL` | No | — |
| `current_period_ends_at` | `timestamptz` | Yes | `NULL` | No | — |
| `cancels_at` | `timestamptz` | Yes | `NULL` | No | Scheduled cancel-at-period-end date. |
| `canceled_at` | `timestamptz` | Yes | `NULL` | No | — |
| `ended_at` | `timestamptz` | Yes | `NULL` | No | — |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - ✅ **Built in H5a** (`2026_07_23_000002_create_subscriptions_table`, ADR-0008) — strict RLS. **Admin-assigned, no Cashier**: the `stripe_customer_id`/`stripe_subscription_id`/`stripe_status` columns ship but are dormant until Phase 4; the super-admin console writes `stripe_status = 'active'` on assign, and the current plan derives from the active subscription (`stripe_status ∈ {active, trialing}` ∧ `ended_at IS NULL`; `Subscription::scopeActive`). No `SubscriptionStatus` PHP enum — the free-text exception below stands as-built.
> - **`stripe_status` is the one deliberate, explicitly-flagged exception** to "one consistent enum strategy" (plan §5): it stores Stripe's own vocabulary verbatim rather than a local PHP enum, because Stripe controls that vocabulary and can extend it outside this app's release cycle — constraining it locally with a `CHECK` would risk hard-rejecting a legitimate new Stripe status before the app has been updated to recognize it. This is a named, reasoned exception, not an inconsistency of the kind legacy had (e.g. its free-text `submission_source` sitting undocumented next to FK-backed siblings).

---

## 18. `usage_counters`

Metering rows backing quota enforcement and usage-based billing (plan §2.2).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `bigint identity` | No | auto-increment | No | Primary key — `bigint`, per the global PK strategy note: pure internal aggregation rows, never addressed externally. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. |
| `subscription_id` | `uuid` | Yes | `NULL` | No | FK to `subscriptions.id`. Nullable to tolerate usage recorded before any subscription exists (e.g. a free-tier trial with no Stripe subscription yet). |
| `metric` | `varchar(30)` — PHP enum: `UsageMetric` | No | — | No | See the 7-value catalog above. |
| `period_start` | `date` | No | — | No | Start of the billing/usage period this row aggregates. |
| `period_end` | `date` | No | — | No | — |
| `value` | `bigint` | No | `0` | No | Running aggregate for the period (count or bytes, depending on `metric`). |
| `limit_snapshot` | `bigint` | Yes | `NULL` | No | The plan quota that applied at the time of the row's last update — denormalized so historical usage reporting stays accurate even if the plan's quota changes later. |
| `last_incremented_at` | `timestamptz` | Yes | `NULL` | No | — |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

Unique constraint: `(tenant_id, metric, period_start)`.

> **Design Notes**
> - ✅ **Built in H5a** (`2026_07_23_000003_create_usage_counters_table`, ADR-0008) — strict RLS, `bigint identity` PK (so the model omits `HasUuidv7`). **Metering wired in H5b**: FLOW metrics (`submissions_count`, `exports_count`, `api_requests`) are incremented at their event sites via a concurrency-safe `ON CONFLICT` upsert on the unique `(tenant_id, metric, period_start)` (`UsageMeter`), while GAUGE metrics (`forms_count`, `storage_bytes`, `active_seats`) are computed LIVE under RLS (`EntitlementService::countGauge()`) — never a running aggregate, since they decrease on archive/delete/remove — and materialized into the period rows nightly for historical reporting by the cross-tenant rollup (`RollUpUsageCountersJob` → per-tenant `ReconcileTenantUsageJob`, a `MaintenanceJob` per ADR-0007 §D3, never a single-tenant job). The rollup also reconciles `submissions_count` from the authoritative `submissions` table (the never-block self-heal) and stamps `limit_snapshot`. `submissions_count` metering NEVER blocks a submission (ADR-0008 §D4).
> - `period_start`/`period_end` bounds are used instead of a single rolling counter so that plan upgrades/downgrades mid-cycle, and historical reporting, both stay accurate against the quota that actually applied at the time — a rolling-only counter would lose this history.
> - As flagged in the enum catalog, `UsageMetric` is the vocabulary most likely to eventually outgrow a code-defined enum (e.g. per-integration metering added dynamically); promoting it to a real lookup table is the anticipated fallback, not needed at current scope.

---

## 18a. `legacy_overrides`

Per-tenant grandfather overrides (ADR-0008 §D5), added in **H5c** to fill the `EntitlementService::legacyOverrides()` seam. One row per tenant; the `feature_flags` map is consulted **ahead of** the plan flags, so a grandfathered tenant reads `true` for a feature its plan would deny.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `bigint identity` | No | auto-increment | No | Primary key — `bigint`, per the global PK strategy note: internal per-tenant state, never addressed externally (so the model omits `HasUuidv7`, the `usage_counters` precedent). |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id` (`cascadeOnDelete`). **Unique** — one override row per tenant. |
| `feature_flags` | `jsonb` | No | `'{}'` | No | The override map, same shape as `plans.feature_flags`: `{ "<flag key>": true, … }`. No DB CHECK on its keys (validated at the application layer), mirroring `plans`. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

Unique constraint: `(tenant_id)`.

> **Design Notes**
> - ✅ **Built in H5c** (`2026_07_23_000004_create_legacy_overrides_table`, ADR-0008 §D5) — strict RLS, `bigint identity` PK. A **merge-day backfill** (`2026_07_23_000005_…`, running `LegacyOverrideBackfill` on the privileged BYPASSRLS connection, the G10a `CollaboratorBackfill` precedent) stamps every **then-existing** tenant with the five ungated Phase-2 features (`xlsform_export`, `offline_sync`, `form_templates`, `field_library`, `api_access`) so the retro-gate regresses no live tenant. Tenants created after merge day get no row and are born gated.
> - Consulted by `EntitlementService::feature()` before the plan flags (an override wins by `array_key_exists`, so even a `false` override would short-circuit — the backfill only writes `true`). Memoized per tenant alongside the resolved plan, since `snapshot()` calls `feature()` once per flag key.

---

## 19. `user_ui_preferences`

Backs PRD Feature #9 (User style/theme preferences). Added after the original 18-table model, at the user's explicit request.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `user_id` | `uuid` | No | — | No | FK to `users.id` (external — see RBAC doc). **Unique** — one row per user. |
| `theme_mode` | `varchar(10)` — PHP enum: `ThemeMode` | No | `'system'` | No | `light` / `dark` / `system` — Phase 1. |
| `accent_token` | `varchar(30)` | Yes | `NULL` | No | References one of a small, curated set of accent options defined in the Design System reference (doc #19 §2.2) — e.g. the default Blueprint, or the dedicated Teal personalization accent (design-system §2.2), pre-verified for WCAG AA contrast. **`NULL` = NO OPINION since H23a3** — the tenant's brand ramp applies if one is active, otherwise the product default; `'blueprint'` is stored explicitly and means the product default even on a branded tenant. Phase 2, amended Phase 3. |
| `font_size_scale` | `varchar(15)` — PHP enum: `FontSizeScale` | No | `'standard'` | No | `standard` / `large` / `extra_large`, remapping the Design System's type-scale tokens uniformly. Phase 2. |
| `use_dyslexia_friendly_font` | `boolean` | No | `false` | No | Swaps only the Design System's Body type role (doc #19 §2.4) to an alternative face; Display and Utility/mono roles are unaffected. Phase 2. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - **Deliberate exception to "every table carries `tenant_id`"** (stated in this document's Conventions section): this table has **no** `tenant_id`. Appearance preference is a personal characteristic of the person, not of their tenant membership — the same user may belong to more than one tenant (Spatie's tenant-scoped "teams" RBAC feature permits this), and should see the same personal theme regardless of which tenant they are currently working in. Row-Level Security here is keyed on `user_id = current_setting('app.current_user_id', true)::uuid` — a "belongs to me" policy rather than a "belongs to my tenant" policy — since there is no legitimate cross-tenant *or even cross-user* query surface over this table; a user only ever reads/writes their own row.
> - `accent_token` is validated against the Design System's curated whitelist at the **application layer**, not via a DB `CHECK` constraint enumerating design-system tokens — a `CHECK` would create an awkward cross-document coupling requiring a schema migration every time the design system adds a new accent option. As built, that whitelist is the `App\Enums\AccentToken` backed enum, applied via `Rule::enum()` in `UpdateAppearanceRequest` — it is the *only* thing standing between the request body and the column, so it is covered by an explicit rejection test rather than left implicit.
> - **Column history.** The B1 migration (`2026_07_05_000103`) shipped **`theme_mode` only**; `accent_token`, `font_size_scale` and `use_dyslexia_friendly_font` were added in Phase 2 · Increment G11 (`2026_07_21_000002`). Between C1 and G11 the shared `ui.theme` Inertia prop reported a hardcoded `'accent' => 'blueprint'` literal from `User::uiTheme()` that never touched the database — so the accent *plumbing* (blade emission, theme CSS) existed while the *storage* did not. Adding the columns required **no RLS change**: the belongs-to-user policy set attached by `withTenantIsolation(..., 'belongs_to_user', ...)` is row-level, so it gates rows, not columns.
> - **`NULL` accent means the product default**, and the mapping is done at the request boundary rather than in the UI: the wire and the Vue controls always carry a real, non-null `'blueprint'`, and `UpdateAppearanceRequest::toColumns()` maps it down to `NULL` on write (`AccentToken::toColumn()`) while `User::uiTheme()` maps it back up on read (`AccentToken::fromColumn()`). That keeps "never expressed an opinion" and "explicitly chose the default" the same state in the database, without forcing every read site to null-coalesce.

---

## 20. `settings`

Backs PRD Feature #10 (App Settings). Added after the original 18-table model, at the user's explicit request. Revives the spirit of the legacy system's `Settings` singleton (site title, maintenance mode, registration toggle) as a tenant-aware, key-value store.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | Yes | `NULL` | No | FK to `tenants.id`. `NULL` = platform-level setting (super-admin only); non-null = a tenant's own setting — the same nullable-`tenant_id`-means-global pattern already used by `field_library`/`form_templates` (§11–12), extended here to a third table. |
| `key` | `varchar(100)` | No | — | No | Dot-namespaced key, e.g. `registration.enabled`, `registration.open_signup` (platform-only), `maintenance.enabled`, `maintenance.message`, `modules.ocr_enabled`, `modules.webhooks_enabled`. |
| `value` | `jsonb` | No | — | No | Value shape depends on `key` (boolean, string, or a small object) — deliberately schemaless per-key so new toggles can be added without a migration each time. |
| `updated_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external, nullable — e.g. a seeded default has no human actor). |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

**Uniqueness**: enforced via two partial unique indexes rather than one plain `UNIQUE(tenant_id, key)` constraint — Postgres treats `NULL` as distinct from `NULL` for uniqueness purposes, so a plain constraint would silently allow two platform-level rows with the same `key`:
```sql
CREATE UNIQUE INDEX settings_platform_key_unique ON settings (key) WHERE tenant_id IS NULL;
CREATE UNIQUE INDEX settings_tenant_key_unique ON settings (tenant_id, key) WHERE tenant_id IS NOT NULL;
```

> **Design Notes**
> - **Key-value structure, not named boolean columns per setting**, so new toggles can ship without a schema migration each time — directly consistent with the "config-flag-gated rollout" best practice (architecture plan §5), which already anticipates flags being added over time as new features ship.
> - **RLS policy**: inherits the same widened `SELECT` policy (`... OR tenant_id IS NULL`) documented in ADR-0002 for `field_library`/`form_templates`, now generalized there as a reusable pattern rather than enumerated as exactly two tables (see ADR-0002 update).
> - Every write to this table is expected to also produce an `audits` row via the `Auditable` trait (PRD Feature #12) — settings changes are exactly the kind of tenant-owned, mutable, business-critical change that trait exists to cover.

---

## 21. `feedback_reports`

Backs PRD Feature #11 (in-app feedback mechanism). Added after the original 18-table model, at the user's explicit request.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. **Not** nullable, unlike `field_library`/`form_templates`/`settings` — feedback always originates from a specific tenant's authenticated user in the initial release (see Design Notes). |
| `user_id` | `uuid` | No | — | No | FK to `users.id` (external). Not nullable in the initial release — see Design Notes. |
| `route` | `varchar(255)` | No | — | No | The frontend route/page the reporter was on when they opened the feedback panel. |
| `remarks` | `text` | No | — | **Yes (conditional)** | Free-text, user-authored — may incidentally name a specific person or describe personal circumstances; the schema is platform-owned but content is data-dependent, same convention as `submission_answers.answers`. |
| `screenshot_attachment_id` | `uuid` | Yes | `NULL` | No | FK to `attachments.id`. The captured screenshot **image itself** may contain PII (whatever was on-screen) even though this column is just a pointer — flagged for GDPR purposes at the `attachments` row, not duplicated here. |
| `browser_info` | `jsonb` | No | `'{}'` | **Yes (conditional)** | User agent string, viewport dimensions — a user agent string is a device fingerprint, treated as PII per this document's stated PII methodology. |
| `status` | `varchar(15)` — PHP enum: `FeedbackStatus` | No | `'new'` | No | `new` / `reviewed` / `resolved` / `wont_fix`. |
| `submitted_at` | `timestamptz` | No | `now()` | No | — |
| `resolved_at` | `timestamptz` | Yes | `NULL` | No | — |
| `resolved_by` | `uuid` | Yes | `NULL` | No | FK to `users.id` (external, nullable) — expected to be a platform support-team member, not a tenant user. |

> **Design Notes**
> - **`tenant_id`/`user_id` are required, not nullable** — unlike the platform/tenant-global pattern used by `field_library`/`form_templates`/`settings`, there is no "platform-global" feedback row analogous to a global template; every report comes from a specific person in a specific tenant. Guest-submitted feedback from the unauthenticated public form runtime is explicitly out of scope for the initial release (a **Decision not pinned by the plan** — PRD Feature #11 does not require it, and adding it would need a guest-identity model analogous to `submissions.guest_token`, deferred until there's a stated need).
> - `screenshot_attachment_id` reuses the **same** shared polymorphic `attachments` table every other file/image in the product uses (per PRD Feature #11's explicit acceptance criterion) — never a bespoke, feedback-specific upload column.
> - Feedback rows are tenant-scoped for the normal RLS-enforced read path (one tenant's users never see another tenant's reports), but the platform support team's internal view reads across tenants via the same elevated-role carve-out already specified in ADR-0002 §D3 for cross-tenant support tooling — not a second, parallel bypass mechanism invented for this table.

---

## 22. `notifications`

Backs PRD Feature #13 (Notifications). One row per in-app notification delivered to a user's notification center; email delivery of the same event is tracked by `emailed_at` rather than a second row. Added in the Phase-0-readiness review.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. |
| `user_id` | `uuid` | No | — | No | FK to `users.id` (external — see RBAC doc). The recipient. |
| `type` | `varchar(40)` — PHP enum: `NotificationType` | No | — | No | See the catalog above; drives copy/icon and the recipient's per-type preference lookup (§23). |
| `data` | `jsonb` | No | `'{}'` | **Yes (conditional)** | Event context needed to render the notification and deep-link to it (e.g. `{"submission_id": "...", "form_id": "...", "form_title": "..."}`); may embed tenant-defined labels, so treated as conditional PII like `webhook_deliveries.payload`. |
| `read_at` | `timestamptz` | Yes | `NULL` | No | Set when the recipient opens/marks the notification read; `NULL` = unread (drives the unread-count badge on the app-shell bell, design-system §3.7). |
| `emailed_at` | `timestamptz` | Yes | `NULL` | No | Set when an email copy of this notification was dispatched via the transactional email provider (Tech-Arch C4); `NULL` if the recipient's preference (§23) or the event type is in-app-only. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

> **Design Notes**
> - Notifications are **raised from the same post-commit domain events** that drive webhook dispatch and realtime pushes (`docs/architecture/technical-architecture.md` §4.1) — never a separate in-controller write path — so a submission notification and a submission webhook fire from one event, not two divergent code paths.
> - **Not the same as webhooks.** Webhooks are a tenant-configured integration surface (§14–15); notifications are a per-user in-product + email alert. A tenant on Free (no webhooks, §24) still gets submission notifications.
> - Real-time delivery to an open session reuses the tenant-namespaced Reverb channel (`docs/architecture/technical-architecture.md` §5, layer 8); this table is the durable backing store the notification center reads on load.

---

## 23. `notification_preferences`

Backs PRD Feature #13 — a user's per-tenant, per-type choice of which channels a notification is delivered on. Added in the Phase-0-readiness review.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`. A user in multiple tenants may set different preferences per tenant. |
| `user_id` | `uuid` | No | — | No | FK to `users.id` (external). |
| `notification_type` | `varchar(40)` — PHP enum: `NotificationType` | No | — | No | Which event type this preference row governs. |
| `in_app_enabled` | `boolean` | No | `true` | No | Whether this type appears in the recipient's notification center. |
| `email_enabled` | `boolean` | No | `true` | No | Whether this type is also emailed. Sensible defaults ship seeded; a row exists only once the user diverges from the default (see Design Notes). |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

**Uniqueness**: `(tenant_id, user_id, notification_type)`.

> **Design Notes**
> - **Absence means default.** No row for a `(tenant_id, user_id, notification_type)` triple means the platform default applies (both channels on for actionable events like `submission_returned`/`review_requested`; in-app-only for high-volume events like `submission_received` on a busy form, so a lead-gen owner isn't emailed per submission unless they opt in) — a row is written only when the user overrides the default, keeping the table sparse.
> - Digest/scheduled-summary delivery (batching many `submission_received` events into one periodic email) is **not** modeled here — it is a tracked backlog item (`docs/feature-backlog.md`), not a Phase-1 commitment.

---

## 24. `connections`

The platform's OAuth grant on a tenant's third-party workspace (H15a; ADR-0009; webhook-integration-design.md §4). One row per `(tenant, provider, external workspace)`.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`, `ON DELETE CASCADE`. |
| `provider` | `varchar(30)` — PHP enum: `ConnectorProviderKey` | No | — | No | `slack` in H15a; Sheets/Airtable are H16. CHECK generated from the enum. |
| `external_account_id` | `varchar(255)` | No | — | No | The provider's own handle for the workspace (a Slack team id). |
| `external_account_label` | `varchar(255)` | No | — | No | Display name of the workspace, for the UI. |
| `scopes` | `jsonb` | No | `'[]'` | No | The scopes actually granted — stored for display and re-consent detection (ADR-0009 §D8). |
| `access_token` | `text` | No | — | No (a **credential**) | **Encrypted at rest** (`encrypted` cast). `text`, not `varchar`, because the cast inflates the value well past 255 chars. Returned by **no** API or presenter in any form, masked or otherwise (ADR-0009 §D1). |
| `refresh_token` | `text` | Yes | `NULL` | No (a **credential**) | Same custody rules. NULL when the provider issues none (Slack's default bot token). |
| `token_expires_at` | `timestamptz` | Yes | `NULL` | No | NULL = the grant does not expire, so the refresh sweep skips it — not "unknown". |
| `status` | `varchar(20)` — PHP enum: `ConnectionStatus` | No | `'active'` | No | `active` / `refresh_failed` (a refused refresh) / `revoked` (disconnected, or the provider rejected the credential mid-delivery). CHECK from the enum. |
| `last_refreshed_at` | `timestamptz` | Yes | `NULL` | No | Stamped by a successful refresh; a machine refresh is not audited, so this is its record. |
| `last_error_at` | `timestamptz` | Yes | `NULL` | No | — |
| `last_error` | `varchar(255)` | Yes | `NULL` | No | The provider's own error code (`invalid_grant`), never a response body and never a token. |
| `connected_by` | `uuid` | Yes | `NULL` | No | FK to `users.id`, `ON DELETE SET NULL` — the user who completed the OAuth flow, carried in the signed `state`. |
| `created_at` / `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft delete = disconnected. |

**Uniqueness**: `(tenant_id, id)` (the composite-FK target) and a **partial** unique `(tenant_id, provider, external_account_id) WHERE deleted_at IS NULL` — one LIVE grant per workspace, so re-connecting updates in place while disconnected history survives.

> **Design Notes**
> - **RLS**: strict (`withTenantIsolation('connections')`). The OAuth callback lands on the central domain with no tenant bound, so it adopts context from the signed `state` **before** writing — without that the INSERT would match no policy and write zero rows *without erroring* (ADR-0009 §D4).
> - **Credentials live here; ROUTING lives in §25.** One grant can feed many destinations without duplicating — or re-refreshing — a token per rule.
> - Both token columns are in `AuditRedactor`'s unconditional secret list under the `connection` alias, registered in the same increment that created the table (ADR-0009 §D10).

---

## 25. `connection_subscriptions`

One "send these events to this destination" rule on a connection (H15a; ADR-0009) — the §14 `webhook_endpoints` analogue for native connectors, minus the secret lifecycle.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`, `ON DELETE CASCADE`. |
| `connection_id` | `uuid` | No | — | No | Composite FK `(tenant_id, connection_id) → connections (tenant_id, id)`, `ON DELETE CASCADE` (ADR-0002 §D5). |
| `form_id` | `uuid` | Yes | `NULL` | No | Composite FK `(tenant_id, form_id) → forms (tenant_id, id)`, `ON DELETE CASCADE`. NULL = tenant-wide. |
| `name` | `varchar(150)` | No | — | No | — |
| `event_types` | `jsonb` | No | `'[]'` | No | Subscribed `DomainEventType` values — the same catalog webhooks use, deliberately (no per-channel dialect). |
| `config` | `jsonb` | No | `'{}'` | No | The provider-specific destination. Never holds a credential. **Slack:** `{channel_id, channel_name}`. **Google Sheets (H16a):** `{spreadsheet_id, sheet_name, mapping}`, where `mapping` is `{fingerprint, columns: [{header, field_key}]}` — a `App\Support\Mapping\ColumnMapping` in its stored form: the destination's column order bound to `form_fields.key` values (plus the reserved `__`-prefixed metadata keys), and a SHA-256 fingerprint of the header row the binding was authored against. `field_key` is nullable for a column the tenant keeps for their own use, which still occupies a cell so the remaining columns cannot shift. The fingerprint is what every append is gated on: a mismatch pauses the rule instead of writing. **The shape is deliberately not per-provider columns** — it is one jsonb whose keys the request layer validates per provider (`SubscriptionConfigRules`) and the adapter parses. |
| `status` | `varchar(20)` — PHP enum: `ConnectorSubscriptionStatus` | No | `'active'` | No | `active` / `paused` / `disabled`. CHECK from the enum. |
| `consecutive_failure_count` | `integer` | No | `0` | No | Drives the circuit breaker, mirroring §14. |
| `last_success_at` / `last_failure_at` | `timestamptz` | Yes | `NULL` | No | — |
| `created_by` | `uuid` | Yes | `NULL` | No | FK to `users.id`, `ON DELETE SET NULL`. |
| `created_at` / `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft delete. |

**Uniqueness**: `(tenant_id, id)` — the composite-FK target §15's shared ledger points back to.

> **Design Notes**
> - **RLS**: strict. A rule delivers only while BOTH it and its grant are active; a dead grant pauses its rules rather than deleting them, so routing survives a re-connect.
> - **No per-tier cap.** Unlike `webhook_endpoints` (`UsageMetric::WebhookEndpointsCount`) there is no subscription-count gauge: the pricing matrix has no such row, and the real cost — actual sends — is already bounded by the shared monthly `webhook_deliveries` quota. A deliberate H15a narrowing, not an omission.
> - `config` is jsonb rather than provider-specific columns because H16's Sheets/Airtable destinations are shaped nothing like a channel id. Each adapter validates its own shape at the request layer.

---

## 26. `saved_report_views`

A user's saved cross-form analytics report (H24a; ADR-0011 §D8; `docs/PRD.md:204`).

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK to `tenants.id`, `ON DELETE CASCADE`. |
| `user_id` | `uuid` | No | — | No | FK to `users.id`, `ON DELETE CASCADE`. Single-column, not composite: `users` is a tenant-free global table, so ADR-0002 §D5's composite rule (which protects tenant-scoped parents) does not apply — the same shape `resource_grants` uses. |
| `name` | `varchar(150)` | No | — | No | Unique per `(tenant_id, user_id)`. A duplicate raises SQLSTATE 23505, mapped to a 422 rather than pre-checked, because two tabs racing on the same name is a real race. |
| `definition` | `jsonb` | No | `'{}'` | No | An `AnalyticsQuery` **declaration**, carrying its own `v` schema tag — never a materialized result. |
| `created_at` / `updated_at` | `timestamptz` | No | `now()` | No | — |

**Indexes:** `unique (tenant_id, user_id, name)`; `(tenant_id, user_id)`. **RLS:** `strict`.

**Design notes.**

- **`strict`, not `belongs_to_user`, and the distinction is a leak** (§D8). The obvious variant — given the `user_id` and the PRD's "per user" — emits policies keyed *only* on `user_id = current_setting('app.current_user_id')`, **with no tenant predicate at all**. For a consultant who belongs to two tenants that is a cross-tenant metadata leak: their Acme report definitions readable while acting for Globex. `scripts/migration-lint.php` cannot catch the mistake — it checks only that *some* isolation call exists, never which variant.
- **Per-user privacy is an APPLICATION predicate.** `strict` scopes to the tenant, so an Owner's bare `SELECT` returns every member's rows. "Per user" is enforced by `SavedReportView::scopeOwnedBy()` on list reads and by `SavedReportViewPolicy`'s owner check on single-row actions — including *from* an Owner; an admin override would need its own decision and its own permission, and neither exists.
- **The row is resolved at read time, not at write time.** That is what lets a view naming a form, a scope node or a `field_key` that has since gone degrade to a **stated refusal** rendered beside the working views, rather than to a wrong number or a page that fails to load. The resolution result rides on every API representation.
- **No soft delete**, deliberately: user-owned convenience metadata with no audit claim on it, and a `deleted_at` every read path must remember to filter is a landmine (the argument `scope_nodes` records for itself).
- **No admin/tenant-wide sharing.** Sharing a view is a new authorization question (who may see whose report?) and is not in Phase 3.

---

## Foreign Key Relationship Summary

```
forms.tenant_id                       -> tenants.id
forms.current_published_version_id    -> form_versions.id
forms.draft_version_id                -> form_versions.id
forms.owner_user_id                   -> users.id            (external — see RBAC doc)
forms.created_by                      -> users.id            (external)
forms.updated_by                      -> users.id            (external)

resource_grants.tenant_id              -> tenants.id         (external table — see RBAC doc, docs/multi-tenancy-rbac-design.md §8)
resource_grants.scopeable_id           -> forms.id | scope_nodes.id   (polymorphic; NO db-level FK — guarded by RLS)
resource_grants.user_id                -> users.id           (external)
resource_grants.granted_by             -> users.id           (external, nullable)
scope_nodes.tenant_id                  -> tenants.id         (external table — see RBAC doc §8)
scope_nodes.(tenant_id, parent_id)     -> scope_nodes.(tenant_id, id)  (nullable; COMPOSITE, ADR-0002 §D5)
forms.(tenant_id, scope_node_id)       -> scope_nodes.(tenant_id, id)  (nullable; COMPOSITE)

form_versions.tenant_id                -> tenants.id
form_versions.form_id                  -> forms.id
form_versions.published_by             -> users.id           (external)

form_sections.tenant_id                -> tenants.id
form_sections.form_version_id          -> form_versions.id

form_fields.tenant_id                  -> tenants.id
form_fields.form_version_id            -> form_versions.id
form_fields.form_section_id            -> form_sections.id
form_fields.created_by                 -> users.id           (external)
form_fields.updated_by                 -> users.id           (external)

form_field_validations.tenant_id           -> tenants.id
form_field_validations.form_version_id     -> form_versions.id
form_field_validations.form_field_id       -> form_fields.id
form_field_validations.related_form_field_id -> form_fields.id

submissions.tenant_id                  -> tenants.id
submissions.form_id                    -> forms.id
submissions.form_version_id            -> form_versions.id
submissions.respondent_user_id         -> users.id           (external)
submissions.validated_by               -> users.id           (external)

submission_answers.submission_id       -> submissions.id     (also PK)
submission_answers.tenant_id           -> tenants.id
submission_answers.form_version_id     -> form_versions.id

submission_answer_index.tenant_id      -> tenants.id
submission_answer_index.submission_id -> submissions.id
submission_answer_index.form_version_id -> form_versions.id
submission_answer_index.form_field_id  -> form_fields.id

attachments.tenant_id                  -> tenants.id
attachments.attachable_id              -> (polymorphic — forms, submissions, form_fields, tenants, webhook_deliveries; no DB-level FK — feedback_reports.screenshot_attachment_id is a direct FK instead, same pattern as form_templates.cover_image_attachment_id)
attachments.uploaded_by                -> users.id           (external)

field_library.tenant_id                -> tenants.id         (nullable)
field_library.created_by               -> users.id           (external, nullable)

form_templates.tenant_id                    -> tenants.id    (nullable)
form_templates.cover_image_attachment_id    -> attachments.id
form_templates.source_form_version_id       -> form_versions.id
form_templates.created_by                   -> users.id      (external, nullable)

audits.tenant_id                       -> tenants.id         (nullable)
audits.auditable_id                    -> (polymorphic — forms, form_versions, submissions, webhook_endpoints, subscriptions, ...; no DB-level FK)
audits.user_id                         -> users.id           (external, nullable)

webhook_endpoints.tenant_id            -> tenants.id
webhook_endpoints.form_id              -> forms.id           (nullable)
webhook_endpoints.created_by           -> users.id           (external, nullable)

webhook_deliveries.tenant_id                     -> tenants.id
webhook_deliveries.webhook_endpoint_id           -> webhook_endpoints.id        (nullable — H15a: exactly one owner)
webhook_deliveries.connection_subscription_id    -> connection_subscriptions.id (nullable — H15a: exactly one owner)
webhook_deliveries.payload_attachment_id         -> attachments.id (nullable)

connections.tenant_id                  -> tenants.id
connections.connected_by               -> users.id           (external, nullable)

connection_subscriptions.tenant_id     -> tenants.id
connection_subscriptions.connection_id -> connections.id
connection_subscriptions.form_id       -> forms.id           (nullable)
connection_subscriptions.created_by    -> users.id           (external, nullable)

subscriptions.tenant_id                -> tenants.id
subscriptions.plan_id                  -> plans.id

usage_counters.tenant_id               -> tenants.id
usage_counters.subscription_id         -> subscriptions.id   (nullable)

tenants.owner_user_id                  -> users.id           (external)
tenants.logo_attachment_id             -> attachments.id     (nullable)

user_ui_preferences.user_id            -> users.id           (external, unique — no tenant_id, see §19 Design Notes)

settings.tenant_id                     -> tenants.id         (nullable)
settings.updated_by                    -> users.id           (external, nullable)

feedback_reports.tenant_id             -> tenants.id
feedback_reports.user_id               -> users.id           (external)
feedback_reports.screenshot_attachment_id -> attachments.id  (nullable)
feedback_reports.resolved_by           -> users.id           (external, nullable)

notifications.tenant_id                -> tenants.id
notifications.user_id                  -> users.id           (external)

notification_preferences.tenant_id     -> tenants.id
notification_preferences.user_id       -> users.id           (external)

saved_report_views.tenant_id           -> tenants.id
saved_report_views.user_id             -> users.id           (external)
```

**Cascade behavior summary** (stated once for brevity rather than repeated per row above): only `form_fields.form_section_id` → `SET NULL` (a field whose section row is deleted becomes ungrouped rather than deleted); every `form_version_id`- and `form_field_id`-family FK is `ON DELETE CASCADE` within its own version (deleting a draft version cleans up its own unpublished sections/fields/validations — published/superseded versions are never deleted, only superseded, so this path is only ever exercised on discarded drafts). `tenant_id` FKs are never cascade-deleted automatically; tenant offboarding is a deliberate, audited, application-orchestrated job, not an implicit `ON DELETE CASCADE` across 17 tables.
