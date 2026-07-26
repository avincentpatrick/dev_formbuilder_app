<?php

declare(strict_types=1);

use App\Enums\UsageMetric;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H13a adds an 8th {@see UsageMetric}, `webhook_endpoints_count` (the per-tier endpoint COUNT cap gauge).
 * The `usage_counters.metric` CHECK is generated from {@see UsageMetric::values()} so the enum and the
 * constraint cannot drift ({@see UsageMetric} docblock) — this drops and recreates it from the now-8-value
 * enum.
 *
 * `webhook_endpoints_count` is a GAUGE: it is computed live (a COUNT under RLS in
 * {@see EntitlementService::computeGauge()}) and NEVER written to
 * `usage_counters`, so the CHECK never actually fires on it — but keeping the constraint aligned with the
 * enum honors the stated invariant and guards a future writer.
 *
 * Alter-only (no `Schema::create`) → scripts/migration-lint.php short-circuits.
 */
return new class extends Migration
{
    public function up(): void
    {
        $metrics = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            UsageMetric::values()
        ));

        DB::statement('ALTER TABLE usage_counters DROP CONSTRAINT usage_counters_metric_check');
        DB::statement(
            'ALTER TABLE usage_counters ADD CONSTRAINT usage_counters_metric_check '
            ."CHECK (metric IN ({$metrics}))"
        );
    }

    public function down(): void
    {
        // Recreate the CHECK without the H13a metric (the seven pre-H13a values).
        $metrics = implode(', ', array_map(
            static fn (UsageMetric $case): string => "'".$case->value."'",
            array_filter(
                UsageMetric::cases(),
                static fn (UsageMetric $case): bool => $case !== UsageMetric::WebhookEndpointsCount,
            )
        ));

        DB::statement('ALTER TABLE usage_counters DROP CONSTRAINT usage_counters_metric_check');
        DB::statement(
            'ALTER TABLE usage_counters ADD CONSTRAINT usage_counters_metric_check '
            ."CHECK (metric IN ({$metrics}))"
        );
    }
};
