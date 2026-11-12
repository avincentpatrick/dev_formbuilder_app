<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Connectors\ConnectorRedirector;
use App\Support\Tenancy\PlatformHost;
use App\Support\Tenancy\TenantUrl;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The testing site's layout on iconnect.com.de (M235, D216).
|
| Testers type the bare `iconnect.com.de`. The app serves a workspace only at `<label>.<CENTRAL_DOMAIN>`
| and keeps the central domain for the operator console, so the box runs `CENTRAL_DOMAIN=com.de` and
| `APP_URL=https://com.de`, and the workspace's `domains` row reads `iconnect` while its slug stays
| `staging`. `com.de` reaches the box only through its hosts file, as `pitahc.gov.ph` did under D46.
|
| These cases pin that layout in the shape the box has it: a TWO-LABEL central domain, and a label that
| differs from the slug. The second is the one that found a defect: the Settings card's sign-up line
| was built from the slug, so on this layout it named `staging.com.de`, a host nothing serves.
*/

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['127.0.0.1', 'localhost', 'com.de'],
        'tenancy.central_domain' => 'com.de',
        'app.url' => 'https://com.de',
    ]);

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Staging', 'slug' => 'staging', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'iconnect']);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

it('serves the workspace on the bare iconnect.com.de', function (): void {
    // A 404 for an unknown form slug means identification SUCCEEDED (CustomDomainRoutingTest's reading).
    $this->withoutVite()->get('https://iconnect.com.de/f/no-such-form')->assertNotFound();

    expect(PlatformHost::subdomainLabel('iconnect.com.de'))->toBe('iconnect')
        ->and(PlatformHost::tenantFor('iconnect.com.de')?->getKey())->toBe($this->tenant->getKey());
});

it('keeps com.de itself as the central host, never a workspace', function (): void {
    $this->withoutVite()->get('https://com.de/f/no-such-form')->assertRedirect('https://com.de');

    expect(PlatformHost::isPlatformHost('com.de'))->toBeTrue()
        ->and(PlatformHost::subdomainLabel('com.de'))->toBeNull();
});

it('builds every outbound workspace link on iconnect.com.de, not on the slug', function (): void {
    expect(TenantUrl::to($this->tenant, 'invitations/abc'))->toBe('https://iconnect.com.de/invitations/abc')
        ->and(TenantUrl::toPublic($this->tenant, 'f/resume/abc'))->toBe('https://iconnect.com.de/f/resume/abc')
        ->and(app(ConnectorRedirector::class)->tenantHost((string) $this->tenant->getKey()))->toBe('iconnect.com.de');
});

it('names iconnect.com.de on the Settings card sign-up line', function (): void {
    $owner = User::factory()->create();
    enterTenant($this->tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $this->withoutVite()->actingAs($owner)->get('https://iconnect.com.de/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('appSettings.access.signup_host', 'iconnect.com.de'));
});
