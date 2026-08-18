<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Exceptions\Tenancy\MembershipException;
use App\Models\Audit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Member role change (Increment I8a) — the surface PRD Feature #14 named and the product never had.
|--------------------------------------------------------------------------
| Before I8a, `MemberController` offered index / invite / remove / transferOwnership and nothing else, so
| "step-up gates role changes" was a criterion nothing could satisfy — while
| TenantMembershipService::joinOpenTenant()'s docblock had been telling readers for months that "an Owner
| can promote them on the Members page in two clicks". This file covers the service that makes that true.
|
| ⚠️ THE FOUR REFUSALS ARE THE POINT, NOT THE HAPPY PATH. Each is a §5/§7 role-model invariant that no
| FormRequest could express, and each has a distinct failure mode if dropped:
|   · the Owner's role is immutable here      → a demoted Owner leaves the tenant ownerless while
|                                                `tenants.owner_user_id` still points at them
|   · `owner` is not assignable               → two Owners, breaking §5's uniqueness from the other side
|   · nobody may re-grade themselves          → an Admin self-promotes, or the last Admin demotes
|                                                themselves out of the ability to promote anyone back
|   · a no-op is refused                      → the ledger fills with `admin → admin` rows
|
| The permission is `tenant.roles.assign` — seeded to Owner/Admin and documented in RBAC §5 since Phase 0
| with ZERO code behind it, the same dormant-key situation I7a found on `feedback.view`. I8a consumes it
| rather than minting a new key, so the closed catalog stays closed.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->member = User::factory()->create();
    enterTenant($this->tenant->id, $this->member->id);
    makeActiveMember($this->member, 'viewer');

    Tenant::query()->whereKey($this->tenant->id)->update(['owner_user_id' => $this->owner->id]);
    $this->tenant->refresh();

    enterTenant($this->tenant->id, $this->owner->id);

    $this->service = app(TenantMembershipService::class);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

it('changes an active member\'s role and audits it against their user row', function (): void {
    $this->service->changeRole($this->tenant, $this->member, 'reviewer', $this->owner);

    enterTenant($this->tenant->id, $this->owner->id);

    $this->member->unsetRelation('roles');
    expect($this->member->getRoleNames()->first())->toBe('reviewer');

    // Same alias, event and auditable_type transferOwnership() already writes for a role change, so both
    // appear together under the audit viewer's "this resource's history" filter. NOT the model_has_roles
    // pivot — its composite PK has no surrogate id audits.auditable_id could address (audit spec §1).
    $audit = Audit::query()
        ->where('auditable_type', 'users')
        ->where('auditable_id', $this->member->id)
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->event)->toBe(AuditEvent::PermissionChanged);
    expect($audit->old_values['role'])->toBe('viewer');
    expect($audit->new_values['role'])->toBe('reviewer');
    expect($audit->user_id)->toBe($this->owner->id);
});

it('refuses to assign the owner role', function (): void {
    expect(fn () => $this->service->changeRole($this->tenant, $this->member, 'owner', $this->owner))
        ->toThrow(MembershipException::class, 'Ownership is established by transfer');
});

it('refuses to change the owner\'s own role', function (): void {
    // The mirror of the rule above: ownership moves by transfer in BOTH directions, so neither promoting
    // into it nor demoting out of it may happen here.
    $admin = User::factory()->create();
    enterTenant($this->tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    enterTenant($this->tenant->id, $admin->id);

    expect(fn () => $this->service->changeRole($this->tenant, $this->owner, 'viewer', $admin))
        ->toThrow(MembershipException::class, 'changes only by transferring ownership');
});

it('refuses to let an actor change their own role', function (): void {
    $admin = User::factory()->create();
    enterTenant($this->tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    enterTenant($this->tenant->id, $admin->id);

    expect(fn () => $this->service->changeRole($this->tenant, $admin, 'viewer', $admin))
        ->toThrow(MembershipException::class, 'cannot change your own role');
});

it('refuses a no-op rather than writing an unchanged audit row', function (): void {
    expect(fn () => $this->service->changeRole($this->tenant, $this->member, 'viewer', $this->owner))
        ->toThrow(MembershipException::class, 'already holds the viewer role');

    enterTenant($this->tenant->id, $this->owner->id);
    expect(Audit::query()->where('event', AuditEvent::PermissionChanged->value)->count())->toBe(0);
});

it('refuses a user who is not an active member', function (): void {
    $stranger = User::factory()->create();

    enterTenant($this->tenant->id, $this->owner->id);

    expect(fn () => $this->service->changeRole($this->tenant, $stranger, 'reviewer', $this->owner))
        ->toThrow(MembershipException::class, 'not an active member');
});

it('refuses an unknown role name', function (): void {
    expect(fn () => $this->service->changeRole($this->tenant, $this->member, 'superuser', $this->owner))
        ->toThrow(MembershipException::class, 'Unknown role');
});

it('keeps one role per tenant rather than accumulating them', function (): void {
    // syncRoles, not assignRole — §7's one-role-per-tenant invariant. assignRole would leave the member
    // holding viewer AND reviewer, and every `.own`/`.any` gate would then resolve to the union.
    $this->service->changeRole($this->tenant, $this->member, 'reviewer', $this->owner);
    enterTenant($this->tenant->id, $this->owner->id);
    $this->member->unsetRelation('roles');

    expect($this->member->getRoleNames()->toArray())->toBe(['reviewer']);
});

it('is reachable over HTTP by an Owner with a fresh password confirmation', function (): void {
    session()->put('auth.password_confirmed_at', now()->unix());

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/members/{$this->member->id}/role", ['role' => 'form_editor'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->owner->id);
    $this->member->unsetRelation('roles');
    expect($this->member->getRoleNames()->first())->toBe('form_editor');
});

it('is forbidden to a member without tenant.roles.assign', function (): void {
    session()->put('auth.password_confirmed_at', now()->unix());

    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');

    $this->actingAs($editor)
        ->patch("http://acme.meridian.test/members/{$this->member->id}/role", ['role' => 'reviewer'])
        ->assertForbidden();
});

it('rejects a role outside the assignable list at the request boundary', function (): void {
    session()->put('auth.password_confirmed_at', now()->unix());

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/members/{$this->member->id}/role", ['role' => 'owner'])
        ->assertSessionHasErrors('role');
});
