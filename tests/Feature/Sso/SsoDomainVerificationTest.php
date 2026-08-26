<?php

declare(strict_types=1);

use App\Enums\DomainVerificationFailure;
use App\Models\SsoVerifiedDomain;
use App\Models\Tenant;
use App\Services\Sso\SsoDomainService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The SSO email-domain trust anchor (M18 — ADR-0016 §D34).
|
| A workspace's SAML connection is metadata that workspace installed for itself, so a valid signature says
| somebody authenticated at a provider THEY chose and nothing about which addresses that provider may speak
| for. This file covers the fact that closes the gap — which domains a workspace has PROVEN it controls —
| and its one consumer question, `isVerifiedFor()`.
|
| ⚠️ THE END-TO-END REFUSAL LIVES IN `SsoAcsWebTest`, NOT HERE, and the split is deliberate rather than
| tidy: this file proves the FACT and its isolation; that one proves that an assertion which reaches the
| real ACS over a real signed round trip is refused, and that the refusal is recorded with a reason the
| database's own CHECK accepts. Neither substitutes for the other — a lifecycle that works and a gate that
| never calls it would both be green here.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();

    $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);

    $this->dns = fakeDns();
    $this->domains = app(SsoDomainService::class);

    enterTenant($this->acme->id);
});

/** Publish the challenge the service is about to look for, at the name it will look at. */
function publishChallengeFor(SsoVerifiedDomain $row): void
{
    /** @var SsoDomainService $service */
    $service = app(SsoDomainService::class);

    test()->dns->publish(
        $service->challengeName($row->domain),
        [$service->expectedValue($row->verification_token)],
    );
}

/*
|--------------------------------------------------------------------------
| The lifecycle
|--------------------------------------------------------------------------
*/

it('mints a claim that grants nothing until the record is published', function (): void {
    $row = $this->domains->claim($this->acme, 'acme.test');

    expect($row->domain)->toBe('acme.test')
        ->and($row->verification_token)->toHaveLength(64)
        ->and($row->isVerified())->toBeFalse()
        // The whole point of the pending state: the row exists and the predicate still says no.
        ->and($this->domains->isVerifiedFor('ada@acme.test'))->toBeFalse();
});

it('verifies a domain whose challenge record is published, at the exact name it advertises', function (): void {
    $row = $this->domains->claim($this->acme, 'acme.test');
    publishChallengeFor($row);

    expect($this->domains->verify($row))->toBeTrue()
        ->and($row->fresh()->isVerified())->toBeTrue()
        ->and($this->domains->isVerifiedFor('ada@acme.test'))->toBeTrue()
        // ⚠️ THE NAME QUERIED IS ASSERTED, NOT INFERRED FROM THE ROW. A wrong challenge prefix is the most
        // likely defect in this feature and the resulting row looks identical either way — the fake records
        // the lookup precisely so this is answerable. It must be the SSO leaf, never `_meridian-challenge`,
        // or a workspace's host claim would silently satisfy an identity claim.
        ->and($this->dns->queried)->toBe(['_meridian-sso.acme.test']);
});

it('records mismatch when the zone publishes something else, and not-found when it publishes nothing', function (): void {
    $row = $this->domains->claim($this->acme, 'acme.test');

    expect($this->domains->verify($row))->toBeFalse()
        ->and($row->fresh()->verification_failure_reason)->toBe(DomainVerificationFailure::NotFound);

    $this->dns->publish('_meridian-sso.acme.test', ['meridian-sso-domain-verification=somebody-elses-token']);

    expect($this->domains->verify($row))->toBeFalse()
        ->and($row->fresh()->verification_failure_reason)->toBe(DomainVerificationFailure::Mismatch);
});

