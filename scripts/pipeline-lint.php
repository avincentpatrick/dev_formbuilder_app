<?php

declare(strict_types=1);

/*
 * Pipeline lint (M81) — the gate over the one ordered line.
 *
 * WHY THIS EXISTS. `scripts/pipeline.php` (M79) made every remaining task visible in one generated
 * line. It could not make an UNQUEUED one impossible, and said so in its own output: "until it lands
 * this file is a floor rather than a census." Five separate realignments have found work that was
 * documented and unscheduled at the same time, and EVERY ONE WAS CAUGHT BY A HUMAN AUDIT. None by a
 * gate. This is the gate.
 *
 * ⛔ IT RUNS ON THE HOST AND MUST NEVER BE `docker exec`ed. Inside the app container
 * RecursiveDirectoryIterator descends the Windows bind mount only partially — controller-gate saw 49
 * of 97 files, M77 pinned 87 of 114 migrations, D17 measured 40 whole test files lost — and a scan
 * that goes blind returns a SHORT LIST rather than an error. Every floor below therefore fails as
 * exit 2, and P5 exists for no other reason.
 *
 * ⛔ WHAT WAS MEASURED BEFORE ANY RULE WAS WRITTEN, because six of the approved design's predicates
 * did not survive contact with the tree. Recorded here rather than in a claim, because the next
 * author will read this file and not that one.
 *
 *   P4   The design said "set equality both directions" against loop.php's held-topic list. That list
 *        is TWELVE KEYWORDS mapping MANY-TO-ONE onto five held rows, so set equality is not a thing
 *        that can be written. What is written below is bidirectional COVERAGE, which catches the same
 *        two failures — a dropped row, and a dropped keyword — and can actually be satisfied.
 *
 *   P3   The one row reading BUILDING named its three obligations in PROSE. A rule requiring it to
 *        cite ids was red on arrival until that cell was corrected, which is the rule working.
 *
 *   P3c  The defect it was designed to catch NO LONGER EXISTS — M79 archived both lane queues — so
 *        the predicate matches nothing on a correct tree. Its only live hit is a QUOTATION of the
 *        defect inside the sentence recording the repair. It is therefore proved from a fixture, and
 *        a rule that fired on the quotation would be the mention-as-declaration failure this
 *        repository has now shipped four times.
 *
 *   P2d  "Absent from the code" is NOT a defect predicate here: documented-and-not-built is a
 *        permanent legitimate state, and 12 of 20 sampled lines were ratified decisions. Absence
 *        alone cannot separate a decision from a debt. The join below is what separates them, and it
 *        needed FIVE terms rather than the three the design named — the two it lacked are the ADR
 *        corpus and THE PIPELINE ITSELF.
 *
 *   P5   The generator's own file floor is 40 against a live scan of 869 — 22x slack, so a walk that
 *        went half-blind in exactly the way the constant's comment cites would sail through it.
 *
 * ⚠️ AND THE DECLARATION SET FOR P2d IS FOUR THINGS, NOT ONE. Measured on the column this rule exists
 * for: its occurrences are a property docblock, a fillable entry, a cast entry, a docblock stating it
 * has no writer, two schema lines and a test's column inventory. NOT ONE IS A USE. A first pass
 * counting only schema files as declarations found 7 dormant columns; the corrected set finds 41.
 * Under-collecting is the BLIND direction and it reports green.
 *
 * Usage:
 *   php scripts/pipeline-lint.php            # all rules, failures only
 *   php scripts/pipeline-lint.php --verbose  # print every measurement, not only the failures
 *
 * Exit 0 = clean. Exit 1 = a rule failed. Exit 2 = could not measure — NEVER a silent skip.
 */

chdir(dirname(__DIR__));

const PIPELINE = 'docs/pipeline.md';
const TRACKER = 'PROGRESS.md';
const IMPERATIVES = 'CLAUDE.md';
const LOOP = 'scripts/loop.php';
const BACKLOG = 'docs/feature-backlog.md';
const DECISIONS = 'docs/claims/decisions.md';
const DICTIONARY_HEADER = '| Column | Type | Nullable | Default | PII? | Description |';

/**
 * The generator, resolved through a seam so a control can stand a stub in its place.
 *
 * This is `scripts/mutate.php`'s own device for the container runtime, and it is the reason the
 * controls for this gate need no directory of their own: a fixture supplies a document, and every
 * rule below reads a document plus a handful of NAMED files.
 */
const GENERATOR_ENV = 'PIPELINE_LINT_GENERATOR';

