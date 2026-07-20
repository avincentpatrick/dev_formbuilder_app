<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire `form_collaborators` (Increment G10a) — `resource_grants` is now the single per-instance access
 * table. Runs only after the preceding migration has copied and verified every row.
 *
 * Deliberately does NOT delete 2026_07_06_000207_create_form_collaborators_table.php: `migrate:fresh`
 * (which the whole test suite relies on) replays every migration in order, so removing the create while
 * keeping the backfill would make the backfill SELECT from a nonexistent table. On a fresh database the
 * table is created and dropped milliseconds later, and the backfill's `<` guard makes 0-of-0 pass.
 *
 * The count re-check below is not redundant with the backfill's own guard: these are separate
 * migrations, an operator may run them separately, and this is the last moment the source rows exist.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Read both counts on the privileged connection — under FORCE RLS with no tenant GUC the app
        // connection sees zero rows in either table, which would make this guard vacuously pass.
        $privileged = DB::connection('pgsql_privileged');

        $source = (int) $privileged->table('form_collaborators')->count();
        $copied = (int) $privileged->table('resource_grants')->where('scopeable_type', 'form')->count();

        if ($copied < $source) {
            throw new RuntimeException(
                "Refusing to drop form_collaborators: {$source} source rows but only {$copied} form grants. "
                .'Re-run the G10a backfill migration first.'
            );
        }

        Schema::dropIfExists('form_collaborators');
    }

    /**
     * Restores the table SHAPE (the preceding migration's down() restores the rows). The isolation call
     * is NOT optional — both because scripts/migration-lint.php parses the whole file AST without
     * distinguishing up() from down(), so the Schema::create here trips its `declares_tenant_column`
     * rule and demands a matching isolation call; and because it is simply correct: a recreated table
     * with no RLS policy is the worst possible rollback outcome.
     */
    public function down(): void
    {
        Schema::create('form_collaborators', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('capacity', 10);
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'form_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        withTenantIsolation('form_collaborators');
    }
};
