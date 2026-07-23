<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantIsolation;

if (! function_exists('withTenantIsolation')) {
    /**
     * Attach PostgreSQL Row-Level Security to a tenant-scoped table (ADR-0002 §D2).
     *
     * The name every tenant-scoped migration ends with, so the backstop is impossible to forget
     * and the migration linter has one canonical call to look for. Delegates to
     * {@see TenantIsolation} for the actual policy SQL.
     *
     * A sixth shape exists — {@see TenantIsolation::resourceGrantGuard()} for `resource_grants`
     * (Increment G10a) — but is deliberately NOT routed through here: its per-alias morph-target map is
     * an `array<string, string>` of a different shape than `$options`, and widening `$options` to carry
     * it would loosen the type for every other caller. That migration calls the generator directly; the
     * migration linter accepts any `TenantIsolation::` static call, so the backstop is still enforced.
     *
     * @param  'strict'|'nullable_global'|'belongs_to_user'|'form_version'|'draft_child'|'append_only'  $variant
     * @param  array<string, string>  $options  variant-specific overrides — e.g. ['column' => 'user_id']
     *                                          for belongs_to_user, or the parent_table/fk_column/
     *                                          status_column/draft_value keys for draft_child.
     */
    function withTenantIsolation(string $table, string $variant = 'strict', array $options = []): void
    {
        match ($variant) {
            'strict' => TenantIsolation::strict($table, $options['column'] ?? 'tenant_id'),
            'nullable_global' => TenantIsolation::nullableGlobal($table, $options['column'] ?? 'tenant_id'),
            'belongs_to_user' => TenantIsolation::belongsToUser($table, $options['column'] ?? 'user_id'),
            // Increment D — the two published-immutability shapes (form-versioning-schema-migration.md §2).
            'form_version' => TenantIsolation::formVersionGuard(
                $table,
                $options['status_column'] ?? 'status',
                $options['draft_value'] ?? 'draft',
                $options['column'] ?? 'tenant_id',
            ),
            'draft_child' => TenantIsolation::draftChildGuard(
                $table,
                $options['parent_table'] ?? 'form_versions',
                $options['fk_column'] ?? 'form_version_id',
                $options['parent_status_column'] ?? 'status',
                $options['draft_value'] ?? 'draft',
                $options['column'] ?? 'tenant_id',
            ),
            // H4 — the append-only ledger shape (audits). SELECT + INSERT only; FORCE RLS denies mutation.
            'append_only' => TenantIsolation::appendOnly($table, $options['column'] ?? 'tenant_id'),
        };
    }
}
