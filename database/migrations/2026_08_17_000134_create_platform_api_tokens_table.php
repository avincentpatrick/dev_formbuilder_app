<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform support tokens (M175, `R-78f43f26`, `D139`, `D141`; data-dictionary §39, ADR-0023).
 *
 * The credential the dev session's feedback pull presents to the platform support API. It belongs to the
 * PLATFORM, not to a workspace, which is why it is not a Sanctum `personal_access_tokens` row: that table is
 * strictly tenant-isolated and read only under a tenant context, and a support token is used with none.
 *
 * ── NO `tenant_id`, NO RLS, NO GRANT TO THE ELEVATED ROLE ─────────────────────────────────────────────────
 * Nothing here is tenant data, so there is nothing to isolate; `scripts/migration-lint.php` checks tables that
 * carry `tenant_id` and `TenantScopedTables` sweeps only those. The app role reads it directly. The elevated
 * `meridian_superadmin` role is NOT granted it: the cross-workspace reads the token authorises still go
 * through `SuperAdminService`'s existing SELECT-only carve-out, and this table never joins that path.
 *
 * `id` is a uuid so a platform audit row can name the token (`audits.auditable_id` is a uuid). `token_hash` is
 * the sha256 of the plaintext, which is shown once and stored nowhere. `ability` has no default, so a row can
 * never acquire one by omission. `expires_at` is required — the token carries no session, so its lifetime is
 * the only clock on it — and the service caps it at 90 days.
 *
 * ⛔ ADDITIVE ONLY (`D137`): a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_api_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('ability', 50);
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_api_tokens');
    }
};
