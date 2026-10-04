<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The folder a form is filed in (M131, `R-9e634897`, `D78`) — at most one, and none means Unfiled.
 *
 * On `forms`, not in the version snapshot: filing is where an author keeps a form, not anything a
 * respondent sees, so it changes without a publish and no version freezes it.
 *
 * ⛔ DELETING A FOLDER UNFILES ITS FORMS — THE DATABASE DOES IT, AND NEVER DELETES ONE (`D79`). The foreign key
 * is composite for `scope_node_id`'s reason (ADR-0002 §D5): referential actions run BYPASSING RLS, so a
 * single-column key would let one tenant's delete write into another's row. `ON DELETE SET NULL (folder_id)`
 * names the one column — a plain `nullOnDelete()` on a composite key would try to null `tenant_id` too — so
 * every form in a deleted folder, archived and soft-deleted ones included, is left in place and unfiled.
 *
 * No `withTenantIsolation()` here: `forms` already carries strict RLS, and a new column does not weaken it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->uuid('folder_id')->nullable();
            $table->index(['tenant_id', 'folder_id']); // tenant_id leads (ADR-0002 §D1)
        });

        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_folder_fk '
            .'FOREIGN KEY (tenant_id, folder_id) REFERENCES form_folders (tenant_id, id) '
            .'ON UPDATE CASCADE ON DELETE SET NULL (folder_id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_folder_fk');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'folder_id']);
            $table->dropColumn('folder_id');
        });
    }
};
