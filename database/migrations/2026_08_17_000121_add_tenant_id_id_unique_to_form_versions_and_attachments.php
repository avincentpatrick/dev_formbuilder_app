<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the composite UNIQUE `(tenant_id, id)` to `form_versions` and `attachments` (M132, `R-bf49e4c1`) — the
 * referenced-column target a PostgreSQL composite foreign key requires, for the reason
 * `2026_07_26_000001_add_tenant_id_unique_to_forms` gives: referential actions BYPASS RLS (ADR-0002 §D5), so a
 * single-column key into either table could follow a delete across tenants.
 *
 * The next migration's `form_version_reference_files` is the first table to point at both. `id` is each table's
 * primary key and unique on its own; these composites are additive and exist only to serve those keys.
 *
 * Alter-only (no `Schema::create`), so `scripts/migration-lint.php` short-circuits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_versions', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'form_versions_tenant_id_id_unique');
        });

        Schema::table('attachments', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'attachments_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropUnique('attachments_tenant_id_id_unique');
        });

        Schema::table('form_versions', function (Blueprint $table): void {
            $table->dropUnique('form_versions_tenant_id_id_unique');
        });
    }
};
