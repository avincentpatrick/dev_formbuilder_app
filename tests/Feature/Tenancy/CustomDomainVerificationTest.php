<?php

declare(strict_types=1);

use App\Enums\DomainVerificationFailure;
use App\Models\Domain;
use App\Rules\ClaimableDomain;
use App\Services\Tenancy\CustomDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/*
| H22a — claim → verify → activate, plus the claim gate and the sweep.
|
| The tenant half is entirely self-serve: claim a hostname, publish a TXT record, get verified. The
| ACTIVATION half is not, and that asymmetry is the whole fail-closed posture: per-domain TLS is Track B,
| which is deferred, so until it exists a certificate is installed by hand and only a human who has done
| that work may put the hostname into service.
*/

function domainService(): CustomDomainService
{
    return app(CustomDomainService::class);
}

it('claims a hostname as pending, minting a token that is never derived', function (): void {
    $tenant = inboxTenant('acme');

    $a = domainService()->claim($tenant, 'Forms.Acme-Example.com');
    $b = domainService()->claim(inboxTenant('beta'), 'forms.beta-example.com');

    expect($a->domain)->toBe('forms.acme-example.com') // stancl lowercases on save
        ->and($a->verification_token)->toHaveLength(64)
        ->and($a->isVerified())->toBeFalse()
        ->and($a->isLive())->toBeFalse()
        // Random per row, not an HMAC of (tenant, domain): an HMAC would be unrotatable per claim and
        // would make an APP_KEY compromise a domain-claim primitive, for a value that lives in public DNS.
        ->and($a->verification_token)->not->toBe($b->verification_token);
});

it('verifies when the challenge TXT record is published, and queries the exact name', function (): void {
    $tenant = inboxTenant('acme');
    $domain = domainService()->claim($tenant, 'forms.acme-example.com');

    $dns = fakeDns([
        '_meridian-challenge.forms.acme-example.com' => [
            'meridian-domain-verification='.$domain->verification_token,
        ],
    ]);

    expect(domainService()->verify($domain))->toBeTrue()
        // The name is asserted explicitly: a wrong prefix produces an identical-looking failed row, so
        // this is the only place the defect is visible.
        ->and($dns->queried)->toBe(['_meridian-challenge.forms.acme-example.com'])
        ->and($domain->fresh()->isVerified())->toBeTrue()
        ->and($domain->fresh()->verification_failure_reason)->toBeNull()
        // Verified is NOT live.
        ->and($domain->fresh()->isLive())->toBeFalse();
});

it('records NotFound when the zone publishes nothing at the challenge name', function (): void {
    $domain = domainService()->claim(inboxTenant('acme'), 'forms.acme-example.com');
    fakeDns();

    expect(domainService()->verify($domain))->toBeFalse()
        ->and($domain->fresh()->verification_failure_reason)->toBe(DomainVerificationFailure::NotFound)
        ->and($domain->fresh()->verification_checked_at)->not->toBeNull();
});

it('records Mismatch when a TXT record exists but carries the wrong token', function (): void {
    $domain = domainService()->claim(inboxTenant('acme'), 'forms.acme-example.com');
    fakeDns(['_meridian-challenge.forms.acme-example.com' => ['meridian-domain-verification=someone-elses']]);

    expect(domainService()->verify($domain))->toBeFalse()
        ->and($domain->fresh()->verification_failure_reason)->toBe(DomainVerificationFailure::Mismatch);
});

it('records LookupFailed without demoting an already-verified domain', function (): void {
    // THE NULL/EMPTY DISTINCTION. A resolver timeout is evidence about US, not about the tenant. If the
    // two were collapsed, one upstream DNS outage would unverify every custom domain in one sweep.
    $tenant = inboxTenant('acme');
    $domain = customDomain($tenant, 'forms.acme-example.com', verified: true, activated: true);

    $dns = fakeDns();
    $dns->failing = true;

    expect(domainService()->verify($domain))->toBeTrue()
        ->and($domain->fresh()->verification_failure_reason)->toBe(DomainVerificationFailure::LookupFailed)
        ->and($domain->fresh()->isVerified())->toBeTrue()
        ->and($domain->fresh()->isLive())->toBeTrue();
});

