<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| A web request for a tenant route (e.g. /dashboard, routes/tenant.php) reaches it only through a tenant
| subdomain. Hitting one on the central/non-subdomain host (localhost, 127.0.0.1, the apex) or an unknown
| subdomain must redirect to the central app home — not surface a raw NotASubdomainException /
| TenantCouldNotBeIdentifiedOnDomainException 500 (bootstrap/app.php withExceptions).
|
| The `auth` middleware is priority-ordered BEFORE the tenancy pipeline, so an UNAUTHENTICATED request to a
| tenant route redirects to login and never reaches the tenancy identification (no exception). The failure
| the user hit surfaces once authenticated — hence actingAs() here.
*/

it('redirects a tenant route on the central host to the central home', function (): void {
    // `localhost` is a central domain (config/tenancy.php) → no subdomain → NotASubdomainException.
    $this->actingAs(User::factory()->create())
        ->get('http://localhost/dashboard')
        ->assertRedirect(config('app.url'));
});

it('redirects a tenant route on an unknown subdomain to the central home', function (): void {
    // `ghost` is a subdomain with no tenant → TenantCouldNotBeIdentifiedOnDomainException.
    $this->actingAs(User::factory()->create())
        ->get('http://ghost.localhost/dashboard')
        ->assertRedirect(config('app.url'));
});
