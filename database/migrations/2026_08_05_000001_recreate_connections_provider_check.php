<?php

declare(strict_types=1);

use App\Enums\ConnectorProviderKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H16a adds a second {@see ConnectorProviderKey}, `google_sheets`. The `connections_provider_check` CHECK is
 * generated from {@see ConnectorProviderKey::values()} so the enum and the constraint cannot drift
 * (`2026_07_27_000001_create_connections_table.php:61-73` states that invariant) — this drops and recreates it
 * from the now-two-value enum.
 *
 * Follows `2026_07_26_000004_recreate_usage_counters_metric_check.php` exactly, including its `down()` shape:
 * the reverse recreates the CHECK from every case EXCEPT the one this migration adds, rather than hard-coding
 * the old list, so a third provider landing later cannot silently make this `down()` wrong.
 *
 * Alter-only (no `Schema::create`) → `scripts/migration-lint.php` short-circuits, and there is no RLS work to
 * do: `withTenantIsolation('connections')` was applied when the table was created and a CHECK does not touch it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->recreate(ConnectorProviderKey::values());
    }

    public function down(): void
    {
        $this->recreate(array_map(
            static fn (ConnectorProviderKey $case): string => $case->value,
            array_filter(
                ConnectorProviderKey::cases(),
                static fn (ConnectorProviderKey $case): bool => $case !== ConnectorProviderKey::GoogleSheets,
            ),
        ));
    }

    /**
     * @param  array<int, string>  $providers
     */
    private function recreate(array $providers): void
    {
        $literals = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $providers,
        ));

        DB::statement('ALTER TABLE connections DROP CONSTRAINT connections_provider_check');
        DB::statement(
            'ALTER TABLE connections ADD CONSTRAINT connections_provider_check '
            ."CHECK (provider IN ({$literals}))"
        );
    }
};
