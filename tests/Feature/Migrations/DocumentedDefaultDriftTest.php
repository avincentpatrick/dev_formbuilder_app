<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The documented `Default` column vs. the database (M58).
|--------------------------------------------------------------------------
| `docs/data-dictionary.md` and `docs/multi-tenancy-rbac-design.md` are the canonical column-level
| schema reference, and the `Default` column of their tables is the one cell a reader consults to
| answer "must I supply this value?". M46 filed thirty rows answering it wrongly; the measurement
| that opened M58 found ninety-two, across two documents rather than one, and split across two
| claimed values rather than one.
|
| ⛔ WHY THIS IS A TEST AND NOT A LINT SCRIPT. `scripts/constraint-boundary-lint.php` exists because
| a drift failure names a constraint and not the file that wrote it, so a static pass adds the
| file:line a catalog cannot carry. That argument does not reach here — the defect IS a document, and
| the failures below already name the file, the table and the column. A static twin would have to
| infer defaults from `->default()` / `->useCurrent()` / `->nullable()` / raw `DB::statement`, which
| is inference offered against a question `information_schema` answers exactly.
|
| ⚠️ WHAT THIS DOES NOT CHECK, DELIBERATELY. Only cells naming a FUNCTION (`now()`, `uuidv7()`) are
| compared. A literal cell — `'{}'::jsonb`, `false`, `0` — is left alone: literals are where the
| false-positive surface lives, and a documented literal that disagrees with the database is a
| different and much noisier question. Filed in `docs/feature-backlog.md` rather than left here.
|
| ⚠️ AND IT CHECKS PRESENCE, NOT EQUALITY. `now()` is documented where PostgreSQL reports
| `CURRENT_TIMESTAMP`; both are the same default expressed two ways, and demanding string equality
| would fail on a synonym rather than on a defect.
|
| Raw `DB::select` against `information_schema`, never `Schema::` — what is being proven is what the
| database stores, and a Blueprint-level answer would describe a migration's intent instead.
|
| Helper names are prefixed `documentedDefault*` deliberately: Pest loads every file in a directory
| into one process, so a same-named file-scope helper is a fatal redeclaration.
*/

/**
 * A plausible floor for the discovery pass.
 *
 * Measured on the tree this test was written against: 2 documents, 39 column tables, 569 column
 * rows. These sit well below that so ordinary editing does not trip them, and well above zero so a
 * renamed heading, a reformatted table header or a broken scan root fails LOUDLY instead of
 * reporting green over nothing — the failure mode `scripts/constraint-boundary-lint.php` records as
 * a reproducible event on this host, not a hypothetical one.
 */
const DOCUMENTED_DEFAULT_MIN_DOCUMENTS = 2;

const DOCUMENTED_DEFAULT_MIN_TABLES = 30;

const DOCUMENTED_DEFAULT_MIN_ROWS = 450;

/** The exact header line every column table in the corpus uses. */
const DOCUMENTED_DEFAULT_HEADER = '| Column | Type | Nullable | Default | PII? | Description |';

/**
 * Every markdown document under `docs/` that carries at least one column table.
 *
 * Discovered by structure rather than hard-coded, so a third document that adopts the same table
 * shape is covered on the day it is written and not on the day somebody remembers this list.
 *
 * @return list<string>
 */
function documentedDefaultDocuments(): array
{
    $found = [];

    foreach (glob(base_path('docs').'/*.md') ?: [] as $path) {
        if (str_contains((string) file_get_contents($path), DOCUMENTED_DEFAULT_HEADER)) {
            $found[] = $path;
        }
    }

    sort($found);

    return $found;
}

/**
 * Parse one document into the column rows whose Default cell names a function.
 *
 * The table name comes from the nearest preceding `##`/`###` heading that names one — the FIRST
 * backticked identifier in it, because RBAC §8's heading names two tables and its own §8.1/§8.2
 * subheadings then override it for the tables that actually follow.
 *
 * @return array{tables: int, rows: int, cells: list<array{doc: string, table: string, column: string, default: string}>}
 */
