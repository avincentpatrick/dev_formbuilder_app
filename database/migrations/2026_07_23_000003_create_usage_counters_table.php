<?php

declare(strict_types=1);

use App\Enums\UsageMetric;
use App\Models\UsageCounter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metering rows backing quota enforcement (data-dictionary §18, ADR-0008). Strictly tenant-scoped → the
 * plain strict RLS shape. The PK is a **bigint identity** (NOT uuidv7): these are pure internal aggregation
 * rows, never addressed externally (data-dictionary global PK-strategy note), so {@see UsageCounter}
 * does NOT use HasUuidv7.
 *
 * Period-bounded (ADR-0008 §D8): each row aggregates one `(period_start, period_end)` window rather than a
 * single rolling counter, so a mid-cycle upgrade/downgrade and historical reporting stay accurate;
 * `limit_snapshot` denormalizes the quota that applied at the row's last update so a historical report stays
 * correct even after the plan's quota changes. **H5a ships the table + the read side only** — the increment
 * logic and the cross-tenant rollup (a MaintenanceJob per ADR-0007 §D3, never a single-tenant job) land with
 * H5b enforcement; counters read as 0 until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->bigIncrements('id'); // bigint identity — internal aggregation rows (see docblock)
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Nullable: usage may be recorded before any subscription exists (a free-tier tenant with no
            // subscription row yet). nullOnDelete so retiring a subscription does not delete its usage history.
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->string('metric', 30); // App\Enums\UsageMetric
            $table->date('period_start');
            $table->date('period_end');

            $table->bigInteger('value')->default(0);            // running aggregate (count or bytes, per metric)
            $table->bigInteger('limit_snapshot')->nullable();   // the plan quota that applied at last update
            $table->timestampTz('last_incremented_at')->nullable();

            $table->timestampsTz();

            // EXPLICIT SHORT NAME (PostgreSQL NAMEDATALEN = 63), tenant_id-leading. One row per
            // (tenant, metric, period) — the metering upsert target.
            $table->unique(['tenant_id', 'metric', 'period_start'], 'usage_counters_tenant_metric_period_unique');
        });

        // Pin the metric vocabulary at the database, from the enum so the two cannot drift.
        $metrics = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            UsageMetric::values()
        ));
        DB::statement(
            'ALTER TABLE usage_counters ADD CONSTRAINT usage_counters_metric_check '
            ."CHECK (metric IN ({$metrics}))"
        );

        withTenantIsolation('usage_counters'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
