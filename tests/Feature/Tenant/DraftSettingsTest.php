<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H10 — tenant-level draft settings (the "Save and finish later" expiry window).
|--------------------------------------------------------------------------
| PATCH /settings/drafts writes tenants.draft_ttl_days for the SUBDOMAIN-resolved tenant only, gated on the
| Owner/Admin `tenant.settings.manage` permission. `tenants` is RLS-exempt, so the controller (not the DB) is
| what scopes the write — a member without the permission is refused, and validation bounds the value 1–365.
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

/** @return array{0: Tenant, 1: User} */
function draftSettingsActor(string $slug = 'acme', string $role = 'admin'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    $tenant->domains()->create(['domain' => $slug]);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, $role);

    return [$tenant, $user];
}

it('lets an admin set the tenant draft expiry window', function (): void {
    [$tenant, $admin] = draftSettingsActor(role: 'admin');

    $this->actingAs($admin)
        ->patch('http://acme.meridian.test/settings/drafts', ['draft_ttl_days' => 14])
        ->assertRedirect();

    // Read through the query builder — the stancl custom-column store would otherwise mask a mis-store.
    expect(DB::table('tenants')->where('id', $tenant->id)->value('draft_ttl_days'))->toBe(14);
});

it('refuses a member without tenant.settings.manage (403)', function (): void {
    [$tenant, $viewer] = draftSettingsActor(role: 'viewer');

    $this->actingAs($viewer)
        ->patch('http://acme.meridian.test/settings/drafts', ['draft_ttl_days' => 14])
        ->assertForbidden();

    expect(DB::table('tenants')->where('id', $tenant->id)->value('draft_ttl_days'))->toBeNull();
});

it('validates the expiry window is 1–365 days', function (): void {
    [, $admin] = draftSettingsActor(role: 'admin');

    $this->actingAs($admin)
        ->patch('http://acme.meridian.test/settings/drafts', ['draft_ttl_days' => 0])
        ->assertSessionHasErrors('draft_ttl_days');

    $this->actingAs($admin)
        ->patch('http://acme.meridian.test/settings/drafts', ['draft_ttl_days' => 400])
        ->assertSessionHasErrors('draft_ttl_days');
});

it('exposes the effective draft setting on the settings page for a manager', function (): void {
    [$tenant, $admin] = draftSettingsActor(role: 'admin');
    $tenant->forceFill(['draft_ttl_days' => 21])->save();

    $this->actingAs($admin)->withoutVite()
        ->get('http://acme.meridian.test/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draftSettings.draft_ttl_days', 21)
            ->where('draftSettings.is_default', false)
            ->where('draftSettings.can_manage', true));
});
