<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attach RLS to `users` — deferred until now because the join-shape SELECT policy references
 * `tenant_users`, which the previous migration created (RBAC §6, "the fourth RLS shape").
 *
 *   - usersVisibility(): SELECT = self OR active co-tenant membership.
 *   - usersWritePolicies(): permissive INSERT (registration), own-row UPDATE/DELETE, plus the
 *     `TO meridian_auth` permissive carve-out so the pre-auth login/reset path can resolve + write.
 *   - GRANT the least-privilege `meridian_auth` role SELECT/UPDATE on `users` only.
 *   - Add the deferred `users.last_active_tenant_id` FK now that `tenants` exists.
 *
 * `users` has no `tenant_id` column and this migration creates no table, so the migration linter
 * does not require withTenantIsolation() here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $authRole = (string) config('database.connections.pgsql_auth.username', 'meridian_auth');
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $authRole) !== 1) {
            throw new RuntimeException("Unsafe pgsql_auth role name: {$authRole}");
        }

        // Owner (meridian_app) grants the pre-auth role access to the identity table — nothing else.
        DB::statement("GRANT SELECT, UPDATE ON users TO {$authRole}");

        TenantIsolation::usersVisibility('users');
        TenantIsolation::usersWritePolicies('users');

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('last_active_tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['last_active_tenant_id']);
        });

        DB::statement('ALTER TABLE users DISABLE ROW LEVEL SECURITY');

        /** @var list<object{policyname: string}> $policies */
        $policies = DB::select("SELECT policyname FROM pg_policies WHERE tablename = 'users'");
        foreach ($policies as $policy) {
            DB::statement('DROP POLICY IF EXISTS '.$policy->policyname.' ON users');
        }
    }
};