/**
 * Floors, every one measured on the tree this gate was written against rather than chosen.
 *
 * Live figures at the time of writing: 869 files reached by the generator, 129 rows in the line, 5
 * held, 9 plan markers, 7 roadmap rows, 2 dictionary documents, 39 column tables, 556 column rows,
 * 814 files in the app corpus. These sit below those so ordinary editing does not trip them, and far
 * enough above zero that a scan which has gone blind fails LOUDLY instead of reporting a short list.
 */
const MIN_GENERATOR_FILES = 600;

const MIN_ROWS = 90;

const MIN_HELD_ROWS = 5;

const MIN_PLAN_ROWS = 8;

const MIN_ROADMAP_ROWS = 7;

const MIN_DICTIONARY_DOCUMENTS = 2;

const MIN_COLUMN_TABLES = 30;

const MIN_COLUMN_ROWS = 450;

const MIN_APP_FILES = 600;

/**
 * The closed vocabulary a roadmap Status cell may speak.
 *
 * Closed rather than free text because three cells claimed in-progress work that had finished months
 * earlier and nothing could see it. A cell speaking none of these is a cell nobody can machine-read.
 */
const ROADMAP_VOCABULARY = ['COMPLETE', 'BUILDING', 'Deferred', 'SHIPPED', 'HELD'];

/** The token a roadmap cell uses to claim work is in flight. Only this one owes a citation. */
const ROADMAP_IN_FLIGHT = 'BUILDING';

/**
 * Lines that are GENERATED and therefore cannot go stale, so P3c does not read them.
 *
 * ⚠️ This is a structural exemption, not an allow-list of known offenders. The hand-off line restates
 * the pipeline counts, and would trip a second-queue rule on every run — but it is rewritten from the
 * tree by `scripts/next.php` at every close-out, so it is incapable of the drift the rule exists to
 * catch. An allow-list would have to name the offender; this names the PROPERTY.
 */
const GENERATED_LINE_PREFIXES = ['**LANE A NEXT PROMPT', '**LANE B NEXT PROMPT'];

/**
 * Three struck-through spans on one line, which is what a retired queue looks like.
 *
 * ⛔ THE THRESHOLD IS MEASURED, NOT CHOSEN. M79 derived it: at three it matched EXACTLY the two lane
 * queue lines and nothing else, and at two it also caught a roadmap row. The arrow-chain predicate
 * the design originally specified matched SIXTEEN lines, of which fourteen were legitimate.
 */
const STRUCK_SPAN_THRESHOLD = 3;

$flags = ['verbose', 'help'];
$opts = getopt('', $flags);

foreach (array_slice($argv, 1) as $argument) {
    if (! in_array(ltrim($argument, '-'), $flags, true)) {
        fwrite(STDERR, sprintf(
            "pipeline-lint: unrecognised argument %s. Known: --%s.\n",
            $argument,
            implode(' --', $flags)
        ));

        exit(1);
    }
}

if (isset($opts['help'])) {
    fwrite(STDOUT, implode("\n", [
        'pipeline-lint — the gate over '.PIPELINE.', the one ordered line of remaining work.',
        '',
        '  php scripts/pipeline-lint.php            all rules, failures only',
        '  php scripts/pipeline-lint.php --verbose  print every measurement',
        '',
        'Runs on the HOST. Never inside the app container: the bind mount truncates a directory walk',
        'and a gate with no floor reports passed while blind.',
        '',
        'Exit 0 = clean. 1 = a rule failed. 2 = could not measure.',
        '',
    ])."\n");

    exit(0);
}

$verbose = isset($opts['verbose']);
$failures = [];
$notes = [];

// ── The document under test. Everything below reads this or a named file; nothing walks a tree. ──
$document = read_pipeline_document();
$rows = $document['rows'];
$offLine = $document['off_the_line'] ?? [];

// ── P5. The floors come FIRST, because every rule after them is meaningless over a short list. ────
if ($document['files_scanned'] < MIN_GENERATOR_FILES) {
    cannot_measure(sprintf(
        'the generator reached only %d file(s), under the floor of %d. A directory walk that has gone '
        .'blind returns a SHORT LIST rather than an error, so this refuses rather than ruling over a '
        .'document that is missing whatever it could not see.',
        $document['files_scanned'],
        MIN_GENERATOR_FILES
    ));
}

$held = array_values(array_filter($rows, static fn (array $r): bool => $r['state'] === 'held'));
$plan = array_values(array_filter($rows, static fn (array $r): bool => $r['class'] === 'plan'));

