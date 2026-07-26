<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the composite UNIQUE `(tenant_id, id)` to `forms` — the referenced-column target a PostgreSQL
 * composite foreign key requires (H13a). `webhook_endpoints.(tenant_id, form_id)` references it so a
 * form-scoped endpoint can carry a tenant-safe FK (ADR-0002 §D5: referential actions BYPASS RLS, so a
 * single-column `form_id` FK could follow a delete across tenants). `forms.id` is already the primary key
 * (unique on its own); this composite is additive and purely to serve the FK — the `scope_nodes` table has
 * the same `(tenant_id, id)` unique for the identical reason.
 *
 * Alter-only (no `Schema::create`) → scripts/migration-lint.php short-circuits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'forms_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropUnique('forms_tenant_id_id_unique');
        });
    }
};
