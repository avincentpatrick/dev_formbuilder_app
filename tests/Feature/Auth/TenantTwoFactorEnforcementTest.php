<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Http\Middleware\EnforceTenantTwoFactor;
use App\Models\User;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Org-level 2FA enforcement (Increment I8a) — PRD Feature #14's second acceptance criterion:
| "An org-level enforcement policy (a tenant Setting, Feature #10) lets an Owner/Admin require 2FA for all
| tenant members; unenrolled members are prompted to complete enrolment before continuing."
|--------------------------------------------------------------------------
| ⚠️ THE DEFAULT IS THE FIRST TEST, AND IT IS THE ONE THAT MATTERS MOST ON DEPLOY DAY. The `settings` table
| is SPARSE, so "absent" is the state of every workspace that already exists. A fail-closed default — which
| is what the rest of SettingKey::default() reaches for — would, on the deploy that adds this key, redirect
| every member of every workspace to an enrolment page they never asked for, including Owners who would
| then have to enrol before they could reach the switch to turn it off.
|
| ⚠️ THE ESCAPE HATCH IS ASSERTED FROM BOTH SIDES. A gate that redirects everywhere redirects to itself, so
| `two-factor.required` lives OUTSIDE the guarded group. Two tests cover it: the interstitial answers 200
| to exactly the user the gate is bouncing, and it is structurally outside the middleware. If someone
| "tidies" that route into the main group, the first goes into a redirect loop and the second reddens with
| a readable reason.
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

    $this->unenrolled = User::factory()->create();
    enterTenant($this->tenant->id, $this->unenrolled->id);
    makeActiveMember($this->unenrolled, 'viewer');

    $this->enrolled = User::factory()->confirmedTwoFactor()->create();
    enterTenant($this->tenant->id, $this->enrolled->id);
    makeActiveMember($this->enrolled, 'viewer');

    enterTenant($this->tenant->id, $this->owner->id);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/**
 * Turn enforcement on for the current tenant, through the real write path.
 *
 * ⚠️ RE-ENTERS THE TENANT FIRST, AND THAT IS NOT DEFENSIVE. The GUC dies with every HTTP request, and
 * `settings` is strict-RLS on INSERT — so a call to this helper AFTER a `$this->get(...)` in the same
 * test fails with SQLSTATE 42501 rather than silently writing the wrong row. Learned the hard way.
 */
function requireTwoFactor(bool $value = true): void
{
    enterTenant(test()->tenant->id, test()->owner->id);

    app(TenantSettingRegistry::class)->put(
        test()->tenant,
        [SettingKey::SecurityRequireTwoFactor->value => $value],
    );
    app(TenantSettingRegistry::class)->forget();
}

it('leaves every existing workspace alone by default', function (): void {
    // No settings row exists. If this ever reddens, the deploy that ships it locks out every workspace
    // in the deployment at once.
    $this->withoutVite();

    expect(app(TenantSettingRegistry::class)->get(SettingKey::SecurityRequireTwoFactor))->toBeFalse();

    $this->actingAs($this->unenrolled)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk();
});

it('redirects an unenrolled member once the workspace requires two-factor', function (): void {
    requireTwoFactor();

    $this->actingAs($this->unenrolled)
        ->get('http://acme.meridian.test/dashboard')
        ->assertRedirect('http://acme.meridian.test/two-factor/required');
});

it('does not touch a member who has completed enrolment', function (): void {
    $this->withoutVite();
    requireTwoFactor();

    $this->actingAs($this->enrolled)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk();
});

it('applies to the Owner who switched it on, and says so on the card', function (): void {
    // Not an oversight — an enforcement policy the person setting it is exempt from is not a policy.
    // The Access card warns about exactly this, which is why `actor_enrolled` reaches the wire.
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('appSettings.access.require_two_factor', false)
            ->where('appSettings.access.actor_enrolled', false)
        );

    requireTwoFactor();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertRedirect('http://acme.meridian.test/two-factor/required');
});

it('serves the interstitial to the very user it is bouncing', function (): void {
    // The redirect-loop test. This route is outside the guarded group; if it is ever moved inside,
    // the assertion below becomes a 302 to itself.
    $this->withoutVite();
    requireTwoFactor();

    $this->actingAs($this->unenrolled)
        ->get('http://acme.meridian.test/two-factor/required')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/TwoFactorRequired', false)
            ->where('enabled', false)
            ->where('confirmed', false)
        );
});

