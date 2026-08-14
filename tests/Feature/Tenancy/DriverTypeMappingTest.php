<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What pdo_pgsql actually hands back (Phase 4, P2b — ADR-0018 §D5).
|--------------------------------------------------------------------------
| ⚠️ THIS FILE EXISTS BECAUSE THE REPOSITORY WAS WRONG ABOUT ITS OWN DRIVER. Four separate places assert,
| in comments, that `pdo_pgsql` returns booleans as the strings 't' / 'f' and that `(bool) 'f'` is
| therefore TRUE. The second half is true; the first half is not, on this stack. Measured on PHP 8.4.24
| with Laravel's default `ATTR_EMULATE_PREPARES = false`, a boolean column comes back as a PHP bool,
| because a native prepared statement carries the column's type OID and the driver converts.
|
| It was found by MUTATION rather than by reading: deleting ExtractRowEncoder's boolean branch reddened
| the two unit cases that feed it strings and left the feature assertion — which reads a real row out of
| a real table — perfectly green. A coercion nothing can redden is either dead code or an unpinned
| assumption, and this file is the difference.
|
| ⚠️ NOTHING HERE IS A REQUEST FOR THIS BEHAVIOUR. It is a record of it. The type mapping is a property of
| a CONNECTION OPTION, not of PostgreSQL: an `options` override in config/database.php that re-enables
| emulated prepares flips every row in this file back to strings, at which point ExtractRowEncoder's
| boolean branch stops being belt-and-braces and becomes the only thing standing between an artefact and
| every one of its flags arriving inverted. If this file reddens, that is what has happened — read
| ExtractRowEncoder before changing anything here.
|
| No RefreshDatabase: every value is a literal cast, so nothing is read from a table and no migration or
| policy is involved.
*/

it('returns booleans as PHP booleans, not as the strings this repo\'s comments claim', function (): void {
    $row = DB::selectOne('select true as t, false as f, null::boolean as n');

    expect($row->t)->toBeBool()->toBeTrue();
    expect($row->f)->toBeBool()->toBeFalse();
    expect($row->n)->toBeNull();
});

it('returns every integer width as a PHP int', function (): void {
    // Relevant to the extract because PHP ints are 64-bit here, so `bigint` survives the round trip into
    // JSON without precision loss. A 32-bit host would need this reconsidered, which is the other reason
    // to have it written down.
    $row = DB::selectOne('select 3::smallint as s, 42::integer as i, 9007199254740993::bigint as b');

    expect($row->s)->toBeInt();
    expect($row->i)->toBeInt();
    expect($row->b)->toBeInt()->toBe(9007199254740993);
});

it('returns numeric as a string, which is what keeps the extract lossless', function (): void {
    // ⚠️ THE DRIVER DOES THIS, NOT THE ENCODER, and the distinction matters: ExtractRowEncoder is often
    // described as "deliberately leaving numerics alone", which reads like a choice it makes. It is not
    // making one — it is declining to undo the driver's. `1.50` and `1.5` are the same JSON number and
    // different `numeric` values, so a cast here would quietly discard a scale the column was storing.
    $row = DB::selectOne('select 1.50::numeric as n');

    expect($row->n)->toBeString()->toBe('1.50');
});

it('returns json and jsonb as encoded text, which is why the decode is load-bearing', function (): void {
    // The one coercion in ExtractRowEncoder that is live on this stack today. Without it every answers
    // payload and schema snapshot in the artefact is a JSON document nested inside a JSON string.
    $row = DB::selectOne('select \'{"a":1}\'::jsonb as j, \'{"a":1}\'::json as k');

    expect($row->j)->toBeString();
    expect($row->k)->toBeString();
});

it('returns timestamps as text rather than as a date object', function (): void {
    $row = DB::selectOne("select '2026-08-14 03:00:00+00'::timestamptz as ts");

    expect($row->ts)->toBeString();
});
