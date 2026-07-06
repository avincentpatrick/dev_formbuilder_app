<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * The central tenant record (ADR-0002 §D1), integrated with stancl/tenancy v3 for subdomain
 * identification (single-database mode). RLS-EXEMPT — it is the discriminator table itself.
 *
 * Extends stancl's base Tenant for the tenant contract + domains, but keeps this project's UUIDv7 id
 * (via config('tenancy.id_generator') = Uuid7Generator) and explicit columns. getCustomColumns() lists
 * the real columns; anything else would spill into stancl's `data` json virtual-column store.
 *
 * NOTE: id generation is handled by stancl's GeneratesIds (from the base) using our Uuid7Generator —
 * this model does NOT use HasUuidv7 (that would double-generate the key).
 *
 * @property string $name
 * @property string $slug
 * @property ?string $owner_user_id
 * @property ?string $status Tenant lifecycle (App\Enums\TenantStatus); not cast (stancl virtual columns).
 * @property string $default_locale Default locale for new forms (data-dictionary §1).
 */
class Tenant extends BaseTenant
{
    use HasDomains;

    /**
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        // default_locale/supported_locales are real columns (2026_07_06_000200) — list them here so
        // stancl treats them as columns, not `data` json virtual attributes.
        return ['id', 'name', 'slug', 'owner_user_id', 'status', 'default_locale', 'supported_locales'];
    }
}
