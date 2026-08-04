<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Services\Tenancy\CustomDomainService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| H22a — `domains:activate`, the operator half of the lifecycle.
|
| A tenant reaches `verified` entirely on its own. It cannot reach `live`: that requires a human on the
| box who has just installed a certificate for the hostname. Per-domain TLS issuance is Track B and Track
| B is deferred, so this command IS the fail-closed boundary — it is the only code path in the
| application that can set `activated_at`.
*/

it('activates a verified domain', function (): void {
    $tenant = inboxTenant('acme');
    $domain = customDomain($tenant, 'forms.acme-example.com', verified: true, activated: false);

    $this->artisan('domains:activate', ['domain' => 'forms.acme-example.com'])
        ->expectsOutputToContain('is live')
        ->assertSuccessful();

    expect($domain->fresh()->isLive())->toBeTrue();
});

it('refuses to activate a domain that is not verified, and prints the record to publish', function (): void {
    $tenant = inboxTenant('acme');
    $domain = app(CustomDomainService::class)->claim($tenant, 'forms.acme-example.com');

    $this->artisan('domains:activate', ['domain' => 'forms.acme-example.com'])
        ->expectsOutputToContain('not verified yet')
        // The operator gets what the tenant needs to publish, so a support conversation does not require
        // a database query.
        ->expectsOutputToContain('_meridian-challenge.forms.acme-example.com')
        ->expectsOutputToContain('meridian-domain-verification='.$domain->verification_token)
        ->assertFailed();

    expect($domain->fresh()->isLive())->toBeFalse();
});

it('refuses an unknown hostname', function (): void {
    $this->artisan('domains:activate', ['domain' => 'forms.nobody-example.com'])
        ->expectsOutputToContain('No domain row')
        ->assertFailed();
});

it('refuses a tenant subdomain, which is always live', function (): void {
    inboxTenant('acme');

    $this->artisan('domains:activate', ['domain' => 'acme'])
        ->expectsOutputToContain('always live')
        ->assertFailed();
});

it('deactivates without losing verification, so re-activating needs no new TXT record', function (): void {
    $tenant = inboxTenant('acme');
    $domain = customDomain($tenant, 'forms.acme-example.com');

    $this->artisan('domains:activate', ['domain' => 'forms.acme-example.com', '--deactivate' => true])
        ->assertSuccessful();

    expect($domain->fresh()->isLive())->toBeFalse()
        ->and($domain->fresh()->isVerified())->toBeTrue();

    $this->artisan('domains:activate', ['domain' => 'forms.acme-example.com'])->assertSuccessful();

    expect($domain->fresh()->isLive())->toBeTrue();
});

it('elects the first live domain as primary, and leaves an existing primary alone', function (): void {
    // H22b. The first activation has to take the flag, or a one-domain tenant would have to go and express
    // a preference between one thing and nothing before its links moved. The SECOND must not: repointing
    // every outstanding respondent link is the tenant's decision on the admin page, never a side effect of
    // an operator running this command in a maintenance window.
    $tenant = inboxTenant('acme');
    $first = customDomain($tenant, 'forms.acme-example.com', verified: true, activated: false);
    $second = customDomain($tenant, 'apply.acme-example.com', verified: true, activated: false);

    $this->artisan('domains:activate', ['domain' => 'forms.acme-example.com'])->assertSuccessful();
    $this->artisan('domains:activate', ['domain' => 'apply.acme-example.com'])->assertSuccessful();

    expect($first->fresh()->is_primary)->toBeTrue()
        ->and($second->fresh()->is_primary)->toBeFalse();
});

it('clears the primary flag when a live domain is taken out of service', function (): void {
    // NOT cosmetic bookkeeping: `domains_primary_requires_live_chk` says a row may be primary only while
    // it is activated, so a deactivate that left the flag set would be a constraint violation rather than
    // a deactivation — the operator's "take it offline now" would fail with a 23505-shaped error.
    $tenant = inboxTenant('acme');
    $domain = customDomain($tenant, 'forms.acme-example.com');
    app(CustomDomainService::class)->makePrimary($domain);

    expect($domain->fresh()->is_primary)->toBeTrue();

    $this->artisan('domains:activate', ['domain' => 'forms.acme-example.com', '--deactivate' => true])
        ->assertSuccessful();

    expect($domain->fresh()->is_primary)->toBeFalse()
        ->and($domain->fresh()->isVerified())->toBeTrue();
});

it('finds the row without any tenant context, because domains is RLS-exempt', function (): void {
    // The command runs on the box with no request and no tenant. `domains` and `tenants` are the central
    // tables precisely so this works; an RLS-scoped table would return zero rows here and the operator
    // would see "No domain row" for a domain that plainly exists.
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com', verified: true, activated: false);

    TenantContext::flush();

    $this->artisan('domains:activate', ['domain' => 'forms.acme-example.com'])->assertSuccessful();

    expect(Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->sole()->activated_at)->not->toBeNull();
});
