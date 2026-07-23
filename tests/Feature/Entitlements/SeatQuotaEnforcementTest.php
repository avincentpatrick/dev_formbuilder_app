<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\TenantUserStatus;
use App\Enums\UsageMetric;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// Hard-block active_seats (ADR-0008 §D4), RESERVE-ON-INVITE: the gauge counts Active + pending Invited
// (matching listMembers()), so a pending invite reserves a seat, over-provisioning is impossible, and
// accept() is count-neutral. remove() frees the seat.
//
// NOTE: accept/remove pre-create the invited user + TenantUser row directly (the MembershipLifecycleTest
// pattern) rather than round-tripping invite() — invite() resolves the invitee on the separate `pgsql_auth`
// connection, which cannot see a row created uncommitted on the default connection under RefreshDatabase.

beforeEach(function (): void {
    TenantContext::flush();
    Notification::fake(); // invite() sends an on-demand mail notification
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner'); // one active seat
    $this->members = app(TenantMembershipService::class);
});

/** Cap active_seats to `$limit` while leaving every other quota unlimited. */
function h5bCapSeats(int $limit): void
{
    $plan = Plan::factory()->tier(PlanTier::Free)
        ->withQuotas([UsageMetric::ActiveSeats->value => $limit])
        ->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget(); // refresh the scoped memo to the newly-assigned plan
}

/** A pending Invited membership row for a pre-made user (bypasses invite()'s pgsql_auth lookup). */
function h5bInviteRow(User $user, string $role = 'viewer'): TenantUser
{
    return TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole($role),
        'invited_at' => now(),
        'invite_expires_at' => now()->addDays(7),
    ]);
}

it('blocks an invite past the active_seats limit (reserve-on-invite)', function (): void {
    h5bCapSeats(2); // owner active = 1 seat

    $this->members->invite($this->tenant, 'a@x.test', 'viewer', $this->owner); // 2 (1 active + 1 invited)

    expect(fn () => $this->members->invite($this->tenant, 'b@x.test', 'viewer', $this->owner))
        ->toThrow(QuotaExceededException::class);

    // The blocked invite left no placeholder membership behind (rolled back inside the transaction).
    expect(TenantUser::query()->whereIn('status', ['active', 'invited'])->count())->toBe(2);
});

it('treats accept as count-neutral (invited→active consumes no new seat)', function (): void {
    $invitee = User::factory()->create();
    $invite = h5bInviteRow($invitee); // owner active + this invited = 2 reserved
    h5bCapSeats(2);

    $this->members->accept($invite, $invitee); // invited → active, still 2

    expect(TenantUser::query()->where('status', 'active')->count())->toBe(2); // owner + invitee

    // Still full at 2/2 — a further invite is blocked.
    expect(fn () => $this->members->invite($this->tenant, 'c@x.test', 'viewer', $this->owner))
        ->toThrow(QuotaExceededException::class);
});

it('counts a pending Invited row as a reserved seat', function (): void {
    $invitee = User::factory()->create();
    h5bInviteRow($invitee); // owner active (1) + this invited (1) = 2 reserved
    h5bCapSeats(2); // full at 2/2 precisely because the pending invite reserves a seat

    // Inviting a NEW person is blocked — the Invited row already occupies the second seat.
    expect(fn () => $this->members->invite($this->tenant, 'c@x.test', 'viewer', $this->owner))
        ->toThrow(QuotaExceededException::class);
});

it('frees a seat when a member is removed', function (): void {
    $member = User::factory()->create();
    makeActiveMember($member, 'reviewer'); // owner + member = 2 active
    h5bCapSeats(2); // full at 2/2

    expect(fn () => $this->members->invite($this->tenant, 'c@x.test', 'viewer', $this->owner))
        ->toThrow(QuotaExceededException::class);

    $this->members->remove($this->tenant, $member, $this->owner); // frees a seat

    $this->members->invite($this->tenant, 'c@x.test', 'viewer', $this->owner); // now allowed

    expect(TenantUser::query()->whereIn('status', ['active', 'invited'])->count())->toBe(2); // owner + new invite
});

it('never blocks invites on an unlimited plan', function (): void {
    assignUnlimitedPlan();

    foreach (['a', 'b', 'c', 'd', 'e'] as $n) {
        $this->members->invite($this->tenant, "{$n}@x.test", 'viewer', $this->owner);
    }

    expect(TenantUser::query()->where('status', 'invited')->count())->toBe(5);
});
