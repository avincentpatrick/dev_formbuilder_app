<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant membership + invite lifecycle (multi-tenancy-rbac-design.md §7). One row per (tenant, user)
 * pair carrying the invite/membership state. This is the table the `users` visibility RLS policy
 * joins against to decide whether a user is visible within the current tenant — so it must exist
 * before that policy is applied (see the next migration).
 *
 * Strictly tenant-scoped → the standard strict RLS shape via withTenantIsolation().
 *
 * `invited_role_id` has NO FK yet — the `roles` table lands in Increment B2 (Spatie RBAC); the FK is
 * added then. The full invite/accept/decline/remove/ownership-transfer behavior is also B2; B1 lands
 * the table + isolation only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 15)->default('invited'); // TenantUserStatus enum
            $table->uuid('invited_role_id')->nullable();       // FK -> roles added in B2
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('invite_expires_at')->nullable();
            $table->string('invite_token', 128)->nullable(); // hashed at rest
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id']); // at most one membership record per tenant, ever
            $table->index(['tenant_id', 'status']);    // tenant_id leads (ADR-0002 §D1); status for the users-visibility join
        });

        withTenantIsolation('tenant_users');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