foreach ([
    ['rows in the line', count($rows), MIN_ROWS],
    ['held rows', count($held), MIN_HELD_ROWS],
    ['plan rows', count($plan), MIN_PLAN_ROWS],
] as [$what, $actual, $floor]) {
    if ($actual < $floor) {
        cannot_measure(sprintf('%s: %d, under the floor of %d.', $what, $actual, $floor));
    }
}

$notes[] = sprintf(
    'the line holds %d row(s) — %d held, %d from the plan — over %d file(s) scanned',
    count($rows),
    count($held),
    count($plan),
    $document['files_scanned']
);

// ── P1. Drift. The generator already answers this exactly; asking it twice would be a second parser.
p1_drift();

// ── P3 / P3c. The tracker's roadmap, and the absence of a second queue. ──────────────────────────
p3_roadmap($rows, $offLine);
p3c_no_second_queue();

// ── P4. Held rows are visible, and the stop-list and the line agree in BOTH directions. ──────────
p4_held_visibility($held);

// ── P2d. Documented artefacts that exist, are used by nothing, and are scheduled nowhere. ────────
p2d_artefact_drift($rows);

// ── P6. The generated file may not arm the next run. ────────────────────────────────────────────
p6_self_arming();

// ── Report. ─────────────────────────────────────────────────────────────────────────────────────
foreach ($notes as $note) {
    fwrite(STDOUT, "pipeline-lint: {$note}\n");
}

if ($failures !== []) {
    fwrite(STDERR, 'pipeline-lint: FAILED — '.count($failures).' check(s) in '.
        count(array_unique($failures)).' rule group(s): '.implode(', ', array_unique($failures))."\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "pipeline-lint: passed (7 rule groups, %d row(s), %d held, %d file(s) scanned).\n",
    count($rows),
    count($held),
    $document['files_scanned']
));

exit(0);

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// Rules
// ═══════════════════════════════════════════════════════════════════════════════════════════════

/**
 * P1 — the committed line matches the tree.
 *
 * Delegated rather than reimplemented. `scripts/loop.php` recorded what a second parser costs when
 * its first draft hand-rolled one, and the generator's own comparison already excludes the
 * provenance line for the right reason: that line names a commit, so comparing it would report
 * staleness as drift, and those are different questions.
 */
function p1_drift(): void
{
    $status = 0;
    sh(generator().' --check', $status);

    if ($status === 0) {
        pass('P1 drift', PIPELINE.' matches the tree');

        return;
    }

    if ($status === 1) {
        fail('P1 drift', PIPELINE.' has DRIFTED from the tree. Regenerate it with scripts/pipeline.php '
            .'rather than editing it — it is generated, and a hand edit is a defect rather than a shortcut.');

        return;
    }

    cannot_measure('the generator exited '.$status.' on --check, so drift is UNKNOWN rather than absent.');
}

/**
 * P3 — the roadmap speaks a closed vocabulary, and a row claiming work is in flight cites a row that
 * is actually in the line.
 *
 * ⛔ THE CITATION HALF IS THE WHOLE RULE. Three cells claimed in-progress work that had finished
 * months earlier — one had not been touched in four months — and nothing in the repository could
 * tell, because a Status cell was free prose accountable to nothing. A cell that must name a LIVE
 * pipeline id cannot make that claim without something being true.
 */
