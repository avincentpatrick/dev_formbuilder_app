<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Services\Settings\TenantSettingRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * A single stored setting (data-dictionary §20, PRD Feature #10) — Increment I5. NULLABLE-GLOBAL shape
 * (ADR-0002 §D2 named exception, the third adopter after {@see FormTemplate} and {@see FieldLibrary}):
 * a NULL `tenant_id` is a PLATFORM setting the super-admin console owns; a non-null one is a tenant's own.
 *
 * Deliberately does NOT use {@see BelongsToTenant}, for the two reasons {@see FieldLibrary} records: the
 * trait's strict-equality global scope would hide exactly the platform rows the widened SELECT policy
 * exists to reveal, and its create hook would stamp the ambient tenant onto a row that is meant to have
 * none. `tenant_id` is therefore set EXPLICITLY at every create site.
 *
 * ── NO `value` CAST, AND THAT IS NOT AN OVERSIGHT ──────────────────────────────────────────────────────
 * The column is a schemaless jsonb whose shape depends on the key — a bool for a toggle, a string for a
 * message, a small object later. An `array` cast would fail on the scalars and a `json` cast would hand
 * every caller a `mixed` it has to re-narrow anyway, so the decode happens once at the repository boundary
 * ({@see TenantSettingRegistry}) — the same call {@see Tenant::brandRamp()} made, and for the same reason.
 *
 * Nothing outside {@see TenantSettingRegistry} and
 * {@see App\Services\Admin\SuperAdminService::updatePlatformSettings()} should query this model directly:
 * a raw row is a SPARSE answer, and the defaults that make it complete live on {@see App\Enums\SettingKey}.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $key
 * @property mixed $value
 * @property ?string $updated_by
 */
class Setting extends Model
{
    use HasUuidv7;

    protected $table = 'settings';

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
        'updated_by',
    ];
}
