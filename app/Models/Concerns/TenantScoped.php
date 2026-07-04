<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Marks an Eloquent model as tenant-scoped. Satisfied automatically by the {@see BelongsToTenant}
 * trait, so a model only needs `implements TenantScoped` for the type system — the trait supplies the
 * method. Lets the trait's boot closures narrow a `Model` to something with a known tenant column
 * without inline type assertions.
 */
interface TenantScoped
{
    /** The discriminator column RLS and the ORM scope key off (default `tenant_id`). */
    public function getTenantColumn(): string;
}
