<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Tests\Feature\Tenancy\ConstraintBoundaryDriftTest;

/**
 * Every constraint that crosses a tenant boundary, and why it is allowed to (Phase 4, P2c — ADR-0002 §D5).
 *
 * §D5 is one sentence: "No foreign key, unique constraint, or index may span tenant boundaries", because a
 * global constraint "would become a real obstacle to the future per-tenant extraction path". That path
 * stopped being hypothetical when P2b shipped `tenants:extract`, so the rule stopped being aspirational.
 *
 * ── WHY A CONSTRAINT CROSSING THE BOUNDARY IS NOT AUTOMATICALLY CAUGHT BY RLS ───────────────────────────
 * This is the fact the whole class turns on, and it is easy to get backwards. PostgreSQL documents that
 * "referential integrity checks, such as unique or primary key constraints and foreign key references,
 * always bypass row security to ensure that data integrity is maintained." So:
 *
 *   * A **unique index** without `tenant_id` is enforced across every tenant's rows at once. Tenant A can be
 *     refused a value because of a row tenant A cannot see — an existence oracle, and a denial of service.
 *   * A **foreign key** without `tenant_id` accepts a reference to another tenant's row. RLS hides that row
 *     from every SELECT the application makes and permits the reference anyway. On an `ON DELETE CASCADE`
 *     edge it is worse than a stale pointer: deleting the neighbour's parent deletes THIS tenant's child.
 *
 * `docs/data-dictionary.md` already states this for `sso_auth_requests`, whose FK is composite precisely
 * "because referential actions bypass RLS". This class is that reasoning applied to the other 35 edges.
 *
 * ── WHY A HAND-MAINTAINED CONSTANT, AND NOT A DERIVED ONE ───────────────────────────────────────────────
 * The same argument {@see TenantScopedTables} makes, and it holds harder here. Whether a boundary crossing
 * is acceptable is a DECISION about what the column means — `domains.domain` is globally unique because two
 * tenants cannot both own a hostname, and no query can tell that apart from an author who forgot
 * `tenant_id`. Deriving the list would answer "which constraints cross the boundary" (a fact, and the
 * drift test asks the catalog exactly that) while silently answering "and that is fine" for all of them.
 *
 * ── WHAT IS DELIBERATELY OUT OF SCOPE ───────────────────────────────────────────────────────────────────
 * **Tables with no `tenant_id` at all** — `users`, `tenants`, `plans`, `failed_jobs`, `user_ui_preferences`
 * and the framework's own plumbing (ADR-0002 §D1's enumerated exemptions). A table with no tenant dimension
 * has no boundary to span, so `users_email_unique` is not an unrecorded exception; it is not a candidate.
 * Stated here rather than left implicit, because a reader who finds `users.email` missing from the list
 * below should be able to see that it was considered.
 *
 * **Primary keys**, and this exclusion is the one a reader is most entitled to challenge, because the
 * PostgreSQL sentence quoted above names "unique **or primary key** constraints" in the same breath. **39
 * tenant-carrying tables have a primary key of `(id)` alone**, so by the predicate above they are 39 more
 * boundary-crossing unique constraints. They are excluded because every one of those keys is a **UUIDv7**
 * assigned by `HasUuidv7` before insert: a cross-tenant collision requires generating the same 128-bit
 * value twice, not merely choosing the same natural key, so the existence oracle and the denial of service
 * both evaporate. That is the same argument this file already makes for
 * `submission_answer_index_submission_id_form_field_id_unique` below — stated here too rather than left to
 * be inferred, since a reader who counts 39 unrecorded primary keys cannot otherwise tell whether somebody
 * reasoned about them or nobody looked. ⚠️ It would stop holding for an integer or natural primary key,
 * which is why ADR-0002 §D1 fixes `tenant_id` and every key as a UUID in the first place.
 *
 * **Non-unique indexes.** §D5's "or index" clause is discharged by §D1's rule that `tenant_id` leads a
 * composite index on a tenant-scoped table — a performance property, not an isolation one, since a plain
 * index constrains nothing and cannot refuse or cascade anything. Ten such indexes do not lead with
 * `tenant_id` today (`submission_geo_index_geom_gist` and the polymorphic morph indexes among them); they
 * are recorded in `docs/feature-backlog.md` rather than gated here, because gating them would put ten
 * entries with no isolation consequence into a list whose whole value is that every entry has one.
 *
 * ⚠️ AN ENTRY HERE IS A CLAIM THAT SOMEBODY REASONED, NOT THAT SOMEBODY LOOKED. The drift test enforces a
 * length floor on every reason for the same purpose `TenantExtractColumns` does: "legacy", "safe" and
 * "pre-existing" are labels, and a label is indistinguishable from a shrug.
 *
 * @see ConstraintBoundaryDriftTest for the catalog drift in both directions
 */
