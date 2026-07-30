<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Support\Migrations\OcrCompatibilityBackfill;

// Pure assertions on the H8 retroactive backfill (no database). What this file guards is the SQL's SHAPE and
// the direction of the guards — the parts a careless edit or a well-meaning "optimization" would silently
// break. The EFFECT on real rows is proven in tests/Feature/Forms/OcrCompatibilityBackfillEffectTest.php,
// which is possible here in a way it was not for the two precedent backfills: their bodies are inherently
// cross-tenant, whereas this one just scans `forms`, so the same body run on the app connection under a live
// tenant GUC does real work. What remains unprovable in-suite is only the privileged connection's
// cross-tenant reach, which is why assertPrivileged() below exists and is asserted here.

it('never restates the section 2 rule in SQL', function (): void {
    // THE DRIFT-PREVENTION TEST, and the reason this backfill reads-computes-writes instead of being one
    // set-based UPDATE. The rule must live in exactly one place (OcrFieldEligibility + CapabilityFlags); if
    // the SQL ever classified a field type itself, a 32nd FieldType case would be classified by the
    // default-less match and SILENTLY NOT by the SQL. So: the statements may not mention any field type, nor
    // the repeat-group column.
    $sql = OcrCompatibilityBackfill::SELECT_SQL.OcrCompatibilityBackfill::FLIP_SQL;

    foreach (FieldType::cases() as $type) {
        expect($sql)->not->toContain(
            "'{$type->value}'",
            "the backfill SQL must not classify field types — found {$type->value}",
        );
    }

    expect($sql)->not->toContain('is_repeatable')
        ->and($sql)->not->toContain('jsonb_array_elements');
});

it('only ever clears a stored true, never writes one', function (): void {
    // Monotonicity, as the SQL sees it: the candidate filter is a stored `true` and the written value is the
    // literal `false`. There is no computed value in the statement at all.
    expect(OcrCompatibilityBackfill::FLIP_SQL)
        ->toContain("'{ocr_compatible}', 'false'::jsonb")
        ->toContain("capability_flags->>'ocr_compatible' = 'true'")
        ->and(OcrCompatibilityBackfill::FLIP_SQL)->not->toContain("'true'::jsonb")
        ->and(OcrCompatibilityBackfill::SELECT_SQL)->toContain("capability_flags->>'ocr_compatible' = 'true'");
});

it('patches only the ocr_compatible key and never invents one', function (): void {
    // jsonb_set on the single path is the only shape that provably preserves has_geofields/has_media AND any
    // key a future increment adds; `false` as the create_if_missing arg means it never adds a key either.
    expect(OcrCompatibilityBackfill::FLIP_SQL)
        ->toContain('jsonb_set(capability_flags,')
        ->toContain("'false'::jsonb, false)")
        ->and(OcrCompatibilityBackfill::FLIP_SQL)->not->toContain('SET capability_flags = ?')
        ->and(OcrCompatibilityBackfill::FLIP_SQL)->not->toContain('has_geofields')
        ->and(OcrCompatibilityBackfill::FLIP_SQL)->not->toContain('has_media');
});

it('does not bump updated_at, so a corrected flag never looks like a tenant edit', function (): void {
    expect(OcrCompatibilityBackfill::FLIP_SQL)->not->toContain('updated_at');
});

it('reads only published forms, and does not skip soft-deleted ones', function (): void {
    // INNER JOIN on current_published_version_id: a form with no published version has nothing to derive a
    // verdict from. No deleted_at filter: a form restored later must not resurrect a stale `true`.
    expect(OcrCompatibilityBackfill::SELECT_SQL)
        ->toContain('JOIN form_versions v ON v.id = f.current_published_version_id')
        ->and(OcrCompatibilityBackfill::SELECT_SQL)->not->toContain('LEFT JOIN')
        ->and(OcrCompatibilityBackfill::SELECT_SQL)->not->toContain('deleted_at');
});

it('walks the candidates by keyset on the primary key', function (): void {
    // A cursor walk rather than OFFSET: new rows sort after the cursor and are simply examined later
    // (harmlessly — they were written by the fixed code), and no row can be skipped.
    expect(OcrCompatibilityBackfill::SELECT_SQL)
        ->toContain('f.id > ?::uuid')
        ->toContain('ORDER BY f.id')
        ->toContain('LIMIT ?');
});

it('binds one value per placeholder in both statements', function (): void {
    // Catches a hand-miscounted bind list, and a stray `?` jsonb-containment operator sneaking back in (PDO
    // would parse it as a placeholder — the classic jsonb-in-Laravel footgun).
    expect(substr_count(OcrCompatibilityBackfill::SELECT_SQL, '?'))->toBe(2)
        ->and(substr_count(OcrCompatibilityBackfill::FLIP_SQL, '?'))->toBe(1);
});

it('passes its postcondition when nothing ineligible survives and blocks when one does', function (): void {
    // `0 > 0` is false, so a migrate:fresh against an empty database passes cleanly — the mirror of the
    // precedents' `0 < 0`. A single violation must abort the migration run and name the blast radius.
    OcrCompatibilityBackfill::assertNoIneligibleSurvivor(0);

    expect(fn () => OcrCompatibilityBackfill::assertNoIneligibleSurvivor(1, 'abc-123'))
        ->toThrow(RuntimeException::class, 'abc-123');

    expect(fn () => OcrCompatibilityBackfill::assertNoIneligibleSurvivor(2))
        ->toThrow(RuntimeException::class, 'H18');
});

it('rejects a privileged role that neither is superuser nor bypasses RLS', function (): void {
    // Closes the vacuous-green hole both precedents leave open: valid credentials on a NOBYPASSRLS role read
    // zero rows under FORCE RLS with no tenant GUC, flip nothing, and pass a postcondition that reads the
    // same zero rows. `select 1` cannot see that; pg_roles can.
    OcrCompatibilityBackfill::assertPrivilegedRole(1);

    expect(fn () => OcrCompatibilityBackfill::assertPrivilegedRole(0))
        ->toThrow(RuntimeException::class, 'BYPASSRLS')
        // null = current_user was not found in pg_roles at all, which is not a reason to proceed.
        ->and(fn () => OcrCompatibilityBackfill::assertPrivilegedRole(null))
        ->toThrow(RuntimeException::class, 'BYPASSRLS')
        ->and(fn () => OcrCompatibilityBackfill::assertPrivilegedRole(false))
        ->toThrow(RuntimeException::class, 'DB_PRIVILEGED_USERNAME');

    // The role, not the data — so the check is equally decisive on an empty database.
    expect(OcrCompatibilityBackfill::PRIVILEGE_SQL)
        ->toContain('rolbypassrls')
        ->toContain('rolname = current_user');
});

it('runs the migration on the privileged connection, never the app connection', function (): void {
    // The effect test drives the body on the APP connection on purpose; this guards against anyone reading
    // that as a licence for the migration to do the same. dirname(__DIR__, 2) because the Unit suite does not
    // boot the framework, so base_path() is unavailable (the GoldenVectorsTest precedent).
    $migration = file_get_contents(
        dirname(__DIR__, 2).'/database/migrations/2026_07_30_000001_backfill_ocr_compatible_capability_flag.php'
    );

    expect($migration)->toContain("DB::connection('pgsql_privileged')")
        ->toContain('OcrCompatibilityBackfill::assertPrivileged(')
        ->toContain('public $withinTransaction = false;');
});