function p3_roadmap(array $rows, array $offLine): void
{
    $cells = roadmap_cells();

    if (count($cells) < MIN_ROADMAP_ROWS) {
        cannot_measure(sprintf(
            'the roadmap table yielded %d row(s), under the floor of %d. A reformatted table header '
            .'makes this rule blind, so it refuses rather than ruling over what it could still see.',
            count($cells),
            MIN_ROADMAP_ROWS
        ));
    }

    $liveIds = [];

    foreach ($rows as $row) {
        $liveIds[$row['id']] = true;
    }

    $knownIds = $liveIds;

    foreach ($offLine as $row) {
        $knownIds[$row['id']] = true;
    }

    foreach ($cells as $cell) {
        $spoken = [];
        $declaration = status_declaration($cell['status']);

        foreach (ROADMAP_VOCABULARY as $word) {
            if (str_contains($declaration, $word)) {
                $spoken[] = $word;
            }
        }

        if ($spoken === []) {
            fail('P3 roadmap', sprintf(
                'the roadmap row "%s" states a status in none of the closed vocabulary (%s). A cell '
                .'accountable to no vocabulary is one nothing can read.',
                $cell['phase'],
                implode(', ', ROADMAP_VOCABULARY)
            ));

            continue;
        }

        if (! in_array(ROADMAP_IN_FLIGHT, $spoken, true)) {
            continue;
        }

        // A row claiming work is in flight owes at least one id that is STILL IN THE LINE.
        $cited = [];

        if (preg_match_all('/`([a-z][a-z0-9-]{2,48})`/', $cell['status'], $matches) > 0) {
            foreach ($matches[1] as $candidate) {
                if (isset($knownIds[$candidate])) {
                    $cited[$candidate] = isset($liveIds[$candidate]);
                }
            }
        }

        if ($cited === []) {
            fail('P3 roadmap', sprintf(
                'the roadmap row "%s" claims %s and cites no pipeline id. A phase in flight names the '
                .'rows that make it so, or the claim is accountable to nothing — which is how three '
                .'cells came to describe work that had finished months earlier.',
                $cell['phase'],
                ROADMAP_IN_FLIGHT
            ));

            continue;
        }

        if (! in_array(true, $cited, true)) {
            fail('P3 roadmap', sprintf(
                'the roadmap row "%s" claims %s but every id it cites (%s) is off the line. A phase '
                .'whose every row is finished is not in flight.',
                $cell['phase'],
                ROADMAP_IN_FLIGHT,
                implode(', ', array_keys($cited))
            ));

            continue;
        }

        pass('P3 roadmap', sprintf('"%s" cites a live row', $cell['phase']));
    }

    pass('P3 roadmap', sprintf('%d row(s), every status inside the closed vocabulary', count($cells)));
}

/**
 * P3c — there is one queue, and it is the generated one.
 *
 * ⚠️ WHAT THIS LOOKS FOR IS A RETIRED QUEUE, NOT A QUEUE. A hand-maintained queue in this repository
 * decays into a chain of struck-through entries with the survivors on the end, and that shape is what
 * a second queue looks like after two increments. Three spans on one line matched exactly the two
 * lane queues and nothing else; two also caught a roadmap row.
 */
function p3c_no_second_queue(): void
{
    $scanned = 0;

    foreach ([TRACKER, IMPERATIVES] as $path) {
        foreach (explode("\n", read_or_die($path)) as $i => $line) {
            $scanned++;

            if (is_generated_line($line)) {
                continue;
            }

            if (preg_match_all('/~~[^~]+~~/', $line) >= STRUCK_SPAN_THRESHOLD) {
                fail('P3c one queue', sprintf(
                    '%s line %d carries %d struck-through spans, which is the shape of a retired '
                    .'queue. There is one queue and it is %s, which is generated.',
                    $path,
                    $i + 1,
                    preg_match_all('/~~[^~]+~~/', $line),
                    PIPELINE
                ));
            }
        }
    }

    if ($scanned < 200) {
        cannot_measure('P3c scanned only '.$scanned.' line(s) across the tracker and the imperatives.');
    }

    pass('P3c one queue', sprintf('%d line(s) carry no retired-queue shape', $scanned));
}

/**
 * P4 — every held row is visible with its blocker named, and the stop-list agrees with the line.
 *
 * ⛔ BOTH DIRECTIONS, AND THE REASON IS MEASURED ELSEWHERE IN THIS REPOSITORY. A catalog gate
 * asserting only "the document mentions every case" passes over a document naming a case the enum
 * does not have; that is how an API published a shape it never returned through 25 green assertions.
 * Here the two failures are different in kind: a held row dropped from the line becomes invisible
 * work, and a keyword dropped from the stop-list lets an unattended run START held work.
 *
 * ⚠️ IT IS COVERAGE, NOT EQUALITY. The stop-list is keywords and the line is rows, many-to-one. The
 * approved design specified set equality, which cannot be written against these two shapes at all.
 */
