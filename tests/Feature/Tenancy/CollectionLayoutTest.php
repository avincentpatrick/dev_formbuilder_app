<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Connectors\ConnectorRedirector;
use App\Support\Tenancy\PlatformHost;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The testing site's layout after the switch to three addresses (M253, `R-43038865`, `D236`)
|--------------------------------------------------------------------------
| The portal is `iconnect.com.de`, iConnect Analytics is `analytics.iconnect.com.de`, and this app, iConnect
| Collection, is `collection.iconnect.com.de`. A workspace host is exactly one label plus CENTRAL_DOMAIN
| (`SubdomainLabel` refuses a dot, `PlatformHost::subdomainLabel()` reads one label), so the box runs
| `CENTRAL_DOMAIN=iconnect.com.de` and `APP_URL=https://iconnect.com.de`, and the workspace's `domains` row reads
| `collection` while its slug stays `staging`.
|
| The central host is then the PORTAL's host. Apache serves this app there only for the operator console, and only
| to loopback (runbook §8.3); every other path there belongs to the portal or redirects. These cases pin the app's
| half: the workspace on `collection.`, the central host never a workspace, every outbound link on `collection.`,
| and the fallback for an unknown host landing on the portal — a host we own, unlike `com.de` today.
*/

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['127.0.0.1', 'localhost', 'iconnect.com.de'],
        'tenancy.central_domain' => 'iconnect.com.de',
        'app.url' => 'https://iconnect.com.de',
    ]);

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Staging', 'slug' => 'staging', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'collection']);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

it('serves the workspace on collection.iconnect.com.de', function (): void {
    // A 404 for an unknown form slug means identification SUCCEEDED (CustomDomainRoutingTest's reading).
    $this->withoutVite()->get('https://collection.iconnect.com.de/f/no-such-form')->assertNotFound();

    expect(PlatformHost::subdomainLabel('collection.iconnect.com.de'))->toBe('collection')
        ->and(PlatformHost::tenantFor('collection.iconnect.com.de')?->getKey())->toBe($this->tenant->getKey());
});

it('keeps the portal host central, never a workspace, and sends a workspace path there to the portal', function (): void {
    // Apache redirects old workspace links on the portal host to `collection.` before they reach this app; if one
    // ever got through, the app answers with the fallback to app.url — the portal — never a workspace page.
    $this->withoutVite()->get('https://iconnect.com.de/f/no-such-form')->assertRedirect('https://iconnect.com.de');

    expect(PlatformHost::isPlatformHost('iconnect.com.de'))->toBeTrue()
        ->and(PlatformHost::subdomainLabel('iconnect.com.de'))->toBeNull()
        ->and(PlatformHost::tenantFor('iconnect.com.de'))->toBeNull();
});

it('never mistakes iConnect Analytics for a workspace', function (): void {
    // `analytics.` is one label under the central domain, so the app would read it as a workspace label — and
    // there is no `analytics` row, so it resolves to none. Apache never sends that host here at all.
    expect(PlatformHost::subdomainLabel('analytics.iconnect.com.de'))->toBe('analytics')
        ->and(PlatformHost::tenantFor('analytics.iconnect.com.de'))->toBeNull();
});

it('builds every outbound workspace link on collection.iconnect.com.de, not on the slug', function (): void {
    expect(TenantUrl::to($this->tenant, 'invitations/abc'))->toBe('https://collection.iconnect.com.de/invitations/abc')
        ->and(TenantUrl::toPublic($this->tenant, 'f/resume/abc'))->toBe('https://collection.iconnect.com.de/f/resume/abc')
        ->and(app(ConnectorRedirector::class)->tenantHost((string) $this->tenant->getKey()))->toBe('collection.iconnect.com.de');
});

it('names collection.iconnect.com.de on the Settings card sign-up line', function (): void {
    $owner = User::factory()->create();
    enterTenant($this->tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $this->withoutVite()->actingAs($owner)->get('https://collection.iconnect.com.de/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('appSettings.access.signup_host', 'collection.iconnect.com.de'));
});
