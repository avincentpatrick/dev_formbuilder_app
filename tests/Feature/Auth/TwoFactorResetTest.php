<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Exceptions\Tenancy\MembershipException;
use App\Models\Audit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\TwoFactorResetService;
use App\Support\Tenancy\SuperAdminContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment M107 — the administrative two-factor reset (`D37`, R-1ad2304d).
|--------------------------------------------------------------------------
| `D37` answered the lockout with an admin reset recorded in the audit log, performed by a workspace
| Owner OR the platform operator. Both surfaces land in one service, so these cases exercise the service
| against real PostgreSQL through the same RLS the request establishes, plus the two routes' own gates.
|
| ⛔ EVERY IDENTITY HERE IS COMMITTED, AND THAT IS NOT A STYLE CHOICE. The service reads and writes on
| `pgsql_auth`, a SEPARATE CONNECTION that cannot see `RefreshDatabase`'s uncommitted rows. A test built
| with `User::factory()->create()` would have its target vanish inside the service and fail as though the
| product were broken — the exact trap `TenantMembershipService::listMembers()`'s docblock records and
| `committedTenantIdentity()` (tests/Pest.php) exists for.
|
| ⚠️ The global role catalog is seeded on the privileged connection and not cleaned up, matching
| `MembershipLifecycleTest`: a privileged DELETE of a parent `roles` row deadlocks the uncommitted
| child assignments.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * Enrol a COMMITTED identity in two-factor, the way a real enrolment leaves the row.
 *
 * Writes over `pgsql_privileged` in one statement: the `users` UPDATE policy is `USING (true)` but the
 * SELECT policy governs the WHERE, so an app-connection write with no matching context would affect zero
 * rows and throw nothing — the silent-zero-row family this whole increment is about.
 */
