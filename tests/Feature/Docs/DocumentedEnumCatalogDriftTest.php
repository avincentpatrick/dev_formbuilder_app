<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The enum catalog's value lists vs. the PHP enums that define them (M88).
|--------------------------------------------------------------------------
| `DocumentedCheckConstraintDriftTest` compares the catalog against `pg_constraint`, which is the
| strongest comparison available — for the eleven rows the database actually constrains. It is
| SILENT for the other seventeen, because there is nothing to compare them to. This file is the
| other half: it compares every catalog row against `App\Enums\*::cases()`, which needs no database
| and is available for every row that has a PHP enum at all.
|
| ⛔ THE ROW THAT ASKED FOR THIS PREDICTED ONE LIVE INSTANCE AND IT WAS RIGHT.
| `form_field_validations.operator` has no value-domain CHECK — the only CHECK on that table is
| `form_field_validations_rule_xor_chk`, a nullability XOR — so no database gate could ever see that
| the catalog listed six `ComparisonOperator` values while the enum has declared eight since
| Increment G3. `gte` and `lte` were added to the enum and never carried here. Corrected in M88, in
| place, and this file is what stops it happening again.
|
| ⛔ THE ROW WAS WRONG ABOUT WHAT MADE THIS HARD, AND THE CORRECTION IS WHY THIS FILE IS SHORT.
| It recorded the difficulty as "the enum name in the catalog is prose, not a resolvable symbol —
| `TenantStatus` is not `App\Enums\TenantStatus` on its face", and concluded the mapping was "either
| a convention this repository has not stated or a second list". Measured: 26 of the 28 names ARE
| literally the class basename. The choice was never convention-versus-list; it is the convention
| PLUS a two-entry exception map, declared below and itself asserted.
|
| ⚠️ THE REAL DIFFICULTY IS THE VALUES CELL, WHICH MIXES THE LIST WITH PROSE THAT CONTAINS MORE
| BACKTICKED TOKENS. A naive "every backticked token in the cell" collector harvests `trial` and
| `cancelled` from TenantStatus's correction note (values the enum deliberately does not have) and
| `audits_event_check` from AuditEvent's. A naive "leading run of backticked tokens" collector stops
| at the first parenthetical and silently loses `export_artifact` and `webhook_payload_archive` from
| AttachmentKind and three cases from NotificationType. Both are the "green while blind" shape.
|
| ⛔ SO THE CELL HAS TWO DECLARED GRAMMARS AND A ROW MUST MATCH EXACTLY ONE. The flat run reads a
| comma-separated run of backticked values in which each value MAY carry a parenthetical
| annotation, and stops at the first token that is not another value. The grouped run reads
| FieldType's shape — `**Category** — v, v, v;` repeated — which is the one cell that opens with
| prose. A row matching NEITHER grammar fails LOUDLY; it is never skipped.
|
| ⚠️ WHY THE GRAMMAR'S FAILURE BIAS IS SAFE. Both directions are compared for set EQUALITY, never
| containment (M56's lesson: Laravel's own JSON assertions are subset checks, which let `/api/v1`
| publish a shape it never returned through 25+ green assertions). So a grammar that swallowed a
| real value as annotation would report it MISSING and fail — it cannot manufacture a pass. The
| worst a parser bug can do here is red, which is the direction a gate is allowed to be wrong in.
|
| ⚠️ NOT REUSING `checkDriftBacktickedRun()`, DELIBERATELY. That helper cuts commentary at ' — ' or
| '. ', and neither terminator occurs in the ValidationRuleType or ComparisonOperator cells — so it
| harvests `rule_types` and `rule_formulas` from their trailing prose as if they were enum cases. It
| is correct for its own caller only because that caller skips both rows for an unrelated reason.
|
| ⚠️ NO DATABASE, NO VITE, NO DIRECTORY WALK. Reads one named document and iterates no directory, so
| the partial `RecursiveDirectoryIterator` descent of the Windows bind mount that
| `docs/gate-baselines.md` records for the lint gates cannot reach it. Equally correct on the host
| and in the container — which is the whole reason this is a Pest arm and not a sixth lint script
| (`DocumentedCommandDriftTest`'s argument, verbatim).
|
| Helper names are prefixed `documentedEnumCatalog*` deliberately: Pest loads every file in a
| directory into one process, so a same-named file-scope helper is a fatal redeclaration — which is
| how M67's first CI run died before a single test executed.
*/

/** The document carrying the catalog, and the header row that anchors it. */
const DOCUMENTED_ENUM_CATALOG_DOCUMENT = 'docs/data-dictionary.md';

const DOCUMENTED_ENUM_CATALOG_HEADER = '| Enum | Backing column(s) | DB CHECK | Values |';

/**
 * Catalog names whose PHP enum is NOT `App\Enums\<name>`, and what it really is.
 *
 * ⛔ ONE ENTRY, AND IT IS A FINDING RATHER THAN A CONVENIENCE. The catalog documents
 * `WebhookEventType`; no such class exists. `webhook_deliveries.event_type` casts to
 * `App\Enums\DomainEventType`, whose own docblock calls the catalog "the `WebhookEventType` starter
 * catalog earmarked in data-dictionary". The two lists agree exactly, so this is a naming
 * divergence and not a drift — but a gate resolving by convention alone would have gone red on its
 * first run for the wrong reason.
 *
 * @var array<string, string>
 */
const DOCUMENTED_ENUM_CATALOG_ALIASES = [
    'WebhookEventType' => 'DomainEventType',
];

/**
 * Catalog rows with no PHP enum anywhere in the tree, and why.
 *
 * ⛔ ALSO ONE ENTRY, ALSO A FINDING. `NotificationChannel` is documented as a vocabulary with "no
 * backing column"; it has no PHP enum either. `in_app` and `email` exist only as array keys and as
 * the boolean columns `notification_preferences.in_app_enabled` / `email_enabled`. The sibling
 * `DocumentedCheckConstraintDriftTest` already skips it, for the unrelated reason that its
 * backing-column cell is prose. It is named here so the skip is a declaration rather than a
 * silence, and the arm below proves the name is still in the catalog.
 *
 * @var array<string, string>
 */
const DOCUMENTED_ENUM_CATALOG_NO_PHP_ENUM = [
    'NotificationChannel' => 'no PHP enum: in_app/email are boolean columns on notification_preferences',
];

/**
 * Plausible floors for the discovery pass.
 *
 * Measured on the tree this gate was written against: 28 catalog rows, 27 of them comparable (28
 * less the one declared no-enum row). These sit below that so ordinary editing does not trip them,
 * and above zero so a renamed document, a changed header or a broken table parser fails LOUDLY
 * rather than reporting green over nothing.
 */
const DOCUMENTED_ENUM_CATALOG_MIN_ROWS = 25;

const DOCUMENTED_ENUM_CATALOG_MIN_COMPARED = 22;

/**
 * PHP's namespace separator, built as a character code.
 *
 * CLAUDE.md's host-traps section: the tool layer collapses doubled backslashes, so an escape is
 * built rather than written. `chr(92)` cannot be mangled by whatever authors this file next.
 */
function documentedEnumCatalogClass(string $name): string
{
    return 'App'.chr(92).'Enums'.chr(92).$name;
}

/**
 * A sorted copy — `sort()` is by-reference and returns a bool, which is a trap in a comparison.
 *
 * @param  list<string>  $values
 * @return list<string>
 */
function documentedEnumCatalogSorted(array $values): array
{
    sort($values);

    return $values;
}

/**
 * The catalog's rows: 1-based document line, the enum name, and the raw Values cell.
 *
 * ⚠️ `explode("\n")` AND NEVER `preg_split` ON A NEWLINE CLASS. Without the `u` modifier that class
 * matches a byte occurring inside common UTF-8 emoji, and this document is full of them — it would
 * silently shift every line number after the first (M42). Both sibling gates record the same trap.
 *
 * @return list<array{line: int, enum: string, values: string}>
 */
function documentedEnumCatalogRows(): array
{
    $path = base_path(DOCUMENTED_ENUM_CATALOG_DOCUMENT);

    expect(is_file($path))->toBeTrue(
        'Discovery floor: '.DOCUMENTED_ENUM_CATALOG_DOCUMENT.' was not found at '.$path.
        '. A moved or renamed document makes this gate blind, so it fails instead.'
    );

    $lines = explode("\n", (string) file_get_contents($path));

    $header = null;
    foreach ($lines as $index => $line) {
        if (trim($line) === DOCUMENTED_ENUM_CATALOG_HEADER) {
            $header = $index;

            break;
        }
    }

    expect($header)->not->toBeNull(
        'Discovery floor: the catalog header "'.DOCUMENTED_ENUM_CATALOG_HEADER.'" is not in '.
        DOCUMENTED_ENUM_CATALOG_DOCUMENT.'. If the table gained or lost a column, update this '.
        'constant — do not loosen the match, because a header matched loosely selects the wrong table.'
    );

    $rows = [];
    $total = count($lines);

    // +2 skips the header and the |---|---| separator beneath it.
    for ($index = (int) $header + 2; $index < $total; $index++) {
        $line = $lines[$index];

        if (! str_starts_with($line, '|')) {
            break; // the blank line after the table ends it
        }

        // A continuation row carries an em-dash in the Enum cell (AttachmentKind's H17 note) and
        // documents no vocabulary of its own.
        if (preg_match('/^\| `([A-Za-z]+)` \|/', $line, $match) !== 1) {
            continue;
        }

        $cells = array_map('trim', explode('|', trim(trim($line), '|')));

        expect(count($cells))->toBe(
            4,
            'Discovery floor: the catalog row for `'.$match[1].'` at '.
            DOCUMENTED_ENUM_CATALOG_DOCUMENT.':'.($index + 1).' split into '.count($cells).
            ' cells, not 4. A pipe inside a cell breaks every column offset this gate reads.'
        );

        $rows[] = ['line' => $index + 1, 'enum' => $match[1], 'values' => $cells[3]];
    }

    expect(count($rows))->toBeGreaterThanOrEqual(
        DOCUMENTED_ENUM_CATALOG_MIN_ROWS,
        'Discovery floor: the catalog yielded only '.count($rows).' rows. A table that has shrunk '.
        'below the floor is far more likely a broken parser than a real edit.'
    );

    return $rows;
}

/**
 * Grammar 1 — a comma-separated run of backticked values, each optionally annotated.
 *
 * Reads from the START of the cell and stops at the first thing that is not another value, so the
 * commentary every cell trails is never harvested. An annotation is a parenthetical, bare or
 * emphasised: `webhook_failed` (PRD Feature #13) and `feedback_screenshot` *(added I7a)* both
 * continue the list. The separators are the ones the catalog actually uses — ", ", ", then ",
 * ", and ", " and " and "; ".
 *
 * @return list<string>
 */
function documentedEnumCatalogFlatRun(string $cell): array
{
    $values = [];
    $offset = 0;

    while (preg_match('/^`([a-z0-9_.]+)`/', substr($cell, $offset), $value) === 1) {
        $values[] = $value[1];
        $offset += strlen($value[0]);

        if (preg_match('/^\s*\*?\([^()]*\)\*?/', substr($cell, $offset), $annotation) === 1) {
            $offset += strlen($annotation[0]);
        }

        if (preg_match('/^(?:\s*,\s*(?:then\s+|and\s+)?|\s+and\s+|\s*;\s*)/', substr($cell, $offset), $separator) !== 1) {
            break;
        }

        // Consume the separator only when another value actually follows it, so a trailing "`a`, and
        // see below" cannot leave the cursor mid-prose.
        if (preg_match('/^`[a-z0-9_.]+`/', substr($cell, $offset + strlen($separator[0]))) !== 1) {
            break;
        }

        $offset += strlen($separator[0]);
    }

    return $values;
}

/**
 * Grammar 2 — `**Category** — v, v, v;` repeated. FieldType is the only cell in this shape.
 *
 * It opens with prose ("31 values across 8 categories …"), so Grammar 1 reads nothing from it. The
 * categories are real information — they mirror `FieldCategory` — so flattening the cell to satisfy
 * the first grammar would destroy something rather than tidy it, and prepending a flat copy would
 * put two lists of the same fact in one cell, which is the defect this repository files elsewhere.
 *
 * `\p{Pd}` with `/u`: the separator is an em-dash, and the cell is full of multi-byte characters.
 *
 * @return list<string>
 */
function documentedEnumCatalogGroupedRun(string $cell): array
{
    if (preg_match_all('/\*\*[^*]+\*\*\s*\p{Pd}\s*((?:`[a-z0-9_.]+`(?:\s*\([^()]*\))?(?:\s*,\s*)?)+)/u', $cell, $groups) === false) {
        return [];
    }

    $values = [];
    foreach ($groups[1] as $group) {
        preg_match_all('/`([a-z0-9_.]+)`/', $group, $found);
        $values = [...$values, ...$found[1]];
    }

    return $values;
}

/**
 * The backed values a PHP enum declares, in declaration order.
 *
 * @param  class-string  $class
 * @return list<string>
 */
function documentedEnumCatalogCases(string $class): array
{
    $values = [];

    foreach ($class::cases() as $case) {
        $values[] = (string) $case->value;
    }

    return $values;
}

/**
 * The class backing a catalog row, or null when the catalog declares that nothing does.
 *
 * @param  array{line: int, enum: string, values: string}  $row
 */
function documentedEnumCatalogClassFor(array $row): ?string
{
    if (array_key_exists($row['enum'], DOCUMENTED_ENUM_CATALOG_NO_PHP_ENUM)) {
        return null;
    }

    return documentedEnumCatalogClass(
        DOCUMENTED_ENUM_CATALOG_ALIASES[$row['enum']] ?? $row['enum']
    );
}

it('resolves every catalog row to a PHP enum or to a declared exception', function (): void {
    $unresolved = [];

    foreach (documentedEnumCatalogRows() as $row) {
        $class = documentedEnumCatalogClassFor($row);

        if ($class === null || enum_exists($class)) {
            continue;
        }

        $unresolved[] = $row['enum'].' ('.DOCUMENTED_ENUM_CATALOG_DOCUMENT.':'.$row['line'].') -> '.$class;
    }

    expect($unresolved)->toBe(
        [],
        'The catalog names a vocabulary with no PHP enum behind it. Either the name is wrong, or it '.
        'belongs in DOCUMENTED_ENUM_CATALOG_ALIASES (a different class backs it) or in '.
        'DOCUMENTED_ENUM_CATALOG_NO_PHP_ENUM (nothing does). Do not delete the catalog row to make '.
        'this pass:'."\n  ".implode("\n  ", $unresolved)
    );
});

it('keeps both exception maps describing rows that are still in the catalog', function (): void {
    $names = array_column(documentedEnumCatalogRows(), 'enum');
    $stale = [];

    foreach (array_keys(DOCUMENTED_ENUM_CATALOG_ALIASES) as $name) {
        if (! in_array($name, $names, true)) {
            $stale[] = $name.' is in DOCUMENTED_ENUM_CATALOG_ALIASES and no longer in the catalog';
        }
    }

    foreach (array_keys(DOCUMENTED_ENUM_CATALOG_NO_PHP_ENUM) as $name) {
        if (! in_array($name, $names, true)) {
            $stale[] = $name.' is in DOCUMENTED_ENUM_CATALOG_NO_PHP_ENUM and no longer in the catalog';
        }
    }

    // An exception that outlives its subject is how a skip list becomes a place defects hide.
    expect($stale)->toBe([], implode("\n  ", $stale));

    foreach (DOCUMENTED_ENUM_CATALOG_ALIASES as $documented => $real) {
        expect(enum_exists(documentedEnumCatalogClass($real)))->toBeTrue(
            'DOCUMENTED_ENUM_CATALOG_ALIASES maps `'.$documented.'` to `'.$real.'`, which is not an enum.'
        );
    }
});

/**
 * The English number words the per-table sections actually use for a catalog size.
 *
 * A closed map on purpose. A count written in a word this map does not know fails as "unknown
 * count prose" rather than being coerced to something plausible — the `toContain`/`toHaveKey`
 * family of traps (M30, M43) arriving through a parser instead of through an expectation. Only
 * `ten` occurs today, and it is emphasised (`**ten-value**`) where the rest are bare digits.
 */
const DOCUMENTED_ENUM_CATALOG_COUNT_WORDS = [
    'two' => 2,
    'three' => 3,
    'four' => 4,
    'five' => 5,
    'six' => 6,
    'seven' => 7,
    'eight' => 8,
    'nine' => 9,
    'ten' => 10,
    'eleven' => 11,
    'twelve' => 12,
];

const DOCUMENTED_ENUM_CATALOG_MIN_POINTERS = 5;

it('keeps every per-table pointer agreeing with the catalog it points at', function (): void {
    /*
     * ⛔ THIS ARM EXISTS BECAUSE THE CATALOG IS NOT THE ONLY PLACE THE COUNT LIVES, AND M88'S OWN
     * FIX PROVED IT. Each table section re-states the size as "See the N-value catalog above". The
     * row this closes believed "the catalog is the only place the ComparisonOperator value list
     * lives"; correcting §6's catalog row to eight left `:393` still saying six, so the document
     * contradicted itself for exactly as long as it took to measure.
     *
     * ⚠️ AND THE SECOND INSTANCE WAS ALREADY ON THE TRUNK. `UsageMetric`'s catalog row was widened
     * to eight by M87; its pointer at `:773` still read seven. That row HAS a database CHECK, so it
     * is outside the population the closed row describes — the ungated surface is the whole
     * document, not only the seventeen unbacked vocabularies.
     */
    $rows = [];
    foreach (documentedEnumCatalogRows() as $row) {
        $rows[$row['enum']] = $row;
    }

    $lines = explode("\n", (string) file_get_contents(base_path(DOCUMENTED_ENUM_CATALOG_DOCUMENT)));

    $pointers = 0;
    $wrong = [];

    foreach ($lines as $index => $line) {
        if (preg_match('/PHP enum: `([A-Za-z]+)`/', $line, $enum) !== 1) {
            continue;
        }

        if (preg_match('/the \*{0,2}([A-Za-z0-9]+)-value\*{0,2} catalog above/', $line, $size) !== 1) {
            continue; // a pointer carrying no count states nothing this arm can check
        }

        $stated = ctype_digit($size[1])
            ? (int) $size[1]
            : (DOCUMENTED_ENUM_CATALOG_COUNT_WORDS[strtolower($size[1])] ?? null);

        expect($stated)->not->toBeNull(
            DOCUMENTED_ENUM_CATALOG_DOCUMENT.':'.($index + 1).' states its catalog size as "'.$size[1].
            '", which is neither a number nor a word in DOCUMENTED_ENUM_CATALOG_COUNT_WORDS. Add the '.
            'word rather than loosening the match.'
        );

        $name = $enum[1];

        // ⚠️ `array_key_exists` AND NOT `expect($rows)->toHaveKey($name, $message)`. Pest reads
        // `toHaveKey`'s second argument as the expected VALUE, not as a failure message — the same
        // trap `toContain(needle, message)` carries, and it fails with a type error that names
        // neither the pointer nor the catalog. Measured here on the first run.
        expect(array_key_exists($name, $rows))->toBeTrue(
            DOCUMENTED_ENUM_CATALOG_DOCUMENT.':'.($index + 1).' points at a catalog entry for `'.$name.
            '`, and the catalog has no such row.'
        );

        $class = documentedEnumCatalogClassFor($rows[$name]);

        if ($class === null || ! enum_exists($class)) {
            continue;
        }

        $pointers++;
        $actual = count(documentedEnumCatalogCases($class));

        if ($stated === $actual) {
            continue;
        }

        $wrong[] = DOCUMENTED_ENUM_CATALOG_DOCUMENT.':'.($index + 1).' says the `'.$name.
            '` catalog has '.$stated.' values; '.$class.' declares '.$actual.'.';
    }

    expect($pointers)->toBeGreaterThanOrEqual(
        DOCUMENTED_ENUM_CATALOG_MIN_POINTERS,
        'Discovery floor: only '.$pointers.' per-table pointers were checked. This form is a '.
        'convention rather than a rule, so a parser that has stopped matching it goes silent '.
        'instead of red.'
    );

    expect($wrong)->toBe([], 'A per-table section restates a catalog size that is no longer true:'."\n  ".implode("\n  ", $wrong));
});

it('publishes exactly the values each enum declares, in both directions', function (): void {
    $rows = documentedEnumCatalogRows();
    $compared = 0;
    $drift = [];

    foreach ($rows as $row) {
        $class = documentedEnumCatalogClassFor($row);

        // A name that resolves to nothing is the FIRST arm's failure, and it owns the explanation.
        // Without this guard `::cases()` raises a PHP Error here too, so one defect reports twice —
        // once legibly and once as a fatal — and the fatal is the one a reader sees first.
        if ($class === null || ! enum_exists($class)) {
            continue;
        }

        $cases = documentedEnumCatalogCases($class);
        $sorted = documentedEnumCatalogSorted($cases);

        $flat = documentedEnumCatalogFlatRun($row['values']);
        $grouped = documentedEnumCatalogGroupedRun($row['values']);

        // The cell must be written in ONE of the two declared grammars. Whichever reproduces the
        // enum is the one it is in; when neither does, the longer reading is reported so the
        // failure message names real tokens instead of an empty list.
        $documented = match (true) {
            documentedEnumCatalogSorted($flat) === $sorted => $flat,
            documentedEnumCatalogSorted($grouped) === $sorted => $grouped,
            count($grouped) > count($flat) => $grouped,
            default => $flat,
        };

        $compared++;

        $missing = array_values(array_diff($cases, $documented));
        $extra = array_values(array_diff($documented, $cases));

        if ($missing === [] && $extra === []) {
            continue;
        }

        $drift[] = $row['enum'].' ('.DOCUMENTED_ENUM_CATALOG_DOCUMENT.':'.$row['line'].')'.
            ($missing === [] ? '' : ' — declared by '.$class.' and NOT documented: '.implode(', ', $missing)).
            ($extra === [] ? '' : ' — documented and NOT declared by '.$class.': '.implode(', ', $extra));
    }

    expect($compared)->toBeGreaterThanOrEqual(
        DOCUMENTED_ENUM_CATALOG_MIN_COMPARED,
        'Discovery floor: only '.$compared.' of '.count($rows).' catalog rows were compared. A gate '.
        'that has quietly stopped comparing rows reports green over nothing.'
    );

    expect($drift)->toBe(
        [],
        'The enum catalog in '.DOCUMENTED_ENUM_CATALOG_DOCUMENT.' disagrees with the enums it '.
        'documents. Seventeen of these vocabularies have no database CHECK, so nothing else in the '.
        'repository can see this:'."\n  ".implode("\n  ", $drift)
    );
});
