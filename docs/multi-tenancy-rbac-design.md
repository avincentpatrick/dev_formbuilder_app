# Multi-Tenancy & RBAC Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — written against the approved architecture plan (§4, Documentation Artifact #9) and the already-ratified `docs/adr/0002-multi-tenancy-shared-db-rls.md`, before any migration is written.
**Scope:** The tables `docs/data-dictionary.md` explicitly excludes as "belonging to the Multi-Tenancy & RBAC Design Doc" — `users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` — plus `tenant_users` (tenant membership/invites) and, since Increment G10a, `resource_grants` + `scope_nodes` (per-resource access scoping, which replaced the original `form_collaborators`) — all introduced by this document. It also formalizes the role catalog that `docs/architecture/technical-architecture.md` deferred here ("Tenant Admin is a role family, not a single role... fine-grained role definitions belong to the Multi-Tenancy & RBAC Design Doc") and operationalizes ADR-0002's enforcement table into an actionable checklist.

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
| **Admin** | PRD §2.3 (the Tenant/Platform Administrator persona): "inviting/removing users, assigning roles, managing billing/plan tier"; PRD Feature #10: "tenant Owners/Admins" manage App Settings | Day-to-day tenant administration: invite/remove members, assign roles (but never grant/revoke Owner), manage Settings (Feature #10), manage webhooks, view the org-wide dashboard, view the Audit Log (PRD: *"visible to Owner/Admin roles"* — this is the literal source for restricting §5's `audit_log.view` permission to exactly these two roles). Tenant-wide access to every form, bypassing the `resource_grants` scoping in §8. |
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

`tenant.settings.manage`, `tenant.billing.manage`, `tenant.billing.view`, `tenant.members.invite`, `tenant.members.remove`, `tenant.roles.assign`, `tenant.ownership.transfer`, `forms.create`, `forms.edit.any`, `forms.edit.own`, `forms.publish.any`, `forms.publish.own`, `forms.delete`, `forms.collaborators.manage`, `submissions.create`, `submissions.edit.any`, `submissions.edit.own`, `submissions.review.any`, `submissions.review.own`, `submissions.export`, `submissions.view`, `dashboard.org.view`, `dashboard.form.view`, `webhooks.manage`, `integrations.manage`, `audit_log.view`, `feedback.submit`, `feedback.view`, `scopes.manage`

