<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use App\Services\Tenancy\CustomDomainService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The custom-domain audit sites (Increment I2) — split out of AuditCoverageTest because they carry two
| hazards that no other audited surface has, and burying them in a list would hide both:
|
|  ① `domains.id` IS AN INTEGER. `create_domains_table.php:23` is `$table->increments('id')`; the target
|     column `audits.auditable_id` is `uuid NOT NULL`. Keying a domain audit on the domain row would push
|     `'3'` into a uuid column and raise SQLSTATE 22P02 on the first live claim — invisible to PHPStan, to
|     the `audits_event_check`, and to the migration linter. So the rows are keyed on the TENANT's uuid
|     with the hostname in the payload, exactly as spec §1 already solves role grants, and the first test
|     below is that fact's only mechanical guard.
|
|  ② `domains` IS RLS-EXEMPT; `audits` IS NOT. The `audits` INSERT policy compares `tenant_id` to the
|     AMBIENT session GUC, not to the row being described — so an operator path holding tenant B's domain
|     under tenant A's context would file B's event in A's ledger and RLS could not stop it, precisely
|     because the source table is exempt. `CustomDomainService::auditDomain()`'s equality check IS that
|     isolation, and the last two tests are what keep it.
|
| The verbs that are NOT audited (verify, activate, deactivate, and the sweep) are asserted here too. Their
| absence is a decision with reasons, so it is pinned like any other behaviour rather than left to be
| "fixed" by someone who reads a gap as an oversight.
*/

function domainAuditService(): CustomDomainService
{
    return app(CustomDomainService::class);
}

/** Every `domain` row in the ledger, oldest first. */
function domainAudits(): Collection
{
    return Audit::query()->where('auditable_type', 'domain')->orderBy('id')->get();
}

beforeEach(function (): void {
    TenantContext::flush();

    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->actingAs($this->owner);
});

it('keys a domain audit on the TENANT uuid, never the integer domain id', function (): void {
    // THE REGRESSION GUARD FOR HAZARD ①. Revert auditDomain() to `(string) $domain->getKey()` and this
    // test does not merely fail an assertion — the INSERT raises 22P02 and the claim 500s, which is
    // exactly the production behaviour it exists to prevent.
    $domain = domainAuditService()->claim($this->tenant, 'forms.acme-example.com');

    $audit = domainAudits()->sole();

    expect($audit->auditable_id)->toBe($this->tenant->id);
    expect($audit->event)->toBe(AuditEvent::Created);
    // The hostname travels in the payload because the key cannot carry it — and `domains` rows are
    // deletable, so a join would not always answer "which host was this about" either.
    expect($audit->new_values)->toBe(['domain' => 'forms.acme-example.com', 'is_primary' => false]);
    expect($audit->old_values)->toBeNull();
    expect($audit->user_id)->toBe($this->owner->id);

    // Sanity: the id we did NOT use is an int, which is the whole reason for the indirection.
    expect($domain->getKey())->toBeInt();
});

it('never records the verification token, which is a settled decision', function (): void {
    domainAuditService()->claim($this->tenant, 'forms.acme-example.com');

    $audit = domainAudits()->sole();
    $stored = Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->sole();

    // The token is deliberately NOT in AuditRedactor::SECRETS — 2026_08_04_000001…:49-51 records why: it
    // is published in public DNS at a name only the zone's controller can write, so it is not a
    // credential. The right handling for a non-secret that is also not accountability-relevant is to keep
    // it out of the payload, which is a different thing from redacting it and must not drift into one.
    expect($audit->new_values)->not->toHaveKey('verification_token');
    expect(json_encode($audit->new_values))->not->toContain($stored->verification_token);
    expect($audit->redacted_fields)->toBeNull();
});

it('audits a primary change on both sides, so the row reads without a join', function (): void {
    $domain = domainAuditService()->claim($this->tenant, 'forms.acme-example.com');
    $domain->forceFill(['verified_at' => now(), 'activated_at' => now()])->save();

    expect(domainAuditService()->makePrimary($domain->refresh()))->toBeTrue();

    $audit = domainAudits()->last();

    expect($audit->event)->toBe(AuditEvent::Updated);
    expect($audit->old_values)->toBe(['domain' => 'forms.acme-example.com', 'is_primary' => false]);
    expect($audit->new_values)->toBe(['domain' => 'forms.acme-example.com', 'is_primary' => true]);
});