function enrolInTwoFactor(User $user): void
{
    DB::connection('pgsql_privileged')->table('users')->where('id', (string) $user->id)->update([
        'two_factor_secret' => encrypt('ENROLLEDSECRET123'),
        'two_factor_recovery_codes' => encrypt((string) json_encode(['aaaaaaaaaa-bbbbbbbbbb'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

/** Read the three columns back from a connection that can always see them. */
function twoFactorColumns(User $user): object
{
    return DB::connection('pgsql_privileged')
        ->table('users')
        ->where('id', (string) $user->id)
        ->first(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
}

/*
|--------------------------------------------------------------------------
| The workspace surface
|--------------------------------------------------------------------------
*/

it('clears all three columns for an active member and records it in the tenant ledger', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha-tfa']);
    $owner = committedTenantIdentity('Owner');
    $member = committedTenantIdentity('Member');
    enterTenant($tenant->id, $owner->id);
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();
    makeActiveMember($owner, 'owner');
    makeActiveMember($member, 'form_editor');
    enrolInTwoFactor($member);

    app(TwoFactorResetService::class)->resetForMember($tenant, $member, $owner);

    $columns = twoFactorColumns($member);

    // ⚠️ ALL THREE, asserted individually. The enforcement middleware reads only the timestamp while
    // Fortify's `hasEnabledTwoFactorAuthentication()` reads the secret, so clearing one and leaving the
    // other produces an account the two halves of the product disagree about.
    expect($columns->two_factor_secret)->toBeNull()
        ->and($columns->two_factor_recovery_codes)->toBeNull()
        ->and($columns->two_factor_confirmed_at)->toBeNull();

    $audit = Audit::query()->where('event', AuditEvent::TwoFactorReset->value)->sole();

    expect($audit->auditable_type)->toBe('users')
        ->and((string) $audit->auditable_id)->toBe((string) $member->id)
        ->and((string) $audit->user_id)->toBe((string) $owner->id)
        ->and((string) $audit->tenant_id)->toBe((string) $tenant->id)
        // No old/new: the only values that moved are a TOTP secret and a recovery-code list, and a
        // compliance ledger is the last place either belongs.
        ->and($audit->old_values)->toBeNull()
        ->and($audit->new_values)->toBeNull()
        // Not an impersonation. An Owner acts in their own name, so nothing renders "Platform operator".
        ->and($audit->acting_as_user_id)->toBeNull();
});

it('refuses to reset your own enrolment, so it cannot become a way around password.confirm', function (): void {
    $tenant = Tenant::create(['name' => 'Beta', 'slug' => 'beta-tfa']);
    $owner = committedTenantIdentity('Owner');
    enterTenant($tenant->id, $owner->id);
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();
    makeActiveMember($owner, 'owner');
    enrolInTwoFactor($owner);

    expect(fn () => app(TwoFactorResetService::class)->resetForMember($tenant, $owner, $owner))
        ->toThrow(MembershipException::class);

    // And it is a REFUSAL, not a silent no-op: the enrolment survives.
    expect(twoFactorColumns($owner)->two_factor_confirmed_at)->not->toBeNull();
});

it('refuses a super-admin target from a workspace, fail-closed', function (): void {
    $tenant = Tenant::create(['name' => 'Gamma', 'slug' => 'gamma-tfa']);
    $owner = committedTenantIdentity('Owner');
    $staff = committedTenantIdentity('Platform staff');
    DB::connection('pgsql_privileged')->table('users')
        ->where('id', (string) $staff->id)->update(['is_super_admin' => true]);
    enterTenant($tenant->id, $owner->id);
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();
    makeActiveMember($owner, 'owner');
    makeActiveMember($staff, 'form_editor');
    enrolInTwoFactor($staff);

    expect(fn () => app(TwoFactorResetService::class)->resetForMember($tenant, $staff, $owner))
        ->toThrow(MembershipException::class);

    expect(twoFactorColumns($staff)->two_factor_confirmed_at)->not->toBeNull();
});

it('refuses somebody who is not an active member of the acting workspace', function (): void {
    $tenant = Tenant::create(['name' => 'Delta', 'slug' => 'delta-tfa']);
    $owner = committedTenantIdentity('Owner');
    $stranger = committedTenantIdentity('Stranger');
    enterTenant($tenant->id, $owner->id);
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();
    makeActiveMember($owner, 'owner');
    enrolInTwoFactor($stranger);

    expect(fn () => app(TwoFactorResetService::class)->resetForMember($tenant, $stranger, $owner))
        ->toThrow(MembershipException::class);

    expect(twoFactorColumns($stranger)->two_factor_confirmed_at)->not->toBeNull();
});

it('refuses a member who was never enrolled, rather than reporting a rescue that did not happen', function (): void {
    $tenant = Tenant::create(['name' => 'Epsilon', 'slug' => 'epsilon-tfa']);
    $owner = committedTenantIdentity('Owner');
    $member = committedTenantIdentity('Member');
    enterTenant($tenant->id, $owner->id);
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();
    makeActiveMember($owner, 'owner');
    makeActiveMember($member, 'form_editor');

    expect(fn () => app(TwoFactorResetService::class)->resetForMember($tenant, $member, $owner))
        ->toThrow(MembershipException::class);

    // ⚠️ AND NO LEDGER ROW. A refusal that still audited would put a reset in the compliance record that
    // never happened, which is worse than the silence.
    expect(Audit::query()->where('event', AuditEvent::TwoFactorReset->value)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The platform console surface
|--------------------------------------------------------------------------
*/

it('clears the enrolment of somebody who belongs to no workspace, into the platform ledger', function (): void {
    // ⚠️ THE CASE THE WORKSPACE SURFACE STRUCTURALLY CANNOT SERVE, and the shape `E2eSeeder`'s own
    // two-factor fixture has: an identity with no membership anywhere. `/members` can never list them.
    $operator = committedTenantIdentity('Operator');
    DB::connection('pgsql_privileged')->table('users')
        ->where('id', (string) $operator->id)->update(['is_super_admin' => true]);
    $orphan = committedTenantIdentity('No workspace');
    enrolInTwoFactor($orphan);

    app(TwoFactorResetService::class)->resetForOperator($orphan, $operator);

    $columns = twoFactorColumns($orphan);

    expect($columns->two_factor_secret)->toBeNull()
        ->and($columns->two_factor_recovery_codes)->toBeNull()
        ->and($columns->two_factor_confirmed_at)->toBeNull();

    // The console has no tenant context, so the row belongs to no tenant — the `tenant_id = NULL` shape
    // `SuperAdminService::updatePlatformSettings()` already writes through the superadmin INSERT bypass.
    //
    // ⚠️ READ THE WAY THE CONSOLE READS IT, inside the elevated context, and not over `pgsql_privileged`.
    // The bypass policies are gated on the `app.is_superadmin_context` GUC rather than on the role, so a
    // bare `pgsql_superadmin` read returns NOTHING — which is how the first run of this case failed, after
    // the write had already been fixed. Asserting it here means the case proves the platform audit page
    // can actually SEE the row, not merely that something was inserted somewhere.
    $audit = DB::connection(SuperAdminContext::CONNECTION)->transaction(function (): ?object {
        SuperAdminContext::applyLocal();

        return DB::connection(SuperAdminContext::CONNECTION)->table('audits')
            ->where('event', AuditEvent::TwoFactorReset->value)->first();
    });

    expect($audit)->not->toBeNull()
        ->and($audit->tenant_id)->toBeNull()
        ->and((string) $audit->auditable_id)->toBe((string) $orphan->id)
        ->and((string) $audit->user_id)->toBe((string) $operator->id);
});

it('lets the operator reset platform staff, because that forces re-enrolment rather than bypassing it', function (): void {
    // `EnsureSuperAdminMfa` redirects an un-enrolled super-admin straight to the enrolment landing, so
    // clearing one grants nobody anything. The refusal that matters is a WORKSPACE Owner reaching
    // platform staff, and that one is asserted above.
    $operator = committedTenantIdentity('Operator');
    $otherStaff = committedTenantIdentity('Other staff');
    DB::connection('pgsql_privileged')->table('users')
        ->whereIn('id', [(string) $operator->id, (string) $otherStaff->id])
        ->update(['is_super_admin' => true]);
    enrolInTwoFactor($otherStaff);

    app(TwoFactorResetService::class)->resetForOperator($otherStaff, $operator);

    expect(twoFactorColumns($otherStaff)->two_factor_confirmed_at)->toBeNull();
});

it('refuses an operator resetting themselves', function (): void {
    $operator = committedTenantIdentity('Operator');
    DB::connection('pgsql_privileged')->table('users')
        ->where('id', (string) $operator->id)->update(['is_super_admin' => true]);
    enrolInTwoFactor($operator);

    expect(fn () => app(TwoFactorResetService::class)->resetForOperator($operator, $operator))
        ->toThrow(MembershipException::class);

    expect(twoFactorColumns($operator)->two_factor_confirmed_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The connection choice, asserted as a property rather than trusted
|--------------------------------------------------------------------------
*/

it('writes on a connection that can see the row, so the reset is never a silent no-op', function (): void {
    // ⛔ THIS IS THE CASE THE WHOLE DESIGN EXISTS FOR, stated as the thing that would have gone wrong.
    // The console runs with NO tenant context. On the app connection, `users_users_visibility` matches
    // neither arm for another user's row, so the UPDATE affects zero rows, throws nothing, and every
    // caller reports success. The service uses `pgsql_auth` instead; this pins the difference.
    $operator = committedTenantIdentity('Operator');
    DB::connection('pgsql_privileged')->table('users')
        ->where('id', (string) $operator->id)->update(['is_super_admin' => true]);
    $target = committedTenantIdentity('Target');
    enrolInTwoFactor($target);

    TenantContext::flush();

    $onAppConnection = DB::connection(config('database.default'))->table('users')
        ->where('id', (string) $target->id)
        ->update(['two_factor_confirmed_at' => null]);

    // The premise: the app connection genuinely cannot do it, and says nothing about that.
    expect($onAppConnection)->toBe(0)
        ->and(twoFactorColumns($target)->two_factor_confirmed_at)->not->toBeNull();

    // The service, on the same state, does.
    app(TwoFactorResetService::class)->resetForOperator($target, $operator);

    expect(twoFactorColumns($target)->two_factor_confirmed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The workspace ROUTE's permission gate
|--------------------------------------------------------------------------
| `step-up` on this route is asserted by `StepUpReauthenticationTest`, which owns that alias and states
| the whole policy including its negative half. What is asserted HERE is the half that file cannot see:
| `tenant.members.two_factor_reset` is seeded to Owner ALONE, so an Admin — who holds every other member
| permission, including removal — must be refused.
*/

it('refuses an Admin over HTTP, because the permission is seeded to Owner alone', function (): void {
    $tenant = inboxTenant();
    $owner = committedTenantIdentity('Owner');
    $admin = committedTenantIdentity('Admin');
    $member = committedTenantIdentity('Member');

    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    enterTenant($tenant->id, $member->id);
    makeActiveMember($member, 'form_editor');
    Tenant::query()->whereKey($tenant->id)->update(['owner_user_id' => $owner->id]);
    enrolInTwoFactor($member);

    // Fresh confirmation, so this measures the `can:` gate and not `step-up` — otherwise a 302 to
    // confirm-password would pass for a refusal and the case would prove nothing about the permission.
    confirmPasswordNow(0);

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/members/{$member->id}/two-factor-reset")
        ->assertForbidden();

    // And it is a refusal rather than a failed write: the enrolment is untouched.
    expect(twoFactorColumns($member)->two_factor_confirmed_at)->not->toBeNull();
});

it('lets the Owner through the same door, end to end', function (): void {
    $tenant = inboxTenant();
    $owner = committedTenantIdentity('Owner');
    $member = committedTenantIdentity('Member');

    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    enterTenant($tenant->id, $member->id);
    makeActiveMember($member, 'form_editor');
    Tenant::query()->whereKey($tenant->id)->update(['owner_user_id' => $owner->id]);
    enrolInTwoFactor($member);

    confirmPasswordNow(0);

    $this->actingAs($owner)
        ->post("http://acme.meridian.test/members/{$member->id}/two-factor-reset")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(twoFactorColumns($member)->two_factor_confirmed_at)->toBeNull();
});
