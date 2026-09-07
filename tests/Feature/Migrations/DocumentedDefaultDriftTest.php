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
| ✅ M83 ADDED THE LITERAL ARM THE PARAGRAPH HERE USED TO DEFER, AND THE DEFERRAL'S REASONING WAS
| MEASURABLY WRONG. It said literals "are where the false-positive surface lives" and needed "a
| normalizer per type". Measured over every comparable pair: 41 of 86 match with NO normalization at
| all, THREE type-agnostic string rules take it to 85 of 87, and the fourth candidate — lowercasing —
| fixes zero. The noise was three cells, not a class.
|
| ⛔ THE TWO ARMS ASK DIFFERENT QUESTIONS AND MUST NOT BE MERGED. The function arm checks PRESENCE:
| `now()` is documented where PostgreSQL reports `CURRENT_TIMESTAMP`, and demanding string equality
| there would fail on a synonym rather than on a defect. The literal arm checks EQUALITY, because a
| documented `'trial'` against a live `'active'` is not a synonym — it is the drift.
|
| ⛔ AND A LITERAL CELL IS A DATABASE-SIDE DEFAULT, WHICH THE DICTIONARY'S OWN PREAMBLE DENIED UNTIL
| THIS INCREMENT. It read "Everywhere else the value is supplied by the application"; 102 columns
| carry a live default and the documented literals record them, 41 of 86 byte-identically. A document
| that says one thing and does another is the reason this arm can exist at all.
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

/**
 * A floor for the LITERAL arm specifically (M83).
 *
 * ⛔ The function arm has no floor of its own and reaches exactly TWO cells of 570 rows — only the
 * named controls in the second test stop it reporting green over nothing. The literal arm is 45×
 * larger, so it gets the floor the older arm never had. Measured 89 on the tree this was written
 * against; 86 after this increment's own `tenants` repair removes three phantom rows. 70 sits far
 * enough below to survive ordinary editing and far enough above zero that a broken sentinel
 * vocabulary — which would silently reclassify every literal cell as prose — fails LOUDLY.
 */
const DOCUMENTED_DEFAULT_MIN_LITERAL_CELLS = 70;

/**
 * The closed vocabulary of Default cells that record a NON-database origin.
 *
 * ⛔ CLOSED, AND IT FAILS ON ANYTHING IT DOES NOT KNOW. The pattern is
 * `tests/Feature/Docs/DocumentedSettingKeyDriftTest.php`'s, and its reason carries over exactly: a
 * default coerced to something plausible is worse than one that fails. `the emailAddress urn` is the
 * proof — it is prose sitting in a Default cell, and the column it describes really does carry a
 * database-side default.
 *
 * ⚠️ `application-generated (HasUuidv7)` contains parentheses but not the empty pair `()`, so the
 * function arm's `str_contains($cell, '()')` correctly does not claim it. That is load-bearing, not
 * a coincidence: 32 cells depend on it.
 */
const DOCUMENTED_DEFAULT_SENTINELS = [
    'NULL',
    '—',
    'set by Eloquent',
    'application-generated (HasUuidv7)',
    'auto-increment',
    'identity',
    '*derived*',
    'the emailAddress urn',
];

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
 * Parse one document into EVERY column row, with its Default cell verbatim.
 *
 * ⚠️ M83 widened this from "rows whose Default cell names a function" to all of them, because the
 * literal arm below needs the same rows the function arm does and a second parser would be a second
 * copy of this document's conventions. The function-shaped filter now lives in
 * `documentedDefaultCells()`, so that arm's behaviour is byte-for-byte what it was.
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
 * EVERY Default cell in the corpus, with the discovery floors already asserted.
 *
 * @return list<array{doc: string, table: string, column: string, default: string}>
 */
function documentedDefaultAllCells(): array
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
 * The FUNCTION-shaped cells — this gate's original arm, filtered here instead of in the parser.
 *
 * @return list<array{doc: string, table: string, column: string, default: string}>
 */
function documentedDefaultCells(): array
{
    return array_values(array_filter(
        documentedDefaultAllCells(),
        static fn (array $cell): bool => str_contains($cell['default'], '()')
    ));
}

/**
 * Is this Default cell a VALUE, as opposed to prose describing where a value comes from?
 *
 * ⛔ THIS PREDICATE IS THE WHOLE DESIGN, NOT A CONVENIENCE. Widen the literal collector to "every
 * non-empty cell that is not a function" and the phantom arm below fires 328 times — 220 `NULL`, 74
 * `set by Eloquent`, 32 `application-generated (HasUuidv7)`, 2 `*derived*`. None of those is a value,
 * and none of them is a defect. It is the difference between a four-failure gate and a 341-failure
 * one, which is the difference between a gate that can merge and `M40`'s that never could.
 *
 * Deliberately NOT accepting a bare `{`/`[`: every JSON default in this corpus is written quoted, so
 * an unquoted one would be a new convention, and a rule that governs no line cannot be reddened.
 * An unrecognised shape falls through to the sentinel check and fails loudly, which is the point.
 */
