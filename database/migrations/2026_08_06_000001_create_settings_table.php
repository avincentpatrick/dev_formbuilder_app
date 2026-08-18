<?php

declare(strict_types=1);

use App\Support\Settings\SettingKey;
use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The key-value settings store (data-dictionary §20, PRD Feature #10) — Increment I5. The THIRD
 * nullable-global adopter after `form_templates` and `field_library` (ADR-0002 §D2 named exception, and
 * {@see TenantIsolation::nullableGlobal()} already listed `settings` among them):
 * a NULL `tenant_id` is a PLATFORM setting the super-admin owns, a non-null one is a tenant's own.
 *
 * Key-value rather than a column per toggle, per §20's Design Notes: new flags ship without a migration,
 * which is the whole point of a config-flag-gated rollout. The trade is that `value` is schemaless per key,
 * so the defaults and the shape live in PHP — {@see SettingKey}, which is the ONLY place a default is
 * written down. The table is SPARSE by design: an absent row means "default", never null.
 *
 * ── TWO PARTIAL UNIQUE INDEXES, NOT ONE `UNIQUE(tenant_id, key)` ────────────────────────────────────────
 * Postgres treats NULL as DISTINCT FROM NULL for uniqueness, so a plain composite constraint would police
 * the tenant rows and silently allow two platform rows with the same key — a store with two answers and no
 * error. The pair below constrains both halves, and `SettingsRlsTest` asserts each one bites with a 23505.
 *
 * The nullable FK is declared with a manual ->foreign (not ->foreignUuid()->constrained(), which isn't
 * nullable-friendly) so scripts/migration-lint.php's declares_tenant_column rule still sees the literal
 * `tenant_id` — the field_library recipe.
 *
 * ⚠️ NOT every Feature #10 toggle lives here. Tenant maintenance mode is two REAL COLUMNS on `tenants`
 * (2026_08_06_000004), because the guest runtime must consult it on every public form render and the
 * tenant model is already resolved there — a `settings` row would put a query in front of every respondent.
 * §20's Design Notes record the split; do not "unify" it back without re-reading that note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // NULL ⇒ platform-level setting (super-admin only)

            $table->string('key', 100);
            $table->jsonb('value');

            $table->uuid('updated_by')->nullable(); // NULL for a seeded default with no human actor

            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // tenant_id leads (ADR-0002 §D1) — the read is always "this tenant's whole map".
            $table->index(['tenant_id', 'key']);
        });

        // One platform row per key. This is the half a plain UNIQUE(tenant_id, key) would NOT enforce.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX settings_platform_key_unique
                ON settings (key)
                WHERE tenant_id IS NULL
        SQL);

        // One row per key per tenant.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX settings_tenant_key_unique
                ON settings (tenant_id, key)
                WHERE tenant_id IS NOT NULL
        SQL);

        // Widened SELECT (own rows OR platform rows); writes stay strict, so a tenant connection can never
        // author or mutate a platform row. The super-admin console's writes are layered on separately in
        // 2026_08_06_000002 — see that migration for why a bypass is required rather than optional.
        withTenantIsolation('settings', 'nullable_global');
    }

    public function down(): void
    {
        // Dropping the table removes its policies and both partial indexes with it.
        Schema::dropIfExists('settings');
    }
};
