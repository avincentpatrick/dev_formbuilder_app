# Multi-Tenancy & RBAC Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — written against the approved architecture plan (§4, Documentation Artifact #9) and the already-ratified `docs/adr/0002-multi-tenancy-shared-db-rls.md`, before any migration is written.
**Scope:** The tables `docs/data-dictionary.md` explicitly excludes as "belonging to the Multi-Tenancy & RBAC Design Doc" — `users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` — plus `tenant_users` (tenant membership/invites) and `form_collaborators` (per-form access), both new tables introduced by this document. It also formalizes the role catalog that `docs/architecture/technical-architecture.md` deferred here ("Tenant Admin is a role family, not a single role... fine-grained role definitions belong to the Multi-Tenancy & RBAC Design Doc") and operationalizes ADR-0002's enforcement table into an actionable checklist.

---

## 1. Purpose, Scope & Relationship to Other Documents

This document does **not** re-decide the tenant-isolation model, Row-Level Security mechanics, or tenant resolution — those are settled, ratified decisions in **ADR-0002**, which states explicitly: *"This ADR records the decision, those documents [this doc and the Testing Strategy Doc, #21] record the full operational detail."* This document's job is the half ADR-0002 deliberately left open: **who is allowed to do what, as whom, scoped to which tenant and which resource** — plus turning ADR-0002's descriptive enforcement table into something a developer or reviewer can literally check off on a pull request.

| Already decided elsewhere | Doc | This document's relationship to it |
|---|---|---|
| Shared DB/schema, `tenant_id` discriminator, Postgres RLS, fail-closed session-variable semantics | ADR-0002 | Assumed as given; extended with RBAC-specific RLS shapes (§6–§8) not covered there |
| Layer-by-layer tenant-isolation enforcement (routing → session → ORM → DB → jobs → storage → cache → guest → realtime → super-admin) | ADR-0002 §D3 | Reformatted into an actionable checklist (§10), with two new layers (team context, per-form collaborator scope) this doc adds |
| `is_super_admin` boolean on `users`, replacing legacy's `id === 1` convention | Architecture plan §2.1, ADR-0002 §D3 | `users.users` table defined here (§6) to satisfy that forward reference; elaborated in §9 |
| Multi-tenancy package: `stancl/tenancy` v4; RBAC package: Spatie Laravel-Permission, tenant-scoped via "teams" | Architecture plan §1 | Concretized into an actual config + table shape (§4) |
| "Tenant Admin is a role family, not a single role" | Technical Architecture Doc | Concretized into the 5-role catalog (§3) |
| `users`/`roles`/`permissions`/Spatie pivot tables out of scope | Data Dictionary (top-of-doc scope note) | Fully specified here (§6, §4) |

---

## 2. Identity & Tenant-Membership Model

- **One global `users` identity.** A person has exactly one `users` row regardless of how many tenants they belong to — there is no per-tenant user duplication. This is required by `docs/data-dictionary.md` §19's own design note on `user_ui_preferences`: *"the same user may belong to more than one tenant... and should see the same personal theme regardless of which tenant they are currently working in."*
- **Membership via `tenant_users`** (§7) — a pivot row per (tenant, user) pair carrying invite/membership lifecycle state and the assigned role. A user with rows in three tenants has three `tenant_users` rows, one `users` row, and (per Spatie's teams feature) up to three distinct `model_has_roles` rows, one per tenant.
- **"Currently active tenant" is carried differently per client**, matching `docs/architecture/technical-architecture.md`'s existing line ("the active tenant is carried as an explicit claim in the session/token"):
  - **Inertia/web session** (Admin/Builder app): the tenant is resolved from the subdomain on every request (ADR-0002 §D3 routing layer) — there is no ambiguity to carry, since the URL itself pins the tenant. `users.last_active_tenant_id` (§6) only matters for **cross-tenant chrome** — e.g., a "switch tenant" picker shown to a user who belongs to more than one tenant, defaulting to their last-active one, and the central (non-tenant) app's post-login redirect target.
  - **Sanctum API/PWA token** (offline client, third-party integrations): unlike the web session, a token has no subdomain to resolve against on every call. Each personal access token is minted **for exactly one tenant** at creation time — a recommended extension of Sanctum's stock `personal_access_tokens` table with a `tenant_id` column (see §6's RLS note) — so tenant context travels with the token itself, not a separate claim that could drift from it.
- **Spatie's "current team" is set immediately after tenant resolution**, in the same middleware position as ADR-0002 §D3's `EstablishTenantDatabaseContext` step (`setPermissionsTeamId($tenantId)`, called right after `SET LOCAL app.current_tenant_id` in the same request lifecycle) — RBAC's "which tenant am I authorizing against" question is answered by the exact same pipeline stage that answers isolation's "which tenant am I querying against" question. This is a deliberate alignment, not a coincidence: two different concerns (data isolation, authorization scope), one shared trigger.

