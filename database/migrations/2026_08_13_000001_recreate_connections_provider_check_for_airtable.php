<?php

declare(strict_types=1);

use App\Enums\ConnectorProviderKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H16c adds a third {@see ConnectorProviderKey}, `airtable`. The `connections_provider_check` CHECK is
 * generated from {@see ConnectorProviderKey::values()} so the enum and the constraint cannot drift
 * (`2026_07_27_000001_create_connections_table.php:61-73` states that invariant) — this drops and recreates it
 * from the now-three-value enum.
 *
 * Follows `2026_08_05_000001_recreate_connections_provider_check.php`, which followed
 * `2026_07_26_000004_recreate_usage_counters_metric_check.php`, including the `down()` shape: the reverse
 * recreates the CHECK from every case EXCEPT the one this migration adds.
 *
 * ⚠️ ONE HONEST CAVEAT ABOUT THAT `down()`, BECAUSE THE PATTERN'S OWN CLAIM IS SLIGHTLY TOO STRONG. H16a's
 * copy says its shape means "a third provider landing later cannot silently make this `down()` wrong". That
 * holds for a SINGLE-STEP rollback, which is the only one anyone runs and the only one `migrate:refresh`
 * exercises. It does not hold for a chained one: `cases()` is read at RUN time, so rolling this migration
 * back and then H16a's would have H16a's `down()` recreate the CHECK from `[slack, airtable]` — re-permitting
 * the value this migration had just removed. Recording it rather than "fixing" H16a's file: pinning a literal
 * list there would trade a two-step-rollback edge case for a constraint that no longer tracks the enum, which
 * is the invariant both migrations exist to hold.
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
                static fn (ConnectorProviderKey $case): bool => $case !== ConnectorProviderKey::Airtable,
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
