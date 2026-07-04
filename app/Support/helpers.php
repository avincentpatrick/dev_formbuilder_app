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
     * @param  'strict'|'nullable_global'|'belongs_to_user'  $variant
     * @param  array<string, string>  $options  e.g. ['column' => 'tenant_id'] or ['column' => 'user_id']
     */
    function withTenantIsolation(string $table, string $variant = 'strict', array $options = []): void
    {
        match ($variant) {
            'strict' => TenantIsolation::strict($table, $options['column'] ?? 'tenant_id'),
            'nullable_global' => TenantIsolation::nullableGlobal($table, $options['column'] ?? 'tenant_id'),
            'belongs_to_user' => TenantIsolation::belongsToUser($table, $options['column'] ?? 'user_id'),
        };
    }
}
