<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the `tenant_users.invited_role_id` → `roles.id` FK deferred from Increment B1.
 *
 * B1 landed the column as a bare nullable uuid (the `roles` table did not exist yet); now that the
 * Spatie catalog exists, wire the constraint. `nullOnDelete` mirrors the other nullable actor FKs on
 * this table (invited_by / removed_by) — the fixed role catalog is never deleted in practice, so this
 * is a safety default rather than an expected path. No RLS change: this ALTER neither creates a table
 * nor declares a tenant_id column, and `tenant_users` already carries strict isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->foreign('invited_role_id')->references('id')->on('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropForeign(['invited_role_id']);
        });
    }
};