it('leaves an audit row behind after a release HARD-deletes the domain', function (): void {
    $domain = domainAuditService()->claim($this->tenant, 'forms.acme-example.com');
    $domain->forceFill(['verified_at' => now(), 'activated_at' => now()])->save();

    expect(domainAuditService()->release($domain->refresh()))->toBeTrue();

    // The row is gone for good — no soft delete, no tombstone. The audit entry is the ONLY surviving
    // record that this tenant ever controlled this hostname, which is why the snapshot is taken before
    // the delete and both happen in one transaction.
    expect(Domain::unscopedQuery()->where('domain', 'forms.acme-example.com')->exists())->toBeFalse();

    $audit = domainAudits()->last();

    expect($audit->event)->toBe(AuditEvent::Deleted);
    expect($audit->old_values['domain'])->toBe('forms.acme-example.com');
    expect($audit->old_values['verified_at'])->toBeString();
    expect($audit->old_values['activated_at'])->toBeString();
    expect($audit->new_values)->toBeNull();
});

it('writes NO audit row from verify, activate or deactivate', function (): void {
    $domain = domainAuditService()->claim($this->tenant, 'forms.acme-example.com');
    $before = domainAudits()->count();

    fakeDns([
        '_meridian-challenge.forms.acme-example.com' => [
            'meridian-domain-verification='.$domain->verification_token,
        ],
    ]);

    domainAuditService()->verify($domain->refresh());
    domainAuditService()->activate($domain->refresh());
    domainAuditService()->deactivate($domain->refresh());

    // Not an oversight — three separate reasons, all in CustomDomainService's docblock. `verify()` is
    // reached from HTTP *and* the console sweep, so a conditional audit would make the ledger depend on
    // which caller won a race; and it records a check result the sweep rewrites every fifteen minutes
    // forever. `activate`/`deactivate` are artisan-only with NO actor, so a row from there would carry
    // `user_id = null` beside `is_system_action = false` — malformed by AuditLogger's own contract.
    expect(domainAudits()->count())->toBe($before);
});

it('does not throw — or write — when the sweep runs with no tenant context', function (): void {
    domainAuditService()->claim($this->tenant, 'forms.acme-example.com');
    $before = domainAudits()->count();

    // The console posture: no tenant GUC at all. An `audits` INSERT here violates the strict append-only
    // WITH CHECK, and the sweep runs inside a $tries = 1 MaintenanceJob — so a throw would land in
    // failed_jobs ON EVERY TICK, FOREVER. The guard fails silent on purpose: an audit gap is recoverable,
    // a permanently-failing sweep is not.
    TenantContext::flush();
    fakeDns([]);

    $result = domainAuditService()->sweep();

    expect($result)->toHaveKeys(['verified', 'released']);

    enterTenant($this->tenant->id, $this->owner->id);
    expect(domainAudits()->count())->toBe($before);
});

it('refuses to file one tenant\'s domain event in another tenant\'s ledger', function (): void {
    // HAZARD ② IN ONE TEST. `domains` is RLS-exempt, so tenant B's row can be handed to this service
    // while the ambient context is tenant A's. The audits WITH CHECK compares tenant_id to the AMBIENT
    // GUC, not to the row's owner — so without the equality check the INSERT would SUCCEED into A's
    // ledger, and no database policy would catch it.
    $beta = inboxTenant('beta');
    $betaDomain = Domain::unscopedQuery()->create([
        'tenant_id' => $beta->id,
        'domain' => 'forms.beta-example.com',
        'verification_token' => bin2hex(random_bytes(32)),
        'verified_at' => now(),
        'activated_at' => now(),
        'is_primary' => false,
    ]);

    // Ambient context is still ACME's, from beforeEach.
    expect(TenantContext::currentTenantId())->toBe($this->tenant->id);

    domainAuditService()->makePrimary($betaDomain);

    // The write itself still happened — this guard protects the LEDGER, not the domains table, whose own
    // isolation is the service's `where('tenant_id', …)` filters.
    expect($betaDomain->refresh()->is_primary)->toBeTrue();
    // …but nothing was filed in Acme's ledger.
    expect(domainAudits()->count())->toBe(0);
});
