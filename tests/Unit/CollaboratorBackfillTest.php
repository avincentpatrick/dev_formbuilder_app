<?php

declare(strict_types=1);

use App\Support\Migrations\CollaboratorBackfill;

// Pure assertions on the G10a backfill (no database). The end-to-end proof — that the copy actually
// lands and the drop is refused when it doesn't — is a dedicated CI job that migrates a seeded database
// across the boundary; it cannot be a RefreshDatabase test, because the privileged connection commits
// OUTSIDE the suite's wrapping transaction and recreating `form_collaborators` mid-run would strand it
// for every sibling test.
//
// What IS testable here is the part most likely to be broken by a careless edit: the SQL text and, above
// all, the direction of the count comparison.

it('copies every column the new table needs, mapping the renamed ones', function (): void {
    $sql = CollaboratorBackfill::COPY_SQL;

    expect($sql)
        ->toContain('INSERT INTO resource_grants')
        ->toContain('FROM form_collaborators fc')
        // The morph type is the literal short ALIAS, matching the CHECK constraint and the morph map.
        ->toContain("'form'")
        ->toContain('fc.form_id')
        // added_by becomes granted_by; capacity needs no translation (identical backing values).
        ->toContain('fc.added_by')
        ->toContain('fc.capacity')
        // A form grant can never cascade — the descendants CHECK would reject `true` here.
        ->toContain('false');
});

it('reuses the source id as the grant primary key, which is what makes the copy idempotent', function (): void {
    // Reusing fc.id buys three things at once: ON CONFLICT makes a re-run a no-op, the ids stay UUIDv7 so
    // `orderByDesc('id')` still means "most recently granted", and reconciliation is a single join.
    // Minting fresh v4 uuids would destroy the ordering permanently and silently.
    expect(CollaboratorBackfill::COPY_SQL)
        ->toContain('SELECT fc.id')
        ->toContain('ON CONFLICT (id) DO NOTHING');
});

it('restores form grants back to the legacy shape, dropping node grants', function (): void {
    $sql = CollaboratorBackfill::RESTORE_SQL;

    expect($sql)
        ->toContain('INSERT INTO form_collaborators')
        ->toContain('g.scopeable_id')
        ->toContain('g.granted_by')
        // Only form-targeted grants have any representation in the old schema. Node grants are dropped,
        // which is why rolling back past this migration is documented as lossy and forward-only in prod.
        ->toContain("WHERE g.scopeable_type = 'form'")
        ->toContain('ON CONFLICT (id) DO NOTHING');
});

it('passes when the copy is complete or has grown, and blocks when it under-copied', function (): void {
    // `<` not `!=`, deliberately: a re-run, or a grant created between the copy and the count, must not
    // abort a correct migration.
    expect(fn () => CollaboratorBackfill::assertCopyComplete(5, 5))->not->toThrow(RuntimeException::class);
    expect(fn () => CollaboratorBackfill::assertCopyComplete(5, 7))->not->toThrow(RuntimeException::class);

    expect(fn () => CollaboratorBackfill::assertCopyComplete(5, 4))
        ->toThrow(RuntimeException::class, 'under-copied');
});

it('passes cleanly on an empty source table, as migrate:fresh produces', function (): void {
    // 0 < 0 is false. If this guard were `!=` it would still pass here — but the `<` form is what also
    // keeps a re-run working, so both properties are pinned by the same operator.
    expect(fn () => CollaboratorBackfill::assertCopyComplete(0, 0))->not->toThrow(RuntimeException::class);
});

it('refuses to continue when a backfilled grant points at a missing or cross-tenant form', function (): void {
    // The privileged connection BYPASSES the RLS guard's WITH CHECK, so this hand-rolled re-check is the
    // only thing standing between a latent cross-tenant legacy row and a laundered authorization grant.
    // `form_collaborators.form_id` is a single-column FK with tenant_id independent, so such a row is
    // structurally legal in the source table.
    expect(fn () => CollaboratorBackfill::assertNoCrossTenant(0))->not->toThrow(RuntimeException::class);

    expect(fn () => CollaboratorBackfill::assertNoCrossTenant(1))
        ->toThrow(RuntimeException::class, 'cross-tenant');
});

it('names the drop migration in both guard messages so the operator knows not to proceed', function (): void {
    // The failure mode this text prevents is an operator seeing a red migration, shrugging, and running
    // the next one — which would destroy the only remaining copy of every access grant.
    foreach ([
        fn () => CollaboratorBackfill::assertCopyComplete(5, 1),
        fn () => CollaboratorBackfill::assertNoCrossTenant(2),
    ] as $failing) {
        expect($failing)->toThrow(RuntimeException::class, 'DO NOT run the drop migration');
    }
});