it('activates only a verified domain, and only through the operator path', function (): void {
    $tenant = inboxTenant('acme');
    $pending = domainService()->claim($tenant, 'forms.acme-example.com');

    expect(domainService()->activate($pending))->toBeFalse()
        ->and($pending->fresh()->isLive())->toBeFalse();

    $pending->forceFill(['verified_at' => now()])->save();

    expect(domainService()->activate($pending->fresh()))->toBeTrue()
        ->and($pending->fresh()->isLive())->toBeTrue();
});

it('deactivates without losing verification', function (): void {
    // Taking a host out of service must not force the tenant to publish a new TXT record to get it back.
    $domain = customDomain(inboxTenant('acme'), 'forms.acme-example.com');

    expect(domainService()->deactivate($domain))->toBeTrue()
        ->and($domain->fresh()->isLive())->toBeFalse()
        ->and($domain->fresh()->isVerified())->toBeTrue();
});

it('refuses to release a tenants subdomain row', function (): void {
    // Deleting it would take the tenant's whole app offline with no way back through this surface.
    $tenant = inboxTenant('acme');
    $label = Domain::unscopedQuery()->where('domain', 'acme')->sole();

    expect(domainService()->release($label))->toBeFalse()
        ->and(Domain::unscopedQuery()->where('domain', 'acme')->exists())->toBeTrue();
});

it('scopes lookups to the requesting tenant, because domains has no RLS', function (): void {
    // `domains` is RLS-EXEMPT — it is the table read to decide which tenant a request IS, so scoping it
    // by tenant is circular. The explicit tenant filter in the service is therefore the ONLY isolation
    // on these queries, with no database backstop underneath.
    $acme = inboxTenant('acme');
    $beta = inboxTenant('beta');
    customDomain($beta, 'forms.beta-example.com');

    expect(domainService()->findForTenant($acme, 'forms.beta-example.com'))->toBeNull()
        ->and(domainService()->findForTenant($beta, 'forms.beta-example.com'))->not->toBeNull()
        ->and(domainService()->forTenant($acme))->toBeEmpty();
});

/*
| ── The claim gate ───────────────────────────────────────────────────────────────────────────────────
*/

it('refuses an unclaimable hostname', function (string $host): void {
    $result = Validator::make(['domain' => $host], ['domain' => [new ClaimableDomain]]);

    expect($result->fails())->toBeTrue();
})->with([
    'bare label' => 'acme',
    'the central apex' => 'meridian.test',
    // The one that matters: routing classifies this host BEFORE any database read and sends it to the
    // subdomain arm, where it resolves by the LABEL `x`. A row for it could never be reached, and
    // reserving it would let one tenant squat a name that resolves to another.
    'under a central domain' => 'x.meridian.test',
    'localhost subdomain' => 'app.localhost',
    'an IP literal' => '203.0.113.10',
    'a scheme' => 'https://forms.acme-example.com',
    'a path' => 'forms.acme-example.com/apply',
    'a trailing dot' => 'forms.acme-example.com.',
    'whitespace' => 'forms acme-example.com',
]);

it('accepts a genuine third-party hostname', function (string $host): void {
    $result = Validator::make(['domain' => $host], ['domain' => [new ClaimableDomain]]);

    expect($result->fails())->toBeFalse();
})->with([
    'forms.acme-example.com',
    'apply.acme-example.co.uk',
    'acme-example.com',
]);

it('refuses a hostname another tenant has merely claimed', function (): void {
    // A PENDING claim reserves the name, because `domains.domain` is globally unique. The global scope
    // hides that row from ordinary reads, which is why the rule queries unscoped — without it the second
    // tenant would pass validation and then hit a raw 23505.
    domainService()->claim(inboxTenant('beta'), 'forms.contested-example.com');

    $result = Validator::make(['domain' => 'forms.contested-example.com'], ['domain' => [new ClaimableDomain]]);

    expect($result->fails())->toBeTrue();
});

