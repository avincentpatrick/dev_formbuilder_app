<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant maintenance mode (Increment I5, PRD Feature #10) — the one Feature #10 toggle that is a COLUMN
 * rather than a `settings` row, and the reason is measured rather than stylistic.
 *
 * ── WHY NOT A `settings` ROW LIKE EVERY OTHER TOGGLE ────────────────────────────────────────────────────
 * This flag is consulted on the GUEST RUNTIME: every public form render and every guest submission. The
 * tenant model is ALREADY resolved on the render path (stancl's identification middleware binds it before
 * the controller runs), so a column costs nothing there, while a `settings` row would put an extra query
 * in front of every respondent, forever. Caching would not help — `CACHE_STORE=database`, so a cache read
 * is the same round trip as the query it replaces. `draft_ttl_days` (H10) and the H23a2 branding columns
 * are real columns for exactly this reason; this is the third instance of that rule, not an exception.
 *
 * `default(false)` is the fail-closed value: an existing tenant keeps serving forms exactly as it did.
 *
 * ⚠️ BOTH COLUMNS MUST BE LISTED IN {@see Tenant::getCustomColumns()}. stancl treats any attribute not on
 * that whitelist as a virtual `data` json key: maintenance mode would appear to save, read back null, and
 * simply never apply — with a green write path the whole way. `TenantMaintenanceColumnsTest` pins these two
 * columns and `TenantColumnWhitelistTest` pins the whole list by set equality; this line cited
 * `TenantCustomColumnsTest` until P2a, which is a file that has never existed.
 *
 * `tenants` is the RLS-EXEMPT discriminator table and stays exempt; this is alter-only, so it is outside
 * scripts/migration-lint.php's scope (which keys on Schema::create) regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('maintenance_mode')->default(false);
            // The respondent-facing notice. NULL ⇒ show the product's own generic copy.
            $table->text('maintenance_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['maintenance_mode', 'maintenance_message']);
        });
    }
};
