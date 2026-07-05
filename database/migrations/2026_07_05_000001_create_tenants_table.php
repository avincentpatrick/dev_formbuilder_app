<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central `tenants` table (ADR-0002 §D1). RLS-EXEMPT by design: it is the discriminator every
 * other tenant-scoped table points at, and is accessed outside any single tenant's context. It has
 * no `tenant_id` column, so the migration linter does not require withTenantIsolation() here.
 *
 * Increment B1 adds the identity/ownership columns and stancl/tenancy's `data` json column (its
 * model spills any non-column attributes into it). `owner_user_id` FKs to `users` (created earlier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->json('data')->nullable(); // stancl/tenancy virtual-column store
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
