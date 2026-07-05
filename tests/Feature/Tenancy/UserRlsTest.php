<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment B1 — user-level RLS fuzz pack (the DoD). Runs as `meridian_app`.
|--------------------------------------------------------------------------
| Proves the three shapes A emitted and B1 applied: the `users` join-shape SELECT (self + active
| co-tenant membership), the app.current_user_id-unset fail-closed default, and the belongs-to-me
| shape on user_ui_preferences. Plus strict isolation on personal_access_tokens. Seeding runs on
| meridian_app: the `users` INSERT policy is permissive (registration has no context) and the
| membership/preference inserts run with the matching context, so no privileged connection is needed.
*/

beforeEach(fn () => TenantContext::flush());

it('fails closed on users with no context, and reveals a user to itself', function (): void {
    $u = User::factory()->create(); // permissive users INSERT (no context needed)

    // No tenant + no user context ⇒ the join-shape policy matches nothing.
    TenantContext::applyLocal(null, null);
    expect(DB::table('users')->count())->toBe(0);

    // A user is always visible to itself via the `id = app.current_user_id` branch.
    TenantContext::applyLocal(null, $u->id);
    expect(DB::table('users')->where('id', $u->id)->exists())->toBeTrue();
});

it('reveals a user only within a tenant they have an active membership in', function (): void {
    $tenantA = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $tenantB = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);
    $member = User::factory()->create();
    $viewer = User::factory()->create(); // the querying admin (context user)

    // Active membership in A (tenant_users INSERT is strict → create it under A's context).
    TenantContext::applyLocal($tenantA->id, null);
    TenantUser::create(['user_id' => $member->id, 'status' => 'active']);

    // Under A's context, an A-admin sees the member; under B's context they do not.
    TenantContext::applyLocal($tenantA->id, $viewer->id);
    expect(DB::table('users')->where('id', $member->id)->exists())->toBeTrue();

    TenantContext::applyLocal($tenantB->id, $viewer->id);
    expect(DB::table('users')->where('id', $member->id)->exists())->toBeFalse();
});

it('does not reveal a member whose membership is not active', function (): void {
    $tenantA = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $member = User::factory()->create();
    $viewer = User::factory()->create();

    TenantContext::applyLocal($tenantA->id, null);
    TenantUser::create(['user_id' => $member->id, 'status' => 'suspended']); // not 'active'

    TenantContext::applyLocal($tenantA->id, $viewer->id);
    expect(DB::table('users')->where('id', $member->id)->exists())->toBeFalse();
});

it('enforces belongs-to-me on user_ui_preferences', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    // Insert requires the row's user_id to equal the current user context.
    TenantContext::applyLocal(null, $owner->id);
    UserUiPreference::create(['user_id' => $owner->id, 'theme_mode' => 'dark']);

    // Visible to the owner …
    expect(DB::table('user_ui_preferences')->count())->toBe(1);

    // … invisible to another user and with no context (fail-closed) …
    TenantContext::applyLocal(null, $other->id);
    expect(DB::table('user_ui_preferences')->count())->toBe(0);
    TenantContext::applyLocal(null, null);
    expect(DB::table('user_ui_preferences')->count())->toBe(0);

    // … and one user cannot author another user's preferences.
    TenantContext::applyLocal(null, $other->id);
    expect(fn () => DB::table('user_ui_preferences')->insert([
        'id' => Uuid::uuid7()->toString(),
        'user_id' => $owner->id,
        'theme_mode' => 'light',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('strictly isolates personal_access_tokens by tenant', function (): void {
    $tenantA = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $tenantB = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);
    $user = User::factory()->create();

    TenantContext::applyLocal($tenantA->id, $user->id);
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
        'tenant_id' => $tenantA->id,
        'name' => 'a-key',
        'token' => hash('sha256', 'a-token'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Visible under A, invisible under B.
    TenantContext::applyLocal($tenantA->id, $user->id);
    expect(DB::table('personal_access_tokens')->count())->toBe(1);
    TenantContext::applyLocal($tenantB->id, $user->id);
    expect(DB::table('personal_access_tokens')->count())->toBe(0);

    // A tenant cannot mint a token for another tenant.
    TenantContext::applyLocal($tenantA->id, $user->id);
    expect(fn () => DB::table('personal_access_tokens')->insert([
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
        'tenant_id' => $tenantB->id,
        'name' => 'smuggled',
        'token' => hash('sha256', 'b-token'),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
