<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The enum catalog's `DB CHECK` column vs. `pg_constraint` (M87).
|--------------------------------------------------------------------------
| `docs/data-dictionary.md`'s enum catalog used to open with a universal: "all values in
| `snake_case`, matching each enum's Postgres `CHECK` constraint". Seventeen of its twenty-eight
| rows have no `CHECK` at all, so that sentence told a reader that seventeen vocabularies are
| database-enforced when they are enforced only by the PHP cast. M87 replaced the universal with a
| per-row `DB CHECK` column, and this file is what keeps that column true.
|
| ⛔ THE INSTRUMENT IS THE WHOLE POINT, AND THE ROW THIS CLOSES EXISTS BECAUSE THE FIRST ATTEMPT
| USED THE WRONG ONE. A grep over `database/migrations/` concluded "3 of 16". Measured live: 44
| CHECKs, 28 catalog rows, 11 backed. The miss was `form_versions_status_chk`, created by
| first-party code in `app/Support/Migrations/PublishedVersionGuard.php` — OUTSIDE
| `database/migrations/`. ⚠️ Not by its `_chk` suffix, which is the explanation the row gave and is
| wrong: seven `_chk`-suffixed constraints live inside that directory and a grep finds all seven.
| Location is the discriminator, which is exactly why this reads `pg_constraint` and not a file.
|
| ⛔ A COLUMN-MEMBERSHIP PREDICATE IS NOT ENOUGH, AND IT IS OFF BY ONE IN THE DIRECTION THAT MATTERS.
| `form_field_validations_rule_xor_chk` references `rule_type` and is a NULLABILITY XOR, not a value
| domain — so "does any CHECK mention this column" reports twelve backed rows where eleven are. A
| gate written from the closed row's prose alone would have encoded the wrong number on its first
| run. `checkDriftIsValueDomain()` below is that distinction, and it is the reason this file is
| longer than the question looks.
|
| ⚠️ VALUES ARE COMPARED TOO, NOT ONLY THE CONSTRAINT NAMES. Naming the constraint proves the column
| is guarded; it does not prove the document says what the guard says. Two rows were wrong on that
| when this was written — `WebhookEventType` listed two cases the database REJECTS and omitted four
| it accepts, and `UsageMetric` was short by one — and neither is visible to a name-only check.
|
| ⚠️ SCOPE, STATED SO IT IS NOT MISREAD AS A CENSUS. This file asserts over the columns the catalog
| NAMES. Eleven further value-domain CHECKs guard columns the catalog has no row for at all — the
| connector, SSO and resource-grant vocabularies — and that gap is filed as its own row rather than
| closed here, because adding those rows is not line-neutral and this document sits at the citation
| ledger's ceiling.
*/

/** The catalog's own shape: a row is `| Enum | Backing column(s) | DB CHECK | Values |`. */
const CHECK_DRIFT_DOCUMENT = 'docs/data-dictionary.md';
const CHECK_DRIFT_HEADER = '| Enum | Backing column(s) | DB CHECK | Values |';

/**
 * The catalog rows, parsed.
 *
 * ⚠️ `explode("\n")` AND NEVER `preg_split` ON A NEWLINE CLASS. Without the `u` modifier that class
 * matches a byte occurring inside common UTF-8 emoji, and this document is full of them — it would
 * silently shift every line after the first one. The sibling `DocumentedSettingKeyDriftTest`
 * records the same trap for the same reason.
 *
 * @return list<array{enum: string, columns: list<string>, constraints: list<string>, values: list<string>|null}>
 */
function checkDriftCatalogRows(): array
{
    $path = base_path(CHECK_DRIFT_DOCUMENT);
    $lines = explode("\n", (string) file_get_contents($path));

    $start = null;
    foreach ($lines as $i => $line) {
        if (trim($line) === CHECK_DRIFT_HEADER) {
            $start = $i + 2; // skip the header and its separator

            break;
        }
    }

    if ($start === null) {
        return [];
    }

    $rows = [];
    for ($i = $start; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (! str_starts_with($line, '|')) {
            break;
        }

        $cells = array_map('trim', array_slice(explode('|', $line), 1, 4));
        if (count($cells) < 4) {
            continue;
        }

        [$enum, $columns, $check, $values] = $cells;

        // The H17 continuation row carries an em dash in the enum cell and documents no vocabulary.
        if ($enum === '' || $enum === '—') {
            continue;
        }

        $rows[] = [
            'enum' => trim($enum, '`'),
            'columns' => checkDriftBacktickedRun($columns, true),
            'constraints' => str_starts_with($check, '—') ? [] : checkDriftBacktickedRun($check, true),
            'values' => str_starts_with($values, '`') ? checkDriftBacktickedRun($values, false) : null,
        ];
    }

    return $rows;
}

