<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chart a member built and kept on `/explore` (M235, ADR-0025 §D8).
 *
 * ── A DECLARATION, NEVER A RESULT ───────────────────────────────────────────────────────────────────
 * `definition` holds an `ExplorerQuery` — which source, which fields, which range — and no data. The chart
 * is run again every time it is opened, as whoever is opening it. So a saved chart cannot go stale, and
 * sharing one cannot show a colleague a row they could not have queried themselves.
 *
 * ── STRICT RLS WITH A `user_id`, NOT THE `belongs_to_user` VARIANT ──────────────────────────────────
 * The same choice as `saved_report_views`, for the reason ADR-0011 §D8 gives: that variant carries no
 * tenant predicate, so a consultant in two workspaces would read one's charts from the other. Whose chart
 * it is, and whether colleagues may see it (`is_shared`), are application predicates — the policy and
 * `ExplorerChart::scopeVisibleTo()`.
 *
 * ── THE NAME IS UNIQUE PER OWNER ────────────────────────────────────────────────────────────────────
 * Two members may each keep a "Weekly intake"; one member may not keep two. The service turns the
 * violation into a field error.
 *
 * Nothing is withheld from the tenant extract: a name the member typed, the declaration they built and
 * whether they shared it. The table joins {@see TenantScopedTables::STRICT}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explorer_charts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // `users` is a global table, so a single-column key is correct here.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);
            $table->jsonb('definition')->default('{}');
            $table->boolean('is_shared')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id', 'name'], 'explorer_charts_tenant_user_name_unique');
        });

        withTenantIsolation('explorer_charts'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('explorer_charts');
    }
};