function documentedDefaultIsValueShaped(string $cell): bool
{
    return $cell === 'true'
        || $cell === 'false'
        || preg_match('/^-?\d+(?:\.\d+)?$/', $cell) === 1
        || (strlen($cell) >= 2 && str_starts_with($cell, "'") && str_ends_with($cell, "'"));
}

/**
 * Reduce a documented cell and a live `information_schema` default to one comparable form.
 *
 * ⛔ THE ORDER OF THE FIRST TWO RULES IS LOAD-BEARING AND NOTHING IN THE BACKLOG SAID SO.
 * `scope_nodes.depth` is `'0'::smallint` and `usage_counters.value` is `'0'::bigint`, while eight
 * other integer columns store a bare `0` — and all ten are documented identically as `0`. Unquote
 * before stripping the cast and `'0'::bigint` becomes `0'::bigint`; strip the cast first and it
 * becomes a clean `'0'`, then `0`. Either rule alone yields two false failures over one physical
 * value.
 *
 * ⚠️ The fourth candidate rule — lowercasing — is DEAD. It was measured over every comparable pair
 * and fixes zero of them, so it is not here. See `docs/feature-backlog.md`'s M78 ablation.
 */
function documentedDefaultNormalize(string $value): string
{
    $value = trim($value);

    // 1. The trailing Postgres cast: `::character varying`, `::jsonb`, `::bigint`, `::varchar(20)`.
    $value = (string) preg_replace('/::"?[a-z][a-z0-9_ ]*"?(?:\(\d+(?:,\d+)?\))?$/i', '', $value);

    // 2. The surrounding single quotes Postgres puts on every non-numeric literal.
    if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
        $value = str_replace("''", "'", substr($value, 1, -1));
    }

    // 3. JSON whitespace. `'["monthly","yearly"]'` and `'["monthly", "yearly"]'` are one value.
    //    Decoded as objects rather than associative arrays deliberately: `json_decode('{}', true)`
    //    is `[]`, which would re-encode to `[]` and make an empty object and an empty array compare
    //    equal — a real drift this gate would then miss.
    if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
        $decoded = json_decode($value, false);

        if (json_last_error() === JSON_ERROR_NONE) {
            $value = (string) json_encode($decoded);
        }
    }

    return $value;
}

/**
 * Every VALUE-shaped Default cell, with the sentinel vocabulary and the literal floor asserted.
 *
 * @return list<array{doc: string, table: string, column: string, default: string}>
 */
