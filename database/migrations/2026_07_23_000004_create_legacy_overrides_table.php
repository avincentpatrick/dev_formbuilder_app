<?php

declare(strict_types=1);

use App\Models\LegacyOverride;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant legacy (grandfather) overrides backing ADR-0008 §D5 — H5c. One row per tenant carrying a
 * `feature_flags` override map that {@see EntitlementService::feature()} consults
 * AHEAD of the plan flags, so a grandfathered tenant sees `true` for a feature its plan would deny. The
 * merge-day backfill (a separate migration) stamps every then-existing tenant with the five ungated Phase-2
 * features; tenants created after merge day get no row and are born gated.
 *
 * Strictly tenant-scoped → the plain strict RLS shape. The PK is a **bigint identity** (NOT uuidv7): this is
 * pure internal per-tenant state, never addressed externally (the `usage_counters` precedent / data-dictionary
 * PK-strategy note), so {@see LegacyOverride} does NOT use HasUuidv7 — which also keeps the raw-SQL backfill
 * trivial (the identity auto-fills; no uuid must be minted in SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_overrides', function (Blueprint $table): void {
            $table->bigIncrements('id'); // bigint identity — internal per-tenant state (see docblock)
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // The override map, same shape as plans.feature_flags: { "<flag key>": true, ... }. No DB CHECK on
            // its keys (validated at the application layer against the feature-flag key set), mirroring plans.
            $table->jsonb('feature_flags')->default('{}');

            $table->timestampsTz();

            // One override row per tenant — the backfill's ON CONFLICT target, and what makes a single-row
            // read authoritative in legacyOverrides().
            $table->unique('tenant_id', 'legacy_overrides_tenant_unique');
        });

        withTenantIsolation('legacy_overrides'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_overrides');
    }
};