it('never demotes a verified domain when the lookup itself fails', function (): void {
    // The null-versus-empty-array contract, and here it protects more than a custom host does: `verified_at`
    // is what stands between an assertion and an account, so letting one SERVFAIL retract it would turn
    // somebody else's DNS outage into a sign-in outage for every new joiner at this workspace.
    $row = $this->domains->claim($this->acme, 'acme.test');
    publishChallengeFor($row);
    $this->domains->verify($row);

    $this->dns->failing = true;

    expect($this->domains->verify($row))->toBeTrue()
        ->and($row->fresh()->isVerified())->toBeTrue()
        ->and($row->fresh()->verification_failure_reason)->toBeNull();
});

it('records lookup_failed on a PENDING row when the resolver cannot answer', function (): void {
    $row = $this->domains->claim($this->acme, 'acme.test');
    $this->dns->failing = true;

    expect($this->domains->verify($row))->toBeFalse()
        ->and($row->fresh()->verification_failure_reason)->toBe(DomainVerificationFailure::LookupFailed);
});

it('re-checks a verified domain without ever writing a failure beside a live verified_at', function (): void {
    // ⚠️ THIS IS A DATABASE CONSTRAINT, NOT A PREFERENCE. `sso_verified_domains_verified_has_no_failure_chk`
    // refuses a row holding both, so a re-check that found the record gone would raise a 23514 on a path
    // whose entire contract is that it never throws on a DNS outcome.
    $row = $this->domains->claim($this->acme, 'acme.test');
    publishChallengeFor($row);
    $this->domains->verify($row);

    $this->dns->clear();

    expect($this->domains->verify($row))->toBeTrue()
        ->and($row->fresh()->verification_failure_reason)->toBeNull()
        ->and($row->fresh()->verification_checked_at)->not->toBeNull();
});

it('rotates the token on a re-claim of a pending domain but never on a verified one', function (): void {
    $pending = $this->domains->claim($this->acme, 'acme.test');
    $firstToken = $pending->verification_token;

    $reclaimed = $this->domains->claim($this->acme, 'acme.test');

    expect($reclaimed->verification_token)->not->toBe($firstToken)
        ->and(SsoVerifiedDomain::query()->count())->toBe(1);

    publishChallengeFor($reclaimed);
    $this->domains->verify($reclaimed);

    // Rotating here would revoke the workspace's own proof of control as a side effect of a button press.
    $again = $this->domains->claim($this->acme, 'acme.test');

    expect($again->verification_token)->toBe($reclaimed->verification_token)
        ->and($again->isVerified())->toBeTrue();
});