The `.any` / `.own` suffix pattern is how tenant-wide administrative access (Owner/Admin) and per-form collaborator-scoped access (Form Editor/Reviewer) coexist as two distinct, independently grantable permissions rather than one permission with an implicit, code-only scoping rule — `.any` is a pure Spatie role check; `.own` additionally requires the Policy-layer grant lookup described in §8 (`resource_grants`, resolved through `ResourceGrantResolver`).

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
| `submissions.edit.any` *(I9c — post-submission answer editing; `SubmissionPolicy::update()`)* | ✓ | ✓ | | | |
| `submissions.edit.own` *(I9c — per-form, and requires **editor** capacity: editing answers is an authoring act, the same tightening G10a applied to `submissions.create`)* | | | ✓ (own forms) | | |
| `submissions.review.any` | ✓ | ✓ | | | |
| `submissions.review.own` | | | | ✓ | |
| `submissions.export` | ✓ | ✓ | ✓ (own forms) | ✓ (own forms) | |
| `submissions.view` | ✓ | ✓ | ✓ (own forms) | ✓ (own forms) | ✓ |
| `dashboard.org.view` | ✓ | ✓ | | | ✓ |
| `dashboard.form.view` | ✓ | ✓ | ✓ (own forms) | ✓ (own forms) | ✓ |
| `webhooks.manage` | ✓ | ✓ | | | |
| `integrations.manage` *(H15a — hold the OAuth grants for native connectors)* | ✓ | ✓ | | | |
| `audit_log.view` | ✓ | ✓ | | | |
| `feedback.submit` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `feedback.view` | ✓ | ✓ | | | |
| `scopes.manage` *(G10a — author the tenant's `scope_nodes` hierarchy)* | ✓ | ✓ | | | |

> **Design Note (I8a, 2026-08-07) — `tenant.roles.assign` finally has code behind it, and the respondent
> clause is the matrix's one row-level exception.** Two corrections to how this table should be read.
>
> **First**, `tenant.roles.assign` has been seeded to Owner/Admin and listed here since Phase 0 with *no
> consumer anywhere in the codebase* — there was no role-change route, no controller method and no service
> method, so PRD Feature #14's "step-up gates role changes" criterion was vacuously satisfiable while
> `TenantMembershipService::joinOpenTenant()`'s docblock told invitees an Owner "can promote them on the
> Members page in two clicks". I8a built `PATCH /members/{user}/role` against **this** key rather than
> minting a `tenant.members.role`: the catalog is closed by design, and a second key for one capability is
> how a matrix comes to disagree with itself. The route also carries `step-up`. Four refusals live in the
> service, not the request, because no FormRequest can know them: the Owner's role is immutable here
> (ownership moves only by transfer, §7), `owner` is not assignable, nobody may re-grade themselves, and a
> no-op is refused rather than written to the ledger.
>
> **Second**, `submissions.view` now carries a **row-level** clause this table cannot express. Since I8a,
> {@see SubmissionPolicy::view()} additionally allows a user to read a submission whose
> `respondent_user_id` is their own — regardless of org-wide visibility or per-form grant. The permission
> is still required, so this grants nothing to a role holding no read at all, and guest rows carry a NULL
> respondent so it is inert on the public runtime. It is `view()` **only**: reading back what you yourself
> submitted is not a privilege, whereas deciding your own submission's outcome (`review()`) or editing it
> after review (`submissions.edit.*`, I9) are different questions with different answers. Mirrored in
> `Submission::scopeVisibleTo()` so the single-row check and the inbox query still express one rule.

> **Design Note (J2b, 2026-08-11) — `FormPolicy::viewOverview()` composes two rows of this table and coins
> no key; the catalog stays closed at 29.** The form hub (`GET /forms/{form}`) is the first surface that
> needed "may this person *read about* a form", as distinct from "may they change it". `FormPolicy::view()`
> could not answer it: it delegates to `canEdit()`, so `can:view,form` and `can:update,form` admit the
> identical set and refuse the **Reviewer and the Viewer** — precisely the two roles the hub exists to give a
> destination to, since they are the ones who meet a form's title in the inbox, the audit ledger and global
> search with nowhere to click. Widening `view()` would have silently widened the analytics page with it.
>
> The new ability is, verbatim:
>
> ```php
> $user->can('dashboard.form.view')
>     && ($user->can('dashboard.org.view') || $this->grants->holdsAny($user, $form));
> ```
>
> That is byte-for-byte the split `Submission::scopeVisibleTo()` and `AnalyticsFormSet::visible()` already
> apply, and the one this table has documented against `dashboard.form.view` since Phase 0 — "✓ (own forms)"
> for Editor and Reviewer is exactly the grant arm. It discloses nothing new either: a Viewer already reads
> every submission in the tenant and every org-wide KPI on the dashboard. What they gain is a destination,
> not a fact. `holdsAny()`, never `holds(Editor)` — a Reviewer's grant is reviewer capacity, so an
> editor-capacity check would refuse the single role the ability was widened for.
>
> ⚠️ **THE `dashboard.form.view` CONJUNCT IS A FAIL-CLOSED GUARD AND NO SHIPPED ROLE CAN OBSERVE IT.** Stated
> precisely because the first draft of the policy's own docblock claimed the test suite "mutates it out and
> requires that case to redden", and when the mutation was actually run **all twelve cases stayed green**.
> All five seeded roles hold the key, and the only product path that changes a member's authority
> (`PATCH /members/{user}/role`) assigns one of those five — so nothing a user can do today distinguishes
> the two implementations. It stays because `resource_grants` is a **capacity** store and not a permission
> store: a grant says "editor capacity on this form", never "may read this tenant's forms". Drop it and the
> day a custom permission set becomes reachable, the hub becomes readable on the strength of a grant alone.
> `FormHubGateTest` pins it with a synthetic member built on `ResourceGrantServiceTest`'s `actorWith()`
> idiom, labelled there as unreachable-today rather than presented as a live rule.
>
> **The same composition appears once more, on the client.** The hub links a recent response to
> `/submissions/{id}` only when the reader was offered the Responses tab, and that is provable rather than a
> proxy: `viewAny` is `submissions.view` alone, `view(row)` is that key AND the disjunction above, and the
> rows were already filtered through `scopeVisibleTo()` — which is that same disjunction **minus the key**.
> So `viewAny ∧ (row ∈ visibleTo) ⟹ view(row)`. It is unobservable for the same reason: all five roles hold
> `submissions.view`.

> **Design Note (J2c, 2026-08-11) — `Form::scopeReadableBy()` is the LIST twin of `viewOverview`, and it
> also coins no key. The catalog stays closed at 29.** J2b added the ability; J2c needed the set. Two
> surfaces ask "which forms may this reader open" — `GET /forms/{form}/submissions`, and the submissions
> inbox's form dropdown — and the scope is byte-for-byte the policy's second conjunct:
> `dashboard.org.view` OR a grant of **any** capacity on the form.
>
> ⚠️ **`Form::scopeVisibleTo()` IS NOT INTERCHANGEABLE WITH IT, AND SUBSTITUTING ONE IS SILENT.** That scope
> is the **authoring** rule — it keys on `forms.edit.any` / `forms.edit.own` and requires **Editor**
> capacity — so a **Reviewer and a Viewer hold neither** and it returns them the empty set. Building the
> inbox's dropdown on it blanks the control for exactly the two roles that live in that inbox, while every
> test written with an Owner stays green. `SubmissionInboxTest` now pins the Reviewer-with-a-grant case
> specifically so the substitution reddens.
>
> ⚠️ **THE ONE HONEST WIDENING, STATED RATHER THAN GLOSSED.** A **Viewer** holds `dashboard.org.view` and is
> 403'd from `/forms` (that route gates on `viewAny,Form` = the `forms.*` keys), so before J2c they could
> not enumerate the tenant's forms. The fixed dropdown lists every form they may open, including ones with
> no responses — so they gain **discoverability of form titles they could not previously enumerate**. They
> gain no authority: `viewOverview` already let them open any of those hubs, and they already read every
> submission in the tenant. The trade was accepted deliberately (a dropdown that cannot offer a form with no
> responses cannot answer "has anything arrived yet?", which is the question it is most often asked). Note
> the boundary that did **not** move: soft-deleted forms stay out, because `readableBy` adds no
> `withTrashed()` — unlike `AnalyticsFormSet::visible()`, which answers a different question.
>
> **And one NARROWING in the same change, recorded because a widening alone would be a half-truth.**
> `Submission::scopeVisibleTo()` admits a row on `respondent_user_id = me` — a **respondent arm** that
> `viewOverview` has no counterpart for. The old submissions-derived dropdown therefore offered a form a
> reader could reach *only* by having answered it; `readableBy` does not. That reader still sees those rows
> and can no longer filter to them by form. Accepted: the alternative is a dropdown whose entries do not all
> correspond to forms the reader may open, which is the defect below.
>
> ⚠️ **THE SAME ASYMMETRY IS WHY AN INBOX ROW'S FORM TITLE IS NOT UNCONDITIONALLY A LINK.** The row set is
> strictly wider than form readability, so *"this row is listed"* does not imply *"its form opens"*. Two live
> paths: a keyer whose grant was revoked keeps seeing rows they encoded (the respondent arm) while
> `/forms/{id}` 403s them; and a **soft-deleted** form renders its title as an em dash while binding to
> nothing, so an unguarded link shipped `—` as a hyperlink to a 404. The row payload carries
> `can.open_form`, resolved once per page against `readableBy` rather than per row against the policy.
>
> ⚠️ **AND `/forms` ITSELF IS NOT REACHABLE BY EVERY ROLE THAT CAN REACH THE PAGES LINKING TO IT** — the
> defect J2c's adversarial review found in its own new breadcrumbs. That route gates on `viewAny,Form` =
> `forms.create | forms.edit.any | forms.edit.own`, which a **Reviewer and a Viewer hold none of**, while
> both can reach the form hub, a form's responses, a submission and the encode screen. A hard `href:
> '/forms'` hands them a bare 403 with no way back. `formsCrumb()` (`resources/js/composables/`) drops the
> href — keeping the crumb as text — off `ShellAbilities`' existing `manageForms`, which is computed from
> the identical ability the route's middleware evaluates. **The tab strip already had this property and the
> trail did not:** `FormTabSet` resolves each tab's gate server-side and `FormTabSetReachabilityTest` issues
> a real request per href, so a tab cannot be offered to a reader the route refuses. Breadcrumbs had no
> equivalent, and nothing structural yet enforced one.
>
> **✅ CLOSED BY J2d, and it found two live defects on the way.** `formsCrumb()` is **deleted**; every trail
> is now built server-side by `App\Support\Navigation\CrumbTrail`, which asks each destination's own gate per
> crumb, and `CrumbTrailReachabilityTest` reads the trail back off the real Inertia response and navigates
> every href. Both defects it exposed were on `submissions/Show.vue`, whose route gates on
> `can:view,submission` ALONE: (1) `SubmissionPolicy::view()`'s **respondent arm** has no counterpart in
> `FormPolicy::viewOverview()`, so a keyer whose grant was revoked opened the page and got a 403 from both
> middle crumbs; (2) a **soft-deleted form** made those same crumbs render an em dash as a live hyperlink to
> a 404, since `/forms/{form}` binds through the default scope. A refused CRUMB keeps its label and loses its
> href — deliberately unlike a refused TAB, which is absent, because dropping a crumb renumbers the trail and
> makes one page render a different depth per role.
>
> **The idiom now covers three surfaces**, and each application found something a string assertion could not:
> the tab strip (J2b), the trail (J2d), and search — `DestinationReachabilityTest` drives every
> `DestinationCatalog` URL and requires an **Inertia** response, which is what caught `/notifications` being
> a JSON endpoint offered as a page, while `SearchResultReachabilityTest` navigates the URL each search arm
> emits as the narrowest role that receives it.
>
> **The route composes both halves, and neither alone is sufficient.** `can:viewAny,Submission` says nothing
> about *which* form — with it alone, any member holding `submissions.view` (all five roles) could open any
> form's responses page and read its **title** above an empty list, because an empty list is not a refusal.
> `can:viewOverview,form` bounds the binding but says nothing about submissions. `FormSubmissionsGateTest`
> drives each half out on its own; `FormTabSet` carries the same conjunction so that "the strip offered it"
> implies "the reader can reach it", which `FormTabSetReachabilityTest` turns from a hope into a theorem.
> Both conjuncts are **fail-closed structural guards** — no shipped role can observe either, and each is
> pinned with a synthetic member rather than presented as a live rule.

> **Design Note (ADR-0011 / H1e, 2026-08-03) — advanced analytics coins no permission.** The Phase-3
> analytics surface (H24a/H24b) authorizes on `dashboard.org.view` and `dashboard.form.view` exactly as
> shipped: the org-wide-versus-own-forms split those two already encode *is* the visibility split an
> analytics read needs, so the matrix does not grow. Two adjacent gates are deliberately not permissions
> and belong elsewhere: the new API ability `read:analytics` is a token-scoping concern
> (`docs/api-specification.md` — new rather than a widening, so already-minted tokens gain nothing), and
> `advanced_analytics` is a plan entitlement, orthogonal to every role in this table.

> **Design Note (G10a)**: `scopes.manage` is the 28th permission, and is deliberately Owner/Admin only
> for the same reason as `forms.collaborators.manage` below — a `scope_nodes` node is something a grant
> can be made *against*, so authoring the hierarchy is authoring authorization structure. It is a new
> permission rather than a reuse of `tenant.settings.manage` specifically because
> `ApiAbilities::ABILITY_TO_PERMISSION` maps the `manage:settings` token ability onto that permission:
> reusing it would retroactively hand every already-minted settings token the authority to author the
> authorization hierarchy the moment G10b ships a write surface. **As of G10b** it maps to the new
> `manage:scopes` token ability (api-specification.md §2.6), alongside `forms.collaborators.manage` —
> a new ability, so no previously-minted token gained anything.
>
> **Design Note (H15a)**: `integrations.manage` is the **29th** permission, Owner/Admin only, and is new
> for the same reason one step sharper. A native connector stores an OAuth credential that lets the
> platform act *inside the tenant's own third-party workspace* (ADR-0009), so folding it into
> `webhooks.manage` would retroactively hand every already-minted `manage:webhooks` token authority whose
> blast radius reaches outside this platform entirely. It maps to the new `manage:integrations` token
> ability (api-specification.md §2.6); no previously-minted token carries it.
>
> **Design Note**: `forms.collaborators.manage` is deliberately restricted to Owner/Admin only — **not** delegable to a form's existing editors — specifically to prevent a Form Editor from adding themselves or others to additional forms they weren't granted access to by an administrator, i.e., a straightforward privilege-escalation vector that would otherwise exist if collaborator management were self-service.

---

## 6. `users` Table

Column-level spec, following `docs/data-dictionary.md`'s exact conventions (UUIDv7 PK, PII methodology, soft-delete/timestamp convention). This satisfies every existing "external — see RBAC doc" forward reference already written into the Data Dictionary: `tenants.owner_user_id`, `forms.owner_user_id`/`created_by`/`updated_by`, `submissions.respondent_user_id`/`validated_by`, `attachments.uploaded_by`, `field_library.created_by`, `form_templates.created_by`, `audits.user_id`, `webhook_endpoints.created_by`, `user_ui_preferences.user_id`, `settings.updated_by`, `feedback_reports.user_id`/`resolved_by`.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | application-generated (`HasUuidv7`) | No | Primary key. |
| `name` | `varchar(150)` | No | — | No | Display name. |
| `email` | `varchar(255)` | No | — | **Yes** | Globally unique. One identity across every tenant membership — never duplicated per tenant. |
| `email_verified_at` | `timestamptz` | Yes | `NULL` | No | Standard Laravel/Fortify verification timestamp. |
| `password` | `varchar(255)` | No | — | No | Hashed. A user invited but not yet registered (§7) has an unusable random hash until they complete signup. ⛔ **It is therefore NOT a signal**: a placeholder's hash is byte-for-byte as real as anyone's, which is what `password_set_at` exists to answer. |
| `password_set_at` | `timestamptz` | Yes | `NULL` | No | **When a HUMAN last chose this password (M76).** The positive signal `identityIsEstablished()` reads first, deciding whether a holder of an invitation token may set a password on this account. NULL on an invite placeholder and on an SSO- or Google-provisioned account, both of which hold a random hash nobody chose. Stamped by `CreateNewUser`, `ResetUserPassword`, `UpdateUserPassword` and `InvitationController` — and by nothing else. ⚠️ **Deliberately absent from `User`'s `#[Fillable]` attribute**, so it can never be reached by mass assignment; every writer uses `forceFill()`. ⚠️ **Not backfilled, by design**: the predicate arm is monotonic, so stamping nobody is safe and no live placeholder can be locked out — see residual 30 in `docs/security-threat-model.md`. |
| `remember_token` | `varchar(100)` | Yes | `NULL` | No | Standard Laravel "remember me" token. |
| `two_factor_secret` | `text` | Yes | `NULL` | No | Laravel Fortify 2FA (PRD Feature #14) — encrypted TOTP secret; `NULL` until the user enrols in two-factor auth. Always redacted in `audits` (Audit Spec §2). |
| `two_factor_recovery_codes` | `text` | Yes | `NULL` | No | Fortify 2FA — encrypted JSON array of one-time recovery codes. Always redacted in `audits`. |
| `two_factor_confirmed_at` | `timestamptz` | Yes | `NULL` | No | Set when the user completes 2FA enrolment (confirms a first TOTP code); `NULL` = not enrolled. |
| `is_super_admin` | `boolean` | No | `false` | No | The explicit platform-wide flag named in the architecture plan §2.1 and ADR-0002 §D3, replacing legacy's fragile `id === 1` convention. Global — not per-tenant, not a Spatie role (see §9 for why). |
| `last_active_tenant_id` | `uuid` | Yes | `NULL` | No | FK `tenants.id`. The tenant to default into on next login / the central app's post-login redirect target, for a user belonging to more than one tenant (§2). Not authoritative for any authorization decision — purely a UX convenience. |
| `tos_accepted_at` | `timestamptz` | Yes | `NULL` | No | Set when this user accepts the platform's own Terms of Service — added per `docs/data-privacy-gdpr-compliance.md` §6's platform-user-consent recommendation. `NULL` for a user mid-invite who hasn't completed signup yet. |
| `privacy_policy_accepted_at` | `timestamptz` | Yes | `NULL` | No | Set when this user accepts the platform's own Privacy Policy — same rationale as `tos_accepted_at`; tracked as a separate timestamp since the two documents can be revised and re-accepted independently. |
| `created_at` | `timestamptz` | No | set by Eloquent | No | — |
| `updated_at` | `timestamptz` | No | set by Eloquent | No | — |
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
| `id` | `uuid` | No | application-generated (`HasUuidv7`) | No | Primary key. |
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
| `created_at` | `timestamptz` | No | set by Eloquent | No | — |
| `updated_at` | `timestamptz` | No | set by Eloquent | No | — |

Unique constraint: `(tenant_id, user_id)` — a person has at most one membership record per tenant, ever (a removed-then-re-invited member reuses/reactivates the same row rather than creating a duplicate).

**Lifecycle:**
1. **Invite**: Admin/Owner invites by email with a chosen role. If the email matches no existing `users` row, a placeholder `users` row is created (unverified, unusable password) so `tenant_users.user_id` always has a valid target; if it matches an existing user, that row is reused directly (this is exactly the mechanism that lets one global identity join multiple tenants). `tenant_users` row created with `status = 'invited'`, `invited_role_id` set, `invite_token` generated.
2. **Accept**: invitee follows the signed invite link, sets a password **only if this identity has never been used**, or signs in as themselves if it has. ⛔ **AMENDED BY M8 (2026-08-20), BECAUSE "if new" WAS THE BUG.** "New" was implemented as `email_verified_at === null`, which is not the same question — an email change nulls that column on an existing, possibly 2FA-enrolled member, and the token holder then got to overwrite their password and was logged in with no challenge. The fork is now `TenantMembershipService::identityIsEstablished()`: a verified address, a confirmed second factor, a linked `google_id`, or a `tenant_users` row this person actually joined (`joined_at IS NOT NULL` — a second PENDING invitation must not count, or a placeholder invited to two workspaces is locked out of both). An established identity is redirected to sign in and returns to the invitation; see `docs/security-threat-model.md` §8 and ADR-0002 §D3's M8 amendment. `status → active`, `joined_at` set, and **only now** is `invited_role_id` materialized into a real `model_has_roles` row (`role_id = invited_role_id`, `tenant_id` = this tenant). Before acceptance, the reserved role grants nothing — an unaccepted invite has zero standing authorization.
3. **Decline**: `status → declined`. No `model_has_roles` row is ever created.
4. **Remove** (by an Admin/Owner): a single transaction — `status → removed`, `removed_at`/`removed_by` set, the corresponding tenant-scoped `model_has_roles` row deleted, and every tenant-scoped Sanctum token for that user revoked. Called out as one atomic operation explicitly because Spatie has no awareness of `tenant_users`'s lifecycle on its own — nothing in the package automatically cleans up role/token state when this application-level table changes; the application must own that transaction.
5. **Ownership transfer** (Owner-only capability, `tenant.ownership.transfer`): updates `tenants.owner_user_id` to the new Owner, changes the outgoing Owner's `model_has_roles` row to `Admin` (never leaves them roleless), and grants the incoming member the `Owner` role — all inside one transaction, logged via `audits` (`event = 'permission_changed'`).

### 7.1 The four doors into a workspace

Membership can be created by four different flows, and **three of the four share one method** —
`TenantMembershipService::attachMember()`. (The fourth, invitation acceptance, transitions a row that
already exists rather than attaching a new member; see the table and the note at the end of this section.) That sharing is the design rather than a refactoring
convenience: the RLS context borrow, the `SET LOCAL` transaction, the seat-quota reservation, the reuse
of a prior `declined`/`removed` row, the `suspended` refusal and the one-role-per-tenant `syncRoles()`
are the same problem every time, and a second implementation would be correct until the day one of them
changed. What differs between the doors is **one string**, recorded in the audit payload as `via`,
because "how did this person get in" is the only question they answer differently and the ledger is the
only place the answer survives.

| Door | `via` | Entry point | Gate on a NEW membership |
|---|---|---|---|
| Invitation | *(no `attachMember()` call — see step 2 above)* | `InvitationController` | The invite itself; an admin named this person |
| Self-registration on a workspace subdomain | `self_registration` | `JoinTenantOnRegistration` (a `Registered` listener) | `RegistrationGate` (via the `GateRegistration` middleware on `/register`) |
| SAML JIT provisioning (P1b) | `sso_jit` | `SsoUserProvisioner` | `sso_connections.jit_provisioning_enabled` |
| **First-party Google sign-in (J3c2)** | **`google_sign_in`** | `GoogleSignInProvisioner` | **`RegistrationGate`** |

**Why Google's gate is `RegistrationGate` and not a new toggle.** SSO asks a per-connection flag because a
workspace administrator configured that trust anchor and can reason about it. Google sign-in has **no
tenant-side configuration at all**, so the question it needs answered — "may somebody who is not yet a
member become one here?" — is exactly the question `/register` already answers. Reusing it means the
button's visibility and the flow's outcome cannot disagree, which is that class's stated reason for
existing. **Stated consequence:** `registration.invite_only` is fail-closed TRUE, so on a default
workspace Google works for existing members and invited people only, and a stranger is refused. That is
correct behaviour, not a defect report.

**Three refusals that are shared, and one that is not.**

- **`suspended` → refused, at every door.** An administrative sanction that a new door quietly reversed
  would be unenforceable in exactly the workspaces most likely to rely on it. Both `attachMember()` and
  each provisioner check it; the duplication is deliberate, so the guarantee holds for future callers.
- **A full seat quota → refused.** ⚠️ But the two provisioners translate the refusal differently and both
  are right. `attachMember()` returns `null`; `JoinTenantOnRegistration` **discards** it (the person keeps
  an account with no workspace, which is what central-host registration already produces), while
  `SsoUserProvisioner` and `GoogleSignInProvisioner` **raise** it, because they are about to establish a
  session and a session with no membership sees an empty product through RLS and reads as data loss. The
  raise happens inside their transaction, so a freshly created account is discarded with it.
- **`invited` → activated at the INVITED role, never at the door's default.** ⚠️ This lives in each
  provisioner and **cannot** live in `attachMember()`, which overwrites `invited_role_id` with whatever
  role it is handed. An admin who invited somebody as an Admin expressed an intent about that person, and
  letting a sign-in door silently demote them would make the invitation surface untrustworthy.
  ⛔ **AMENDED BY M9 (2026-08-24), AND THE LAST SENTENCE ABOVE WAS THE DEFECT.** *"Expressed an intent about
  that person"* is false — an invitation names an **ADDRESS**, `MemberController::invite()` has no
  domain-ownership check at any layer, and `resolveOrCreateUser()` binds the row to that address's existing
  global identity. So this courtesy was an account takeover on the SSO door, **needing no emailed token**,
  and it was strictly stronger than the one M8 closed. `SsoUserProvisioner` now asks
  `TenantMembershipService::identityIsEstablished()` first and refuses an established identity outright, for
  `invited`, `declined` and `removed` alike; the invited-role courtesy survives only for a **never-used
  placeholder**, which is the one case the sentence above was ever true of. See ADR-0016 §D33 and
  `docs/security-threat-model.md` §8. ⚠️ **The `cannot live in `attachMember()`` half is untouched and still
  correct** — that is a mechanism note, not the reasoning that failed.
- **Not shared: what a brand-new member's role is.** SSO uses the connection's `default_role_name`
  (CHECK-constrained to the catalog minus `owner`, because §5 establishes Owner only by ownership
  transfer). Google has no per-workspace setting and uses `viewer`, `joinOpenTenant()`'s reasoning
  unchanged: somebody who arrived holding a consumer account has proved nothing about what they should be
  able to do, and an Owner can promote them from the Members page.

`MemberJoined` fires from inside `attachMember()`'s transaction for every `via`, so the Owner's
notification is identical across the three doors that go through it — the distinction belongs in the audit
ledger, where it is, and not in a bell.

⚠️ **BUT THAT IS THREE DOORS, NOT FOUR, AND THE GAP IS THE INVITATION ONE.** `attachMember()` is the only
dispatch site for `MemberJoined` in the codebase, and `InvitationController` never calls it — an invitee
accepting is a status transition on a row that already exists (§7 step 2), not an attach. So an Owner is
told when somebody self-registers, is JIT-provisioned by their IdP, or signs in with Google, and is told
**nothing** when the person they personally invited accepts — the one door where they had already
expressed interest in that individual by name. Pre-existing rather than introduced by the fourth door, and
recorded here because §7.1's first sentence read as though parity existed: a reader planning notification
work would not discover the gap until testing invite acceptance by hand. Whether to close it is a product
decision (an acceptance is arguably an answer to the Owner's own action rather than news), not an
oversight to be quietly patched.

---

## 8. `resource_grants` + `scope_nodes` — Per-Resource Access Scoping

> **Increment G10a** generalized this section. It previously specified a single-purpose
> `form_collaborators` table; its closing paragraph nominated that table's two-layer composition as
> "the general pattern for any future resource that needs per-instance scoping beyond the tenant
> level", and G10a made that literal. `form_collaborators` was backfilled into `resource_grants` and
> dropped. The authorization SEMANTICS below are unchanged for the direct-per-form case.

These tables are what make the **Form Editor** and **Reviewer** roles real for any specific form. Holding the Form Editor role at the tenant level (via `model_has_roles`) grants **no** access to any particular form by itself — it is a capability class ("this person is allowed to be assigned as an editor somewhere"), not a blanket grant. Owner and Admin bypass this table entirely (`forms.edit.any` / `submissions.review.any` are pure tenant-wide Spatie role checks); Form Editor/Reviewer access is always resolved through a Policy-layer lookup against this table.

### 8.1 `resource_grants`

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | application-generated (`HasUuidv7`) | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK `tenants.id`; denormalized for RLS. |
| `scopeable_type` | `varchar(30)` — PHP enum: `ResourceScopeable` | No | — | No | Morph-map alias: `form` or `scope_node`. Pinned by a DB CHECK generated from the enum, so the RLS guard's per-alias branch set is provably exhaustive. |
| `scopeable_id` | `uuid` | No | — | No | Polymorphic target — **no DB foreign key** (the target table varies by type). Unlike `attachments`, this is an authorization input, so the missing FK is closed at the database by a dedicated policy shape — see the RLS note below. |
| `user_id` | `uuid` | No | — | No | FK `users.id`. |
| `capacity` | `varchar(20)` — PHP enum: `ResourceCapacity` | No | — | No | `editor` or `reviewer` — which per-resource permission (`forms.edit.own` / `submissions.review.own`) this row grants. |
| `includes_descendants` | `boolean` | No | `false` | No | Meaningful only for a `scope_node` target (a CHECK forbids `true` on a form). When set, the grant reaches every form under that node's subtree. Default-off keeps each grant's blast radius legible from the row itself rather than inferred from tree position. |
| `granted_by` | `uuid` | Yes | `NULL` | No | FK `users.id`. The Owner/Admin who granted this access (per §5's restriction that only they can). Was `added_by`. |
| `created_at` / `updated_at` | `timestamptz` | No | set by Eloquent | No | — |

Unique constraint: `(tenant_id, scopeable_type, scopeable_id, user_id)` — a person holds exactly one capacity on a given target at a time (editor *or* reviewer, never both). `capacity` is deliberately **not** in the key, so granting the other capacity UPDATES the existing row; were it in the key, a demotion from editor to reviewer would insert a second row and silently leave edit rights standing.

Hard deletes only, matching the table it replaces: revoke means gone. There is no `deleted_at`.

> **RLS note — why this table does not use the plain strict shape.** A polymorphic column carries no
> foreign key, so under `withTenantIsolation('resource_grants')` an INSERT bearing the attacker's own
> `tenant_id` and another tenant's `form_id` is **accepted by PostgreSQL**. For `attachments` (the other
> morph in this schema) that would merely misfile a row; here the row is an authorization decision input,
> so the same gap is a cross-tenant privilege grant. `TenantIsolation::resourceGrantGuard` therefore adds
> one same-tenant `EXISTS` branch per registered alias to the INSERT/UPDATE `WITH CHECK`. SELECT and
> DELETE stay tenant-only, so a grant orphaned by a hard-deleted target stays readable and revokable.
> It must be the **sole** write policy per command — Postgres OR-combines same-command permissive
> policies, so additionally calling `withTenantIsolation()` would silently restore bare tenant equality.

### 8.2 `scope_nodes`

The tenant's own hierarchy — the generic successor to the legacy PSGC/catchment-area model, carrying
**zero platform semantics**: `name`, `code` and `node_type` are the tenant's own labels, which the
platform stores and displays but never interprets or switches on (PRD §7). A tenant needing Philippine
geography, clinical-trial sites, or sales territories models all three identically.

| Column | Type | Nullable | Default | PII? | Description |
|---|---|---|---|---|---|
| `id` | `uuid` | No | application-generated (`HasUuidv7`) | No | Primary key. |
| `tenant_id` | `uuid` | No | — | No | FK `tenants.id`. |
| `parent_id` | `uuid` | Yes | `NULL` | No | Self-reference. **Composite** FK `(tenant_id, parent_id)` → `(tenant_id, id)`, `ON DELETE CASCADE` — ADR-0002 §D5. A single-column FK would be unsafe: PostgreSQL runs referential actions *bypassing RLS*, so one tenant's delete could cascade into another tenant's rows. |
| `name` | `varchar(150)` | No | — | No | Display label. |
| `code` | `varchar(60)` | Yes | `NULL` | No | The tenant's own opaque code; unique per tenant, never interpreted. |
| `node_type` | `varchar(60)` | Yes | `NULL` | No | The tenant's own level label (e.g. "region"). **Never switched on in PHP** — that is precisely the anti-pattern this table replaces. |
| `path` | `varchar(512)` **`COLLATE "C"`** | No | — | No | Self-inclusive materialized path, `/{root}/…/{self}/`. Derived from `parent_id`; written only by `ScopeNodeService`, and excluded from the model's `$fillable` so no payload can graft a node onto another branch and inherit its grants. The collation is load-bearing: descendant resolution is a constant-prefix `LIKE`, which PostgreSQL compiles into indexable range quals **only** under `COLLATE "C"`. |
| `depth` | `smallint` | No | `0` | No | CHECK 0–12, which bounds `path` inside its `varchar(512)`. |
| `position` | `integer` | No | `0` | No | Sibling ordering. |
| `is_active` | `boolean` | No | `true` | No | Deactivation, used **instead of** a soft delete: a `deleted_at` the authorization resolver must remember to filter is a landmine. The resolver filters `is_active` in both of its query shapes, with tests on each. |
| `created_by` | `uuid` | Yes | `NULL` | No | FK `users.id`. |
| `created_at` / `updated_at` | `timestamptz` | No | set by Eloquent | No | — |

`forms` gains a nullable `scope_node_id` with the same composite-FK shape, plus a PostgreSQL 15+
column-list `ON DELETE SET NULL (scope_node_id)` — a plain composite `SET NULL` would try to null
`tenant_id` too and violate its NOT NULL constraint on every node delete.

Authoring the hierarchy is gated on **`scopes.manage`** (§5), Owner/Admin only: a node is something a
grant can be made against, so authoring the tree is authoring authorization structure.

Re-parenting was rejected outright in G10a, because moving a node must re-path its entire subtree and an
unlocked check-then-act cycle guard races into mutual ancestry that corrupts `path` irreversibly — and
`path` is exactly what authorization reads.

**G10b ships `ScopeNodeService::move()`.** Its concurrency model, in order:

1. A transaction-scoped **advisory lock keyed on the tenant** serializes moves within a workspace. Locking
   only the node and target parent is *not* sufficient: a concurrent move of an *ancestor* of the target
   re-paths the target without locking it, so the first mover computes its new prefix from a path that is
   already stale. There is no bounded set of rows the second mover could lock instead short of the
   target's whole ancestor chain and the node's whole subtree. This is the only advisory lock in the
   codebase — every other service serializes on a row — because it is the only operation whose
   correctness spans an unbounded row set rather than one aggregate root.
2. Both endpoints are re-read `FOR UPDATE` and all decisions are made on the **re-read** rows, never the
   arguments. This also blocks a concurrent rename/deactivate of the two nodes.
3. The cycle check is one prefix test (`path` is self-inclusive, so it covers "onto itself" too). From
   G10b on this — not `scope_nodes_no_self_parent`, which only ever covered the single-node case — is what
   prevents a longer cycle.
4. The depth cap is measured across the whole **subtree**: a shallow node can carry a deep one.
5. The re-path is a **single** `UPDATE` over the constant-prefix `LIKE`, so it is atomic, index-served by
   `(tenant_id, path)` under `COLLATE "C"`, and costs one statement regardless of subtree size.

Two CHECK constraints added in G10b make an incorrectly re-pathed row impossible regardless of which
writer produced it — Eloquent, the query builder, raw SQL, or a future migration:
`scope_nodes_path_self_suffix_chk` (a path ends with its own id) and `scope_nodes_depth_matches_path_chk`
(`depth` equals the path's segment count). The second also forces `path` and `depth` to be written in the
same statement. `ScopeNode::booted()` is kept unchanged and still rejects any *model-level* write to
`parent_id`/`path`/`depth`; `move()` does not need an exemption from it because a query-builder statement
fires no model events.

Deleting a node cascades to its descendants and `SET NULL`s their forms' `scope_node_id`. Grants on the
deleted subtree are removed **in the same transaction** — `resource_grants` is a morph, so it has no FK to
cascade through, and leaving them would manufacture invisible authorization rows on every delete.

### 8.3 Authorization composition

How §5's `.any`/`.own` split is actually enforced. A `FormPolicy::update($user, $form)` check is:
```php
return $user->can('forms.edit.any')
    || ($user->can('forms.edit.own')
        && $this->grants->holds($user, $form, ResourceCapacity::Editor));
```
`$user->can()`, never `hasPermissionTo()` — the latter THROWS `PermissionDoesNotExist` when the catalog
is unseeded, and policies are reached from `HandleInertiaRequests::share()` on every response, including
off-tenant where no catalog or team is set.

`ResourceGrantResolver` is the single place `resource_grants` is interpreted. It answers both the
single-row question (`holds`) and the list-scoping one (`grantedFormIdsQuery`, used by
`Submission::scopeVisibleTo`) from one loaded grant set, so a policy check and an inbox query cannot
drift into "a row appears in the list but 403s when opened". A user reaches a form either by a direct
grant naming it, or by a grant on the node it is assigned to — and, when that grant sets
`includes_descendants`, by a grant on any ancestor of that node.

Resolution runs **downward from the grant** (`scope_nodes.path LIKE :granted_path || '%'`), never upward
from the form with a leading-wildcard substring: a leading `%` is unindexable by any btree, and this
predicate sits inside the submissions inbox query. Cost is bounded by the user's *grant count*, never by
descendant count — no descendant id set is ever materialized (PostgreSQL caps bind parameters at 65535,
and a region-level subtree in a PSGC-shaped tree is ~44,000 nodes).
`SubmissionPolicy`'s review check follows the identical shape against `capacity = 'reviewer'` and `submissions.review.own`. This two-layer composition — a coarse Spatie permission ("can this role ever do this action, in principle") plus a fine Policy-layer resource check ("does a grant cover *this specific* resource") — **is** the general pattern for per-instance scoping beyond the tenant level. Increment G10a made it literal: adding a third scopeable type is one `ResourceScopeable` case plus one migration, never a `switch`.

> **One deliberate semantic change in G10a.** `SubmissionPolicy::create` (manual encoding) now requires **editor** capacity, where it previously accepted either. Encoding is an authoring act, and once a grant can name a subtree, a reviewer grant on an interior node would otherwise confer write access to every form beneath it.

### 8.4 Who may hand out access (G10b)

Until G10b there was no writer of a grant other than `FormService::create` (always editor, always the
creator), so `forms.collaborators.manage` and `FormPolicy::manageCollaborators` were defined but
referenced nowhere — **a Reviewer could not be scoped to a form in production at all**.
`ResourceGrantService` is that writer, and `ResourceGrantPolicy` gates it with three rules:

1. **Base gate** — `forms.collaborators.manage` (Owner/Admin only, per the design note in §5).
2. **No self-grant.** Without it, "may manage collaborators" silently means "may give myself any capacity
   on any resource". Self-*revoke* stays allowed: it can only reduce what the actor holds.
3. **Anti-amplification** — the actor must hold the **`.any`** counterpart of the capacity being handed
   out (`ResourceCapacity::anyPermission()`: editor → `forms.edit.any`, reviewer →
   `submissions.review.any`). A grant activates the `.own` half of a pair for its holder, so the granter
   must already hold the tenant-wide half.

| Actor holds | grant editor | grant reviewer | self-grant | revoke |
|---|---|---|---|---|
| Owner / Admin (both `.any`) | ✓ | ✓ | ✗ (rule 2) | ✓ |
| Form Editor / Reviewer / Viewer | ✗ (rule 1) | ✗ (rule 1) | ✗ | ✗ |
| `collaborators.manage` + `forms.edit.any` only | ✓ | ✗ (rule 3) | ✗ | ✓ |
| `collaborators.manage` + `submissions.review.any` only | ✗ (rule 3) | ✓ | ✗ | ✓ |

**Rule 3 is unreachable under the shipped 5-role matrix** — Owner and Admin are the only holders of
`forms.collaborators.manage` and both hold each `.any` permission. It is stated here, and tested against
directly-constructed permission subsets rather than seeded roles, so it does not get filed as dead code
the way `forms.collaborators.manage` itself was. It is what stops any future custom role, or any
delegation of collaborator management, from quietly becoming "may mint any authority".

A separate service-level guard refuses a grant to someone who is not an **active** member: the resolver
counts only active members' grants, so such a row would be written, listed in the UI, and confer nothing.
In practice the `users` visibility RLS policy refuses first (an outsider is invisible, so the write 404s
without confirming the account exists); the guard is the backstop if that policy ever widens.

> **Design Note — relationship to `forms.owner_user_id`**
> `docs/data-dictionary.md` §2 already defines `forms.owner_user_id` as "the form's business owner (dashboard scoping)." That column is retained as-is and continues to mean **attribution** — whose name shows as the form's primary point of contact, and the default assignee dashboard-scoping falls back to — but it is no longer the sole gate on who may edit the form. A form's creator is expected to also receive a `resource_grants` row targeting that form with `capacity = 'editor'` at creation time (so the common case of "I built it, I can edit it" keeps working without extra clicks), but that is now an ordinary grant like any other, not a special-cased check against `owner_user_id` in the Policy layer.

---

## 9. Super-Admin Design

Elaborates ADR-0002 §D3's isolation-focused mention of the super-admin carve-out with the actual platform-operations surface:

- **Console scope**: tenant list/suspend/reactivate (`tenants.status`), platform-level `settings` rows (`docs/data-dictionary.md` §20's `tenant_id IS NULL` rows), cross-tenant billing reconciliation view, the internal `feedback_reports` (§21) review queue, and cross-tenant Audit Log search for support investigations.
- **`is_super_admin` stays a plain boolean, never a Spatie role.** Making it a team-scoped role would reintroduce exactly the ambiguity teams-mode exists to avoid — a role is inherently "scoped to a team," and super-admin is by definition *not* scoped to any one tenant. This mirrors ADR-0002 §D3's own reasoning for why the flag exists as a column rather than a positional/derived convention in the first place.
- **Every super-admin action routes through one narrow, named service layer** using ADR-0002 §D3's separate elevated Postgres role (`current_setting('app.is_superadmin_context', true) = 'true'`) — never an ad hoc `if ($user->is_super_admin)` branch scattered through ordinary controllers. Every such action is logged via the carried-forward `Auditable` trait, exactly as ADR-0002 §D3 already requires.

**Implementation status (Increment B2c, 2026-07-05).** The mechanism is now built. The elevated role is a dedicated **non-superuser / NOBYPASSRLS `meridian_superadmin`** login role on its own `pgsql_superadmin` connection (mirroring B1's `meridian_auth`) — deliberately *not* the superuser seeding role, so RLS still applies to it and defense-in-depth holds. `App\Support\Tenancy\TenantIsolation::superAdminBypass*` emits an **additive, role-scoped, GUC-gated permissive policy** (`TO meridian_superadmin USING (current_setting('app.is_superadmin_context', true) = 'true')`) layered on a table that already has its base RLS+FORCE; it is applied to `users` now (`GRANT SELECT` + the policy — plus a `GRANT SELECT ON tenant_users` so the `users` visibility policy's membership-join subquery is evaluable for the role). `App\Support\Tenancy\SuperAdminContext` opens the GUC **transaction-locally** (`is_local = true`) on the elevated connection, and `App\Services\Admin\SuperAdminService` is the single place it is ever opened (tenant list/suspend/reactivate run on the ordinary connection — `tenants` is RLS-exempt — while cross-tenant user reads go through the elevated path). **Mandatory MFA** (below, security-threat-model §8) is enforced by the `superadmin` + `superadmin.mfa` middleware on the central-domain `/admin` console. **`Auditable` logging of super-admin actions is deferred to Phase 1** (no `audits` table exists until then) — carried as an explicit `TODO(audits, Phase 1)` in `SuperAdminService`, per the Q2 decision below.

**Implementation status (Increment I7a, 2026-08-07) — the `feedback_reports` review queue is built, and it is the first console surface whose READ and WRITE take different routes.** `/admin/feedback` lists every tenant's reports through a **SELECT-only** carve-out (`applySuperAdminBypass('feedback_reports', ['SELECT'])` + the matching `GRANT`), while the New → Reviewed → Resolved transitions deliberately do **not** elevate: the console already knows the report's tenant, so `SuperAdminService::transitionFeedback()` adopts that tenant's context on the ordinary connection (the H4 pattern `changeStatus()`/`assignPlan()` established). That is what makes §9's own rule concrete — the requirement is *route through the one service*, not *elevate every operation* — and it is also what delivers decision 2 below: because the write is unelevated, **its audit row lands in the reporting workspace's own ledger**, where that workspace can read it. An UPDATE bypass would have written `tenant_id = NULL` and made the operator's handling of a tenant's report invisible to that tenant, which is the transparency posture inverted.

Two implementation notes that generalise to every future console surface over a tenant-scoped table:

- **The RLS carve-out is only half the job.** `BelongsToTenant` independently adds `where tenant_id = <context ?? sentinel>` to every Eloquent query, and the console runs on the central host with no context — so a cross-tenant read must ALSO call `withoutGlobalScope($tenantScope)`. Omitting it returns an empty list with no error. `listAllUsers()` never needed it only because `users` has no tenant column.
- **Route-model binding cannot be used.** Binding resolves on the app connection, which likewise has no tenant context there, so every valid id 404s. Console routes over RLS-protected tables take a raw uuid and resolve through the service.

**Implementation status (Increment I7b, 2026-08-08) — the workspace detail page and the PLATFORM audit view are built; cross-tenant audit SEARCH is not, and that is a decision rather than a gap.** `GET /admin/tenants/{tenant}` finally surfaces the plan-assign route that had existed since H5a with no UI, alongside usage and custom domains, all read by adopting the affected tenant's context rather than by elevating (eight RLS-scoped tables reached with one `SET LOCAL` instead of eight new GRANTs). `GET /admin/audit-log` reads the `tenant_id IS NULL` slice that I5 had been writing since 2026-08-06 with no reader.

**The console-scope bullet above promises "cross-tenant Audit Log search for support investigations". I7b deliberately does NOT deliver it.** It was available in a single line — `applySuperAdminBypass('audits', ['SELECT'])` — and was rejected: that helper's gate is unrestricted, so it would have handed the platform operator every tenant's complete history (every form title, every reviewer's `returned_reason`, every membership change in the deployment) in order to display a handful of platform-settings rows. It also inverts this section's own posture, which assumes a tenant-affecting action is readable *by the affected tenant*, not accumulated in a platform console. The narrowed policy `audits_platform_select` (`… AND tenant_id IS NULL`) is what shipped instead, and `PlatformAuditRlsTest` asserts in both directions: a tenant cannot read a platform row, and the operator cannot read a tenant row.

A third note joins the two above, and it is the one this increment adds:

- **A console read over a table whose base policy is NOT nullable-global needs a carve-out narrowed to the slice it actually serves, not the generic bypass.** The unrestricted gate is a sensible default only where the platform slice is already public; on a strict or append-only table it is a silent, enormous widening. Use `TenantIsolation::platformRowsBypass()` (ADR-0002 §D3's I7b amendment), and name the policy outside the `*_superadmin_*` prefix so a `pg_policies` sweep for unrestricted reads stays honest.

**Per-entity search visibility (Increment J1b, PRD §3.7).** Global search coins **no new permission key** — the catalog stays closed at 29. Each entity is one arm behind an interface, and each arm reuses the gate its own list page already uses, so "what may I find" can never drift from "what may I open". Three rules govern the table:

1. **The arm gate runs first.** A refused arm is **ABSENT from the response**, never rendered as a group with zero results — a `0` is a claim about how much exists, an absent key is the truth. This is binding on every search surface (`docs/ux/design-system-reference.md` §3.4.1).
2. **Counts are computed after permission filtering**, from the same builder as the rows (`SearchCountLeakTest`). A badge reading "3" over a list of 1 discloses that two invisible rows exist, which is a leak with no row leaving the database.
3. **The row rule is the policy's, never analytics'.** `Form::scopeVisibleTo()` is the list twin of `FormPolicy::view()` and is pinned to it in both directions by `FormVisibilityScopeTest`.

| Entity | Arm gate | Row rule | Owner / Admin | Form Editor | Reviewer | Viewer |
|---|---|---|---|---|---|---|
| Forms | `viewAny` on `Form` (`forms.create` \| `.edit.any` \| `.edit.own`) | `Form::scopeVisibleTo()`, Editor capacity, non-archived | ✓ every non-archived form | ✓ editor-granted only | ✗ **arm refused** | ✗ **arm refused** |
| Submissions | `viewAny` on `Submission` | `Submission::scopeVisibleTo()` + `countable()`; matches own vector, the FORM's title, its 8-char `reference` (exact), or its full id (exact) | ✓ every non-draft | ✓ granted forms | ✓ granted forms | ✓ per `submissions.view` |
| Members *(J1c)* | `tenant.members.invite` — the key `/members` itself uses | `whereExists` over `tenant_users (tenant_id, status='active')` on the **default** connection; `ILIKE` over name + email | ✓ active roster | ✗ **arm refused** | ✗ **arm refused** | ✗ **arm refused** |
| Settings & pages *(J1c)* | — (never refused) | a static catalog, filtered per row by `ShellAbilities` + the plan feature — the same pair `Sidebar.vue` reads | ✓ every reachable page | ✓ theirs | ✓ theirs | ✓ theirs |
| Audit rows | — | — | ✗ **not an entity** | ✗ | ✗ | ✗ |

⚠️ **Pending invitations are NOT searchable, and that is a decision with a measurement behind it.** The join-shape policy admits only `tu.status = 'active'`, and RLS applies at *every* reference to `users` — so a `tenant_users`-first join does not rescue an invited row either. Verified against the seeded corpus: joining `tenant_users` to `users` under one tenant's context returns its six active members and drops the invited one, whose `tenant_users` row is perfectly visible. Pending invites stay visible on `/members`, which already holds their identities. **If that ever needs to change, the fix is a policy decision about `users_visibility` — never a connection hop**, because widening the policy would change every `users` read in the product (feedback reporter names, audit actors, ownership transfer) in order to serve one search surface.

⚠️ **Audit rows are not on this list, and adding them would be a widening rather than a completion** — see the refusal recorded immediately above. The same reasoning that rejected `applySuperAdminBypass('audits', ['SELECT'])` for the console applies to a tenant-side search arm: a keyword search over `old_values`/`new_values` is a channel over precisely the data `AuditRedactor` exists to remove, and `AuditableTypes::label()` fails open, so a newly-registered alias is un-redacted until someone remembers to add it. `App\Enums\SearchEntity`'s docblock carries the same warning at the code.

⚠️ **THE STANDING RULE, ADDED BY J1c AND BROADER THAN SEARCH: no user-supplied predicate may ever run on `pgsql_auth`.** `users_auth_select … USING (true)` (§6's fourth RLS shape) exists to let the pre-auth login path resolve an identity with no tenant context, and on that connection there is no tenant boundary of any kind — `users` has no `tenant_id` column at all. `TenantMembershipService::listMembers()` is safe there only because its id set is derived from an RLS-bounded `tenant_users` read *before* the connection hop; it adds no predicate of its own. A search does the opposite. Measured on the seeded corpus rather than argued — one tenant's admin running `email ILIKE '%o%'`:

| Connection | Rows | Includes |
|---|---|---|
| `pgsql_auth` | 8 | **`owner@northwind.test` — another tenant's user** |
| default (app) | 6 | that tenant's active members only |

`MemberSearchArm` therefore runs on the default connection, where the join-shape policy *is* the boundary, and carries its own `whereExists` over `tenant_users` as well. `SearchMemberConnectionTest` pins the rule three ways: a runtime `QueryExecuted` guard, a comment-stripped source assertion on the arm, and a directory sweep over the whole search namespace that will catch an arm nobody has written yet.

⛔ **AMENDED BY M8 (2026-08-20) — THIS PARAGRAPH USED TO CARRY A FOURTH GUARANTEE, AND IT IS NO LONGER TRUE.** It argued that `meridian_auth` is granted `SELECT, UPDATE` on `users` **and nothing else**, so the arm's `whereExists` over `tenant_users` *"cannot execute on the pre-auth connection at all, which turns a would-be silent cross-tenant leak into a hard failure"*. Migration `2026_08_17_000107` grants that role `SELECT ON tenant_users`, because `InvitationController` has to ask whether an invitee's identity has ever joined a workspace **before anybody is authenticated**, and the only positive record of that lives in another tenant's rows. **So the accident that made a wrong connection fail LOUDLY is gone: an arm hopped to `pgsql_auth` would now succeed — silently — and return every tenant's members.** Nothing is left holding that line except the three pins above, which is exactly why they are structural rather than outcome-based, and why the rule at the head of this section — **no user-supplied predicate may ever run on `pgsql_auth`** — is now the whole of the guarantee rather than half of it. The new grant is SELECT-only and role-scoped, and its single consumer matches a server-derived uuid; ADR-0002 §D3's M8 amendment records why the invitation question was judged worth spending it on.

### 9.1 The list-page keyword filters (J1e)

Every row-list page takes a `?q`. These are not search *arms* — they narrow a list the viewer is already authorized to be looking at — so they coin no permission and change no gate; the route's existing `can:` middleware and the presenter's existing row rule both apply first, unchanged. Three of them are nonetheless authorization-relevant enough to record here.

**The members roster filters in PHP, over rows the `pgsql_auth` hop has already bounded.** This is §9's standing rule arriving one increment later on the same method: `listMembers()` is safe on that connection only because it adds no predicate, and a keyword *is* a predicate. `TenantMembershipService::listMembers(Tenant, ?SearchTerms)` therefore builds its rows exactly as before and applies `SearchTerms::matchesAny()` to each one afterwards. The candidate set is one roster — tens to low hundreds — so the scan is the honest shape, not a compromise. `MembersRosterFilterTest` asserts this **structurally**: it listens on `pgsql_auth` and fails if the user's text ever appears in a binding there. An outcome-only test would pass just as happily against an implementation that fetched every tenant's matching users and filtered the leak out in PHP afterwards.

**Its one deliberate asymmetry with the search arm, which runs the safe direction.** The roster's `q` **finds pending invitations**; `MemberSearchArm` structurally cannot (the measurement two paragraphs up). Both are correct: `/members` has already fetched and rendered those identities, so filtering the list it is showing discloses nothing new — while global search would have to go and fetch them. Pinned in both directions so neither half can be "made consistent" by accident.

**The audit log's `q` narrows to target and actor, and never reads the diff.** It resolves to `(auditable_type = 'form' AND auditable_id IN (matching forms)) OR user_id IN (matching users)`. The refusal above — that a keyword over `old_values`/`new_values` is a channel over exactly what `AuditRedactor` removes — is *why*, and it holds whether the search is a global arm or a filter on the page itself. Two properties keep it that way rather than leaving it to discipline:

  - The forms and users subqueries confine the non-leakproof operators (`@@`, `ILIKE`) to `forms` and `users`; every predicate on `audits` stays `=`/`IN` over plain columns, which is also what keeps `audits_tenant_auditable_idx` and `audits_tenant_user_idx` reachable under RLS (J1b's leakproof measurement, applied forward).
  - The users subquery runs on the **default** connection, per the standing rule.

  `AuditKeywordFilterTest` seeds a distinctive token into a diff and requires it to be unfindable *while* a target-title search still works — so the refusal cannot degrade into a dead parameter unnoticed.

**Resolved decisions (2026-07-05, decided with the product owner rather than silently picked):**

1. **Impersonation — deferred.** The platform console does **not** support "log in as this user" in Phase 0. It is a large security surface and would need an `acting_as_user_id`-style `audits` column (and the `audits` table itself, which does not exist until Phase 1) to keep a super-admin's own actions distinguishable from actions taken while impersonating. Revisit when support tooling genuinely requires it; design the `acting_as_user_id` column alongside the Phase-1 `audits` table if so.

   > **AS BUILT (I11a then I11b, 2026-08-09) — THE PRECONDITION AND THE FEATURE ARE BOTH SHIPPED.** ⚠️ This line read *"the feature is NOT BUILT YET"* until the pre-merge review of the integration PR, which is the branch that ships impersonation — the document denied a feature its own merge delivers, which is the failure mode this corpus has an ADR-numbering incident to show for. I11b built the cross-host single-use token hand-off described below, the banner, the exit path and the 30-minute cap (`EnforceImpersonationTimeout`); the four decisions of record recorded two paragraphs down are all implemented. What follows is kept as written because it is still the reasoning, not a status. `audits.acting_as_user_id` exists, is written by `AuditLogger` on every row, and is read by both viewers, the CSV/XLSX export and `/api/v1`. It landed **before** I11b's actual impersonation, deliberately: a release in which the ledger could be lied to but not record the lie is the window `security-threat-model.md` §8 describes, and shipping the column first closes it by construction.
   >
   > ⚠️ **This entry is a DEFERRAL, not a specification, and reading it as one is a trap I11b must avoid.** The single sentence above is the *entire* impersonation requirement in this repository — a grep for `impersonat` across `docs/`, the PRD, the backlog, the ADRs and the threat model returns nothing else normative. Actor eligibility, a banner, the exit path, session TTL, read-only vs read-write, and notifying the tenant were all **unspecified**, and were therefore decided with the product owner on 2026-08-09 rather than inferred: **full read-write impersonation, fully audited; any ACTIVE tenant member may be impersonated but never another super-admin and never yourself; the affected tenant sees it in their OWN `/audit-log` and the Owner is notified through I3's notification substrate.** Those four are decisions of record — do not re-derive them.
   >
   > The structural constraint I11b inherits, and the one that shapes everything: `SESSION_DOMAIN` is null, so **the session cookie is host-only** (`routes/tenant.php` records the correction). A super-admin authenticated on the central host has *no* session on `acme.…`, so impersonation cannot be a session flip — it is a cross-host, single-use, TTL'd token handoff. stancl/tenancy ships a `UserImpersonation` feature for exactly that shape, but it is commented out in `config/tenancy.php` and is not adoptable as-is: it calls `loginUsingId()` and leaves no trace of who the operator really was, which is precisely the failure this column exists to prevent.
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
- [ ] Any endpoint touching a per-form resource resolves the Policy-layer grant check (§8) in addition to the tenant-wide Spatie permission — **the other RBAC-specific layer this document adds**. Go through `ResourceGrantResolver`, never a hand-rolled `resource_grants` query: it is what keeps a single-row policy check and a list-scoping query in agreement, and it alone knows that a grant may name a `scope_nodes` subtree rather than the form itself. An endpoint that checks only `forms.edit.own` without the grant lookup would incorrectly grant every Form Editor access to every form in the tenant.

**Every new queued job touching tenant-scoped or RBAC-scoped data:**
- [ ] `tenant_id` serialized into the job payload (ADR-0002 §D3 jobs layer).
- [ ] If the job performs an authorization-sensitive action (e.g., an automated role change), it re-establishes both tenant context *and* the permissions team on execution — not just the former.

**Every new super-admin action:**
- [ ] Routed through the narrow service layer (§9), never an inline `is_super_admin` branch.
- [ ] Logged via `Auditable`.

---

## 11. Out of Scope / Deferred

- Detailed OpenAPI request/response shapes for `/api/v1/users`, `/api/v1/roles` → Doc #14 (API Specification).
- ~~SSO/SAML → Phase 4 (architecture plan §3).~~ **BUILT (P1a–P1c, ADR-0016)** — a per-tenant SAML 2.0 Service Provider, SP-initiated only, Enterprise-gated. §7.1 above already describes JIT provisioning as one of the four doors, so this line was contradicting its own document. Its threat surface is `docs/security-threat-model.md` §8.
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

resource_grants.tenant_id              -> tenants.id
resource_grants.scopeable_id           -> forms.id | scope_nodes.id   (polymorphic; NO db-level FK,
                                                                       guarded by RLS — see §8.1)
resource_grants.user_id                -> users.id
resource_grants.granted_by             -> users.id                    (nullable)

scope_nodes.tenant_id                  -> tenants.id
scope_nodes.(tenant_id, parent_id)     -> scope_nodes.(tenant_id, id) (nullable; COMPOSITE, ADR-0002 §D5)
scope_nodes.created_by                 -> users.id                    (nullable)

forms.(tenant_id, scope_node_id)       -> scope_nodes.(tenant_id, id) (nullable; COMPOSITE)

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
