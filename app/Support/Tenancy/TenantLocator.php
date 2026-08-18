<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Resolve the workspace an operator typed on a command line — Increment K1c.
 *
 * Extracted verbatim from `ExtractTenantCommand::resolveTenant()`, which had been the only one of its kind
 * until K1c added a second operator command that needs the identical three-way lookup. Two copies of this
 * would have been two chances to disagree about what an operator may type, and the uuid guard below is
 * exactly the sort of hard-won detail that gets left out of the second copy.
 *
 * ⚠️ **THE UUID CHECK IS NOT DEFENSIVE TIDINESS.** `tenants.id` is a uuid column, so handing `find()` a slug
 * makes PostgreSQL raise `22P02 invalid input syntax for type uuid` — a stack trace instead of "no tenant
 * matches that", for the input an operator is most likely to type.
 *
 * Runs with NO tenant context and needs none: `tenants` and `domains` are the RLS-exempt central tables.
 */
final class TenantLocator
{
    /** By id, slug, or primary/custom domain — in that order. Null when nothing matches. */
    public static function find(string $needle): ?Tenant
    {
        $needle = trim($needle);

        if ($needle === '') {
            return null;
        }

        $tenant = Str::isUuid($needle) ? Tenant::query()->find($needle) : null;

        // `Domain::unscopedQuery()`, never `$tenant->domains()` or `whereHas()`: the Domain model carries a
        // tenant-scoping global scope, and an operator command deliberately runs before any context exists,
        // so the scoped query would match nothing and report a live domain as unknown.
        $resolved = $tenant
            ?? Tenant::query()->where('slug', $needle)->first()
            ?? Domain::unscopedQuery()->where('domain', mb_strtolower($needle))->first()?->tenant;

        // The `tenant` relation is typed against stancl's Tenant CONTRACT, not this application's model, so
        // the narrowing is real rather than a cast to satisfy the analyser.
        return $resolved instanceof Tenant ? $resolved : null;
    }
}
