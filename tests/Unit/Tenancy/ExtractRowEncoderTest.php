<?php

declare(strict_types=1);

use App\Exceptions\Tenancy\TenantExtractException;
use App\Services\Tenancy\Extraction\ExtractRowEncoder;
use App\Support\Tenancy\TenantExtractColumns;

/*
|--------------------------------------------------------------------------
| Row encoding and the rename guard (Phase 4, P2b — ADR-0018 §D5).
|--------------------------------------------------------------------------
| Pure, no database. Both subjects here are the kind of defect that produces a WELL-FORMED artefact:
| nothing throws, the JSON parses, the row counts are right, and the values are wrong.
*/

it('reads PostgreSQL\'s boolean rendering as a boolean and not as a truthy string', function (string $raw, bool $expected): void {
    // ⚠️ THE INVERSION THIS EXISTS FOR, AND THE CONFIGURATION IT DEPENDS ON. Under EMULATED prepared
    // statements `pdo_pgsql` returns booleans as the strings 't' / 'f', and `(bool) 'f'` is TRUE — so an
    // encoder that passed them through would write `"f"`, which a destination trusting JSON types reads as
    // truthy, and every `false` flag in the extract would arrive inverted with nothing failing anywhere.
    //
    // On THIS stack the driver already returns real bools (DriverTypeMappingTest measures it), so these
    // two cases are the only thing that pins the branch: the end-to-end feature assertion stays green
    // without it. That is not a reason to delete the branch — it is a reason the branch needs a unit test,
    // because the string form is one `options` override away and would be silent when it arrived.
    $encoded = (new ExtractRowEncoder(['flag' => 'boolean']))->encode(['flag' => $raw]);

    expect($encoded['flag'])->toBe($expected);
})->with([
    ['t', true],
    ['f', false],
]);

it('decodes json and jsonb rather than nesting an encoded document inside a string', function (string $type): void {
    $encoded = (new ExtractRowEncoder(['answers' => $type]))->encode(['answers' => '{"a":1,"b":[2,3]}']);

    expect($encoded['answers'])->toBe(['a' => 1, 'b' => [2, 3]]);
})->with(['json', 'jsonb']);

it('leaves numerics as PostgreSQL rendered them', function (): void {
    // Deliberate, and the reason is a loss that cannot be undone downstream: PHP floats cannot hold
    // arbitrary precision, and a JSON number cannot express the difference between `1.50` and `1.5` — which
    // for a `numeric` column is a difference the database was storing on purpose. A reader who wants a
    // number can parse one; a reader handed a rounded float has no way back.
    $encoded = (new ExtractRowEncoder(['amount' => 'numeric', 'n' => 'bigint']))
        ->encode(['amount' => '1.50', 'n' => '9007199254740993']);

    expect($encoded['amount'])->toBe('1.50');
    expect($encoded['n'])->toBe('9007199254740993');
});

it('keeps nulls null whatever the column type claims', function (): void {
    $encoded = (new ExtractRowEncoder(['answers' => 'jsonb', 'flag' => 'boolean']))
        ->encode(['answers' => null, 'flag' => null]);

    expect($encoded)->toBe(['answers' => null, 'flag' => null]);
});

it('raises on malformed json instead of writing a silent null', function (): void {
    // json_decode() returns null on failure, so without JSON_THROW_ON_ERROR a corrupt jsonb column would
    // be written to the artefact as `null` — indistinguishable from a column that was genuinely empty.
    expect(fn () => (new ExtractRowEncoder(['answers' => 'jsonb']))->encode(['answers' => '{not json']))
        ->toThrow(JsonException::class);
});

it('refuses to extract a table whose withheld column has been renamed away', function (): void {
    // ⚠️ THE ONLY RUNTIME HALF OF THE COLUMN POLICY, and the failure it closes is silent in both
    // directions. Rename `users.password` to `users.password_hash` and the WITHHELD entry matches no
    // column: it filters nothing, the new column enters the verbatim select list, and every assertion of
    // the form "password is withheld" keeps passing because the entry is still sitting there. The ABSENCE
    // is the defect, which is why the guard asserts presence rather than exclusion.
    expect(fn () => TenantExtractColumns::assertWithheldColumnsExist('users', ['id', 'name', 'password_hash']))
        ->toThrow(TenantExtractException::class, 'SILENTLY STOPPED HAPPENING');
});

it('accepts a table whose withheld columns are all present', function (): void {
    $columns = [...array_keys(TenantExtractColumns::withheldFor('users')), 'id', 'name', 'email'];

    expect(fn () => TenantExtractColumns::assertWithheldColumnsExist('users', $columns))
        ->not->toThrow(TenantExtractException::class);
});

it('drops withheld columns from the verbatim select list and leaves the rest in order', function (): void {
    $verbatim = TenantExtractColumns::verbatimFor('users', ['id', 'name', 'password', 'email', 'is_super_admin']);

    expect($verbatim)->toBe(['id', 'name', 'email']);
});

it('has no withheld entry for a table it does not know', function (): void {
    expect(TenantExtractColumns::withheldFor('a_table_that_does_not_exist'))->toBe([]);
    expect(TenantExtractColumns::verbatimFor('a_table_that_does_not_exist', ['x']))->toBe(['x']);
});
