<?php

declare(strict_types=1);

use App\Enums\DomainVerificationFailure;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| H22a — the `domains` verification columns and the two database guarantees that keep the dot honest as a
| discriminator. `domains` is RLS-exempt (it is read before tenant context exists), so these constraints are
| the only structural enforcement this table has.
*/

it('repoints the tenancy domain model at ours', function (): void {
    // config/tenancy.php:'domain_model' is the single switch. If it regressed to stancl's model the casts
    // below would silently return strings and every isLive() check would still "work" — on a string.
    expect(config('tenancy.domain_model'))->toBe(Domain::class);

    $tenant = inboxTenant('acme');

    expect($tenant->domains()->first())->toBeInstanceOf(Domain::class);
});

it('leaves every existing subdomain row valid and live', function (): void {
    // The sixty existing fixture sites write a bare label with no verification data. All six columns are
    // nullable with no default precisely so none of them had to change.
    $domain = inboxTenant('acme')->domains()->first();

    expect($domain->isCustom())->toBeFalse()
        ->and($domain->isLive())->toBeTrue()
        ->and($domain->isVerified())->toBeFalse()
        ->and($domain->verification_token)->toBeNull();
});

it('casts the verification columns', function (): void {
    $tenant = inboxTenant('acme');
    $tenant->domains()->create([
        'domain' => 'forms.acme-example.com',
        'verification_token' => str_repeat('a', 64),
        'token_issued_at' => now(),
        'verified_at' => now(),
        'verification_checked_at' => now(),
        'verification_failure_reason' => DomainVerificationFailure::Mismatch->value,
    ]);

    $custom = Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->sole();

    expect($custom->verified_at)->toBeInstanceOf(Carbon::class)
        ->and($custom->verification_failure_reason)->toBe(DomainVerificationFailure::Mismatch)
        ->and($custom->isCustom())->toBeTrue()
        // Verified is NOT live. The operator activation step is what stands between a proven hostname and a
        // respondent being sent to an origin with no certificate on it.
        ->and($custom->isVerified())->toBeTrue()
        ->and($custom->isLive())->toBeFalse();

    $custom->update(['activated_at' => now()]);

    expect($custom->fresh()->isLive())->toBeTrue();
});

it('refuses a dotted row with no verification token', function (): void {
    // domains_custom_requires_token_chk. Without it, a hand-written or mass-assigned FQDN row could exist in
    // a state the verification service never minted — and `$guarded = []` on stancl's model makes that a
    // one-line mistake, not a contrived one.
    $tenant = inboxTenant('acme');

    expect(fn () => $tenant->domains()->create(['domain' => 'forms.acme-example.com']))
        ->toThrow(QueryException::class);
});

it('refuses two rows sharing a verification token', function (): void {
    $tenant = inboxTenant('acme');
    $other = inboxTenant('beta');
    $token = str_repeat('b', 64);

    $tenant->domains()->create(['domain' => 'a.acme-example.com', 'verification_token' => $token]);

    expect(fn () => $other->domains()->create(['domain' => 'b.beta-example.com', 'verification_token' => $token]))
        ->toThrow(QueryException::class);
});

it('leaves the token index partial so subdomain rows do not collide on NULL', function (): void {
    // Two tenants, two label rows, both with a NULL token. A non-partial unique index would still allow this
    // (NULLs are not comparable), but the predicate is asserted so the index is not "simplified" later into
    // one that also indexes every subdomain row for no reason.
    inboxTenant('acme');
    inboxTenant('beta');

    $indexes = DB::table('pg_indexes')->where('tablename', 'domains')->pluck('indexdef', 'indexname');

    expect($indexes)->toHaveKey('domains_verification_token_unique')
        ->and($indexes['domains_verification_token_unique'])->toContain('WHERE (verification_token IS NOT NULL)')
        ->and($indexes)->toHaveKey('domains_custom_sweep_idx');
});

it('keeps domains out of RLS', function (): void {
    // Stated as a test because H22a is the increment most likely to make someone reach for RLS here. It
    // cannot have it: this is the table read to DECIDE which tenant a request is, so scoping it by the
    // tenant is circular. The compensating control is explicit tenant_id filtering in CustomDomainService.
    $relrowsecurity = DB::table('pg_class')->where('relname', 'domains')->value('relrowsecurity');

    expect($relrowsecurity)->toBeFalse();
});

it('never lets a tenant see another tenants domain rows through the relation', function (): void {
    // The relation is tenant-keyed even though the table is not RLS-scoped, so the ordinary read path is
    // safe; what is NOT safe is an unfiltered Domain::query(), which is why CustomDomainService always
    // constrains on tenant_id. Tenant::domains() is asserted here as the shape everything else builds on.
    $acme = inboxTenant('acme');
    inboxTenant('beta');

    expect($acme->domains()->pluck('domain')->all())->toBe(['acme']);
});
