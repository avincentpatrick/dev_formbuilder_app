<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the forms ⇄ form_versions foreign-key cycle. `forms` was created with its two version
 * pointers as bare uuid columns; now that `form_versions` exists, this alter-only migration adds the
 * FKs. Same technique as 2026_07_05_032600_add_invited_role_fk_to_tenant_users. Creates no table and
 * declares no tenant_id, so the migration linter correctly skips it.
 *
 * ON DELETE SET NULL (not cascade): deleting a version must never delete the durable form record — an
 * archived form's last-published version pointer simply becomes NULL. `down()` drops both FKs BEFORE
 * the form_versions table can be dropped by 000202's own down(), so migrate:rollback resolves cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->foreign('current_published_version_id')->references('id')->on('form_versions')->nullOnDelete();
            $table->foreign('draft_version_id')->references('id')->on('form_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropForeign(['current_published_version_id']);
            $table->dropForeign(['draft_version_id']);
        });
    }
};