/**
 * The backticked tokens of a cell.
 *
 * ⚠️ `$anywhere = false` READS ONLY THE VALUE LIST, AND THE TWO CUTS ARE BOTH LOAD-BEARING. Every
 * Values cell is written as `<the cases> <commentary>`, where the commentary is separated either by
 * a spaced em dash or by the end of the list's own sentence — and the commentary is full of further
 * backticked tokens (a constraint name, an `SQLSTATE`, a `(PointRule, threshold)` pair). Harvesting
 * the whole cell reads those as enum cases and fails an honest row.
 *
 * ⛔ AND A LEADING-RUN PARSER IS NOT ENOUGH EITHER; IT WAS THE FIRST ATTEMPT AND IT WAS WRONG.
 * `NotificationType` annotates cases mid-list — "`webhook_failed` (PRD Feature #13), then
 * `impersonation_started` (I11b)" — so a run that stops at the first non-comma stopped after seven
 * of ten and reported a correct row as drifted. A gate that cries wolf on a correct document is
 * worse than none, because the next author fixes the document.
 *
 * @return list<string>
 */
function checkDriftBacktickedRun(string $cell, bool $anywhere): array
{
    if (! $anywhere) {
        foreach ([' — ', '. '] as $terminator) {
            $at = mb_strpos($cell, $terminator);
            if ($at !== false) {
                $cell = mb_substr($cell, 0, $at);
            }
        }
    }

    preg_match_all('/`([^`]+)`/', $cell, $m);

    return array_values(array_unique($m[1]));
}

/**
 * Every CHECK constraint in the public schema, with its definition and the columns it names.
 *
 * @return list<array{table: string, name: string, definition: string, columns: list<string>}>
 */
function checkDriftLiveChecks(): array
{
    $rows = DB::select(<<<'SQL'
        SELECT t.relname AS tbl,
               c.conname AS name,
               pg_get_constraintdef(c.oid) AS def,
               ARRAY(
                   SELECT a.attname
                   FROM unnest(c.conkey) AS k(attnum)
                   JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
               ) AS cols
        FROM pg_constraint c
        JOIN pg_class t ON t.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = t.relnamespace
        WHERE c.contype = 'c' AND c.conrelid <> 0 AND n.nspname = 'public'
        ORDER BY t.relname, c.conname
    SQL);

    return array_map(static fn (object $r): array => [
        'table' => (string) $r->tbl,
        'name' => (string) $r->name,
        'definition' => (string) $r->def,
        'columns' => array_values(array_filter(explode(',', trim((string) $r->cols, '{}')))),
    ], $rows);
}

/**
 * Is this constraint a VALUE DOMAIN over `$column`, rather than a relational rule that merely
 * mentions it? A value domain either enumerates cases (`= ANY (ARRAY[...])`) or pins a single one.
 *
 * @param  array{table: string, name: string, definition: string, columns: list<string>}  $check
 */
function checkDriftIsValueDomain(array $check, string $column): bool
{
    if (! in_array($column, $check['columns'], true)) {
        return false;
    }

    $quoted = preg_quote($column, '/');

    return preg_match('/\('.$quoted.'\)::text = ANY \(/', $check['definition']) === 1
        || preg_match('/\('.$quoted.'\)::text = \047/', $check['definition']) === 1;
}

/**
 * The cases a value-domain CHECK accepts.
 *
 * @return list<string>
 */
function checkDriftAcceptedValues(string $definition): array
{
    preg_match_all('/\047([^\047]+)\047::character varying/', $definition, $m);
    if ($m[1] !== []) {
        return array_values(array_unique($m[1]));
    }

    preg_match_all('/::text = \047([^\047]+)\047::text/', $definition, $single);

    return array_values(array_unique($single[1]));
}

// -- The floor ----------------------------------------------------------------------------------

