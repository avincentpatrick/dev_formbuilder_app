<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a respondent goes after the thank-you screen (M130, `R-db169c29`, `D76`): another form of the same
 * workspace, or a web address — at most one, never both, and never the form itself.
 *
 * ON `forms`, NOT IN THE VERSION SNAPSHOT, and the placement has a consequence worth stating: like the
 * confirmation message beside it, the destination is after-submit behaviour an author changes without a
 * republish, so a change reaches the next submission at once and no version freezes it. The submit response
 * resolves it at acceptance, so a respondent who opened the form an hour ago goes where the author points now.
 *
 * No `withTenantIsolation()` here: `forms` already carries strict RLS, and adding columns does not weaken a row
 * predicate. The form FK is composite for `scope_node_id`'s reason (ADR-0002 §D5): referential actions run
 * BYPASSING RLS, so a single-column FK would let one tenant's delete write a SET NULL into another's row.
 * `ON DELETE SET NULL (redirect_form_id)` names the one column, so a deleted target un-sets the destination and
 * leaves the row's tenancy intact. A soft-deleted target keeps its id here; the resolver treats it as unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->string('redirect_url', 2000)->nullable();
            $table->uuid('redirect_form_id')->nullable();
            $table->index(['tenant_id', 'redirect_form_id']); // tenant_id leads (ADR-0002 §D1)
        });

        // PostgreSQL 15+ column-list SET NULL, as `forms_scope_node_fk` does: a plain nullOnDelete() on a
        // composite key would try to NULL `tenant_id` too.
        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_redirect_form_fk '
            .'FOREIGN KEY (tenant_id, redirect_form_id) REFERENCES forms (tenant_id, id) '
            .'ON UPDATE CASCADE ON DELETE SET NULL (redirect_form_id)'
        );

        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_redirect_one_target_check '
            .'CHECK (redirect_url IS NULL OR redirect_form_id IS NULL)'
        );

        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_redirect_not_self_check '
            .'CHECK (redirect_form_id IS DISTINCT FROM id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_redirect_not_self_check');
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_redirect_one_target_check');
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_redirect_form_fk');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'redirect_form_id']);
            $table->dropColumn(['redirect_url', 'redirect_form_id']);
        });
    }
};
