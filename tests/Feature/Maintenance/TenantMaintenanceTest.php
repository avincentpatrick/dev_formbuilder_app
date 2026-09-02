<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Tenant maintenance mode (Increment I5, PRD Feature #10) — the guest runtime paused, the admin app not.
|
| PRD #10's exact words: it "blocks new guest submissions and shows a configurable message on that tenant's
| public forms **while leaving the authenticated admin app usable (so an admin can turn it back off)**".
| That parenthesis is a REQUIREMENT, so the "admin app still works" test is not a nice-to-have — it is the
| property that stops a tenant locking itself out with its own switch.
|
| ⚠️ THE OTHER-TENANT CASE IS ITS OWN TEST, not a second assertion. Within one Pest test the container
| keeps the Tenant bound by the FIRST request (stancl early-returns if tenancy is already initialized), so
| a second request to a different subdomain would be answered under the first tenant's identity and the
| assertion would prove nothing.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/** A tenant with one published, guest-open form, optionally paused. */
function maintenanceFixture(string $slug, bool $paused, ?string $message = null): array
{
    $tenant = inboxTenant($slug);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner, 'Feedback');
    $form->forceFill(['public_slug' => $slug.'-feedback', 'allow_guest_submissions' => true])->save();

    if ($paused) {
        $tenant->forceFill(['maintenance_mode' => true, 'maintenance_message' => $message])->save();
    }

    return [$tenant, $owner, $form];
}

it('serves the guest form normally when the tenant is not paused', function (): void {
    [$tenant, $owner, $form] = maintenanceFixture('acme', paused: false);

    // ⚠️ `withoutVite()` IS REQUIRED ON EVERY CASE IN THIS FILE, PAUSED OR NOT. This comment used to say it
    // was needed "on the two not-paused cases specifically" because only those render
    // `public-runtime.blade.php` — **that was false, and M61 paid a CI cycle to find out**: the maintenance
    // blade carries `@vite(['resources/css/app.css'])` too, so the paused arm throws
    // `ViteManifestNotFoundException` from `MaintenanceResponse::make()` and the 503 arrives as a 500. The
    // paused cases below were never green *without* it — they have always called it, and the comment
    // explained a habit it did not describe. The Pest CI job never runs `npm run build`; locally it passes
    // whenever `public/build` happens to exist, which is exactly the silent local/CI divergence PROGRESS.md
    // records under "Any Pest test that renders a blade view needs withoutVite()".
    $this->withoutVite();

    $this->get('http://acme.meridian.test/f/acme-feedback')->assertOk();
});

it('503s the guest form shell with the tenant\'s own message when paused', function (): void {
    [$tenant, $owner, $form] = maintenanceFixture('acme', paused: true, message: 'Back on Monday.');

    $this->withoutVite();

    $this->get('http://acme.meridian.test/f/acme-feedback')
        ->assertStatus(503)
        ->assertSee('Back on Monday.');
});

it('falls back to the product notice when the tenant stored no message', function (): void {
    [$tenant, $owner, $form] = maintenanceFixture('acme', paused: true);

    $this->withoutVite();

    $this->get('http://acme.meridian.test/f/acme-feedback')
        ->assertStatus(503)
        ->assertSee('temporarily unavailable');
});

it('503s the public submit API in the /api/v1 envelope', function (): void {
    [$tenant, $owner, $form] = maintenanceFixture('acme', paused: true, message: 'Paused.');

    $token = app(GuestShareTokenService::class)
        ->mint($tenant->id, $form->id, (string) $form->current_published_version_id);

    $this->postJson("http://acme.meridian.test/api/v1/public/f/{$token->token}/submissions", [])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'maintenance_mode')
        ->assertJsonPath('error.message', 'Paused.');
});

it('blocks the schema GET too, so an already-mounted offline SPA stops as well', function (): void {
    [$tenant, $owner, $form] = maintenanceFixture('acme', paused: true, message: 'Paused.');

    $token = app(GuestShareTokenService::class)
        ->mint($tenant->id, $form->id, (string) $form->current_published_version_id);

    $this->getJson("http://acme.meridian.test/api/v1/public/f/{$token->token}")
        ->assertStatus(503);
});

it('leaves the authenticated admin app fully usable — the property that prevents a lockout', function (): void {
    [$tenant, $owner, $form] = maintenanceFixture('acme', paused: true, message: 'Paused.');

    $this->withoutVite();

    $this->actingAs($owner)->get('http://acme.meridian.test/settings')->assertOk();
    $this->actingAs($owner)->get('http://acme.meridian.test/dashboard')->assertOk();
});

it('does not pause another tenant\'s forms', function (): void {
    // A SEPARATE test from the paused case above — see the file header on the bound-Tenant early-return.
    $paused = Tenant::create(['name' => 'Paused', 'slug' => 'paused', 'maintenance_mode' => true]);
    $paused->domains()->create(['domain' => 'paused']);

    [$tenant, $owner, $form] = maintenanceFixture('acme', paused: false);

    // Renders the real guest shell, so it needs withoutVite() for the reason the first test spells out.
    $this->withoutVite();

    $this->get('http://acme.meridian.test/f/acme-feedback')->assertOk();
});

// ── M61: availability outranks canonicalization ───────────────────────────────────────────────

it('503s a mixed-case guest URL too, rather than canonicalizing past the pause', function (): void {
    [$tenant, $owner, $form] = maintenanceFixture('acme', paused: true, message: 'Back on Monday.');

    // ⛔ withoutVite() — and this case is why the comment at the top of this file now says EVERY case needs
    // it. Written without, on the strength of that comment's claim that the paused arm renders no @vite,
    // it passed locally (this host has a public/build) and was the single red test in CI: 500, not 503,
    // from ViteManifestNotFoundException inside MaintenanceResponse::make().
    $this->withoutVite();

    // EnforceTenantMaintenance short-circuits BEFORE $next(), so the controller — and with it M61's
    // canonical 301 — never runs. That is the correct precedence twice over: a paused tenant must not
    // answer differently for a mis-cased URL than for a canonical one, or the redirect leaks the slug's
    // existence past the pause. It is an ORDERING property, so it is pinned rather than reasoned about.
    $this->get('http://acme.meridian.test/f/AcMe-FeEdBaCk')
        ->assertStatus(503)
        ->assertSee('Back on Monday.')
        ->assertHeaderMissing('Location');
});
