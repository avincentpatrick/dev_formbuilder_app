<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The global `users` identity table (multi-tenancy-rbac-design.md §6). One identity per person across
 * every tenant — never duplicated per tenant. UUIDv7 PK (client-generated via App\Models\User's
 * HasUuidv7), Fortify 2FA columns, the `is_super_admin` platform flag (ADR-0002 §D3, replacing
 * legacy's `id === 1`), and platform ToS/privacy consent timestamps.
 *
 * RLS is deliberately NOT attached here: the `users` join-shape SELECT policy references
 * `tenant_users`, which doesn't exist until a later migration — see `..._apply_users_rls.php`.
 * `users` has no `tenant_id` column, so the migration linter does not require the helper here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 255)->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password', 255);
            $table->rememberToken();

            // Laravel Fortify two-factor authentication (PRD Feature #14). Encrypted; NULL until enrolled.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();

            // Platform-wide super-admin flag — global, never per-tenant, never a Spatie role (RBAC §9).
            $table->boolean('is_super_admin')->default(false);

            // UX convenience only (default tenant on next login); FK to tenants added in apply_users_rls
            // once the tenants table exists. NOT authoritative for any authorization decision.
            $table->uuid('last_active_tenant_id')->nullable();

            // Platform consent (data-privacy-gdpr-compliance.md §6) — separate, independently revisable.
            $table->timestampTz('tos_accepted_at')->nullable();
            $table->timestampTz('privacy_policy_accepted_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index(); // matches users.id (uuid)
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
