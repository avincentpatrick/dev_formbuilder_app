<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| I6 — what `/` does, on each of the three host shapes it can be asked on.
|
| One route serves all three. The alternative — a domain constraint here plus a tenant `/` in
| routes/tenant.php — reads as the obvious design and is wrong: `routes/web.php` is registered long before
| tenant.php (which is mapped in `app->booted()`), and constraining this route would let the tenant one
| answer CUSTOM domains with a 302 where `CustomDomainRoutingTest` pins a 404. The last test in this file
| pins the branch that keeps that true.
|--------------------------------------------------------------------------
*/

/** The central host under the test env's `CENTRAL_DOMAIN`. */
function centralHost(): string
{
    return (string) config('tenancy.central_domain');
}

function makeWorkspace(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    $tenant->domains()->create(['domain' => $slug]);

    return $tenant;
}

it('renders the platform landing page on the central host', function (): void {
    $this->withoutVite()
        ->get('http://'.centralHost().'/')
        ->assertOk()
        // Second arg `false` skips Inertia's page-file existence check: a route test shouldn't depend on
        // built assets (the tests job doesn't run `npm build`).
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome', false)
            ->has('appName')
            ->has('centralHost')
            ->where('registrationOpen', true));
});

it('hides the create-workspace call to action when platform signup is closed', function (): void {
    // A NULL-tenant platform row. Written on the privileged connection because `nullable_global` widens
    // SELECT only — a tenant-connection write of a platform row affects zero rows and raises nothing.
    DB::connection('pgsql_privileged')->table('settings')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => null,
        'key' => SettingKey::RegistrationOpenSignup->value,
        'value' => json_encode(false, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The front door must not offer a button that `GateRegistration` then 404s.
    $this->withoutVite()
        ->get('http://'.centralHost().'/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('registrationOpen', false));
});

it('sends a signed-out visitor on a workspace host to the sign-in page', function (): void {
    makeWorkspace();

    $this->get('http://acme.'.centralHost().'/')
        ->assertRedirect(route('login'));
});

it('sends a signed-in member on a workspace host to their dashboard', function (): void {
    $tenant = makeWorkspace();
    $user = User::factory()->create();

    // No tenancy middleware runs on this route, so there is no tenant GUC — and `$request->user()` still
    // resolves, because the `users` provider is `rls_aware` and reads on `pgsql_auth`. That is the whole
    // reason this branch can exist without `EstablishTenantDatabaseContext`.
    $this->actingAs($user)
        ->get('http://acme.'.centralHost().'/')
        ->assertRedirect(config('fortify.home'));

    expect($tenant->slug)->toBe('acme');
});

it('sends an unknown workspace label back to the central landing', function (): void {
    // Deliberately NOT a 404: `bootstrap/app.php` already redirects an unidentifiable tenant to app.url, and
    // a mistyped subdomain should behave one way in this product rather than two.
    $this->get('http://nosuchworkspace.'.centralHost().'/')
        ->assertRedirect(config('app.url'));
});

it('still 404s the landing page on a custom domain, rather than redirecting', function (): void {
    $tenant = makeWorkspace();
    $tenant->domains()->create([
        'domain' => 'forms.acme-example.com',
        'verification_token' => str_repeat('a', 32),
        'verified_at' => now(),
        // ⚠️ NOT activated — an activated row enters Domain's `resolvable` global scope.
        'activated_at' => null,
    ]);

    // ⚠️ THE REGRESSION THIS FILE EXISTS FOR. `CustomDomainRoutingTest` already pins `/` as 404 on a custom
    // host. If a later change adds `Route::domain(central_domain)` to routes/web.php and moves the tenant
    // root into routes/tenant.php, this becomes a 302 to app.url — a host the platform does not own would
    // learn that the path exists, and the redirect would leak the canonical hostname. Asserting "404, and
    // specifically not a redirect" is what makes that change fail loudly rather than quietly.
    $response = $this->get('http://forms.acme-example.com/');

    $response->assertNotFound();
    expect($response->isRedirect())->toBeFalse();
});
