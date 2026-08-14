<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\ExtractionGuardException;
use App\Support\Tenancy\ExtractionGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The two preconditions of an RLS-filtered read (Phase 4, P2a — ADR-0017 §D4).
|--------------------------------------------------------------------------
| Reading tenant data with no `where` clause is the shape ADR-0002 argues for — the database is the
| isolation, and a hand-written predicate puts the guarantee back in PHP. But it relocates the filter
| into two things the calling code never states: WHICH ROLE the connection authenticated as, and
| WHETHER THE GUC ACTUALLY TOOK. Both failures are silent, and this file proves the guards catch them.
|
| ⚠️ Reset with `TenantContext::applyLocal(null)` and never `forget()`. forget() is `is_local = false`,
| a SESSION-scoped GUC that survives RefreshDatabase's rollback and bleeds into the next test on this
| connection.
*/

afterEach(function (): void {
    TenantContext::applyLocal(null);
    TenantContext::flush();
});

it('accepts the ordinary application role', function (): void {
    // The guard must not be so strict that it blocks the only connection that is supposed to use it.
    expect(fn () => ExtractionGuard::assertRlsSubjectRole())->not->toThrow(ExtractionGuardException::class);
});

it('refuses a role that bypasses row-level security', function (): void {
    // THE CASE THAT MATTERS. `pgsql_privileged` authenticates as a SUPERUSER/BYPASSRLS role, so every
    // policy is ignored: a tenant-scoped read on this connection returns EVERY tenant's rows, with no
    // error and no `where` clause anywhere to notice. Without this guard the only thing standing between
    // an operator command and a full-database dump labelled as one tenant is which role `.env` names.
    expect(fn () => ExtractionGuard::assertRlsSubjectRole('pgsql_privileged'))
        ->toThrow(ExtractionGuardException::class, 'SUPERUSER or BYPASSRLS');
});

it('names the offending role, so the failure says which connection to fix', function (): void {
    // Not decoration: this fires from a console command whose whole job is reading data, and "refused"
    // without the role name costs the reader the investigation the guard existed to save them.
    expect(fn () => ExtractionGuard::assertRlsSubjectRole('pgsql_privileged'))
        ->toThrow(ExtractionGuardException::class, (string) config('database.connections.pgsql_privileged.username'));
});

it('accepts a context established inside a transaction', function (): void {
    $tenant = inboxTenant('acme');

    DB::transaction(function () use ($tenant): void {
        TenantContext::applyLocal((string) $tenant->getKey());

        expect(fn () => ExtractionGuard::assertContextEstablished((string) $tenant->getKey()))
            ->not->toThrow(ExtractionGuardException::class);
    });
});

it('refuses a context that was never established', function (): void {
    $tenant = inboxTenant('acme');

    TenantContext::applyLocal(null);

    expect(fn () => ExtractionGuard::assertContextEstablished((string) $tenant->getKey()))
        ->toThrow(ExtractionGuardException::class, 'carries no tenant context');
});

it('refuses a context established for a different tenant', function (): void {
    // Cheap to catch here and expensive to catch downstream: without the read-back, a caller that set
    // tenant B and acted for tenant A produces a complete, well-formed, entirely wrong result.
    $acme = inboxTenant('acme');
    $globex = inboxTenant('globex');

    DB::transaction(function () use ($acme, $globex): void {
        TenantContext::applyLocal((string) $globex->getKey());

        expect(fn () => ExtractionGuard::assertContextEstablished((string) $acme->getKey()))
            ->toThrow(ExtractionGuardException::class, 'is scoped to');
    });
});

it('reads the live session rather than the PHP mirror, which is what makes it a guard at all', function (): void {
    // TenantContext keeps a PHP-side copy of the GUC so BelongsToTenant can inject its predicate without a
    // round trip. If the guard consulted that copy it would agree with the caller by construction and
    // could never disagree with the DATABASE — which is the only opinion that decides what RLS returns.
    //
    // Proven by driving the two apart: set the session GUC directly, leave the mirror pointing elsewhere,
    // and assert the guard follows the session.
    $acme = inboxTenant('acme');
    $globex = inboxTenant('globex');

    DB::transaction(function () use ($acme, $globex): void {
        TenantContext::applyLocal((string) $acme->getKey());

        // Move the mirror only. The session still says acme.
        TenantContext::restoreMirror((string) $globex->getKey(), null);

        expect(TenantContext::currentTenantId())->toBe((string) $globex->getKey());
        expect(fn () => ExtractionGuard::assertContextEstablished((string) $acme->getKey()))
            ->not->toThrow(ExtractionGuardException::class);
        expect(fn () => ExtractionGuard::assertContextEstablished((string) $globex->getKey()))
            ->toThrow(ExtractionGuardException::class);
    });
});

/*
| ⚠️ WHAT THIS FILE DELIBERATELY DOES NOT TEST, AND WHY SAYING SO IS THE POINT.
|
| The failure `assertContextEstablished()` exists for — `applyLocal()` called OUTSIDE a transaction being a
| silent no-op — is NOT REPRODUCIBLE IN THIS SUITE. RefreshDatabase wraps every test in a transaction, so
| there is always an ambient one and `SET LOCAL` always takes. That is precisely why `enterTenant()` works
| in 300-odd files without wrapping anything.
|
| So a test asserting "the GUC is empty outside a transaction" would fail here while being TRUE in
| production, and the tempting fix — asserting it is populated — would pin the harness's behaviour as
| though it were the product's. The guard's value is at the console, where no ambient transaction exists;
| the cases above prove the mechanism (it reads the session, it refuses a mismatch, it refuses an absence),
| and this note records the one arm the harness structurally cannot reach.
*/
