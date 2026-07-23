<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use App\Services\Entitlements\EntitlementService;
use Database\Factories\LegacyOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-tenant legacy (grandfather) override (ADR-0008 §D5, H5c) — the storage behind
 * {@see EntitlementService::legacyOverrides()}. One row per tenant; its
 * `feature_flags` map is consulted AHEAD of the plan flags, so a grandfathered tenant sees `true` for a
 * feature its plan would deny. Strictly tenant-scoped via {@see BelongsToTenant} (auto-fills `tenant_id`
 * from context so the strict INSERT WITH CHECK passes).
 *
 * Deliberately does NOT use `HasUuidv7`: the PK is a bigint identity — internal per-tenant state never
 * addressed externally (the {@see UsageCounter} precedent).
 *
 * @property int $id
 * @property string $tenant_id
 * @property array<string, bool> $feature_flags
 */
class LegacyOverride extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<LegacyOverrideFactory> */
    use HasFactory;

    /** `tenant_id` is EXCLUDED — BelongsToTenant auto-fills it (bypassing $fillable). */
    protected $fillable = [
        'feature_flags',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature_flags' => 'array',
        ];
    }
}
