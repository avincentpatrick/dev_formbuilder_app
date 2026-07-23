<?php

declare(strict_types=1);

use App\Support\Migrations\LegacyOverrideBackfill;

// Pure assertions on the H5c merge-day backfill (no database). The end-to-end proof — that the privileged
// INSERT actually stamps every existing tenant — cannot be a RefreshDatabase test: the privileged connection
// commits OUTSIDE the suite's wrapping transaction AND cannot see the test's uncommitted tenants, so a
// populated cross-boundary run belongs to a seeded-migration CI job (the G10a CollaboratorBackfill precedent).
// What IS testable here is the override map and the direction of the count guard — the parts a careless edit
// would silently break. The grandfather EFFECT (a Free tenant with an override reads a feature as enabled) is
// proven behaviourally in tests/Feature/Entitlements/LegacyOverrideTest.php.

it('grandfathers exactly the five ungated Phase-2 features', function (): void {
    // ADR-0008 §D5's list. form_templates + field_library are the two keys the retro-gate introduced.
    expect(LegacyOverrideBackfill::GRANDFATHERED_KEYS)->toBe([
        'xlsform_export',
        'offline_sync',
        'form_templates',
        'field_library',
        'api_access',
    ]);
});

it('builds an override map with every grandfathered key set to true', function (): void {
    $flags = LegacyOverrideBackfill::overrideFlags();

    expect($flags)->toHaveCount(5)
        ->and(array_keys($flags))->toBe(LegacyOverrideBackfill::GRANDFATHERED_KEYS)
        ->and(array_values($flags))->each->toBeTrue();
});

it('passes when the backfill is complete or has grown, and blocks when it under-stamped', function (): void {
    // `<` not `!=`, deliberately: a re-run (ON CONFLICT DO NOTHING) and a tenant created between the INSERT
    // and the count must both pass a correct migration.
    expect(fn () => LegacyOverrideBackfill::assertBackfillComplete(5, 5))->not->toThrow(RuntimeException::class);
    expect(fn () => LegacyOverrideBackfill::assertBackfillComplete(5, 7))->not->toThrow(RuntimeException::class);

    expect(fn () => LegacyOverrideBackfill::assertBackfillComplete(5, 4))
        ->toThrow(RuntimeException::class, 'under-stamped');
});

it('passes cleanly on an empty tenants table, as migrate:fresh produces', function (): void {
    // 0 < 0 is false — the merge-day work happens only against a live, populated database.
    expect(fn () => LegacyOverrideBackfill::assertBackfillComplete(0, 0))->not->toThrow(RuntimeException::class);
});

it('warns the operator about a merge-day regression when it under-stamps', function (): void {
    expect(fn () => LegacyOverrideBackfill::assertBackfillComplete(5, 1))
        ->toThrow(RuntimeException::class, 'regress on merge day');
});
