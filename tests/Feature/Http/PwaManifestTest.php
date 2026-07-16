<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G8a — GET /f/{slug}/manifest.webmanifest serves a per-form web manifest linked from the guest
| shell (installability). Resolution + the three 404 gates mirror the mint route exactly (resolve by
| per-tenant public_slug; 404 — never 403 — for missing / guest-disabled / unpublished).
|--------------------------------------------------------------------------
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

/** A tenant reachable at {slug}.meridian.test. */
function pwaTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

/** A published form exposed at a public slug (guest access toggleable). Requires enterTenant already called. */
function pwaGuestForm(Tenant $tenant, User $owner, string $slug = 'intake', bool $guest = true): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name');
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->update(['public_slug' => $slug, 'allow_guest_submissions' => $guest]);

    return $form->refresh();
}

it('serves a per-form web manifest pinned to the form URL', function (): void {
    $tenant = pwaTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    pwaGuestForm($tenant, $owner);

    $response = $this->get('http://acme.meridian.test/f/intake/manifest.webmanifest');

    $response->assertOk()
        ->assertJsonPath('name', 'Intake')
        ->assertJsonPath('id', '/f/intake')
        ->assertJsonPath('start_url', '/f/intake')
        ->assertJsonPath('scope', '/f/intake')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('theme_color', '#1B5E5E')
        ->assertJsonPath('icons.0.src', '/icons/icon-192.png')
        ->assertJsonPath('icons.2.purpose', 'maskable');

    expect($response->baseResponse->headers->get('Content-Type'))->toContain('application/manifest+json');
});

it('404s an unknown slug', function (): void {
    pwaTenant();

    $this->get('http://acme.meridian.test/f/nope/manifest.webmanifest')->assertNotFound();
});

it('404s a published form with guest access disabled (non-disclosure)', function (): void {
    $tenant = pwaTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    pwaGuestForm($tenant, $owner, 'intake', guest: false);

    $this->get('http://acme.meridian.test/f/intake/manifest.webmanifest')->assertNotFound();
});

it('404s a form that has a slug but no published version', function (): void {
    $tenant = pwaTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = app(FormService::class)->create($tenant, $owner, 'Draft only');
    $form->update(['public_slug' => 'draftme', 'allow_guest_submissions' => true]);

    $this->get('http://acme.meridian.test/f/draftme/manifest.webmanifest')->assertNotFound();
});