it('gives up the proof when a domain is released', function (): void {
    $row = $this->domains->claim($this->acme, 'acme.test');
    publishChallengeFor($row);
    $this->domains->verify($row);

    expect($this->domains->isVerifiedFor('ada@acme.test'))->toBeTrue();

    $this->domains->release($row);

    expect($this->domains->isVerifiedFor('ada@acme.test'))->toBeFalse()
        ->and(SsoVerifiedDomain::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The predicate — the two parses that look right and are not
|--------------------------------------------------------------------------
*/

it('matches the domain exactly and never by suffix', function (): void {
    SsoVerifiedDomain::factory()->verified()->forDomain('acme.test')->create();

    // A workspace that proved it controls `acme.test` has proved nothing about `mail.acme.test`: a subdomain
    // can be delegated to a third party, so a `str_ends_with` here would hand an assertion authority over an
    // address space the workspace may have given away.
    expect($this->domains->isVerifiedFor('ada@acme.test'))->toBeTrue()
        ->and($this->domains->isVerifiedFor('ada@mail.acme.test'))->toBeFalse()
        ->and($this->domains->isVerifiedFor('ada@notacme.test'))->toBeFalse()
        // And the other direction: the verified domain must not match an address whose domain merely
        // contains it as a prefix.
        ->and($this->domains->isVerifiedFor('ada@acme.test.evil.example'))->toBeFalse();
});

it('takes the domain from the LAST at-sign, so a quoted local part cannot smuggle one', function (): void {
    SsoVerifiedDomain::factory()->verified()->forDomain('acme.test')->create();

    // `filter_var(FILTER_VALIDATE_EMAIL)` accepts a quoted local part containing an `@`, so `"a@b"@acme.test`
    // reaches the provisioner as a valid address. Splitting on the FIRST one yields `b"@acme.test` — which
    // nobody could ever verify, so the naive parse fails CLOSED here and would merely have been a puzzling
    // refusal. It is asserted because the direction it fails in is luck, not design.
    expect(SsoDomainService::domainOf('"a@b"@acme.test'))->toBe('acme.test')
        ->and($this->domains->isVerifiedFor('"a@b"@acme.test'))->toBeTrue()
        ->and(SsoDomainService::domainOf('nobody-at-all'))->toBeNull()
        ->and($this->domains->isVerifiedFor('nobody-at-all'))->toBeFalse();
});

it('normalises case and a trailing root dot, so a pasted zone-file name still matches', function (): void {
    // `acme.test.` is the fully-qualified form and an ordinary thing to paste out of a zone file, while
    // `users.email` never carries one. Without normalisation a workspace could verify a domain no assertion
    // it ever receives can match — and the failure would present as "I verified it and it still refuses me".
    $row = $this->domains->claim($this->acme, '  ACME.test.  ');

    expect($row->domain)->toBe('acme.test');

    publishChallengeFor($row);
    $this->domains->verify($row);

    expect($this->domains->isVerifiedFor('ADA@Acme.Test'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Isolation — the property the class docblock claims RLS provides
|--------------------------------------------------------------------------
*/

it('has row-level security enabled AND forced', function (): void {
    // FORCE is the half that matters: without it the table's OWNER — the application role — bypasses every
    // policy, and every case below would pass while isolating nothing.
    $flags = DB::selectOne(
        'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
        ['sso_verified_domains'],
    );

    expect($flags->relrowsecurity)->toBeTrue()
        ->and($flags->relforcerowsecurity)->toBeTrue();
});

it('does not let one workspace’s verified domain authorise another’s assertions', function (): void {
    // ⚠️ THE CLAIM THIS FILE EXISTS TO CHECK. `SsoDomainService` carries no `where('tenant_id', …)` — it
    // relies on RLS, diverging from `CustomDomainService`, which needs an explicit filter because `domains`
    // is RLS-exempt. That divergence is only safe if the database really is doing the scoping.
    $globexRow = TenantContext::runFor((string) $this->globex->getKey(), function (): SsoVerifiedDomain {
        return SsoVerifiedDomain::factory()->verified()->forDomain('globex.test')->create();
    });

    expect($globexRow->tenant_id)->toBe((string) $this->globex->getKey());

    enterTenant($this->acme->id);

    expect($this->domains->isVerifiedFor('ada@globex.test'))->toBeFalse();

    enterTenant($this->globex->id);

    expect($this->domains->isVerifiedFor('ada@globex.test'))->toBeTrue();
});

it('lets two workspaces each verify the SAME domain, which a global unique would have forbidden', function (): void {
    // ADR-0002 §D5 rules out a global unique on `domain`, and that is the right answer on the merits too:
    // one controller legitimately runs two workspaces, and a global unique would have let whichever claimed
    // first deny the other — turning a control designed to stop squatting into a squatting primitive.
    $acmeRow = $this->domains->claim($this->acme, 'shared.test');

    $globexRow = TenantContext::runFor((string) $this->globex->getKey(), fn (): SsoVerifiedDomain => $this->domains->claim($this->globex, 'shared.test'));

    expect($globexRow->getKey())->not->toBe($acmeRow->getKey())
        // Each proves control on its own token, so neither can verify using the other's published record.
        ->and($globexRow->verification_token)->not->toBe($acmeRow->verification_token);
});

/*
|--------------------------------------------------------------------------
| `php artisan sso:domains` — the only surface that can satisfy the control today
|--------------------------------------------------------------------------
|
| ⚠️ COVERED HERE RATHER THAN IN A FILE OF ITS OWN, because the command IS this lifecycle's surface and
| splitting them would let the two drift. It is not a nice-to-have: the tenant-facing card is the other
| lane's tree and is filed as its own row, so until it lands this command is the ONLY way a workspace can
| get a domain verified — and shipping a merge-blocking refusal whose sole remedy is untested is the half
| of a security increment that quietly does not work.
|
| ⚠️ EVERY CASE RUNS WITH NO AMBIENT TENANT CONTEXT, WHICH IS THE POINT. `sso_verified_domains` is under
| strict RLS and a console process has none, so a verb that forgot `TenantContext::runFor()` would return
| zero rows and report "this workspace has no domains" — an answer, not an error. `TenantContext::flush()`
| before each assertion is what makes that failure reachable instead of masked by the test's own setup.
|
*/

it('claims a domain from the console and prints the record to publish', function (): void {
    TenantContext::flush();

    $this->artisan('sso:domains', ['tenant' => 'acme', '--claim' => 'acme.test'])
        ->expectsOutputToContain('_meridian-sso.acme.test')
        ->expectsOutputToContain('meridian-sso-domain-verification=')
        ->assertExitCode(0);

    enterTenant($this->acme->id);
    expect(SsoVerifiedDomain::query()->where('domain', 'acme.test')->exists())->toBeTrue();
});

it('verifies from the console once the record is published, and fails loudly until then', function (): void {
    TenantContext::flush();
    $this->artisan('sso:domains', ['tenant' => 'acme', '--claim' => 'acme.test'])->assertExitCode(0);

    // FAILURE rather than SUCCESS on "not there yet", so a retry loop can tell the two apart without
    // parsing prose — which is the whole reason an operator command returns a code at all.
    TenantContext::flush();
    $this->artisan('sso:domains', ['tenant' => 'acme', '--verify' => 'acme.test'])->assertExitCode(1);

    enterTenant($this->acme->id);
    publishChallengeFor(SsoVerifiedDomain::query()->where('domain', 'acme.test')->firstOrFail());

    TenantContext::flush();
    $this->artisan('sso:domains', ['tenant' => 'acme', '--verify' => 'acme.test'])->assertExitCode(0);

    enterTenant($this->acme->id);
    expect($this->domains->isVerifiedFor('ada@acme.test'))->toBeTrue();
});

it('says out loud that releasing a VERIFIED domain stops new joiners but not existing members', function (): void {
    enterTenant($this->acme->id);
    SsoVerifiedDomain::factory()->verified()->forDomain('acme.test')->create();

    TenantContext::flush();
    $this->artisan('sso:domains', ['tenant' => 'acme', '--release' => 'acme.test'])
        // The consequence an operator is least likely to have in mind, and the one this whole design turns
        // on: the grandfather is the ACTIVE MEMBERSHIP, so releasing a domain is not a lockout.
        ->expectsOutputToContain('existing active members are unaffected')
        ->assertExitCode(0);

    enterTenant($this->acme->id);
    expect(SsoVerifiedDomain::query()->count())->toBe(0);
});

it('warns rather than reassures when a workspace has verified nothing', function (): void {
    TenantContext::flush();

    // An empty list is the state in which single sign-on admits NO new members, so a bare "no rows" would
    // be the most misleading possible answer.
    $this->artisan('sso:domains', ['tenant' => 'acme'])
        ->expectsOutputToContain('will admit no NEW members')
        ->assertExitCode(0);
});

it('refuses two verbs at once rather than silently preferring one', function (): void {
    TenantContext::flush();

    $this->artisan('sso:domains', [
        'tenant' => 'acme',
        '--claim' => 'acme.test',
        '--release' => 'globex.test',
    ])->assertExitCode(1);

    enterTenant($this->acme->id);
    expect(SsoVerifiedDomain::query()->count())->toBe(0);
});

it('refuses a tenant nobody can resolve, rather than acting on a guess', function (): void {
    TenantContext::flush();

    $this->artisan('sso:domains', ['tenant' => 'no-such-workspace', '--claim' => 'acme.test'])
        ->assertExitCode(1);
});