function documentedDefaultParse(string $path): array
{
    $doc = basename($path);
    $table = null;
    $inTable = false;
    $tables = 0;
    $rows = 0;
    $cells = [];

    // Split on "\n" only. PCRE's \R without /u matches the byte 0x85 INSIDE UTF-8 characters, and
    // these documents are full of them (M42).
    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        $line = rtrim($line, "\r");

        if (str_starts_with($line, '## ') || str_starts_with($line, '### ')) {
            $inTable = false;

            if (preg_match('/`([a-z][a-z0-9_]*)`/', $line, $m) === 1) {
                $table = $m[1];
            }

            continue;
        }

        if ($line === DOCUMENTED_DEFAULT_HEADER) {
            $inTable = true;
            $tables++;

            continue;
        }

        if (! $inTable) {
            continue;
        }

        if (str_starts_with($line, '|---')) {
            continue;
        }

        if (! str_starts_with($line, '| ')) {
            $inTable = false;

            continue;
        }

        // Limit 7: the Description cell is prose and may itself contain a pipe.
        $fields = explode('|', $line, 7);

        if (count($fields) < 6) {
            continue;
        }

        $rows++;

        $columnCell = $fields[1];
        $defaultCell = trim(str_replace('`', '', $fields[4]));

        if (! str_contains($defaultCell, '()')) {
            continue;
        }

        // One cell may name several columns, e.g. created_at / updated_at.
        foreach (explode('/', $columnCell) as $piece) {
            $column = trim(str_replace('`', '', $piece));

            if (preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
                continue;
            }

            $cells[] = [
                'doc' => $doc,
                'table' => $table ?? '(no heading)',
                'column' => $column,
                'default' => $defaultCell,
            ];
        }
    }

    return ['tables' => $tables, 'rows' => $rows, 'cells' => $cells];
}

/**
 * Every function-shaped Default cell in the corpus, with the floors already asserted.
 *
 * @return list<array{doc: string, table: string, column: string, default: string}>
 */
function documentedDefaultCells(): array
{
    $documents = documentedDefaultDocuments();

    expect(count($documents))->toBeGreaterThanOrEqual(
        DOCUMENTED_DEFAULT_MIN_DOCUMENTS,
        'Discovery floor: fewer documents carry a column table than this corpus is known to have. '.
        'A renamed heading or a reformatted table header makes this gate blind, so it fails instead.'
    );

    $tables = 0;
    $rows = 0;
    $cells = [];

    foreach ($documents as $path) {
        $parsed = documentedDefaultParse($path);
        $tables += $parsed['tables'];
        $rows += $parsed['rows'];
        $cells = array_merge($cells, $parsed['cells']);
    }

    expect($tables)->toBeGreaterThanOrEqual(
        DOCUMENTED_DEFAULT_MIN_TABLES,
        'Discovery floor: column tables found ('.$tables.').'
    );

    expect($rows)->toBeGreaterThanOrEqual(
        DOCUMENTED_DEFAULT_MIN_ROWS,
        'Discovery floor: column rows scanned ('.$rows.').'
    );

    return $cells;
}

/**
 * Every column in the live schema that carries a database-side default.
 *
 * @return array<string, string> "table.column" => the default expression
 */
function documentedDefaultActual(): array
{
    /** @var list<object{k: string, d: string}> $found */
    $found = DB::select(
        "select table_name || '.' || column_name as k, column_default as d
         from information_schema.columns
         where table_schema = 'public' and column_default is not null"
    );

    $map = [];

    foreach ($found as $row) {
        $map[$row->k] = $row->d;
    }

    return $map;
}

/**
 * Every column in the live schema, so a documented column that does not exist is told apart from
 * one that merely has no default.
 *
 * @return array<string, true>
 */
function documentedDefaultKnownColumns(): array
{
    /** @var list<object{k: string}> $found */
    $found = DB::select(
        "select table_name || '.' || column_name as k
         from information_schema.columns where table_schema = 'public'"
    );

    return array_fill_keys(array_map(static fn (object $r): string => $r->k, $found), true);
}

it('does not document a database-side default the database does not have', function (): void {
    $actual = documentedDefaultActual();
    $known = documentedDefaultKnownColumns();

    $phantom = [];
    $unknown = [];

    foreach (documentedDefaultCells() as $cell) {
        $key = $cell['table'].'.'.$cell['column'];

        if (! isset($known[$key])) {
            $unknown[] = $cell['doc'].' — '.$key.' documents '.$cell['default'].', and no such column exists';

            continue;
        }

        if (! isset($actual[$key])) {
            $phantom[] = $cell['doc'].' — '.$key.' documents '.$cell['default'].', and the column has no default';
        }
    }

    expect($unknown)->toBe(
        [],
        "A documented database-side default names a column that does not exist:\n".implode("\n", $unknown)
    );

    expect($phantom)->toBe(
        [],
        "The Default column claims a database-side default the database does not have — the value is ".
        "supplied by the application, so the document must say so:\n".implode("\n", $phantom)
    );
});

it('still recognises the two defaults that are real', function (): void {
    // The discriminating control, kept as an assertion rather than a comment: both of these ARE
    // database-side, put there by ->useCurrent(). A repair that swept now() out of the corpus
    // wholesale, or a parser that stopped finding function-shaped cells at all, turns this red while
    // the sweep above stays green.
    $documented = array_map(
        static fn (array $c): string => $c['table'].'.'.$c['column'],
        documentedDefaultCells()
    );

    expect($documented)->toContain('audits.created_at');
    expect($documented)->toContain('feedback_reports.submitted_at');

    $actual = documentedDefaultActual();

    expect(array_key_exists('audits.created_at', $actual))->toBeTrue();
    expect(array_key_exists('feedback_reports.submitted_at', $actual))->toBeTrue();
});
