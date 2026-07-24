<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Builder;
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
 * @property ?int $draft_ttl_days Tenant-configured draft-expiry window in days; null ⇒ system default (H10).
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
        // stancl treats them as columns, not `data` json virtual attributes. draft_ttl_days (H10,
        // 2026_07_23_000008) is the same shape: a real column that MUST be whitelisted here or it
        // spills into `data` and reads back null.
        //
        // 'status' MUST stay in this list. It is what makes scopeActive() below a real SQL predicate;
        // remove it and stancl silently relocates the value into the `data` json column, at which
        // point `where('status', …)` matches NOTHING and every tenant vanishes from every fan-out —
        // with no error. TenantCustomColumnsTest pins this.
        return ['id', 'name', 'slug', 'owner_user_id', 'status', 'default_locale', 'supported_locales', 'draft_ttl_days'];
    }

    /**
     * Active tenants only — the enumeration ADR-0007 §D3 fans out over, and the predicate §D13's
     * per-job lifecycle guard agrees with. One definition so the SQL and PHP halves cannot diverge.
     *
     * Compares against ->value, never the enum case: `status` is NOT cast (see the class docblock and
     * App\Enums\TenantStatus), so it is a plain ?string. This mirrors App\Services\Admin\SuperAdminService.
     *
     * @param  Builder<covariant Tenant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', TenantStatus::Active->value);
    }

    /**
     * Whether this tenant may have work executed on its behalf (ADR-0007 §D13).
     *
     * NEVER write `$tenant->status !== TenantStatus::Active` — that compares a ?string to an enum
     * OBJECT and is therefore ALWAYS true, which in a job's lifecycle guard would release-then-delete
     * every job for every tenant on the platform. tryFrom() also keeps PHPStan level 8 happy on the
     * ?string→string narrowing that from() would require.
     *
     * TWO WAYS TO GET A FALSE NEGATIVE, both of which read `status` as null:
     *   1. A partial ->select() upstream. stancl's base model sets
     *      $modelsShouldPreventAccessingMissingAttributes = false, so an unselected column reads as
     *      null rather than throwing.
     *   2. A just-created, unrefreshed instance. `tenants.status` gets its 'active' value from a
     *      DATABASE default (2026_07_05_000001_create_tenants_table.php:26), which Eloquent does not
     *      back-fill into the in-memory model — so `Tenant::create([...])->isActive()` is FALSE while
     *      the persisted row is 'active'.
     * TenantAwareJob is unaffected because it re-reads the tenant from the database by key; anything
     * else calling this on a model it just created must ->refresh() first.
     */
    public function isActive(): bool
    {
        return TenantStatus::tryFrom((string) $this->status) === TenantStatus::Active;
    }
}
