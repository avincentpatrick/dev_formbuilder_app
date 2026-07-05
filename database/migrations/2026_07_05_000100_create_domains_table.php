<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stancl/tenancy's `domains` table (subdomain → tenant lookup). Re-dated to run after `tenants`
 * (its FK target) and reshaped so `tenant_id` is a uuid matching our `tenants.id`.
 *
 * Deliberately RLS-EXEMPT: this is the table read BEFORE any tenant context is established, in order
 * to resolve which tenant a request belongs to — applying tenant-scoped RLS to it would be circular.
 * It is added to EXEMPT_TABLES in scripts/migration-lint.php so the linter does not flag its
 * `tenant_id` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('domain', 255)->unique();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