final class ConstraintBoundaries
{
    /**
     * Unique indexes on a `tenant_id`-carrying table whose KEY COLUMNS do not include `tenant_id`.
     *
     * ⚠️ KEY COLUMNS, NOT THE INDEX DEFINITION TEXT. This list used to live inside the drift test, which
     * filtered on `pg_get_indexdef(...) not like '%tenant_id%'` — and that matches the WHERE clause as
     * readily as the key. `settings_platform_key_unique` is `ON settings (key) WHERE (tenant_id IS NULL)`:
     * a bare global unique on a tenant-carrying table that the old sweep never reported, because the two
     * words it was looking for appear in its predicate. It is the thirteenth entry below and it was found
     * by fixing the query, not by reading the schema.
     *
     * @var array<string, string>
     */
    public const array UNIQUE_EXCEPTIONS = [
        // ── Globally unique by definition: the value names something outside any one tenant ──────────────
        'domains_domain_unique' => 'A hostname is globally unique BY DEFINITION — two tenants cannot both own '
            .'`forms.acme.com`, and DNS is the authority saying so. Scoping it per tenant would make the '
            .'uniqueness meaningless and let a second workspace claim a live host.',
        'domains_verification_token_unique' => 'The DNS challenge token proving control of a hostname '
            .'(ADR-0012). It is matched from an unauthenticated verification poll before any tenant is '
            .'resolved, so a per-tenant index could not be consulted at the moment it is needed.',

        // ── Matched before a tenant is known, so a per-tenant index would be unreadable at lookup time ───
        'google_auth_requests_state_id_unique' => 'Google sign-in\'s OAuth state, matched on the CENTRAL host '
            .'from an unauthenticated callback (ADR-0019, first-party Google sign-in — that document was '
            .'0017 until the two lanes\' collision was resolved by renumbering it). A cross-tenant collision '
            .'would give the lookup a second row it could legitimately match, which is ambiguity exactly '
            .'where the flow must be unambiguous.',
        'google_auth_requests_handoff_hash_unique' => 'The single-use handoff token\'s SHA-256, presented by a '
            .'browser holding nothing but a URL, so again no tenant context exists at lookup. It is also a '
            .'secret, and falls under the collision-is-an-auth-bug argument below.',
        'sso_auth_requests_request_id_unique' => 'The SAML `InResponseTo` this SP mints. An identity provider '
            .'correlates on it and has no notion of our tenants, so the value must be unique in the only '
            .'namespace the IdP can see — this installation as a whole.',

        // ── Secrets: global uniqueness is the property being bought, not a boundary being crossed ────────
        'impersonation_tokens_token_hash_unique' => 'A credential hash. A token that collides across tenants '
            .'is a cross-tenant authentication bug, so global uniqueness is the guarantee wanted here rather '
            .'than an oversight — scoping it per tenant would permit exactly the collision that must not exist.',
        'personal_access_tokens_token_unique' => 'The hashed API token, presented on requests that carry no '
            .'tenant context until the token itself resolves one. Same argument as above in both halves: '
            .'unreadable per-tenant, and a collision would authenticate the wrong workspace.',
        // ── Safe on today's DATA, and nothing holds that data in place ───────────────────────────────────
        'permissions_name_guard_name_unique' => 'The 29 permission keys are PLATFORM rows with a NULL '
            .'`tenant_id` — the product\'s own vocabulary, identical for every tenant (ADR-0002 §D1) — so '
            .'no two tenants contend for a name today. ⚠️ THAT IS A FACT ABOUT THE SEEDED ROWS, NOT ABOUT '
            .'THIS INDEX, AND `permissions` IS NOT `roles`. `permissions_tenant_insert` exists with the '
            .'same `with_check (tenant_id = current_setting(...))` shape `roles_tenant_insert` has, and '
            .'`meridian_app` holds INSERT on the table — so a tenant-owned permission row is insertable by '
            .'the ordinary application connection right now. `roles` survives that because its unique is '
            .'`(tenant_id, name, guard_name)`; THIS one is `(name, guard_name)`, so the moment two tenants '
            .'define a permission with the same name the second gets a 23505 against a row it cannot see — '
            .'the existence oracle in this class\'s own header. The asymmetry is the defect, and the fix is '
            .'to make this index match the one on `roles`; it is recorded here rather than changed because '
            .'P2c ships no migration. Revisit trigger: the first non-NULL `permissions.tenant_id`.',

        // ── Anchored on a tenant-scoped UUID, so a collision is unreachable without guessing one ─────────
        'submission_answer_index_submission_id_form_field_id_unique' => 'One projection row per queryable '
            .'field per submission. Both key columns are UUIDv7 primary keys of tenant-scoped tables, so a '
            .'cross-tenant collision requires guessing a UUID rather than merely colliding on a natural key. '
            .'⚠️ What makes this safe is UUID unguessability, NOT referential integrity: both FKs are '
            .'single-column and appear in self::FOREIGN_KEY_EXCEPTIONS, so the database would accept a '
            .'cross-tenant reference if the application ever offered one.',
        'submission_geo_index_submission_id_form_field_id_unique' => 'The PostGIS twin of the row above, with '
            .'the identical key shape, the identical UUID argument and the identical caveat that its two FKs '
            .'are single-column and are excepted below rather than enforcing the boundary.',
        'webhook_deliveries_endpoint_event_unique' => 'The delivery idempotency guard, partial on '
            .'`webhook_endpoint_id IS NOT NULL`. Both columns are UUIDs and the endpoint\'s own FK to '
            .'`webhook_endpoints` IS composite, so this edge is genuinely tenant-confined one hop up — the '
            .'index is the only part of the pair that does not carry the tenant.',
        'webhook_deliveries_subscription_event_unique' => 'The connector-side twin, partial on '
            .'`connection_subscription_id IS NOT NULL`, whose owning FK to `connection_subscriptions` is '
            .'likewise composite. The `owner_check` constraint makes the two indexes mutually exclusive per row.',

        // ── The platform half of a matched pair, where "no tenant" is the whole point ─────────────────────
        'settings_platform_key_unique' => 'The platform half of a deliberately matched pair: this index is '
            .'partial on `tenant_id IS NULL` and its sibling `settings_tenant_key_unique` is partial on '
            .'`tenant_id IS NOT NULL` and DOES lead with the tenant. A platform setting has no tenant, so '
            .'there is no boundary for its key to span — global uniqueness on `key` is precisely the rule. '
            .'⚠️ Flagging this one without its sibling would read as a defect; they are one decision.',
    ];