---

## 3. Role Catalog

A **fixed, platform-defined catalog of five roles** — tenants cannot define custom roles or granular per-permission grants (all authorization flows through these five; see §4's note on why `model_has_permissions` exists in the schema but is unused). Each role below is grounded in specific language already committed elsewhere in this project's docs, not invented fresh:

| Role | Grounded in | Summary |
|---|---|---|
| **Owner** | `tenants.owner_user_id` (Data Dictionary §1: "the account's primary owner/billing contact"); PRD's repeated "Owner/Admin" pairing | Everything Admin can do, **plus**: transfer ownership to another tenant member, delete/offboard the tenant, manage the primary billing relationship, and cannot be removed by an Admin. Exactly one active Owner per tenant at any time — enforced at the application layer (see §7's transfer-of-ownership note), not a database constraint, mirroring how `docs/data-dictionary.md` §4 enforces `min_instances <= max_instances` at the app layer rather than via `CHECK`. |
| **Admin** | PRD §2.3 (the Tenant/Platform Administrator persona): "inviting/removing users, assigning roles, managing billing/plan tier"; PRD Feature #10: "tenant Owners/Admins" manage App Settings | Day-to-day tenant administration: invite/remove members, assign roles (but never grant/revoke Owner), manage Settings (Feature #10), manage webhooks, view the org-wide dashboard, view the Audit Log (PRD: *"visible to Owner/Admin roles"* — this is the literal source for restricting §5's `audit_log.view` permission to exactly these two roles). Tenant-wide access to every form, bypassing the `form_collaborators` scoping in §8. |
| **Form Editor** *(a.k.a. Form Owner/Builder in PRD prose)* | PRD's dashboard section: *"Form Owner/Editor view shows: submissions over time for that form..."*; PRD Feature #8 (the core builder) | Build/edit/publish forms **they are a collaborator on** (§8), manage that form's field library usage, manual-encode submissions against forms they collaborate on, view that form's dashboard. No user/role/settings/billing/webhook access, and no access to forms they are not a collaborator on. |
| **Reviewer** *(a.k.a. Validator in PRD prose)* | `docs/data-dictionary.md` §7 (`submissions.validated_by`): *"reviewer who moved status to approved/returned"*; §7's `remarks` column: *"Internal reviewer notes"* | Review submissions (approve/return, write `remarks`) **on forms they are a collaborator on** (§8), and — per the §5 matrix — may also manual-encode (`submissions.create`) and export (`submissions.export`) submissions on those same forms, plus view that form's dashboard. Cannot edit form structure, cannot manage users/settings. |
| **Viewer** | Not explicitly named anywhere in the PRD — a genuinely new addition, confirmed with the user (see this doc's originating discussion) rather than textually derived like the other four. | Read-only: org-wide dashboard, per-form dashboards, submission lists/detail. No edit, no review, no export, and — per PRD's explicit Owner/Admin-only restriction — **no Audit Log access**. |

> **Design Note**
> The role *name* itself (e.g., `'owner'`, `'form_editor'`) is stored as plain data in the `roles` table (§4), **not** a PHP backed enum, even though the catalog is fixed — this is a deliberate, flagged exception to `docs/data-dictionary.md`'s "one consistent enum strategy" (its Conventions section already names one such exception, `subscriptions.stripe_status`, for an analogous reason). Spatie Laravel-Permission's entire relationship/team-scoping machinery (`model_has_roles`, `hasRole()`, permission caching) is built around roles being real, queryable model rows, not a code-only vocabulary — modeling it as a PHP enum would fight the library rather than use it. The catalog stays closed in practice (no UI ever lets a tenant insert a 6th row), which is what "fixed catalog" actually means here — not that the database enforces it via `CHECK`.

---

## 4. Spatie Laravel-Permission "Teams" Configuration

**`config/permission.php`**: `'teams' => true`, `'team_foreign_key' => 'tenant_id'` — deliberately overriding the package's default `team_id` column name so this stays the same discriminator name used everywhere else in the schema, and so it remains the leading column of composite indexes exactly as ADR-0002 §D1 requires for every other tenant-scoped table.

**A project-wide customization beyond Spatie's stock migrations**: this schema uses UUIDv7 primary keys everywhere externally-addressable identifiers exist (Data Dictionary "Primary key strategy" note), specifically to avoid sequential-ID enumeration on any path that might leak an identifier — and role IDs **are** externally addressable, since `docs/architecture/technical-architecture.md` already forward-references a `GET /api/v1/roles` endpoint. Spatie's stock migrations use auto-increment `bigint` IDs by default; this project overrides that (swapping `$table->id()` for `$table->uuid('id')->primary()`, and setting the package's `Role`/`Permission` models to use Laravel's `HasUuids` trait) so `roles`/`permissions` rows follow the same convention as every other table in this schema, not a silent exception.

| Table | `tenant_id` (team key) | Shape | Notes |
|---|---|---|---|
| `roles` | Always `NULL` | `id` (uuid), `tenant_id` (uuid, nullable), `name` (varchar 50), `guard_name` (varchar 50, default `'web'`), `created_at`, `updated_at` | Global, platform-seeded catalog (§3) — reuses ADR-0002 §D2's nullable-`tenant_id`-means-global pattern, extending it from three adopters (`field_library`, `form_templates`, `settings`) to five (see this doc's companion ADR-0002 amendment). Seeded once via a migration/seeder, never created per-tenant. |
| `permissions` | Always `NULL` | Same shape as `roles`, `name` holds a permission string (e.g. `forms.edit.any`, see §5) | Same global pattern; code-controlled vocabulary (a new permission always requires a code change to actually enforce, so it stays seeded/global rather than tenant-editable — consistent with how `docs/data-dictionary.md`'s `WebhookEventType` enum reasons about "phase in more, not data-driven"). |
| `role_has_permissions` | N/A (no column) | `role_id` (uuid, FK `roles.id`), `permission_id` (uuid, FK `permissions.id`) — composite PK | Global-to-global mapping; added to ADR-0002 §D1's exemption list alongside `plans`, since it has no legitimate tenant dimension at all. |
| `model_has_roles` | **Not nullable** | `role_id` (uuid, FK `roles.id`), `model_type` (varchar, e.g. `App\Models\User`), `model_id` (uuid, morph id), `tenant_id` (uuid, FK `tenants.id`) — composite PK, no surrogate `id` | The actual per-tenant role assignment. Strictly tenant-scoped, gets the full strict RLS policy shape like any ordinary tenant-scoped table (ADR-0002 §D2) — **not** the nullable-global exception. Role grants/revokes are audited against the affected `users` row (Audit Spec §1), **not** this pivot, since its composite PK has no surrogate `id` for `audits.auditable_id` to reference. |
| `model_has_permissions` | **Not nullable** | Same shape as `model_has_roles`, `permission_id` instead of `role_id` | Present because Spatie ships it as part of the standard package migration, but **unused in Phase 1** — every authorization decision flows through roles, never a direct per-user permission grant, for auditability (a flagged scope decision, not an oversight: it is much easier to audit "why can this person do X" when the answer is always "because of role Y" rather than a mix of role- and ad hoc permission-grants). |

> **Design Note — a real CI-relevant gap to close before Phase 0 ships**
> Spatie's stock published migration for `model_has_roles`/`model_has_permissions` has no awareness of this project's RLS-policy-migration helper (ADR-0002 §D2). Whoever runs `php artisan vendor:publish` for this package **must** manually extend the published migration to call the same `withTenantIsolation($table)` helper every other tenant-scoped table's migration uses — otherwise these two tables silently ship with no RLS policy at all, which ADR-0002 §D6's migration linter should catch (a new linter rule to add: recognize Spatie's team-scoped pivot tables specifically, since their `tenant_id` column is added by a package migration the linter doesn't author itself).

> **Package-version caveat**: consistent with ADR-0002's own References section, treat the exact Spatie Laravel-Permission teams-mode API (config keys, published-migration shape, `setPermissionsTeamId()` method name) as **indicative, not pinned** — verify against the installed package version's current documentation at implementation time.

---

## 5. Permission Matrix

**Permission-string catalog** (each a `permissions.name` row, dot-namespaced by domain):

`tenant.settings.manage`, `tenant.billing.manage`, `tenant.billing.view`, `tenant.members.invite`, `tenant.members.remove`, `tenant.roles.assign`, `tenant.ownership.transfer`, `forms.create`, `forms.edit.any`, `forms.edit.own`, `forms.publish.any`, `forms.publish.own`, `forms.delete`, `forms.collaborators.manage`, `submissions.create`, `submissions.edit.any`, `submissions.edit.own`, `submissions.review.any`, `submissions.review.own`, `submissions.export`, `submissions.view`, `dashboard.org.view`, `dashboard.form.view`, `webhooks.manage`, `audit_log.view`, `feedback.submit`, `feedback.view`

The `.any` / `.own` suffix pattern is how tenant-wide administrative access (Owner/Admin) and per-form collaborator-scoped access (Form Editor/Reviewer) coexist as two distinct, independently grantable permissions rather than one permission with an implicit, code-only scoping rule — `.any` is a pure Spatie role check; `.own` additionally requires the Policy-layer `form_collaborators` lookup described in §8.

| Permission | Owner | Admin | Form Editor | Reviewer | Viewer |
|---|:---:|:---:|:---:|:---:|:---:|
| `tenant.settings.manage` | ✓ | ✓ | | | |
| `tenant.billing.manage` | ✓ | | | | |
| `tenant.billing.view` | ✓ | ✓ | | | |
| `tenant.members.invite` | ✓ | ✓ | | | |
| `tenant.members.remove` | ✓ | ✓ | | | |
| `tenant.roles.assign` | ✓ | ✓ | | | |
| `tenant.ownership.transfer` | ✓ | | | | |
| `forms.create` | ✓ | ✓ | ✓ | | |
| `forms.edit.any` | ✓ | ✓ | | | |
| `forms.edit.own` | | | ✓ | | |
| `forms.publish.any` | ✓ | ✓ | | | |
| `forms.publish.own` | | | ✓ | | |
| `forms.delete` | ✓ | ✓ | | | |
| `forms.collaborators.manage` | ✓ | ✓ | | | |
| `submissions.create` | ✓ | ✓ | ✓ (own forms) | ✓ (own forms) | |
| `submissions.edit.any` *(post-submission answer editing — fast-follow)* | ✓ | ✓ | | | |
| `submissions.edit.own` *(fast-follow)* | | | ✓ (own forms) | | |
| `submissions.review.any` | ✓ | ✓ | | | |
| `submissions.review.own` | | | | ✓ | |
| `submissions.export` | ✓ | ✓ | ✓ (own forms) | ✓ (own forms) | |
| `submissions.view` | ✓ | ✓ | ✓ (own forms) | ✓ (own forms) | ✓ |
| `dashboard.org.view` | ✓ | ✓ | | | ✓ |
| `dashboard.form.view` | ✓ | ✓ | ✓ (own forms) | ✓ (own forms) | ✓ |
| `webhooks.manage` | ✓ | ✓ | | | |
| `audit_log.view` | ✓ | ✓ | | | |
| `feedback.submit` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `feedback.view` | ✓ | ✓ | | | |

> **Design Note**: `forms.collaborators.manage` is deliberately restricted to Owner/Admin only — **not** delegable to a form's existing editors — specifically to prevent a Form Editor from adding themselves or others to additional forms they weren't granted access to by an administrator, i.e., a straightforward privilege-escalation vector that would otherwise exist if collaborator management were self-service.

---

## 6. `users` Table

Column-level spec, following `docs/data-dictionary.md`'s exact conventions (UUIDv7 PK, PII methodology, soft-delete/timestamp convention). This satisfies every existing "external — see RBAC doc" forward reference already written into the Data Dictionary: `tenants.owner_user_id`, `forms.owner_user_id`/`created_by`/`updated_by`, `submissions.respondent_user_id`/`validated_by`, `attachments.uploaded_by`, `field_library.created_by`, `form_templates.created_by`, `audits.user_id`, `webhook_endpoints.created_by`, `user_ui_preferences.user_id`, `settings.updated_by`, `feedback_reports.user_id`/`resolved_by`.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `name` | `varchar(150)` | No | — | No | Display name. |
| `email` | `varchar(255)` | No | — | **Yes** | Globally unique. One identity across every tenant membership — never duplicated per tenant. |
| `email_verified_at` | `timestamptz` | Yes | `NULL` | No | Standard Laravel/Fortify verification timestamp. |
| `password` | `varchar(255)` | No | — | No | Hashed. A user invited but not yet registered (§7) has an unusable random hash until they complete signup. |
| `remember_token` | `varchar(100)` | Yes | `NULL` | No | Standard Laravel "remember me" token. |
| `two_factor_secret` | `text` | Yes | `NULL` | No | Laravel Fortify 2FA (PRD Feature #14) — encrypted TOTP secret; `NULL` until the user enrols in two-factor auth. Always redacted in `audits` (Audit Spec §2). |
| `two_factor_recovery_codes` | `text` | Yes | `NULL` | No | Fortify 2FA — encrypted JSON array of one-time recovery codes. Always redacted in `audits`. |
| `two_factor_confirmed_at` | `timestamptz` | Yes | `NULL` | No | Set when the user completes 2FA enrolment (confirms a first TOTP code); `NULL` = not enrolled. |
| `is_super_admin` | `boolean` | No | `false` | No | The explicit platform-wide flag named in the architecture plan §2.1 and ADR-0002 §D3, replacing legacy's fragile `id === 1` convention. Global — not per-tenant, not a Spatie role (see §9 for why). |
| `last_active_tenant_id` | `uuid` | Yes | `NULL` | No | FK `tenants.id`. The tenant to default into on next login / the central app's post-login redirect target, for a user belonging to more than one tenant (§2). Not authoritative for any authorization decision — purely a UX convenience. |
| `tos_accepted_at` | `timestamptz` | Yes | `NULL` | No | Set when this user accepts the platform's own Terms of Service — added per `docs/data-privacy-gdpr-compliance.md` §6's platform-user-consent recommendation. `NULL` for a user mid-invite who hasn't completed signup yet. |
| `privacy_policy_accepted_at` | `timestamptz` | Yes | `NULL` | No | Set when this user accepts the platform's own Privacy Policy — same rationale as `tos_accepted_at`; tracked as a separate timestamp since the two documents can be revised and re-accepted independently. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |
| `deleted_at` | `timestamptz` | Yes | `NULL` | No | Soft-delete; consistent with the rest of the schema's trash-grace-period convention. |

> **Design Note — a genuinely new, fourth RLS shape**
> Every RLS shape ADR-0002 documents so far is one of: (a) strict tenant equality, (b) nullable-`tenant_id`-means-global (`OR tenant_id IS NULL`), or (c) `user_ui_preferences`'s "belongs to me" (`user_id = current_setting('app.current_user_id', ...)`). `users` needs a **fourth shape**, since a row must be visible to *itself* and to *anyone who currently shares an active tenant membership with it* (an Admin needs to see the list of users in their tenant) — a join through `tenant_users`/`model_has_roles`, not a flat equality check:
> ```sql
> CREATE POLICY users_visibility ON users
>     FOR SELECT
>     USING (
>         id = current_setting('app.current_user_id', true)::uuid
>         OR EXISTS (
>             SELECT 1 FROM tenant_users tu
>             WHERE tu.user_id = users.id
>               AND tu.tenant_id = current_setting('app.current_tenant_id', true)::uuid
>               AND tu.status = 'active'
>         )
>     );
> ```
> This is heavier than ADR-0002's flat-equality norm (a subquery per row, versus a single column comparison) and should be benchmarked during the Phase 0 spike alongside ADR-0002 §D2's own flagged-but-unmeasured RLS overhead assumption — not treated as free. `INSERT`/`UPDATE`/`DELETE` on `users` are not tenant-scoped operations at all (a user's own account) and are governed by ordinary application-layer authorization instead of a write-side RLS policy.

> **Sanctum extension**: `personal_access_tokens` (Sanctum's own table) is recommended to gain a `tenant_id` column so API/PWA tokens get the same standard strict-tenant RLS shape as any other tenant-scoped table (§2's point that a token is minted for exactly one tenant) — flagged here as a recommendation for whoever wires up Sanctum, not a change to a table this document otherwise specifies.

---

## 7. `tenant_users` Table — Membership & Invite Lifecycle

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK `tenants.id`. |
| `user_id` | `uuid` | No | — | No | FK `users.id`. |
| `status` | `varchar(15)` — PHP enum: `TenantUserStatus` | No | `'invited'` | No | `invited`, `active`, `suspended`, `declined`, `removed`. |
| `invited_role_id` | `uuid` | Yes | `NULL` | No | FK `roles.id`. The role reserved at invite time; materialized into an actual `model_has_roles` row only on acceptance (see lifecycle below) — never granted before the invitee has agreed to join. |
| `invited_by` | `uuid` | Yes | `NULL` | No | FK `users.id`. `NULL` for the original Owner row created alongside the tenant itself (nobody invited the founding Owner). |
| `invited_at` | `timestamptz` | Yes | `NULL` | No | — |
| `invite_expires_at` | `timestamptz` | Yes | `NULL` | No | — |
| `invite_token` | `varchar(128)` | Yes | `NULL` | No | Opaque, hashed at rest — same convention as Laravel's standard password-reset tokens, not stored/compared in plaintext. |
| `joined_at` | `timestamptz` | Yes | `NULL` | No | Set on transition `invited` → `active`. |
| `removed_at` | `timestamptz` | Yes | `NULL` | No | — |
| `removed_by` | `uuid` | Yes | `NULL` | No | FK `users.id`. |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

Unique constraint: `(tenant_id, user_id)` — a person has at most one membership record per tenant, ever (a removed-then-re-invited member reuses/reactivates the same row rather than creating a duplicate).

**Lifecycle:**
1. **Invite**: Admin/Owner invites by email with a chosen role. If the email matches no existing `users` row, a placeholder `users` row is created (unverified, unusable password) so `tenant_users.user_id` always has a valid target; if it matches an existing user, that row is reused directly (this is exactly the mechanism that lets one global identity join multiple tenants). `tenant_users` row created with `status = 'invited'`, `invited_role_id` set, `invite_token` generated.
2. **Accept**: invitee follows the signed invite link, sets a password if new, or simply confirms if already a registered user. `status → active`, `joined_at` set, and **only now** is `invited_role_id` materialized into a real `model_has_roles` row (`role_id = invited_role_id`, `tenant_id` = this tenant). Before acceptance, the reserved role grants nothing — an unaccepted invite has zero standing authorization.
3. **Decline**: `status → declined`. No `model_has_roles` row is ever created.
4. **Remove** (by an Admin/Owner): a single transaction — `status → removed`, `removed_at`/`removed_by` set, the corresponding tenant-scoped `model_has_roles` row deleted, and every tenant-scoped Sanctum token for that user revoked. Called out as one atomic operation explicitly because Spatie has no awareness of `tenant_users`'s lifecycle on its own — nothing in the package automatically cleans up role/token state when this application-level table changes; the application must own that transaction.
5. **Ownership transfer** (Owner-only capability, `tenant.ownership.transfer`): updates `tenants.owner_user_id` to the new Owner, changes the outgoing Owner's `model_has_roles` row to `Admin` (never leaves them roleless), and grants the incoming member the `Owner` role — all inside one transaction, logged via `audits` (`event = 'permission_changed'`).

---

## 8. `form_collaborators` Table — Per-Form Access Scoping

This table is what makes the **Form Editor** and **Reviewer** roles real for any specific form. Holding the Form Editor role at the tenant level (via `model_has_roles`) grants **no** access to any particular form by itself — it is a capability class ("this person is allowed to be assigned as an editor somewhere"), not a blanket grant. Owner and Admin bypass this table entirely (`forms.edit.any` / `submissions.review.any` are pure tenant-wide Spatie role checks); Form Editor/Reviewer access is always resolved through a Policy-layer lookup against this table.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | `uuidv7()` | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK `tenants.id`; denormalized for RLS, per the Data Dictionary's stated convention for every tenant-scoped table. |
| `form_id` | `uuid` | No | — | No | FK `forms.id`, `ON DELETE CASCADE`. |
| `user_id` | `uuid` | No | — | No | FK `users.id`. |
| `capacity` | `varchar(10)` — PHP enum: `FormCollaboratorCapacity` | No | — | No | `editor` or `reviewer` — which of the two per-form permissions (`forms.edit.own` / `submissions.review.own`) this row grants. |
| `added_by` | `uuid` | Yes | `NULL` | No | FK `users.id`. The Owner/Admin who granted this access (per §5's restriction that only they can). |
| `created_at` | `timestamptz` | No | `now()` | No | — |
| `updated_at` | `timestamptz` | No | `now()` | No | — |

Unique constraint: `(form_id, user_id)` — a person holds exactly one capacity on a given form at a time (editor *or* reviewer, not simultaneously both; granting the other capacity updates the existing row rather than inserting a second one).

**Authorization composition** (how §5's `.any`/`.own` split is actually enforced): a `FormPolicy::update($user, $form)` check is, conceptually:
```
return $user->hasPermissionTo('forms.edit.any')
    || ($user->hasPermissionTo('forms.edit.own')
        && FormCollaborator::where('form_id', $form->id)
              ->where('user_id', $user->id)
              ->where('capacity', 'editor')
              ->exists());
```
`ReviewerPolicy`/submission-review checks follow the identical shape against `capacity = 'reviewer'` and `submissions.review.own`. This two-layer composition — a coarse Spatie permission ("can this role ever do this action, in principle") plus a fine Policy-layer resource check ("does this row grant it for *this specific* form") — is the general pattern for any future resource that needs per-instance scoping beyond the tenant level, not a one-off invented solely for forms.

> **Design Note — relationship to `forms.owner_user_id`**
> `docs/data-dictionary.md` §2 already defines `forms.owner_user_id` as "the form's business owner (dashboard scoping)." That column is retained as-is and continues to mean **attribution** — whose name shows as the form's primary point of contact, and the default assignee dashboard-scoping falls back to — but it is no longer the sole gate on who may edit the form. A form's creator is expected to also receive a `form_collaborators` row with `capacity = 'editor'` at creation time (so the common case of "I built it, I can edit it" keeps working without extra clicks), but that is now an ordinary collaborator row like any other, not a special-cased check against `owner_user_id` in the Policy layer.

---

## 9. Super-Admin Design

Elaborates ADR-0002 §D3's isolation-focused mention of the super-admin carve-out with the actual platform-operations surface:

- **Console scope**: tenant list/suspend/reactivate (`tenants.status`), platform-level `settings` rows (`docs/data-dictionary.md` §20's `tenant_id IS NULL` rows), cross-tenant billing reconciliation view, the internal `feedback_reports` (§21) review queue, and cross-tenant Audit Log search for support investigations.
- **`is_super_admin` stays a plain boolean, never a Spatie role.** Making it a team-scoped role would reintroduce exactly the ambiguity teams-mode exists to avoid — a role is inherently "scoped to a team," and super-admin is by definition *not* scoped to any one tenant. This mirrors ADR-0002 §D3's own reasoning for why the flag exists as a column rather than a positional/derived convention in the first place.
- **Every super-admin action routes through one narrow, named service layer** using ADR-0002 §D3's separate elevated Postgres role (`current_setting('app.is_superadmin_context', true) = 'true'`) — never an ad hoc `if ($user->is_super_admin)` branch scattered through ordinary controllers. Every such action is logged via the carried-forward `Auditable` trait, exactly as ADR-0002 §D3 already requires.

**Implementation status (Increment B2c, 2026-07-05).** The mechanism is now built. The elevated role is a dedicated **non-superuser / NOBYPASSRLS `meridian_superadmin`** login role on its own `pgsql_superadmin` connection (mirroring B1's `meridian_auth`) — deliberately *not* the superuser seeding role, so RLS still applies to it and defense-in-depth holds. `App\Support\Tenancy\TenantIsolation::superAdminBypass*` emits an **additive, role-scoped, GUC-gated permissive policy** (`TO meridian_superadmin USING (current_setting('app.is_superadmin_context', true) = 'true')`) layered on a table that already has its base RLS+FORCE; it is applied to `users` now (`GRANT SELECT` + the policy — plus a `GRANT SELECT ON tenant_users` so the `users` visibility policy's membership-join subquery is evaluable for the role). `App\Support\Tenancy\SuperAdminContext` opens the GUC **transaction-locally** (`is_local = true`) on the elevated connection, and `App\Services\Admin\SuperAdminService` is the single place it is ever opened (tenant list/suspend/reactivate run on the ordinary connection — `tenants` is RLS-exempt — while cross-tenant user reads go through the elevated path). **Mandatory MFA** (below, security-threat-model §8) is enforced by the `superadmin` + `superadmin.mfa` middleware on the central-domain `/admin` console. **`Auditable` logging of super-admin actions is deferred to Phase 1** (no `audits` table exists until then) — carried as an explicit `TODO(audits, Phase 1)` in `SuperAdminService`, per the Q2 decision below.

**Resolved decisions (2026-07-05, decided with the product owner rather than silently picked):**

1. **Impersonation — deferred.** The platform console does **not** support "log in as this user" in Phase 0. It is a large security surface and would need an `acting_as_user_id`-style `audits` column (and the `audits` table itself, which does not exist until Phase 1) to keep a super-admin's own actions distinguishable from actions taken while impersonating. Revisit when support tooling genuinely requires it; design the `acting_as_user_id` column alongside the Phase-1 `audits` table if so.
2. **Audit-log visibility of super-admin actions — transparency.** A tenant's own Audit Log (PRD Feature #12) **will** surface actions the platform/support team took against that tenant's data. This aligns with the processor posture in `docs/data-privacy-gdpr-compliance.md` (the tenant is Controller; the platform is Processor and should be transparent about its access). This is the principle the Phase-1 `audits` work must implement; B2c records it here because the `audits` table does not exist yet.
3. **Internal staff graduation — binary.** Platform-side access stays a single full-access boolean (`is_super_admin`). Graduated tiers (e.g. read-only support vs. full super-admin) are **not** introduced now — nothing in the current product defines an internal staffing model that needs them. Revisit if/when that model is defined; the change would be additive (a tier column/enum + gating), not a rework of the binary flag.

---

## 10. Layer-by-Layer Enforcement Checklist

ADR-0002 §D3 documents *why* each layer is enforced (a descriptive table). This section turns it into an actionable checklist — for a developer building a new tenant-scoped feature, or a reviewer approving a PR that touches one:

**Every new tenant-scoped table:**
- [ ] Carries a non-nullable `tenant_id` (or is on ADR-0002 §D1's explicit exemption list, updated whenever this doc adds one — see the ADR-0002 amendment accompanying this document).
- [ ] Has an RLS-policy migration generated by the shared helper (ADR-0002 §D2), not hand-written.
- [ ] Is confirmed as either the strict-equality shape, the nullable-global shape, the `user_ui_preferences` "belongs to me" shape, or `users`'s new join-based shape (§6) — not a fifth ad hoc variant.
- [ ] Appears in ADR-0002 §D6's cross-tenant fuzz-test suite.

**Every new authenticated request path:**
- [ ] Tenant resolved before any controller code runs (ADR-0002 §D3 routing layer).
- [ ] `SET LOCAL app.current_tenant_id` issued (ADR-0002 §D3 session layer).
- [ ] `SET LOCAL app.current_user_id` issued for authenticated requests (unset/NULL for guests) — **required** by the `users` (§6) and `user_ui_preferences` (ADR-0002 §D2) RLS policies, which key on it rather than `tenant_id`. Without it those tables fail closed and a logged-in user cannot read their own row or theme preference.
- [ ] **Spatie's current team set** (`setPermissionsTeamId($tenantId)`, §2) — the RBAC-specific layer this document adds to ADR-0002's table. A request that establishes tenant context for isolation but forgets to also set the permissions team will fail every `hasRole()`/`hasPermissionTo()` check silently (Spatie simply finds no roles for the "no team" context) rather than leaking — fail-closed, but worth an explicit check since a silently-broken authorization check can look identical to "user genuinely has no permissions" during testing.
- [ ] Any endpoint touching a per-form resource resolves the Policy-layer `form_collaborators` check (§8) in addition to the tenant-wide Spatie permission — **the other RBAC-specific layer this document adds**. An endpoint that checks only `hasPermissionTo('forms.edit.own')` without also checking the collaborator row would incorrectly grant every Form Editor access to every form in the tenant.

**Every new queued job touching tenant-scoped or RBAC-scoped data:**
- [ ] `tenant_id` serialized into the job payload (ADR-0002 §D3 jobs layer).
- [ ] If the job performs an authorization-sensitive action (e.g., an automated role change), it re-establishes both tenant context *and* the permissions team on execution — not just the former.

**Every new super-admin action:**
- [ ] Routed through the narrow service layer (§9), never an inline `is_super_admin` branch.
- [ ] Logged via `Auditable`.

---

## 11. Out of Scope / Deferred

- Detailed OpenAPI request/response shapes for `/api/v1/users`, `/api/v1/roles` → Doc #14 (API Specification).
- SSO/SAML → Phase 4 (architecture plan §3).
- Dedicated-database tenancy → ADR-0002's own explicitly deferred future ADR.
- GDPR subject-access/erasure mechanics for `users`/`tenant_users` rows → Doc #12 (Data Privacy & GDPR/Compliance Doc).
- Full audit-event redaction rule detail → Doc #13 (Audit & Compliance Logging Spec) — this doc only specifies that role/permission changes emit `audits.event = 'permission_changed'` (already in the Data Dictionary's `AuditEvent` enum).
- Plan-tier seat/role quotas (e.g., "Starter plan caps at 5 Form Editors") → Doc #24 (Pricing & Feature-Gating Matrix).
- Tenant-creation/onboarding UX (how the very first Owner and tenant row come to exist) → Doc #25 (Onboarding & Template Content Plan).
- Privilege-escalation threat scenarios (this doc defines the mechanism precisely enough for that analysis to build on; it does not itself attempt an adversarial review) → Doc #11 (Security & Threat Model Doc).

---

## 12. Foreign-Key Relationship Summary

```
users.last_active_tenant_id            -> tenants.id                     (nullable)

tenant_users.tenant_id                 -> tenants.id
tenant_users.user_id                   -> users.id
tenant_users.invited_role_id           -> roles.id                       (nullable)
tenant_users.invited_by                -> users.id                       (nullable)
tenant_users.removed_by                -> users.id                       (nullable)

form_collaborators.tenant_id           -> tenants.id
form_collaborators.form_id             -> forms.id
form_collaborators.user_id             -> users.id
form_collaborators.added_by            -> users.id                       (nullable)

roles.tenant_id                        -> tenants.id                     (nullable — always NULL, global catalog)
permissions.tenant_id                  -> tenants.id                     (nullable — always NULL, global catalog)

role_has_permissions.role_id           -> roles.id
role_has_permissions.permission_id     -> permissions.id

model_has_roles.role_id                -> roles.id
model_has_roles.tenant_id              -> tenants.id                     (not nullable)
model_has_roles.model_id               -> users.id                       (polymorphic; users.id only in Phase 1)

model_has_permissions.permission_id    -> permissions.id
model_has_permissions.tenant_id        -> tenants.id                     (not nullable)
model_has_permissions.model_id         -> users.id                       (polymorphic; unused in Phase 1, see §4)
```

This appendix mirrors `docs/data-dictionary.md`'s closing "Foreign Key Relationship Summary" convention. The reverse direction — every existing "external — see RBAC doc" FK already listed in that document's own summary (`tenants.owner_user_id -> users.id`, `forms.owner_user_id/created_by/updated_by -> users.id`, etc.) — is not repeated here; see that document directly.
