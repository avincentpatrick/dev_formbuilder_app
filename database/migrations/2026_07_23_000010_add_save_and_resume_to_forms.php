<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H10 — the per-form save-and-resume opt-in (docs/ux/form-filling-ux-flow.md §5.2). Save-and-resume
 * is gated two ways: the tenant plan (`feature:save_and_resume`, Starter+, enforced at the H9b route) AND this
 * per-form toggle, so a form owner controls whether respondents of a given form see "Save and finish later".
 * Defaults to `false` — enabling it is a deliberate act, and a form that collected no save-and-resume before
 * H10 keeps behaving the same.
 *
 * No RLS re-emit: `forms` already carries strict RLS and policies are row predicates (same as
 * 2026_07_20_000002_add_scope_node_id_to_forms). Alter-only, so the migration linter skips it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->boolean('save_and_resume')->default(false)->after('single_page_mode');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn('save_and_resume');
        });
    }
};
