<?php

declare(strict_types=1);

use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

uses(RefreshDatabase::class);

/*
| H22a — ResolvableDomainScope, the chokepoint. A custom domain is invisible to ordinary Eloquent until
| it is `live` (DNS-verified AND operator-activated), which is what makes "an unverified row cannot be
| routed to, and cannot appear in any link" a property of the model rather than a rule five call sites
| have to remember.
*/

it('hides a pending custom domain from the relation', function (): void {
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com', verified: false, activated: false);

    expect($tenant->domains()->pluck('domain')->all())->toBe(['acme'])
        ->and(Domain::unscopedQuery()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('hides a verified-but-not-activated custom domain', function (): void {
    // The operator gate, at the model layer. Proving control of a hostname is not the same as having a
    // certificate installed for it, and only the second one may put respondents on that origin.
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com', verified: true, activated: false);

    expect($tenant->domains()->pluck('domain')->all())->toBe(['acme']);
});

it('reveals a live custom domain', function (): void {
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com');

    expect($tenant->domains()->pluck('domain')->all())
        ->toContain('acme')
        ->toContain('forms.acme-example.com');
});

it('never hides a subdomain row', function (): void {
    // A label row has no verified_at and no activated_at and must stay unconditionally visible — it is
    // the tenant's app host, and hiding it would take the whole tenant offline.
    inboxTenant('acme');

    expect(Domain::query()->where('domain', 'acme')->exists())->toBeTrue();
});

it('does not leak the OR out of an enclosing where', function (): void {
    // The closure wrapper inside the scope. Without it, `->where('tenant_id', X)->orWhereNotNull(...)`
    // composes as `tenant_id = X OR activated_at IS NOT NULL`, and one tenant's live custom domain
    // would appear in every other tenant's relation. This is the assertion that would catch that.
    $acme = inboxTenant('acme');
    customDomain($acme, 'forms.acme-example.com');
    $beta = inboxTenant('beta');

    expect($beta->domains()->pluck('domain')->all())->toBe(['beta'])
        ->and($acme->domains()->pluck('domain')->all())->toHaveCount(2);
});

it('makes the tenant resolver fail closed on a non-live custom domain', function (bool $verified, bool $activated, bool $resolves): void {
    // stancl's resolveWithoutCache() builds its whereHas subquery from newQueryWithoutRelationships(),
    // which registers global scopes — so this is enforced inside the vendor resolver without patching it.
    customDomain(inboxTenant('acme'), 'forms.acme-example.com', $verified, $activated);

    $resolve = fn () => app(DomainTenantResolver::class)->resolve('forms.acme-example.com');

    if ($resolves) {
        expect($resolve()->slug)->toBe('acme');
    } else {
        expect($resolve)->toThrow(TenantCouldNotBeIdentifiedOnDomainException::class);
    }
})->with([
    'pending' => [false, false, false],
    'verified only' => [true, false, false],
    'live' => [true, true, true],
]);

it('keeps unscopedQuery scoped to exactly one named scope', function (): void {
    // withoutGlobalScopes() would also discard any scope added here later. Dropping the named one keeps
    // that future scope in force, which is the difference between an escape hatch and a hole.
    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com', verified: false, activated: false);

    expect(Domain::unscopedQuery()->where('tenant_id', $tenant->id)->pluck('domain')->all())
        ->toContain('forms.acme-example.com');
});

it('leaves the resolver cache disabled, which the scope depends on', function (): void {
    // A TRAP, pinned rather than fixed. If $shouldCache is ever flipped true, getArgsForTenant() maps
    // $tenant->domains — under this scope, a domain being demoted is ALREADY excluded by the time the
    // `saved` event fires, so its cache key would never be forgotten and a revoked host would keep
    // routing for up to $cacheTTL. Enabling the cache is therefore not a free switch; whoever does it
    // must override getArgsForTenant() with unscopedQuery() first.
    expect(DomainTenantResolver::$shouldCache)->toBeFalse();
});