it('parses a catalog with both backed and unbacked rows, so the arms below cannot pass blind', function (): void {
    $rows = checkDriftCatalogRows();

    // ⛔ EVERY ARM BELOW IS A LOOP OVER THIS LIST. A parser that stops matching yields an empty list
    // and a green suite that measured nothing, which is the failure mode this repository keeps
    // paying for. The thresholds are floors, not counts: the catalog may legitimately grow.
    expect(count($rows))->toBeGreaterThanOrEqual(25, 'the enum catalog parsed to fewer rows than it has — the table shape moved');

    $backed = array_filter($rows, static fn (array $r): bool => $r['constraints'] !== []);
    $unbacked = array_filter($rows, static fn (array $r): bool => $r['constraints'] === []);

    expect(count($backed))->toBeGreaterThanOrEqual(8, 'no backed rows parsed — the DB CHECK column is not being read');
    expect(count($unbacked))->toBeGreaterThanOrEqual(8, 'no unbacked rows parsed — the none form is not being read');

    expect(count(checkDriftLiveChecks()))->toBeGreaterThanOrEqual(40, 'the live CHECK census came back short — the query, not the schema');
});

// -- Direction one: every constraint the document names is real, and guards what it says ---------

it('names only CHECK constraints that exist and constrain the documented column', function (): void {
    $byName = [];
    foreach (checkDriftLiveChecks() as $check) {
        $byName[$check['name']] = $check;
    }

    $wrong = [];

    foreach (checkDriftCatalogRows() as $row) {
        foreach ($row['constraints'] as $name) {
            if (! array_key_exists($name, $byName)) {
                $wrong[] = "{$row['enum']}: {$name} is named in DB CHECK and exists in no live constraint";

                continue;
            }

            $guards = false;
            foreach ($row['columns'] as $qualified) {
                $column = str_contains($qualified, '.') ? explode('.', $qualified)[1] : $qualified;
                if (checkDriftIsValueDomain($byName[$name], $column)) {
                    $guards = true;

                    break;
                }
            }

            if (! $guards) {
                $wrong[] = "{$row['enum']}: {$name} exists but is not a value domain over any documented backing column";
            }
        }
    }

    expect($wrong)->toBe([]);
});

// -- Direction two: no documented column is guarded by a CHECK the document does not name --------

it('records every value-domain CHECK on a documented backing column', function (): void {
    $live = checkDriftLiveChecks();
    $missing = [];

    foreach (checkDriftCatalogRows() as $row) {
        foreach ($row['columns'] as $qualified) {
            if (! str_contains($qualified, '.')) {
                continue; // a prose cell, e.g. NotificationChannel's "delivery channels a … supports"
            }

            [$table, $column] = explode('.', $qualified, 2);

            foreach ($live as $check) {
                if ($check['table'] !== $table || ! checkDriftIsValueDomain($check, $column)) {
                    continue;
                }

                if (! in_array($check['name'], $row['constraints'], true)) {
                    $missing[] = "{$row['enum']} ({$qualified}) is guarded by {$check['name']} and the DB CHECK cell does not say so";
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

// -- Direction three: the documented VALUES are the values the constraint accepts ----------------

it('documents the cases each backing CHECK actually accepts', function (): void {
    $live = checkDriftLiveChecks();
    $drift = [];

    foreach (checkDriftCatalogRows() as $row) {
        if ($row['constraints'] === [] || $row['values'] === null) {
            continue;
        }

        foreach ($row['columns'] as $qualified) {
            if (! str_contains($qualified, '.')) {
                continue;
            }

            [$table, $column] = explode('.', $qualified, 2);

            foreach ($live as $check) {
                if ($check['table'] !== $table
                    || ! in_array($check['name'], $row['constraints'], true)
                    || ! checkDriftIsValueDomain($check, $column)) {
                    continue;
                }

                $accepted = checkDriftAcceptedValues($check['definition']);
                sort($accepted);
                $documented = $row['values'];
                sort($documented);

                if ($accepted !== $documented) {
                    $drift[] = sprintf(
                        '%s (%s): documented-but-REJECTED [%s]; accepted-but-undocumented [%s]',
                        $row['enum'],
                        $check['name'],
                        implode(', ', array_values(array_diff($documented, $accepted))),
                        implode(', ', array_values(array_diff($accepted, $documented))),
                    );
                }
            }
        }
    }

    // ⛔ THE DIRECTION THAT MATTERS IS THE FIRST HALF OF EACH MESSAGE. A case the document lists and
    // the database rejects is not a documentation nit — it tells a reader a write will succeed that
    // fails with SQLSTATE 23514. `WebhookEventType` carried two of those when this was written.
    expect($drift)->toBe([]);
});