/*
| ── The sweep ────────────────────────────────────────────────────────────────────────────────────────
*/

it('verifies pending domains in a sweep and bounds the batch', function (): void {
    config(['tenancy.custom_domains.sweep_batch' => 2]);
    $tenant = inboxTenant('acme');

    foreach (['a', 'b', 'c', 'd'] as $label) {
        domainService()->claim($tenant, "{$label}.acme-example.com");
    }

    $dns = fakeDns();

    domainService()->sweep();

    // dns_get_record() takes NO timeout, so an unbounded batch of dead nameservers is the one way this
    // job can blow its 300s budget — and with $tries = 1 that means failed_jobs every tick, forever.
    expect($dns->queried)->toHaveCount(2);
});

it('sweeps oldest-checked-first so nothing starves', function (): void {
    config(['tenancy.custom_domains.sweep_batch' => 1]);
    $tenant = inboxTenant('acme');

    $first = domainService()->claim($tenant, 'a.acme-example.com');
    $second = domainService()->claim($tenant, 'b.acme-example.com');
    $first->forceFill(['verification_checked_at' => now()])->save();
    $second->forceFill(['verification_checked_at' => now()->subHour()])->save();

    $dns = fakeDns();
    domainService()->sweep();

    expect($dns->queried)->toBe(['_meridian-challenge.b.acme-example.com']);
});

it('releases a pending claim once its TTL expires, but never a verified one', function (): void {
    // `domains.domain` is globally unique, so an abandoned or squatted claim would otherwise block the
    // hostname's real owner forever. A verified domain is deliberately never released by the sweep: a
    // transient DNS outage must not take a tenant's production host out of service.
    config(['tenancy.custom_domains.claim_ttl_hours' => 24]);
    $tenant = inboxTenant('acme');

    $stale = domainService()->claim($tenant, 'stale.acme-example.com');
    $stale->forceFill(['created_at' => now()->subDays(3)])->save();

    $verifiedOld = customDomain($tenant, 'kept.acme-example.com', verified: true, activated: false);
    $verifiedOld->forceFill(['created_at' => now()->subDays(3)])->save();

    fakeDns();
    $result = domainService()->sweep();

    expect($result['released'])->toBe(1)
        ->and(Domain::unscopedQuery()->where('domain', 'stale.acme-example.com')->exists())->toBeFalse()
        ->and(Domain::unscopedQuery()->where('domain', 'kept.acme-example.com')->exists())->toBeTrue();
});

it('does not re-check a recently checked verified domain', function (): void {
    config(['tenancy.custom_domains.recheck_minutes' => 60]);
    $tenant = inboxTenant('acme');
    $domain = customDomain($tenant, 'forms.acme-example.com');
    $domain->forceFill(['verification_checked_at' => now()->subMinutes(5)])->save();

    $dns = fakeDns();
    domainService()->sweep();

    expect($dns->queried)->toBeEmpty();
});

it('re-checks a verified domain once the cadence has elapsed', function (): void {
    // The dangling-DNS control: a tenant that lets its domain lapse is noticed, and the outcome is
    // recorded. Demoting on N consecutive misses is deliberately deferred — see ADR-0012.
    config(['tenancy.custom_domains.recheck_minutes' => 60]);
    $tenant = inboxTenant('acme');
    $domain = customDomain($tenant, 'forms.acme-example.com');
    $domain->forceFill(['verification_checked_at' => now()->subHours(3)])->save();

    $dns = fakeDns();
    domainService()->sweep();

    expect($dns->queried)->toBe(['_meridian-challenge.forms.acme-example.com'])
        ->and($domain->fresh()->verification_failure_reason)->toBe(DomainVerificationFailure::NotFound)
        // Recorded, but still live. Routing is not withdrawn on a single miss.
        ->and($domain->fresh()->isLive())->toBeTrue();
});
