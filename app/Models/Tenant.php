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
 */
class Tenant extends BaseTenant
{
    use HasDomains;

    /**
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'slug', 'owner_user_id', 'status'];
    }
}
