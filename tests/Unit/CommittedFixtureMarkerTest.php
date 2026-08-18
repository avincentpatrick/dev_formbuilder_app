<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The committed-fixture marker contract (I11a).
|--------------------------------------------------------------------------
| `committedPlatformTenant()` writes a tenant on `pgsql_privileged`, OUTSIDE RefreshDatabase's transaction,
| because the elevated `pgsql_superadmin` connection cannot see uncommitted rows. Nothing rolls such a row
| back, and `migrate:fresh` runs once per process — so the only thing that removes it is
| `purgeCommittedPlatformAuditFixtures()`, which deletes by the `platform-audit-` slug marker.
|
| A fixture named outside that marker therefore leaks for the rest of the run. I11a shipped one
| (`impersonated-slice`) and it took twelve tests down across seven suites — sweeps, reapers, a rollup and
| both seeder smoke tests — none of which touch impersonation. The I7a block in tests/Pest.php had already
| recorded the identical failure ("Nine unrelated tests went red in CI on a locally-green tree"); the marker
| convention was the fix then, and a convention is exactly what a later author walks past.
|
| ⭐ THE MUTATION GUARD FOR THAT ENFORCEMENT. Delete the `str_starts_with` check in
| `committedPlatformTenant()` and this file is the only thing in the suite that goes red — the leak it
| prevents is otherwise invisible until an unrelated gate fails hundreds of files later.
|
| PURE: the guard throws before the helper touches a connection, so this needs no database.
*/

it('refuses a committed tenant slug the purge cannot match', function (): void {
    expect(fn (): mixed => committedPlatformTenant('impersonated-slice'))
        ->toThrow(InvalidArgumentException::class, 'platform-audit-');
});

it('names the offending slug, so the failure is actionable where it happens', function (): void {
    // Not decoration: this throws at collection-adjacent depth inside a helper called from four files, and
    // "must begin with platform-audit-" alone does not say WHICH call site to fix.
    expect(fn (): mixed => committedPlatformTenant('northwind'))
        ->toThrow(InvalidArgumentException::class, "got 'northwind'");
});
