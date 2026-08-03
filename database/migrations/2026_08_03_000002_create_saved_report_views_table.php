<?php

declare(strict_types=1);

use App\Support\Analytics\AnalyticsQuery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's saved analytics report (`docs/PRD.md:204`, ADR-0011 §D8, Increment H24a).
 *
 * ── STRICT RLS, even though every row carries a `user_id` ───────────────────────────────────────────────
 * The obvious move — given `user_ui_preferences` and the PRD's own words "per user" — is the
 * `belongs_to_user` isolation variant. §D8 forbids it, and the reason is concrete rather than stylistic:
 * `TenantIsolation::belongsToUserSql()` emits policies keyed ONLY on `user_id = current_setting('app.current_user_id')`,
 * **with no tenant predicate at all**. For a consultant who belongs to two tenants, that is a cross-tenant
 * metadata leak — their Acme report definitions would be readable while acting for Globex.
 *
 * `scripts/migration-lint.php` cannot catch that mistake: it checks only that SOME `withTenantIsolation()`
 * call exists in a migration that declares `tenant_id`, never which variant. Hence this paragraph.
 *
 * ── `strict` scopes to the TENANT, so per-user privacy is an APPLICATION predicate ──────────────────────
 * Under `strict`, an Owner's `SELECT * FROM saved_report_views` returns every member's views. "Per user"
 * is therefore enforced by `SavedReportView::scopeOwnedBy()` on every read path and by
 * `SavedReportViewPolicy`'s owner check on every single-row action — not by RLS. Stated here so the next
 * reader does not assume the database is already doing it.
 *
 * ── `definition` stores a DECLARATION, resolved at read time ────────────────────────────────────────────
 * Not a materialized result and not resolved ids-plus-labels: an {@see AnalyticsQuery}
 * payload, carrying its own `v` schema tag. §D8's point is that a view naming a `field_key` — or a form, or
 * a scope node — that no longer exists must degrade to a STATED refusal rather than to a wrong number, and
 * that only works if resolution happens when the view is read.
 *
 * No soft deletes: a saved view is user-owned convenience metadata with no audit claim on it, and a
 * `deleted_at` that every read path must remember to filter is a landmine (the same argument
 * `scope_nodes` records for itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_report_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `users` is a global (tenant-free) table, so a single-column FK is correct here — the composite
            // (tenant_id, …) form of ADR-0002 §D5 applies to tenant-scoped parents, which this is not.
            // Matches resource_grants. cascadeOnDelete: a view dies with its author. A REMOVED MEMBER keeps
            // their users row, so their views persist and are visible to nobody — accepted, and recorded
            // rather than discovered.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('name', 150);
            $table->jsonb('definition')->default('{}');
            $table->timestampsTz();

            // One name per user per tenant. A duplicate raises 23505, which SavedReportViewService maps to a
            // 422 rather than letting it surface as a 500.
            $table->unique(['tenant_id', 'user_id', 'name'], 'saved_report_views_tenant_user_name_unique');

            // tenant_id LEADS (ADR-0002 §D1) — every read is "this tenant, this user".
            $table->index(['tenant_id', 'user_id'], 'saved_report_views_tenant_user_idx');
        });

        withTenantIsolation('saved_report_views'); // strict — NOT belongs_to_user; see the docblock (§D8)
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_report_views');
    }
};