    /**
     * Foreign keys whose TARGET carries `tenant_id` while the key itself does not, so the database will
     * accept a reference across the boundary. The compliant shape is the composite `(tenant_id, x_id)` →
     * `(tenant_id, id)` that nine constraints already use.
     *
     * ⚠️ THE PREDICATE IS ABOUT THE TARGET, AND WRITING IT ABOUT BOTH SIDES MISSES THREE. The obvious sweep
     * — both tables carry `tenant_id`, the key does not — finds 26 and silently excludes every FK whose
     * SOURCE has no tenant column: Spatie's `role_has_permissions` (the pivot ADR-0017 already flags as the
     * thing that leaks the day a tenant-owned role exists) and `tenants.logo_attachment_id`, where the
     * source is the tenants table itself. Those three cannot be fixed by adding `tenant_id` to a key, which
     * is exactly why they must be recorded rather than derived away.
     *
     * ⚠️ THIS LIST IS A MEASUREMENT, NOT AN ENDORSEMENT, AND ADR-0002 CLAIMED IT WAS EMPTY. The Consequences
     * section offers "no cross-tenant foreign keys or unique constraints (D5)" as one of the properties that
     * keeps per-tenant extraction workable. There are **29**, and what this list buys today is that the
     * **30th** cannot be added silently. Remediation covers 26 of them — it needs `(tenant_id, id)` uniques
     * on eight parent tables and a circular `forms` ↔ `form_versions` pair untangled, and is filed in
     * `docs/feature-backlog.md`; the other three have no `tenant_id` on the source to add.
     *
     * ⚠️ AND "ALL PREDATING THE CONVENTION" WAS TOO KIND TO ITSELF. An earlier draft said every FK written
     * since `scope_nodes` (2026-07-20) uses the composite shape. Three do not:
     * `usage_counters_subscription_id_foreign` (07-23), `tenants_logo_attachment_id_foreign` (08-05) and
     * `feedback_reports_screenshot_attachment_id_foreign` (08-07) — the last **eighteen days** after the
     * convention appeared, and written as a fluent single-column `foreignUuid()->constrained()`. The
     * majority genuinely are Phase 0–1 legacy, but "legacy" was doing work in that sentence that the dates
     * do not support, and the comfortable version of the story is the one that stops a gate being built.
     *
     * The `ON DELETE` action is named in each reason because it is the difference between a dangling pointer
     * and a cross-tenant delete.
     *
     * @var array<string, string>
     */
    public const array FOREIGN_KEY_EXCEPTIONS = [
        // ── The form aggregate: parent resolved under RLS, child written in the same request ─────────────
        'form_versions_form_id_foreign' => 'A version belongs to the form it was drafted from (CASCADE). The '
            .'form is loaded through the tenant-scoped builder query before a version is ever created, so a '
            .'cross-tenant value has no path in. If one existed, deleting the neighbour\'s form would delete '
            .'this tenant\'s version history.',
        'forms_draft_version_id_foreign' => 'The form\'s pointer back at its working draft (SET NULL), written '
            .'only by the service that just created that version under this tenant\'s context. Half of the '
            .'circular pair with the constraint above, which is why remediating either needs both.',
        'forms_current_published_version_id_foreign' => 'The published-version pointer (SET NULL), set by the '
            .'publish path from a version it loaded tenant-scoped in the same transaction. ADR-0013\'s '
            .'immutability trigger reads it, so a cross-tenant value would make another tenant\'s version '
            .'immutable on this tenant\'s behalf.',
        'form_sections_form_version_id_foreign' => 'A section belongs to one version of one form (CASCADE), '
            .'created only by the builder while it holds that version. Same aggregate, same resolution path.',
        'form_fields_form_version_id_foreign' => 'A field belongs to one version (CASCADE) and is created in '
            .'the same builder write as its section. The version is the aggregate root the tenant scope is '
            .'applied at.',
        'form_fields_form_section_id_foreign' => 'A field\'s optional section (SET NULL) — nullable because a '
            .'field may sit directly on the version. Both rows are written by the same builder call, so the '
            .'section is by construction a sibling within the same version.',
        'form_field_validations_form_version_id_foreign' => 'A validation rule\'s version anchor (CASCADE), '
            .'written alongside the field it constrains and never separately.',
        'form_field_validations_form_field_id_foreign' => 'The field a validation rule constrains (CASCADE). '
            .'Created in the same builder write as the field itself, from the field\'s own in-memory key.',
        'form_field_validations_related_form_field_id_foreign' => 'The OTHER field a cross-field rule reads '
            .'(CASCADE) — the only edge here chosen by an AUTHOR rather than derived by the writer, so it is '
            .'the one whose safety rests on the builder validating that the referenced key belongs to the '
            .'same version. That check is application code; the database does not repeat it.',

        // ── The submission tree and its derived projections ──────────────────────────────────────────────
        'submissions_form_id_foreign' => 'The form a response was submitted against (CASCADE). Resolved from '
            .'the tenant-scoped public slug or the authenticated builder route before the submission row '
            .'exists. A cross-tenant value here would let a neighbour\'s form deletion erase this tenant\'s '
            .'responses, which is the sharpest consequence in this list.',
        'submissions_form_version_id_foreign' => 'The exact version the response was rendered from (CASCADE), '
            .'read off the form resolved immediately above, so it cannot diverge from it.',
        'submission_answers_submission_id_foreign' => 'The 1:1 JSONB answer document\'s owner (CASCADE), '
            .'written by SubmissionFinalizer keyed on the submission it just created or loaded.',
        'submission_answers_form_version_id_foreign' => 'The version the answer document was validated '
            .'against (CASCADE), copied from the submission row rather than supplied by a caller.',
        'submission_answer_index_submission_id_foreign' => 'The scalar projection\'s submission (CASCADE). '
            .'SubmissionProjectionWriter deletes and re-inserts the whole set for one submission, so every '
            .'row it writes carries the id it was handed.',
        'submission_answer_index_form_version_id_foreign' => 'The projection\'s version anchor (CASCADE), read '
            .'from the submission being projected.',
        'submission_answer_index_form_field_id_foreign' => 'The field a projected value came from (CASCADE), '
            .'enumerated from that version\'s own fields during projection.',
        'submission_geo_index_submission_id_foreign' => 'The PostGIS projection\'s submission (CASCADE), '
            .'written by the same delete-then-insert pass as the scalar index above.',
        'submission_geo_index_form_version_id_foreign' => 'The geo projection\'s version anchor (CASCADE), '
            .'from the submission being projected.',
        'submission_geo_index_form_field_id_foreign' => 'The geo field a geometry came from (CASCADE), '
            .'enumerated from the version\'s fields during projection.',

        // ── Template and attachment edges ────────────────────────────────────────────────────────────────
        'form_templates_source_form_version_id_foreign' => 'The version a template was captured from (SET '
            .'NULL), written by the capture path from a version it loaded tenant-scoped. ⚠️ AN EARLIER '
            .'DRAFT OF THIS ENTRY CALLED IT "the one edge that legitimately points at a PLATFORM row" AND '
            .'WAS WRONG THREE TIMES OVER, IN THE ONE ENTRY WHERE THIS CLASS\'S CENTRAL DISTINCTION BITES. '
            .'(1) It does not point at a platform row: `form_versions.tenant_id` is NOT NULL, so no platform '
            .'`form_versions` exists — the nullable `tenant_id` is on the SOURCE, and inverting source and '
            .'target is precisely the mistake the FOREIGN_KEY_EXCEPTIONS docblock warns about. (2) The '
            .'obstacle it claimed was imaginary: foreign keys default to MATCH SIMPLE, which SKIPS the check '
            .'entirely when any key column is NULL, so a composite `(tenant_id, source_form_version_id)` '
            .'needs no "widened shape" — `generalize_webhook_deliveries_owner` documents that exact '
            .'mechanism in this repo. (3) The premise is moot anyway: `PlatformTemplateSeeder` sets '
            .'`source_form_version_id => null` on all 10 catalog templates, so no platform row has ever '
            .'exercised the edge. Remediating this one IS mechanical.',
        'form_templates_cover_image_attachment_id_foreign' => 'A template\'s cover image (SET NULL), uploaded '
            .'in the same request that creates the template and under the same tenant context.',
        'feedback_reports_screenshot_attachment_id_foreign' => 'The screenshot captured with a feedback report '
            .'(SET NULL, ADR-0015). The attachment is created by the capture endpoint immediately before the '
            .'report row, from the same authenticated session.',

        // ── Spatie RBAC pivots: safe because of a precondition, not because of a constraint ──────────────
        'model_has_roles_role_id_foreign' => 'The role side of Spatie\'s role assignment (CASCADE). ⚠️ SAFE '
            .'ONLY BECAUSE ALL FIVE `roles` ROWS ARE PLATFORM ROWS TODAY — ADR-0017 (tenant isolation tiering)\'s '
            .'"When to Revisit" states that `roles_tenant_insert` permits a tenant-owned role and that the '
            .'moment one exists this pivot leaks cross-tenant with two CASCADE edges and no policy to stop '
            .'it. That is a precondition nothing enforces, not a property of this constraint.',
        'model_has_permissions_permission_id_foreign' => 'The permission side of a direct grant (CASCADE). '
            .'The 29 permission rows are platform rows for the same reason and with the same caveat: the '
            .'safety is a fact about today\'s DATA, which no constraint holds in place.',
        'tenant_users_invited_role_id_foreign' => 'The role an outstanding invitation will confer (SET NULL). '
            .'Chosen from the platform role catalog at invitation time; inherits the same precondition as '
            .'the two pivots above, and the same revisit trigger.',

        // ── Untenanted SOURCE, tenant-scoped TARGET — the shape a `tenant_id`-on-both sweep never sees ────
        'role_has_permissions_role_id_foreign' => 'Spatie\'s global role→permission mapping (CASCADE). The '
            .'PIVOT carries no `tenant_id` at all — ADR-0002 §D1 calls it "global-to-global … no tenant '
            .'dimension" — so there is no column a composite key could use, while `roles` DOES carry one. '
            .'⚠️ This is the exact constraint ADR-0017 (tenant isolation tiering)\'s "When to Revisit" names: the '
            .'sentence is true only while all five roles are platform rows, and nothing enforces that.',
        'role_has_permissions_permission_id_foreign' => 'The permission side of the same untenanted pivot '
            .'(CASCADE). Identical shape and identical precondition — the 29 permissions are platform rows, '
            .'which is a fact about the seeded data rather than a constraint anybody could point at.',
        'tenants_logo_attachment_id_foreign' => 'A workspace\'s branding logo (SET NULL, H23a). The SOURCE is '
            .'`tenants` itself, which cannot carry a `tenant_id` because it defines one, pointing at the '
            .'tenant-scoped `attachments`. Confined by the upload path, which writes the attachment under '
            .'the acting tenant\'s context in the same request that sets this column — and by nothing in the '
            .'database, so a mis-set value would render another workspace\'s image as this one\'s brand.',

        // ── Entitlement ──────────────────────────────────────────────────────────────────────────────────
        'usage_counters_subscription_id_foreign' => 'The subscription a metered counter is billed against '
            .'(SET NULL, ADR-0008). Resolved from the tenant\'s own single active subscription rather than '
            .'from any caller-supplied id — `UsageMeter` reads it through the tenant scope and never accepts '
            .'a subscription id as input. ⚠️ An earlier draft added "and `usage_counters` is already '
            .'uniquely keyed on `(tenant_id, metric, period_start)`" as if that mitigated anything. It does '
            .'not: that unique constrains the metric and the period, and says nothing whatever about which '
            .'subscription `subscription_id` points at. A true fact offered as a safety argument is worse '
            .'than no second argument, because it reads as one.',
    ];

    /**
     * The index names this class excepts.
     *
     * @return list<string>
     */
    public static function indexNames(): array
    {
        return array_keys(self::UNIQUE_EXCEPTIONS);
    }

    /**
     * The foreign-key constraint names this class excepts.
     *
     * @return list<string>
     */
    public static function foreignKeyNames(): array
    {
        return array_keys(self::FOREIGN_KEY_EXCEPTIONS);
    }

    /** The recorded reason for a unique index, or null if it is not excepted. */
    public static function reasonForIndex(string $index): ?string
    {
        return self::UNIQUE_EXCEPTIONS[$index] ?? null;
    }

    /** The recorded reason for a foreign key, or null if it is not excepted. */
    public static function reasonForForeignKey(string $constraint): ?string
    {
        return self::FOREIGN_KEY_EXCEPTIONS[$constraint] ?? null;
    }
}
