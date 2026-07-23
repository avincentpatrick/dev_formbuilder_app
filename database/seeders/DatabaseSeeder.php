<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The fixed RBAC catalog (roles/permissions/matrix) — global rows, seeded via pgsql_privileged.
        $this->call(RolePermissionSeeder::class);

        // The platform form-template gallery (G9a) — NULL-tenant rows, also seeded via pgsql_privileged.
        $this->call(PlatformTemplateSeeder::class);

        // The platform question library (G9b) — NULL-tenant rows, also seeded via pgsql_privileged.
        $this->call(PlatformFieldLibrarySeeder::class);

        // The global plan catalog (H5a) — the tenant_id-free `plans` table; seeded on the DEFAULT
        // connection (it has no RLS), idempotent on `code`.
        $this->call(PlanSeeder::class);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // A platform super-admin (RBAC §9) for exercising the B2c console in dev. Left WITHOUT confirmed
        // two-factor auth so the mandatory-MFA enrollment redirect (security §8) is exercised on first
        // console access. Seeded on the default connection — the permissive `users` INSERT policy allows
        // it, same path as the Test User above.
        User::factory()->superAdmin()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@meridian.test',
        ]);
    }
}
