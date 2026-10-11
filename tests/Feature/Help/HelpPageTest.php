<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M162 — the in-app user manual at /help (`R-f9211765`, `D114`).
|
| The page is the same text for everyone, so what can go wrong is the DOOR, not the content:
|
|  1. A GATE CREEPING ONTO IT. Its first chapter tells a Reviewer or a Viewer why Forms is not in their
|     menu; a `can:` or `feature:` on the route would refuse exactly that reader the page that explains it.
|     Every role is driven here, on Free — the plan with the fewest features — so a gate of either kind
|     reddens a case.
|  2. THE PAGE LEAKING TO A GUEST. It sits inside the tenant group's `auth`; a route moved out of it would
|     render the shell's page to anyone with the link.
|
| The manual's TEXT is `resources/js/components/help/manual.ts`, and its labels are checked against the files
| that draw them by `manual.test.ts` (Vitest) — not here.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Free);
});

it('opens the manual for every role, on the plan with the fewest features', function (string $role): void {
    $member = User::factory()->create();
    makeActiveMember($member, $role);

    $this->withoutVite()
        ->actingAs($member)
        ->get('http://acme.meridian.test/help')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('help/Index', false));
})->with(['owner', 'admin', 'form_editor', 'reviewer', 'viewer']);

it('sends a guest to sign in rather than showing the manual', function (): void {
    $this->get('http://acme.meridian.test/help')->assertRedirect('http://acme.meridian.test/login');
});

it('passes no props of its own, because the manual is the same for every reader', function (): void {
    // The content is client-side data; a prop here would be the first step towards a per-role manual, which
    // `D114` did not ask for and which would let two readers be told two different things about one button.
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test/help')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('help/Index', false)->missing('manual'));
});
