<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Services\Branding\TenantBrandingService;
use App\Support\Branding\BrandRamp;
use App\Support\Branding\BrandRampGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property ?string $primary_color The brand hex the tenant TYPED (#RRGGBB); null ⇒ no brand colour (H23a2).
 * @property mixed $brand_ramp Derived ramp payload (jsonb); read through brandRamp(), never raw.
 * @property ?string $logo_attachment_id FK to attachments; null ⇒ no brand logo (H23a2).
 * @property bool $maintenance_mode Guest runtime paused for this tenant (I5, PRD #10); the admin app is unaffected.
 * @property ?string $maintenance_message Respondent-facing notice while paused; null ⇒ the product's generic copy.
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
        //
        // primary_color / brand_ramp / logo_attachment_id (H23a2, 2026_08_05_000003) are the same shape
        // again. Their failure mode is quieter than 'status' but no less real: branding would appear to
        // save, read back null, and simply never apply — with a green write path the whole way.
        //
        // maintenance_mode / maintenance_message (I5, 2026_08_06_000004) are columns rather than `settings`
        // rows precisely so the guest runtime can read them off the already-resolved tenant with no extra
        // query — which is exactly the property that omitting them here would destroy, silently and in the
        // fail-OPEN direction (a null flag reads as "not in maintenance" and every form keeps serving).
        return ['id', 'name', 'slug', 'owner_user_id', 'status', 'default_locale', 'supported_locales', 'draft_ttl_days', 'primary_color', 'brand_ramp', 'logo_attachment_id', 'maintenance_mode', 'maintenance_message'];
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

    /**
     * Whether this tenant's PUBLIC form runtime is paused (I5, PRD Feature #10).
     *
     * Scoped to respondents on purpose: the authenticated app stays fully usable while this is true, because
     * the person who switched it on has to be able to switch it off. {@see App\Http\Middleware\EnforceTenantMaintenance}
     * is the only enforcement site.
     *
     * **A method rather than a bare property read, even though `pdo_pgsql` hands this column back as a real
     * PHP bool** (verified against the running database rather than assumed, because this model carries no
     * casts at all — stancl's virtual-column machinery intercepts attribute access, so `status` above is a
     * plain uncast `?string`). The two ways it reads NULL are the ones {@see self::isActive()} documents: a
     * partial `->select()` upstream, and a just-created unrefreshed instance whose DB-default `false` has
     * not been back-filled. Both land on false here, which is the right direction — an unreadable flag must
     * fail OPEN and keep serving, never take a tenant's forms offline by accident.
     */
    public function isUnderMaintenance(): bool
    {
        return $this->maintenance_mode === true;
    }

    /**
     * The respondent-facing notice, falling back to the product's own copy.
     *
     * The fallback lives here rather than in the view so the blade, the JSON arm of the guest API and any
     * later surface cannot render three different sentences for one state.
     */
    public function maintenanceNotice(): string
    {
        $message = $this->maintenance_message;

        return is_string($message) && trim($message) !== ''
            ? trim($message)
            : 'This form is temporarily unavailable while its organisation performs maintenance. Please try again later.';
    }

    /**
     * The stored brand logo, or null.
     *
     * The SoftDeletes global scope on {@see Attachment} is what stops a deleted logo rendering — the FK's
     * `ON DELETE SET NULL` fires only on a `forceDelete()`. Both halves are pinned by `BrandingStorageTest`.
     *
     * @return BelongsTo<Attachment, $this>
     */
    public function logoAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'logo_attachment_id');
    }

    /**
     * The stored brand ramp (H23a2, ADR-0014 §D8), or null when the tenant has set no brand colour.
     *
     * **Decoded here rather than through a `casts()` entry, and that is deliberate.** This model carries
     * no casts at all — stancl's virtual-column machinery intercepts attribute access, which is why even
     * `status` is documented above as an uncast `?string`. Adding the project's first cast to this one
     * class to save a `json_decode` would trade a local convenience for an interaction nobody has tested.
     *
     * **Never re-derives.** A stored ramp was validated by the engine version recorded inside it; running
     * {@see BrandRampGenerator} here would re-open that question on every read and would fail loudly on
     * exactly the rows a future VERSION bump is meant to migrate deliberately.
     */
    public function brandRamp(): ?BrandRamp
    {
        $raw = $this->brand_ramp;

        if ($raw === null || $raw === '') {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($raw) ? $raw : json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        return BrandRamp::fromArray($payload);
    }

    /**
     * Whether a brand ramp is STORED — which is not the same question as whether it RENDERS.
     *
     * Rendering additionally requires the `branding` plan feature (Starter+, ADR-0008 §D7). A tenant that
     * downgrades keeps its stored ramp and stops being branded; the settings surface says so and offers
     * removal, on the ADR-0012 §D9 precedent that a downgraded tenant must retain a path to undo what a
     * paid tier let them create. See {@see TenantBrandingService::isActive()}.
     */
    public function hasBrandRamp(): bool
    {
        return $this->brand_ramp !== null && $this->brand_ramp !== '';
    }
}
