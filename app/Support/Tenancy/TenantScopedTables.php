<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\TenantTableClass;
use Tests\Feature\Tenancy\TenantTableClassificationDriftTest;

/**
 * Which tables a tenant-scoped read actually reaches, and what it returns (Phase 4, P2a — ADR-0017 §D3).
 *
 * ── WHY THIS IS A HAND-MAINTAINED CONSTANT AND NOT DERIVED FROM THE CATALOG ─────────────────────────────
 * Deriving it looks obviously better — a list cannot go stale if nobody maintains it. It is wrong here for
 * two measured reasons.
 *
 * **Classification is a DECISION, not a fact.** `domains` carries `tenant_id NOT NULL` and has no RLS at
 * all, and that is deliberate: it is the table read to decide *which tenant a request is*, so scoping it by
 * tenant would be circular (`scripts/migration-lint.php` exempts it in exactly those words). No query can
 * distinguish "unprotected because somebody reasoned about it" from "unprotected because somebody forgot".
 * The list is where the reasoning lives; {@see TenantTableClassificationDriftTest}
 * is where drift is caught. That is this repo's own idiom — `ToggleableModules::KEYS` and
 * `PlanCatalog::FEATURE_KEYS` are two hand-maintained lists pinned to each other by a test, for the same
 * reason and with the same comment.
 *
 * **The derivation would have to parse `pg_get_expr` output.** Distinguishing the widened shape from the
 * strict one means string-matching `OR (tenant_id IS NULL)` inside `pg_policies.qual`, which is normalised,
 * reformatted, and free to change across major versions. A parser that drifts here drifts OPEN — it would
 * silently reclassify a strict table as shared, which is the direction that leaks.
 *
 * ⚠️ THE TRAP THIS CLASS EXISTS TO CLOSE. The obvious rule — "has `tenant_id` ⇒ RLS is the filter ⇒ read it
 * with no predicate" — is wrong twice over on this schema, and both errors are silent:
 *
 *   1. `domains` would be read with no predicate and no RLS, yielding EVERY tenant's hostnames, custom
 *      domains and verification tokens.
 *   2. `audits` and `personal_access_tokens` carry a NULLABLE `tenant_id` and are nonetheless STRICT.
 *      Classifying by column nullability would declare the append-only compliance ledger platform-shared —
 *      the precise widening {@see TenantIsolation} refuses, because it "would leak it".
 */
final class TenantScopedTables
{
    /**
     * RLS returns EXACTLY the tenant's rows: `tenant_id` present, `FORCE ROW LEVEL SECURITY` on, and a
     * SELECT policy whose `USING` is plain equality against `app.current_tenant_id`.
     *
     * ⚠️ `audits` and `personal_access_tokens` belong HERE despite a nullable `tenant_id` column, and that
     * is not an oversight — see the class docblock and {@see TenantIsolation::appendOnlySql()}. Moving
     * either into {@see self::NULLABLE_GLOBAL} widens a SELECT policy that is deliberately narrow.
     *
     * @var list<string>
     */
    public const array STRICT = [
        'attachments',
        'audits',
        // K1b. Nothing withheld, and it holds even less than its `point_awards` sibling: the row is a member
        // id, a catalog key from a closed PHP enum, and a date. There is no subject column at all, so not
        // even the one-way digest that ledger carries appears here.
        'badge_awards',
        'connection_subscriptions',
        'connections',
        'feedback_reports',
        'form_field_validations',
        'form_fields',
        'form_sections',
        'form_versions',
        'forms',
        'google_auth_requests',
        'impersonation_tokens',
        'legacy_overrides',
        'model_has_permissions',
        'model_has_roles',
        'notification_preferences',
        'notifications',
        'personal_access_tokens',
        // K1a. Nothing withheld: the ledger carries no credential, no derived column and no fact about a
        // subject outside this tenant — `subject_id` is a uuid of the tenant's own row, or a one-way digest
        // of an address the tenant itself typed into an invite.
        'point_awards',
        'probes',
        'resource_grants',
        'saved_report_views',
        'scope_nodes',
        'sso_auth_failures',
        'sso_auth_requests',
        'sso_connections',
        // M18. Strict rather than exempt, and the contrast with `domains` two entries' worth of reasoning up
        // is the point: that table is unprotected because it is read to decide WHICH TENANT a request is, so
        // scoping it by tenant would be circular. This one is read only once a tenant is already established,
        // by a host that resolved it — so nothing here is circular and the database does the scoping. Its
        // isolation is asserted in `SsoDomainVerificationTest`, not merely classified here.
        'sso_verified_domains',
        'submission_answer_index',
        'submission_answers',
        'submission_geo_index',
        'submissions',
        'subscriptions',
        'tenant_users',
        'usage_counters',
        'webhook_deliveries',
        'webhook_endpoints',
    ];