function p4_held_visibility(array $held): void
{
    foreach ($held as $row) {
        if (trim((string) $row['blocker']) === '') {
            fail('P4 held', sprintf(
                'the held row `%s` names no blocker. A held row without a stated blocker is exactly '
                .'the invisibility the line exists to remove.',
                $row['id']
            ));
        }
    }

    $topics = held_topics();

    if ($topics === []) {
        cannot_measure('no held-topic list could be read from '.LOOP.', so the cross-check below '
            .'would pass vacuously in both directions.');
    }

    $haystacks = [];

    foreach ($held as $row) {
        $haystacks[$row['id']] = strtolower(
            $row['id'].' '.($row['title'] ?? '').' '.($row['headline'] ?? '').' '.$row['blocker']
        );
    }

    // Direction 1 — every stop-list keyword is represented by a row that is actually in the line.
    foreach ($topics as $topic) {
        $matched = false;

        foreach ($haystacks as $haystack) {
            if (str_contains($haystack, $topic)) {
                $matched = true;

                break;
            }
        }

        if (! $matched) {
            fail('P4 held', sprintf(
                'the stop-list refuses "%s" and NO held row in the line covers it. The topic is held '
                .'and invisible at once, which is the state five realignments were spent correcting.',
                $topic
            ));
        }
    }

    // Direction 2 — every held row is refused by the stop-list that guards unattended work.
    foreach ($haystacks as $id => $haystack) {
        $matched = false;

        foreach ($topics as $topic) {
            if (str_contains($haystack, $topic)) {
                $matched = true;

                break;
            }
        }

        if (! $matched) {
            fail('P4 held', sprintf(
                'the held row `%s` is matched by no keyword in %s, so an unattended run would not '
                .'refuse it. An under-refusing stop-list is unsafe where an over-refusing one is '
                .'merely annoying.',
                $id,
                LOOP
            ));
        }
    }

    pass('P4 held', sprintf(
        '%d held row(s) and %d stop-list keyword(s) cover each other in both directions',
        count($held),
        count($topics)
    ));
}

/**
 * P2d — a documented artefact that exists, that nothing uses, and that nothing has scheduled.
 *
 * ⛔ THE PREDICATE IS A FIVE-TERM JOIN AND EVERY TERM WAS EARNED. Absence alone is not a defect:
 * documented-and-unbuilt is a permanent legitimate state here, and a gate on absence manufactures
 * obligations out of settled questions. Measured, the terms remove in this order — 556 documented
 * columns, 41 with no use outside a declaration, 22 once the corpus is widened past the app tree,
 * and 10 once the four scheduling surfaces are consulted.
 *
 *   1. It must EXIST. A documented column present in no schema file is a documented NON-column,
 *      which is a different and already-filed class. Both survivors of an earlier four-term version
 *      were exactly this, one of them a row whose own text says the column does not exist.
 *   2. Nothing may USE it, where a declaration and a comment are not uses.
 *   3. It must be absent from the defect ledger.
 *   4. It must be absent from the decision record — WHICH IS THREE FILES, NOT ONE. A rejection
 *      ratified in an architecture record is still a decision, and the term was added because a
 *      column refused on the record survived a join that could not read one.
 *   5. It must be absent from THE LINE ITSELF. The column this rule exists for survived every other
 *      term while sitting at position two of the queue. A gate asking whether an obligation is
 *      scheduled, that cannot read the schedule, is absurd.
 */
function p2d_artefact_drift(array $rows): void
{
    $documents = dictionary_documents();

    if (count($documents) < MIN_DICTIONARY_DOCUMENTS) {
        cannot_measure(sprintf(
            'only %d document(s) carry a column table, under the floor of %d. A reformatted table '
            .'header makes this rule blind.',
            count($documents),
            MIN_DICTIONARY_DOCUMENTS
        ));
    }

    $tables = 0;
    $cells = [];

    foreach ($documents as $path) {
        $parsed = dictionary_parse($path);
        $tables += $parsed['tables'];
        $cells = array_merge($cells, $parsed['cells']);
    }

    if ($tables < MIN_COLUMN_TABLES || count($cells) < MIN_COLUMN_ROWS) {
        cannot_measure(sprintf(
            'the dictionary scan found %d column table(s) and %d row(s), under the floors of %d and %d.',
            $tables,
            count($cells),
            MIN_COLUMN_TABLES,
            MIN_COLUMN_ROWS
        ));
    }

    $corpus = app_corpus();

    if (count($corpus) < MIN_APP_FILES) {
        cannot_measure(sprintf(
            'the code corpus reached only %d file(s), under the floor of %d. This is the bind-mount '
            .'truncation signature, and a blind corpus reports every documented column as dormant.',
            count($corpus),
            MIN_APP_FILES
        ));
    }

    $schema = concat_files(glob('database/migrations/*.php') ?: []);
    $scheduled = strtolower(
        read_or_die(BACKLOG).' '.read_or_die(DECISIONS).' '.concat_files(glob('docs/adr/*.md') ?: [])
    );

    foreach ($rows as $row) {
        $scheduled .= ' '.strtolower($row['id'].' '.($row['title'] ?? '').' '.($row['headline'] ?? ''));
    }

    $dormant = [];

    foreach ($cells as $cell) {
        $column = $cell['column'];

        // ⚠️ THE TERMS ARE A CONJUNCTION, SO THEIR ORDER CHANGES ONLY THE COST — AND IT CHANGES IT BY
        // AN ORDER OF MAGNITUDE. The corpus term reads files; the other three are string tests over
        // buffers already in memory. Running the cheap ones first leaves the walk with a handful of
        // candidates instead of every documented column.

        // Term 1 — it must exist.
        if (! str_contains($schema, "'".$column."'")) {
            continue;
        }

        // Terms 3, 4 and 5 — the ledger, the decision record and the line.
        if (str_contains($scheduled, $column)) {
            continue;
        }

        // Term 2 — nothing may use it. Last, because it is the only one that touches a disk.
        if (column_is_used($column, $corpus)) {
            continue;
        }

        $dormant[] = $cell;
    }

    foreach ($dormant as $cell) {
        fail('P2d artefact', sprintf(
            '`%s.%s` is documented at %s:%d, exists in the schema, is used by nothing outside a '
            .'declaration, and is scheduled nowhere — not in the ledger, not in the decision record, '
            .'not in the line. Either give it a row, or record the decision not to.',
            $cell['table'],
            $cell['column'],
            $cell['doc'],
            $cell['line']
        ));
    }

    pass('P2d artefact', sprintf(
        '%d documented column(s) over %d table(s) in %d document(s), %d file(s) of code corpus',
        count($cells),
        $tables,
        count($documents),
        count($corpus)
    ));
}

