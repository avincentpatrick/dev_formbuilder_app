<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Tenant-scoped API keys (Increment E). A Sanctum personal access token is minted for EXACTLY ONE tenant:
 * `personal_access_tokens` carries a `tenant_id` under the strict RLS shape (added in B1). The stock
 * Sanctum model never sets `tenant_id`, so its INSERT is rejected by the strict `WITH CHECK` policy —
 * BelongsToTenant + TenantScoped auto-fill `tenant_id` from the current context at mint time (the
 * `creating` hook) and add the redundant-but-harmless ORM tenant scope on reads.
 *
 * The auth-path RLS story needs NO change: because the table is owned by `meridian_app` and lookups run
 * with the tenant GUC already set (EstablishTenantDatabaseContext runs before AuthenticateApiToken), the
 * strict SELECT policy reveals a token IFF it was minted for the current subdomain's tenant — cross-tenant
 * tokens are invisible (fail closed). This model keeps the DEFAULT connection (`meridian_app`) so the
 * lookup, tokenable load, and `last_used_at` write all run under RLS.
 *
 * Registered via `Sanctum::usePersonalAccessTokenModel()` in AppServiceProvider::boot(). Note the PK is a
 * bigint (`$table->id()`), not a UUID — so this model does NOT use HasUuidv7; only `tokenable` is a uuid morph.
 *
 * @property int $id
 * @property ?string $tenant_id
 * @property string $name
 * @property list<string> $abilities
 * @property ?Carbon $last_used_at
 * @property ?Carbon $expires_at
 * @property ?Carbon $created_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken implements TenantScoped
{
    use BelongsToTenant;
}
