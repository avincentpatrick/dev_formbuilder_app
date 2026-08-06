<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\Notification;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The notification routes' deliberate ABSENCES (Increment I4) — the BrandingRoutesTest posture.
|
| Four of the five destinations next to this block in routes/tenant.php carry a `feature:` gate and a
| permission, so the notification block looks inconsistent and looks like an oversight. It is neither, and
| this file is what stops the next reader "tidying" it into symmetry:
|
|  · No plan feature, because PRD Feature #13's own acceptance criterion is that notifications are
|    "available on EVERY tier — a Free tenant with no webhook access still gets submission notifications",
|    and PlanCatalog defines no notifications key anywhere. Adding one would be MAKING a pricing decision
|    rather than enforcing one.
|  · No permission, because a notification is addressed to ONE person by `notifications.user_id`, so "may I
|    read this?" is `Notification::scopeForUser()` and never a role. Coining `notifications.view` would
|    invent a catalog entry whose audience is "every authenticated user".
|
| The static-segment ordering is asserted too: `read-all` is declared before `{notification}/read`, and a
| reorder would silently make the bulk route resolve as a notification id.
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
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

it('serves the bell to the least-privileged role on the cheapest tier', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');
    assignPlanTier(PlanTier::Free);

    $this->actingAs($viewer)->getJson('http://acme.meridian.test/notifications')->assertOk();
});

it('never lets the row binding shadow read-all', function (): void {
    Notification::factory()->create(['user_id' => $this->owner->id]);

    // If `{notification}` were declared first it would capture "read-all" as an id and 404 on the uuid
    // cast — the H14 static-segment rule, made executable.
    $this->actingAs($this->owner)
        ->postJson('http://acme.meridian.test/notifications/read-all')
        ->assertNoContent();
});

it('404s a mark-read on something that is not a notification id', function (): void {
    $this->actingAs($this->owner)
        ->postJson('http://acme.meridian.test/notifications/not-a-uuid/read')
        ->assertNotFound();
});