    /**
     * The SELECT policy is widened with `OR tenant_id IS NULL` (ADR-0002 §D2's named exception): rows with a
     * NULL discriminator are platform-provided and visible to every tenant.
     *
     * ⚠️ **RLS RETURNS A SUPERSET HERE.** A caller that treats RLS as its filter receives the tenant's rows
     * AND the platform catalog — today that is 10 `form_templates`, 6 `field_library`, 29 `permissions` and
     * 5 `roles`. Whether those belong in a tenant-facing artefact is a product question, not a technical
     * one, and it is recorded as an entry criterion for P2b rather than assumed either way here.
     *
     * @var list<string>
     */
    public const array NULLABLE_GLOBAL = [
        'field_library',
        'form_templates',
        'global_probes',
        'permissions',
        'roles',
        'settings',
    ];

    /**
     * Carries `tenant_id` and is DELIBERATELY unprotected. Table => the reason, which is the whole value of
     * this list: an entry with no rationale is indistinguishable from a table somebody forgot.
     *
     * @var array<string, string>
     */
    public const array UNPROTECTED_TENANT_TABLES = [
        'domains' => 'Resolves the tenant from the host, so it is read BEFORE any tenant context exists — '
            .'RLS-scoping it would be circular. Isolation is CustomDomainService\'s explicit where(tenant_id). '
            .'Exempted on the same grounds by scripts/migration-lint.php.',
    ];

    /**
     * RLS'd, but keyed on a PERSON rather than an organisation — so a tenant-only GUC does not describe what
     * they return. `users` is the join shape (`app.current_user_id` OR an active co-tenancy);
     * `user_ui_preferences` is the belongs-to-user shape. Neither carries `tenant_id`.
     *
     * @var list<string>
     */
    public const array NON_TENANT_RLS = [
        'users',
        'user_ui_preferences',
    ];

    /**
     * Every table a tenant-scoped read reaches — strict and widened together.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::STRICT, ...self::NULLABLE_GLOBAL];
    }

    public static function classify(string $table): TenantTableClass
    {
        return match (true) {
            in_array($table, self::STRICT, true) => TenantTableClass::TenantScoped,
            in_array($table, self::NULLABLE_GLOBAL, true) => TenantTableClass::PlatformShared,
            default => TenantTableClass::NotExtracted,
        };
    }

    /**
     * Whether RLS alone over-selects on this table — i.e. a no-predicate read returns rows the tenant does
     * not own. True for the widened six and nothing else.
     */
    public static function rlsReturnsSuperset(string $table): bool
    {
        return self::classify($table)->returnsSuperset();
    }

    /**
     * Tables that carry `tenant_id` in any capacity, protected or not. The denominator the drift test's
     * reverse direction sweeps, so a newly-created tenant-scoped table cannot ship unclassified.
     *
     * @return list<string>
     */
    public static function everyTenantIdCarrier(): array
    {
        return [...self::all(), ...array_keys(self::UNPROTECTED_TENANT_TABLES)];
    }
}