it('keeps the interstitial structurally outside the gate', function (): void {
    // Stated as a manifest assertion too, because the HTTP test above would also pass if the middleware
    // merely happened to allow this path — and a path allow-list is exactly the design this rejected.
    $middleware = Route::getRoutes()
        ->getByName('two-factor.required')
        ?->gatherMiddleware() ?? [];

    expect($middleware)->not->toContain(EnforceTenantTwoFactor::class);

    // And the gate really is on the app, so the test above is not vacuous.
    $dashboard = Route::getRoutes()
        ->getByName('dashboard')
        ?->gatherMiddleware() ?? [];

    expect($dashboard)->toContain(EnforceTenantTwoFactor::class);
});

it('sends an enrolled user away from the interstitial rather than stranding them', function (): void {
    // Without this the user confirms their TOTP and stays on a page telling them to do what they just
    // did — the middleware would let them anywhere, but nothing on screen takes them there.
    requireTwoFactor();

    $this->actingAs($this->enrolled)
        ->get('http://acme.meridian.test/two-factor/required')
        ->assertRedirect('http://acme.meridian.test/dashboard');
});

it('leaves the public guest runtime untouched', function (): void {
    // A respondent filling in a public form is not a member of anything, and enforcement must never
    // reach them. The guest group does not carry this middleware; asserted rather than assumed.
    requireTwoFactor();

    $guestRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'f/'))
        ->flatMap(fn ($route): array => $route->gatherMiddleware())
        ->unique();

    expect($guestRoutes)->not->toContain(EnforceTenantTwoFactor::class);
    expect($guestRoutes)->not->toBeEmpty(); // the filter matched something, so the check is not vacuous
});

it('writes the setting through the audited registry like every other access toggle', function (): void {
    // An ENROLLED Owner, deliberately. An unenrolled one is gated out of /settings by their own switch on
    // the very next request — see the test below, which pins that as behaviour rather than working
    // around it here.
    $enrolledOwner = User::factory()->confirmedTwoFactor()->create();
    enterTenant($this->tenant->id, $enrolledOwner->id);
    makeActiveMember($enrolledOwner, 'owner');

    $this->actingAs($enrolledOwner)
        ->patch('http://acme.meridian.test/settings/access', ['require_two_factor' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $enrolledOwner->id);
    app(TenantSettingRegistry::class)->forget();

    expect(app(TenantSettingRegistry::class)->get(SettingKey::SecurityRequireTwoFactor))->toBeTrue();

    // The partial-write guarantee UpdateAccessSettingsRequest's `sometimes` exists for: the other axis on
    // the same card must be untouched, still resolving to its own default.
    expect(app(TenantSettingRegistry::class)->get(SettingKey::RegistrationInviteOnly))->toBeTrue();
});

it('leaves invite-only alone when only that key is sent, and vice versa', function (): void {
    $enrolledOwner = User::factory()->confirmedTwoFactor()->create();
    enterTenant($this->tenant->id, $enrolledOwner->id);
    makeActiveMember($enrolledOwner, 'owner');

    $this->actingAs($enrolledOwner)
        ->patch('http://acme.meridian.test/settings/access', ['require_two_factor' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($enrolledOwner)
        ->patch('http://acme.meridian.test/settings/access', ['invite_only' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $enrolledOwner->id);
    app(TenantSettingRegistry::class)->forget();

    // Two independent axes sharing one panel. If the second write had carried both keys, the first
    // decision would have been silently reverted to whatever the form was seeded with.
    expect(app(TenantSettingRegistry::class)->get(SettingKey::SecurityRequireTwoFactor))->toBeTrue();
    expect(app(TenantSettingRegistry::class)->get(SettingKey::RegistrationInviteOnly))->toBeFalse();
});

it('gates an UNENROLLED Owner out of settings the moment they switch it on', function (): void {
    // ⚠️ FOUND BY THIS SUITE, AND IT IS CORRECT BEHAVIOUR RATHER THAN A DEFECT — an enforcement policy the
    // person setting it is exempt from is not a policy. But it is startling: the write succeeds and the
    // NEXT request, including a return to the very page they were on, lands on the enrolment
    // interstitial. Recoverable (the interstitial IS the enrolment page), and the Access card warns about
    // it in as many words, which is why `actor_enrolled` reaches the wire at all. Pinned so nobody
    // "fixes" the surprise by exempting whoever flipped the switch.
    $this->actingAs($this->owner)
        ->patch('http://acme.meridian.test/settings/access', ['require_two_factor' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/settings')
        ->assertRedirect('http://acme.meridian.test/two-factor/required');
});
