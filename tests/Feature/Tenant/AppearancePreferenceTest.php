<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment C2 — appearance (theme) preference persistence.
|--------------------------------------------------------------------------
| The write lives in the authenticated tenant group so app.current_user_id is set — the
| belongs-to-user RLS key on user_ui_preferences — and the upsert succeeds. Proves: default is
| "system", a PATCH persists the choice and it flows back through the shared `ui.theme` prop AND the
| <html data-theme-mode> emission, and an unknown mode is rejected. (Helpers enterTenant/
| makeActiveMember are the shared ones from MembershipLifecycleTest, global in Pest.)
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

it('defaults a member with no preference to the system theme', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard', false)
            ->where('ui.theme.mode', 'system'));
});

it('persists a chosen theme and reflects it in the shared prop and <html>', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['theme_mode' => 'dark'])
        ->assertRedirect();

    // The row is upserted under the user's own RLS context.
    enterTenant($tenant->id, $user->id);
    expect(UserUiPreference::where('user_id', $user->id)->value('theme_mode'))->toBe('dark');

    // The shared prop now resolves to dark, and a full render emits the root attribute.
    $html = $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ui.theme.mode', 'dark'))
        ->getContent();

    expect($html)->toContain('data-theme-mode="dark"');
});

it('rejects an unknown theme mode', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)
        ->patch('http://acme.meridian.test/settings/appearance', ['theme_mode' => 'purple'])
        ->assertSessionHasErrors('theme_mode');
});
