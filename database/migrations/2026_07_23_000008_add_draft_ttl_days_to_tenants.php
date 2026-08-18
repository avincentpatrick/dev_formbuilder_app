<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H10 — the tenant-configurable draft-expiry window (docs/ux/form-filling-ux-flow.md §5.2). H9a
 * stamped every draft's `draft_expires_at` at a fixed `now()+30d`; this column lets a tenant override that
 * default. NULL means "use the system default" (SubmissionDraftService::DRAFT_TTL_DAYS = 30), so a fresh DB
 * and every pre-H10 tenant keep the 30-day behaviour with no backfill.
 *
 * `tenants` is the central, RLS-exempt discriminator table (ADR-0002 §D1), so no `withTenantIsolation()` is
 * needed and the migration linter skips this alter-only migration. NOTE: a real column here MUST be added to
 * {@see Tenant::getCustomColumns()} or stancl/tenancy silently relocates its value into the `data`
 * json virtual-column store and reads it back as null (`TenantColumnWhitelistTest` pins that list by set
 * equality — it cited `TenantCustomColumnsTest` until P2a, which is a file that has never existed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedSmallInteger('draft_ttl_days')->nullable()->after('supported_locales');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('draft_ttl_days');
        });
    }
};