/**
 * P6 — the committed line carries no marker that would arm the next generation.
 *
 * ⚠️ NOT A DUPLICATE OF THE GENERATOR'S OWN GUARD, AND THE DIFFERENCE IS THE POINT. That one refuses
 * to WRITE a self-arming document; this one refuses to accept one that is already ON DISK. A hand
 * edit is precisely the gap between those two, and this file exists because hand edits happen.
 */
function p6_self_arming(): void
{
    $lines = explode("\n", read_or_die(PIPELINE));

    foreach ($lines as $i => $line) {
        if (str_starts_with($line, '<!-- pipeline:')) {
            fail('P6 self-arming', sprintf(
                '%s line %d starts a marker at column 0. The generated file would be harvested on the '
                .'next run, making it its own input.',
                PIPELINE,
                $i + 1
            ));
        }
    }

    pass('P6 self-arming', sprintf('%s carries no line-start marker over %d line(s)', PIPELINE, count($lines)));
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// Measurement helpers
// ═══════════════════════════════════════════════════════════════════════════════════════════════

function generator(): string
{
    $override = getenv(GENERATOR_ENV);

    return $override === false || $override === '' ? 'php scripts/pipeline.php' : $override;
}

/**
 * @return array{files_scanned: int, rows: list<array<string, mixed>>, off_the_line: list<array<string, mixed>>}
 */
function read_pipeline_document(): array
{
    $status = 0;
    $raw = sh(generator().' --json', $status);

    if ($status !== 0) {
        cannot_measure('the generator exited '.$status.' on --json, so there is no document to rule over.');
    }

    $decoded = json_decode($raw, true);

    if (! is_array($decoded) || ! isset($decoded['rows']) || ! isset($decoded['files_scanned'])) {
        cannot_measure('the generator did not return an object carrying rows and a file count.');
    }

    return $decoded;
}

/**
 * The roadmap's phase and status cells.
 *
 * @return list<array{phase: string, status: string}>
 */
function roadmap_cells(): array
{
    $out = [];
    $inTable = false;

    foreach (explode("\n", read_or_die(TRACKER)) as $line) {
        if (str_starts_with($line, '## ')) {
            $inTable = str_contains($line, 'Roadmap Phases');

            continue;
        }

        if (! $inTable || ! str_starts_with($line, '| ')) {
            continue;
        }

        $parts = array_map('trim', explode('|', trim($line, '|')));

        if (count($parts) < 3 || $parts[0] === 'Phase' || str_starts_with($parts[0], '---')) {
            continue;
        }

        $out[] = ['phase' => strip_emphasis($parts[0]), 'status' => $parts[2]];
    }

    return $out;
}

/**
 * The part of a Status cell that DECLARES the phase's state, as opposed to discussing it.
 *
 * ⛔ THIS RULE WALKED INTO THE MENTION-AS-DECLARATION TRAP ON ITS FIRST RUN, WHICH IS THE FOURTH
 * TIME THIS REPOSITORY HAS SHIPPED IT AND THE FIRST TIME INSIDE THE GATE MEANT TO CATCH IT. A cell
 * reading "this cell read <the in-flight token> until it was corrected" is a RECORD of a repair, and
 * a substring test over the whole cell reads that record as a fresh claim — so the gate demanded a
 * citation from the one row that had already been fixed.
 *
 * Every cell in this corpus opens with its verdict in a bold span. That span is the declaration; the
 * prose after it is commentary, and commentary is entitled to quote anything. A cell with no bold
 * span declares in the clear, so the opening runs of text stand in.
 */
function status_declaration(string $status): string
{
    if (preg_match('/\*\*(.+?)\*\*/s', $status, $m) === 1) {
        return $m[1];
    }

    return substr($status, 0, 120);
}

function is_generated_line(string $line): bool
{
    foreach (GENERATED_LINE_PREFIXES as $prefix) {
        if (str_starts_with($line, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * The held-topic stop-list, read out of the driver rather than restated here.
 *
 * ⚠️ RESOLVED FROM THE FILE, NEVER COPIED. A second copy of this list is the defect the whole
 * increment is about: the same list has already existed in three places and drifted.
 *
 * @return list<string>
 */
function held_topics(): array
{
    $body = read_or_die(LOOP);

    if (preg_match('/const HELD_TOPICS = \[(.*?)\];/s', $body, $m) !== 1) {
        cannot_measure('no held-topic list could be parsed out of '.LOOP.'. It is the other half of '
            .'a two-way check, and half a two-way check passes for free.');
    }

    preg_match_all("/'([^']+)'/", $m[1], $found);

    return $found[1];
}

/** @return list<string> */
function dictionary_documents(): array
{
    $found = [];

    foreach (glob('docs/*.md') ?: [] as $path) {
        if (str_contains((string) file_get_contents($path), DICTIONARY_HEADER)) {
            $found[] = str_replace('\\', '/', $path);
        }
    }

    sort($found);

    return $found;
}

/**
 * Parse one document into its column rows.
 *
 * The table name comes from the nearest preceding heading that names one, which is the convention
 * `tests/Feature/Migrations/DocumentedDefaultDriftTest.php` already established over this same
 * corpus. Split on the newline character only: the pattern class for a line break matches a byte
 * that occurs inside common UTF-8 characters, and these documents are full of them.
 *
 * @return array{tables: int, cells: list<array{doc: string, line: int, table: string, column: string}>}
 */
function dictionary_parse(string $path): array
{
    $table = null;
    $inTable = false;
    $tables = 0;
    $cells = [];

    foreach (explode("\n", (string) file_get_contents($path)) as $i => $line) {
        $line = rtrim($line, "\r");

        if (str_starts_with($line, '## ') || str_starts_with($line, '### ')) {
            $inTable = false;

            if (preg_match('/`([a-z][a-z0-9_]*)`/', $line, $m) === 1) {
                $table = $m[1];
            }

            continue;
        }

        if ($line === DICTIONARY_HEADER) {
            $inTable = true;
            $tables++;

            continue;
        }

        if (! $inTable) {
            continue;
        }

        // ⛔ THE SEPARATOR TEST MUST PRECEDE THE ROW TEST. A separator row does not open with a space
        // after its bar, so testing the row shape first ends every table on its own separator and
        // yields ZERO rows from 39 tables — which reads as a clean short list rather than an error.
        if (str_starts_with($line, '|---') || str_starts_with($line, '| ---')) {
            continue;
        }

        if (! str_starts_with($line, '| ')) {
            $inTable = false;

            continue;
        }

        $parts = array_map('trim', explode('|', trim($line, '|')));

        if (count($parts) < 6 || $table === null) {
            continue;
        }

        if (preg_match('/^`([a-z][a-z0-9_]*)`$/', $parts[0], $m) !== 1) {
            continue;
        }

        $cells[] = ['doc' => $path, 'line' => $i + 1, 'table' => $table, 'column' => $m[1]];
    }

    return ['tables' => $tables, 'cells' => $cells];
}

/**
 * The code corpus a column may be used in.
 *
 * ⚠️ WIDER THAN THE APP TREE, MEASURED RATHER THAN ASSUMED. Restricting this to the app tree reports
 * 41 dormant columns; widening it reports 22. Nineteen of those were used somewhere the design pass
 * never looked, and every one would have been filed as a defect.
 *
 * @return list<string>
 */
function app_corpus(): array
{
    $out = [];

    foreach (['app', 'resources', 'routes', 'config', 'database/seeders', 'database/factories', 'tests', 'packages'] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (preg_match('#/(vendor|node_modules|storage|\.git|public/build|coverage)/#', $path) === 1) {
                continue;
            }

            if (preg_match('/\.(php|vue|ts|js)$/', $path) === 1) {
                $out[] = $path;
            }
        }
    }

    sort($out);

    return $out;
}

/**
 * Is this line a DECLARATION of the column rather than a use of it?
 *
 * ⛔ FOUR SHAPES, AND A PASS THAT KNEW ONLY THE FIRST FOUND SEVEN DORMANT COLUMNS WHERE THERE ARE
 * FORTY-ONE. Under-collecting here is the blind direction: it reports a dormant column as live and
 * the gate goes green over exactly the defect it was built for.
 */
function is_declaration_line(string $line, string $column): bool
{
    $trimmed = ltrim($line);

    // A comment, in every syntax this corpus uses. A document explaining a dormant column must name
    // it, so reading prose as a use lets the gate clear itself with its own warning.
    if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
        || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')
        || str_starts_with($trimmed, '<!--')) {
        return true;
    }

    $quoted = preg_quote("'".$column."'", '/');

    // A fillable/guarded/hidden entry: the name standing alone.
    if (preg_match('/^'.$quoted.'\s*,?$/', $trimmed) === 1) {
        return true;
    }

    // ⛔ A CAST IS RECOGNISED BY ITS VALUE, NEVER BY ITS SHAPE, AND THE FIRST DRAFT OF THIS LINE
    // REPORTED TEN LIVE COLUMNS AS DORMANT. A cast entry and an array-literal WRITE are the same
    // bytes at line level — `'x' => 'datetime',` against `'x' => Carbon::now(),` — so a pattern
    // accepting any right-hand side swallows every write in the codebase and calls the column dead.
    // Two of the ten were being written one frame from where the gate said nothing touched them.
    // The cast vocabulary is closed and short; an expression is not in it.
    if (preg_match(
        '/^'.$quoted.'\s*=>\s*(\'(datetime|immutable_datetime|date|timestamp|boolean|bool|integer|'
        .'int|float|double|string|array|object|collection|json|hashed|encrypted[a-z:]*|decimal:\d+)\''
        .'|[A-Z][A-Za-z0-9_\\\\]*::class)\s*,?$/',
        $trimmed
    ) === 1) {
        return true;
    }

    // A column inventory: the name inside a long run of space-separated identifiers.
    return preg_match('/[\'"][a-z0-9_]+(\s+[a-z0-9_]+){4,}[\'"]/', $trimmed) === 1;
}

function column_is_used(string $column, array $corpus): bool
{
    foreach ($corpus as $path) {
        // The schema files are the declaration site by definition.
        if (str_contains($path, 'database/migrations/')) {
            continue;
        }

        $body = (string) file_get_contents($path);

        if (! str_contains($body, $column)) {
            continue;
        }

        foreach (explode("\n", $body) as $line) {
            if (! str_contains($line, $column) || is_declaration_line($line, $column)) {
                continue;
            }

            return true;
        }
    }

    return false;
}

function concat_files(array $paths): string
{
    $out = '';

    foreach ($paths as $path) {
        $out .= (string) file_get_contents($path);
    }

    return $out;
}

function strip_emphasis(string $text): string
{
    return trim(str_replace(['**', '*', '`'], '', $text));
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════
// Plumbing
// ═══════════════════════════════════════════════════════════════════════════════════════════════

function sh(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command.' 2>&1', $output, $status);

    return implode("\n", $output);
}

function read_or_die(string $path): string
{
    if (! is_file($path)) {
        cannot_measure($path.' does not exist, so the rule that reads it would pass vacuously.');
    }

    return (string) file_get_contents($path);
}

function fail(string $rule, string $message): void
{
    global $failures;

    $failures[] = $rule;
    fwrite(STDERR, "pipeline-lint: [FAIL] {$rule} — {$message}\n");
}

function pass(string $rule, string $message): void
{
    global $verbose;

    if ($verbose) {
        fwrite(STDOUT, "pipeline-lint: [ok]   {$rule} — {$message}\n");
    }
}

function cannot_measure(string $why): never
{
    fwrite(STDERR, "pipeline-lint: CANNOT MEASURE — {$why}\n");

    exit(2);
}