function documentedDefaultLiteralCells(): array
{
    $literal = [];
    $unrecognised = [];

    foreach (documentedDefaultAllCells() as $cell) {
        if (str_contains($cell['default'], '()')) {
            continue;
        }

        if (in_array($cell['default'], DOCUMENTED_DEFAULT_SENTINELS, true)) {
            continue;
        }

        if (documentedDefaultIsValueShaped($cell['default'])) {
            $literal[] = $cell;

            continue;
        }

        $unrecognised[] = $cell['doc'].' — '.$cell['table'].'.'.$cell['column'].
            ' reads '.$cell['default'];
    }

    expect($unrecognised)->toBe(
        [],
        "A Default cell is neither a value, a function, nor a known sentinel. Add it to\n".
        "DOCUMENTED_DEFAULT_SENTINELS if it describes where the value comes from, or write it as a\n".
        "value if it is one — do not leave it for this gate to guess:\n".implode("\n", $unrecognised)
    );

    expect(count($literal))->toBeGreaterThanOrEqual(
        DOCUMENTED_DEFAULT_MIN_LITERAL_CELLS,
        'Discovery floor: value-shaped Default cells found ('.count($literal).'). A drop here means '.
        'the sentinel vocabulary or the value predicate has swallowed the corpus, which would make '.
        'the literal arm below green over nothing.'
    );

    return $literal;
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

/**
 * The FUNCTION arm's classification, collected once so each finding can be asserted separately.
 *
 * ⛔ WHY THIS IS A COLLECTOR AND NOT A LOOP INSIDE ONE `it()` (M84). Both arms used to collect
 * every bucket in one pass and then assert them in sequence, and Pest aborts a test at its first
 * failed expectation — so a run reported only the earliest non-empty bucket and a reader could not
 * see the whole drift set. M83 met that directly: its first run showed three `$unknown` cells and
 * said nothing at all about `tenants.status`, which was sitting in `$drift` behind them and only
 * became visible once the first bucket was cleared.
 *
 * ⚠️ AND THE REASON THE OLD SHAPE LOOKED DEFENSIBLE IS FALSE. The row that filed this argued the
 * sequence was deliberate — that a column which does not exist "poisons" any comparison of its
 * value, so unknowns genuinely should be read first. That is true of the CLASSIFICATION and it is
 * already enforced below by the early `continue`s, which make the buckets disjoint: a cell in
 * `unknown` can never reach the normalizer and can never enter `drift`. Nothing was being protected
 * by asserting them in order. The sequence bought a reading order and nothing else.
 *
 * ⛔ WHAT THIS DOES NOT FIX, STATED SO THE NEXT READER DOES NOT ASSUME IT DID. The executed
 * assertion chain is EIGHT long, not three, and the five that fire FIRST are floors inside the
 * collectors — the three discovery floors in `documentedDefaultAllCells()` and the closed-vocabulary
 * and literal floors in `documentedDefaultLiteralCells()`. Splitting the classification cannot reach
 * them: when a floor fails, the later arms' data has not been computed yet, and every test in this
 * file routes through the same collectors, so one vocabulary failure still blinds the whole file.
 * That is a real limit of this repair and is filed rather than hidden.
 *
 * @return array{unknown: list<string>, phantom: list<string>}
 */
function documentedDefaultFunctionFindings(): array
{
    $actual = documentedDefaultActual();
    $known = documentedDefaultKnownColumns();

    $unknown = [];
    $phantom = [];

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

    return ['unknown' => $unknown, 'phantom' => $phantom];
}

/**
 * The LITERAL arm's classification, collected once for the same reason as the function arm above.
 *
 * The three buckets are disjoint by construction — each branch below `continue`s — so the order they
 * are asserted in carries no meaning, which is exactly why they are asserted independently.
 *
 * @return array{unknown: list<string>, phantom: list<string>, drift: list<string>}
 */
function documentedDefaultLiteralFindings(): array
{
    $actual = documentedDefaultActual();
    $known = documentedDefaultKnownColumns();

    $unknown = [];
    $phantom = [];
    $drift = [];

    foreach (documentedDefaultLiteralCells() as $cell) {
        $key = $cell['table'].'.'.$cell['column'];

        if (! isset($known[$key])) {
            $unknown[] = $cell['doc'].' — '.$key.' documents '.$cell['default'].
                ', and no such column exists';

            continue;
        }

        if (! isset($actual[$key])) {
            $phantom[] = $cell['doc'].' — '.$key.' documents the value '.$cell['default'].
                ', and the column has no database-side default';

            continue;
        }

        $documented = documentedDefaultNormalize($cell['default']);
        $live = documentedDefaultNormalize($actual[$key]);

        if ($documented !== $live) {
            $drift[] = $cell['doc'].' — '.$key.' documents '.$cell['default'].
                ', the database has '.$actual[$key];
        }
    }

    return ['unknown' => $unknown, 'phantom' => $phantom, 'drift' => $drift];
}

it('does not document a database-side default on a column that does not exist', function (): void {
    $unknown = documentedDefaultFunctionFindings()['unknown'];

    expect($unknown)->toBe(
        [],
        "A documented database-side default names a column that does not exist:\n".implode("\n", $unknown)
    );
});

it('does not document a database-side default the database does not have', function (): void {
    $phantom = documentedDefaultFunctionFindings()['phantom'];

    expect($phantom)->toBe(
        [],
        'The Default column claims a database-side default the database does not have — the value is '.
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

it('does not document a literal default on a column that does not exist', function (): void {
    $unknown = documentedDefaultLiteralFindings()['unknown'];

    expect($unknown)->toBe(
        [],
        "A documented literal default names a column that does not exist:\n".implode("\n", $unknown)
    );
});

it('does not document a literal value the database does not default to', function (): void {
    $phantom = documentedDefaultLiteralFindings()['phantom'];

    expect($phantom)->toBe(
        [],
        "The Default column states a value the database does not default to at all — either the\n".
        "database supplies no default and the cell should say where the value comes from, or the\n".
        "default was dropped from a migration:\n".implode("\n", $phantom)
    );
});

it('does not document a literal default that disagrees with the database', function (): void {
    $drift = documentedDefaultLiteralFindings()['drift'];

    expect($drift)->toBe(
        [],
        "A documented literal default disagrees with the live schema after normalization. These are\n".
        "compared with the Postgres cast stripped, quotes removed and JSON whitespace canonicalized,\n".
        "so what remains is a real difference in value:\n".implode("\n", $drift)
    );
});

it('attributes every column table to a table that exists', function (): void {
    // The parser takes a table name from the FIRST backticked identifier in the nearest preceding
    // heading, and a heading carrying none does not reset it. Neither failure is visible today —
    // all attributions resolve — but both are silent by construction, and M82 measured in this same
    // repository that positional attribution can be meaningless while looking authoritative. This
    // converts the next one from a confident wrong answer into a red build.
    /** @var list<object{t: string}> $found */
    $found = DB::select(
        "select table_name as t from information_schema.tables
         where table_schema = 'public' and table_type = 'BASE TABLE'"
    );

    $live = array_fill_keys(array_map(static fn (object $r): string => $r->t, $found), true);

    $attributed = array_values(array_unique(array_map(
        static fn (array $cell): string => $cell['table'],
        documentedDefaultAllCells()
    )));

    sort($attributed);

    $missing = array_values(array_filter(
        $attributed,
        static fn (string $table): bool => ! isset($live[$table])
    ));

    expect($missing)->toBe(
        [],
        "A column table is attributed to a name that is not a live table. Either the heading above it\n".
        "names no table and the rows inherited the previous section's, or the heading names two and\n".
        "only the first was taken:\n".implode("\n", $missing)
    );
});
