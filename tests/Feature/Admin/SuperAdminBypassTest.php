<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\SuperAdminContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment B2c — the super-admin cross-tenant RLS carve-out, at the DB level.
|--------------------------------------------------------------------------
| The `users` join-shape RLS (B1) hides a user from anyone who is not a co-tenant. The elevated
| `meridian_superadmin` role sees all users ONLY through the `superadmin_bypass` policy, and ONLY while
| SuperAdminService has opened the `app.is_superadmin_context` GUC (transaction-local). This fuzzes:
| context set ⇒ visible; unset ⇒ fail-closed; and that the carve-out is role-scoped (setting the GUC on
| the ordinary meridian_app connection grants nothing).
|
| Harness subtlety (same as AuthenticationTest): the pgsql_superadmin connection is SEPARATE from the
| default meridian_app one, so it can't see rows written inside RefreshDatabase's uncommitted
| transaction. The cross-tenant users are therefore seeded COMMITTED on the privileged connection, with
| distinct @superadmintest.local emails and NOT cleaned up in afterEach (a privileged DELETE would
| deadlock a test's own open transaction — the B2a/B2b lesson); migrate:fresh wipes them next run.
*/

/** Seed a committed user (visible across connections), returning its id. */
function committedCrossTenantUser(string $email): string
{
    return (string) User::on('pgsql_privileged')->forceCreate([
        'name' => 'Cross Tenant',
        'email' => $email,
        'password' => Hash::make(Str::random(40)),
        'email_verified_at' => now(),
    ])->id;
}

it('lets the elevated role read all users cross-tenant while the context is open', function (): void {
    $ids = [
        committedCrossTenantUser('one@superadmintest.local'),
        committedCrossTenantUser('two@superadmintest.local'),
    ];

    // The query MUST run inside the transaction: applyLocal() uses is_local=true, discarded outside it.
    DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($ids): void {
        SuperAdminContext::applyLocal();

        expect(User::on(SuperAdminContext::CONNECTION)->whereIn('id', $ids)->count())->toBe(2);
    });
});

it('fails closed for the elevated role when the context is NOT open', function (): void {
    $ids = [committedCrossTenantUser('closed@superadmintest.local')];

    DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($ids): void {
        // No applyLocal() — the gate `is_superadmin_context = 'true'` is NULL, and the join-shape
        // visibility policy matches nothing without tenant/user context ⇒ zero rows.
        expect(User::on(SuperAdminContext::CONNECTION)->whereIn('id', $ids)->exists())->toBeFalse();
    });
});

it('does not widen the ordinary app role even if the superadmin GUC is set there', function (): void {
    $ids = [committedCrossTenantUser('scoped@superadmintest.local')];

    // Set the GUC on the DEFAULT (meridian_app) connection. The bypass policy is scoped TO
    // meridian_superadmin, so it must not apply here — the app role still sees only co-tenants (none).
    DB::statement("SELECT set_config('app.is_superadmin_context', 'true', true)");

    expect(User::whereIn('id', $ids)->exists())->toBeFalse();
});
