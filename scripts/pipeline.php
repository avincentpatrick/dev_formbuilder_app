<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The one ordered pipeline — every remaining task, in a single line (M79).
|--------------------------------------------------------------------------
| This project kept two lists and nothing linked them. `docs/PRD.md` and PROGRESS.md's roadmap say
| what the product should be; `docs/feature-backlog.md` and its generated triage say what the next
| increment works on. `scripts/state.php` and `scripts/backlog-triage.php` have never opened the PRD.
| So a plan item with no hand-written queue row was DOCUMENTED AND UNSCHEDULED AT THE SAME TIME, and
| invisible to every session that asked "what is next". That produced five separate realignments,
| each caught by a human audit and none by a gate.
|
| ⛔ THIS FILE IS GENERATED. Hand-editing it is a defect, not a shortcut. The census this project
| replaced went 110 commits stale with its top three ranked items all closed, which is why
| `docs/backlog-triage.md`, `docs/gate-baselines.md` and the hand-off line are all generated too.
|
| ⛔ A HELD ROW IS UNSCHEDULED, NOT INVISIBLE. It sits in the line, in its position, with its blocker
| named. The rule it replaces — "progress is computed over BUILDABLE scope only, a held row is not a
| denominator" — is the mechanism behind all five realignments, and the user overrode it directly:
| "we will never finish this project if we hide or exclude tasks in the main pipeline."
|
| HOW A PLAN ITEM ENTERS. A human writes the fact at its POINT OF TRUTH, in a constrained form; this
| script does every aggregation, ordering and count. The constrained form is a line-anchored HTML
| comment placed immediately after the sentence it governs:
|
|     <!-- pipeline: id=gdpr-subject-export title="Build the Article 15 export" phase=4 state=ready size=L -->
|
| ⚠️ POSITIONAL, NOT NATURAL LANGUAGE, AND THE REASON IS MEASURED. A declaration and a QUOTATION of a
| declaration are the same bytes — the failure behind `f565ac9`'s 1,086-line deletion, behind R7
| reading a mention of its own marker as a declaration, and behind `state.php`'s first draft going red
| on its own claim. A marker must therefore be at column 0 to arm, so a document that needs to
| DISCUSS the grammar indents it or fences it, which deliberately does not arm.
|
| ⚠️ AND THIS FILE MAY NOT ARM ITSELF. The generated document quotes markers by necessity; every one
| it emits is indented. `assert_not_self_arming()` refuses to write output that would be harvested on
| the next run — the fourth occurrence of this repository's signature failure.
|
| ⛔ THE ORDER IS TIER FIRST, THEN READINESS (M93). Until M93 it was readiness alone, and this header
| said so in terms — "it cannot say what matters most". That was true, and it was the problem: the line
| grew from 109 rows to 173 in eight days while nothing in it said which rows a testing server needed.
| A tier is the user's priority, written at each row's point of truth; within a tier the order still
| says only what can be STARTED. An open decision is a row too, blocked on the user by name, and a row
| whose remaining work IS that decision is blocked on it rather than published as ready.
|
| ⚠️ IT REPORTS THAT A VERDICT IS RECORDED, NEVER THAT IT IS RIGHT. A marker saying `state=ready` on
| work that is actually blocked passes everything here, exactly as a backlog row marked `live` whose
| defect is dead passes `BacklogProvenanceTest`. Overselling this would be the defect it exists to end.
|
| NOTHING IS RE-PARSED. The chain is state.php -> backlog-triage.php --json -> here, each deriving
| from the one below it. The defect ranking is consumed as an ARRAY INDEX and never sorted a second
| time: `scripts/loop.php` recorded what a second parser costs when its first draft hand-rolled one.
|
| Usage:
|   php scripts/pipeline.php              # regenerate docs/pipeline.md
|   php scripts/pipeline.php --dry-run    # print it, write nothing
|   php scripts/pipeline.php --check      # exit 1 if the file on disk has drifted from the tree
|   php scripts/pipeline.php --json       # the ordered rows, for a machine
|   php scripts/pipeline.php --help       # this text
|
| ⛔ AN UNRECOGNISED FLAG IS REFUSED, NOT IGNORED, and the default action writes a committed file.
|
| Exit 0 = written, clean, or printed. 1 = a --check drift, or a refused argument. 2 = COULD NOT
| MEASURE — never a silent pass. Every floor below fails as 2, because a scan matching almost nothing
| reports a short list rather than an error, and this repository has shipped that four times.
*/

chdir(dirname(__DIR__));

const PIPELINE = 'docs/pipeline.md';
const TRACKER = 'PROGRESS.md';
const BACKLOG = 'docs/feature-backlog.md';
const DECISIONS = 'docs/claims/decisions.md';
const LOOP = 'scripts/loop.php';

/**
 * Files that are LEDGERS or generated artefacts: they quote obligations rather than owning them, so
 * a marker found in one would be a copy of a fact that lives elsewhere.
 */
const EXCLUDED = [
    'docs/pipeline.md',
    'docs/feature-backlog.md',
    'docs/backlog-triage.md',
    'docs/backlog-triage-m37.md',
    'PROGRESS_ARCHIVE.md',
];

const EXCLUDED_DIRS = ['docs/claims', '.git', 'vendor', 'node_modules', 'storage'];

/** Every key a marker may carry. An unknown key is a failure, never a shrug. */
const MARKER_KEYS = ['id', 'title', 'phase', 'state', 'size', 'blocker', 'done', 'tier', 'decision'];

const STATES = ['ready', 'blocked', 'held', 'done', 'n/a'];
const SIZES = ['S', 'M', 'L', 'XL'];

/**
 * The marker id that records that the user has been told the app is ready for a testing server (M99).
 *
 * ⛔ IT IS AN ORDINARY `done` MARKER AT ITS POINT OF TRUTH, not a new mechanism — the grammar already
 * had `done` and `done=`. What it buys is that the zero-gate EVENT leaves a record: `testing_gate()`
 * finds it by this id, `render_testing_gate()` publishes the answer on the `Notified:` line, and the
 * two imperative copies in `scripts/state.php` and `scripts/next.php` key on that line.
 *
 * ⚠️ ITS HOME IS `PROGRESS.md` AND IT CANNOT BE ANYWHERE ELSE. `CLAUDE.md` sits outside the corpus
 * walk (measured at 56 files against this walk's 55, the extra being CLAUDE.md), and `docs/claims`,
 * `docs/pipeline.md`, `docs/feature-backlog.md` and `PROGRESS_ARCHIVE.md` are all EXCLUDED above —
 * which is exactly why lane-a.md's and PROGRESS.md's prose records of the M95 notification were
 * invisible to every generator for four increments.
 */
const NOTIFIED_MARKER_ID = 'testing-server-notified';

/**
 * The tier vocabulary (M93) — closed, and ordered most urgent first. The same list lives in
 * `scripts/state.php`, `scripts/pipeline-lint.php` and BacklogProvenanceTest, and
 * PipelineLintControlsTest pins every copy equal and in the same order.
 *
 * ⚠️ `tier=` IS REQUIRED ON A MARKER BUT ITS VALUE IS NOT VALIDATED HERE, deliberately: a bad value is
 * carried into the document so `pipeline-lint` P7c can refuse it by name. A value outside this list
 * sorts as untiered, after every tier.
 * ⚠️ No name may contain "upload": `scripts/loop.php`'s HELD_TOPICS matches that substring.
 */
const TIERS = ['before-testing', 'early-testing', 'during-testing', 'before-launch', 'after-launch'];

/**
 * How many ready rows, then blocked rows, the Next section names from its tier.
 *
 * ⛔ THE DEFECT WAS THE WORD *SILENTLY*, NOT THE NUMBER (M99). At 5 and 8 this section hid three of
 * eight ready `early-testing` rows and 19 of 27 blocked ones, and said nothing — so `CLAUDE.md` and
 * the generated hand-off both point every session at a list that is a strict subset of its own tier,
 * and two of the three hidden rows were the cleanest in it. `render_next()` now states what it left
 * out, so a raised cap is a comfort rather than the correctness argument: **a cut that announces
 * itself cannot mislead, and a cut that does not cannot be trusted at any size.**
 */
const NEXT_READY = 12;
const NEXT_BLOCKED = 12;

/**
 * Floors. Each fails as CANNOT MEASURE rather than as a short list.
 *
 * `controller-gate` once reported `passed` while scanning 49 of 97 files, and the container's
 * iterator was pinned at 87 of 114 migrations — a scan that goes blind reports a small number, not
 * an error, unless something asserts the floor.
 */
const MIN_SCANNED_FILES = 40;
const MIN_DEFECT_ROWS = 50;

$flags = ['dry-run', 'check', 'json', 'corpus', 'help'];
$opts = getopt('', $flags);

foreach (array_slice($argv, 1) as $argument) {
    if (! in_array(ltrim($argument, '-'), $flags, true)) {
        fwrite(STDERR, sprintf(
            'pipeline: unrecognised argument %s. Known: --%s. Refusing rather than falling through '
            .'to the default, which rewrites %s.'."\n",
            $argument,
            implode(' --', $flags),
            PIPELINE
        ));

        exit(1);
    }
}

if (isset($opts['help'])) {
    fwrite(STDOUT, implode("\n", [
        'pipeline — regenerate '.PIPELINE.', the one ordered line of remaining work.',
        '',
        '  php scripts/pipeline.php              rewrite '.PIPELINE,
        '  php scripts/pipeline.php --dry-run    print it, write nothing',
        '  php scripts/pipeline.php --check      exit 1 if the file on disk has drifted',
        '  php scripts/pipeline.php --json       the ordered rows, for a machine',
        '  php scripts/pipeline.php --corpus     the files a marker may live in, one per line',
        '  php scripts/pipeline.php --help       this text',
        '',
        'A plan item enters by a marker at its point of truth, at column 0:',
        '  <!-- pipeline: id=<slug> title="<what it is>" phase=<0-4|n/a> state=<ready|blocked|held|done|n/a> size=<S|M|L|XL> tier=<'.implode('|', TIERS).'> [decision=D<n>] -->',
        'A non-ready state must name a blocker. A done state must cite where it landed. A held, done or n/a',
        'marker may not name a decision.',
        '',
        'Exit 0 = written, clean, or printed. 1 = drift or a refused argument. 2 = could not measure.',
        '',
    ])."\n");

    exit(0);
}

$dryRun = isset($opts['dry-run']);
$check = isset($opts['check']);
$json = isset($opts['json']);
$corpusOnly = isset($opts['corpus']);

// ---------------------------------------------------------------------------------------------
// Measurement
// ---------------------------------------------------------------------------------------------

$scan = scan_markers();
$markers = $scan['markers'];

// ⛔ TWO MARKERS MAY NOT CARRY ONE ID (M99, closing R-964bc5a4). An id is what every consumer joins
//    on — `pipeline-lint`'s stop-list, the roadmap cross-check, `loop.php`'s held-topic refusal — and
//    a duplicate produced two rows in the line that no reader could tell apart, with the second
//    silently deciding what the first meant wherever a lookup took the last match. The scan reports
//    every offender and the files they sit in rather than the first, because a copy-paste of a marker
//    block makes several at once.
$seen = [];

foreach ($markers as $marker) {
    $seen[(string) $marker['id']][] = $marker['source'] ?? '(unknown)';
}

$duplicates = array_filter($seen, static fn (array $where): bool => count($where) > 1);

if ($duplicates !== []) {
    cannot_measure(sprintf(
        'the marker scan found %d id(s) declared more than once: %s. An id is what every consumer '
        .'joins on, so a duplicate puts two rows in the line that no reader can tell apart.',
        count($duplicates),
        implode('; ', array_map(
            static fn (string $id, array $where): string => $id.' at '.implode(' and ', $where),
            array_keys($duplicates),
            $duplicates
        ))
    ));
}

if ($scan['files'] < MIN_SCANNED_FILES) {
    cannot_measure(sprintf(
        'the marker scan reached only %d file(s), under the floor of %d. A directory walk that has '
        .'gone blind returns a SHORT LIST rather than an error, so this refuses instead of writing '
        .'a pipeline missing whatever it could not see.',
        $scan['files'],
        MIN_SCANNED_FILES
    ));
}

// ⚠️ `--corpus` ANSWERS WITHOUT THE LEDGER, AND THAT IS THE WHOLE REASON IT EXISTS. `--json` needs
// the defect ranking and the trunk sha, so it cannot run where there is no git remote — which is
// exactly where `tests/Feature/Docs/PipelineLintControlsTest.php` runs. The corpus is a property of
// the TREE rather than of the queue, and a consumer that needs only the file list should not have to
// buy the rest. It sits AFTER the floor above, so a walk that has gone blind still refuses.
if ($corpusOnly) {
    // ⚠️ chr(10) rather than the escape, and rather than PHP_EOL. The escape is written here as a
    // character code because this host's tool layer collapses a doubled backslash, which turned the
    // first draft of this line into a literal newline inside a string — valid PHP, and Pint caught it.
    // PHP_EOL was the second draft and is worse: it makes a MACHINE-READABLE list platform-dependent.
    fwrite(STDOUT, implode(chr(10), $scan['paths']).chr(10));

    exit(0);
}

$triage = read_triage();
$defects = read_defects($triage);

if (count($defects) < MIN_DEFECT_ROWS) {
    cannot_measure(sprintf(
        'backlog-triage.php --json returned %d open row(s), under the floor of %d. An empty or short '
        .'ranking reads as a finished queue.',
        count($defects),
        MIN_DEFECT_ROWS
    ));
}

// ⛔ A ROW AWAITING AN OPEN DECISION IS BLOCKED ON IT BY NAME (M93). This is the defect `R-f0525946`
//    measured: a row whose only remaining work was a question handed to the user was published `ready`,
//    because state came from liveness alone. The decisions themselves join the line as rows.
$openDecisions = (array) ($triage['decisions']['open'] ?? []);
$rows = array_map(
    static fn (array $row): array => apply_decision_override($row, $openDecisions),
    array_merge($markers, $defects)
);
$rows = array_merge($rows, read_decisions($triage));
usort($rows, 'compare_pipeline_rows');

$open = array_values(array_filter($rows, static fn (array $r): bool => ! in_array($r['state'], ['done', 'n/a'], true)));

// ⛔ THE GATE COUNTS OVER A WIDER SET THAN THE LINE DOES, AND THE TWO ARE KEPT APART ON PURPOSE (M99).
//    `testing_gate()`'s docblock promises "0 open of N" where N is every row the tier has ever held.
//    It was handed open rows alone, so a finished tier read "0 open of 1" — the arithmetic was right
//    and the input was a third of the question. The closed rows join HERE, for the denominator, and
//    never join `$rows`, which is what the line and the "Off the line" table are rendered from.
$gate = testing_gate(array_merge($rows, read_closed_defects($triage)));

$sha = trunk_sha();
$body = render_body($open, $rows, $scan, $gate);
$document = render_banner($sha, $open).$body;

assert_not_self_arming($document);

// ---------------------------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------------------------

if ($json) {
    // ⚠️ `off_the_line` CARRIES THE done AND n/a ROWS, AND IT IS NOT A CONVENIENCE. Without it a
    // consumer cannot tell a FINISHED id from a MISTYPED one — both are simply absent from `rows` —
    // and `scripts/pipeline-lint.php` P3 has to tell exactly those two apart to judge whether a
    // roadmap row claiming work in flight is telling the truth. This adds a key and touches no
    // rendered byte, so `--check` is unaffected.
    // ⛔ `corpus` IS THE FILE LIST ITSELF, AND IT IS THE ANSWER TO M81's FORWARD CAUTION. The
    // coverage rules in `scripts/pipeline-lint.php` must rule over the same files a marker could be
    // READ from, or the two disagree in the one direction that makes the gate useless: a rule
    // demanding a disposition in a file this walk never opens asks for a fact no marker can carry.
    // Publishing the list is what makes the two corpora ONE DEFINITION rather than two that agree
    // today. Measured while writing it: a hand-rolled reproduction of `corpus()` in the gate reached
    // 56 markdown files against this walk's 55 — the extra was `CLAUDE.md`, which is deliberately
    // outside the walk and in which a marker would be invisible.
    fwrite(STDOUT, json_encode([
        'sha' => $sha,
        'files_scanned' => $scan['files'],
        'corpus' => $scan['paths'],
        'counts' => census($open),
        'testing_gate' => $gate,
        'rows' => $open,
        'off_the_line' => array_values(array_filter(
            $rows,
            static fn (array $r): bool => in_array($r['state'], ['done', 'n/a'], true)
        )),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

    exit(0);
}

if ($check) {
    exit(run_check($body));
}

if ($dryRun) {
    fwrite(STDOUT, $document);

    exit(0);
}

file_put_contents(PIPELINE, $document);
$counts = census($open);
fwrite(STDOUT, sprintf(
    'pipeline: wrote %s from the tree at %s — %d row(s) in the line (%d ready, %d blocked, %d held, '
    ."%d decision(s)), %d plan marker(s) over %d file(s).\n",
    PIPELINE,
    substr($sha, 0, 7),
    count($open),
    $counts['ready'],
    $counts['blocked'],
    $counts['held'],
    $counts['decision'],
    count($markers),
    $scan['files']
));

exit(0);

// ---------------------------------------------------------------------------------------------
// Measurement helpers
// ---------------------------------------------------------------------------------------------

/**
 * Walk the corpus for line-anchored markers.
 *
 * ⚠️ The paths come back with the markers, and `files` is derived from THAT LIST rather than counted
 * alongside it. A separately-incremented counter is a second measurement of one thing, and this file
 * exists because two copies of a fact drift apart.
 *
 * @return array{markers: list<array<string, mixed>>, files: int, paths: list<string>}
 */
function scan_markers(): array
{
    $markers = [];
    $paths = corpus();

    foreach ($paths as $path) {
        $lines = explode("\n", (string) file_get_contents($path));

        foreach ($lines as $i => $line) {
            if (! str_starts_with($line, '<!-- pipeline:')) {
                continue;
            }

            $markers[] = parse_marker($line, $path, $i + 1) + [
                'class' => 'plan',
                'source' => $path.':'.($i + 1),
            ];
        }
    }

    return ['markers' => $markers, 'files' => count($paths), 'paths' => $paths];
}

/**
 * The files a marker may live in. Ledgers and generated artefacts are excluded: they QUOTE
 * obligations rather than owning them, and a marker in one would be a second copy of a fact.
 *
 * @return list<string>
 */
function corpus(): array
{
    $out = [TRACKER];

    foreach (['docs', 'app'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! preg_match('/\.(md|php)$/', $path)) {
                continue;
            }

            if (in_array($path, EXCLUDED, true)) {
                continue;
            }

            foreach (EXCLUDED_DIRS as $dir) {
                if (str_starts_with($path, $dir.'/')) {
                    continue 2;
                }
            }

            $out[] = $path;
        }
    }

    sort($out);

    return $out;
}

/**
 * @return array<string, mixed>
 */
function parse_marker(string $line, string $path, int $lineNo): array
{
    $inner = trim(substr($line, strlen('<!-- pipeline:')));
    $inner = trim(rtrim($inner, '>'), '- ');

    $fields = [];
    preg_match_all('/([a-z]+)=("[^"]*"|\S+)/', $inner, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $key = $match[1];

        if (! in_array($key, MARKER_KEYS, true)) {
            cannot_measure(sprintf(
                'unknown key "%s" in the pipeline marker at %s:%d. Known keys: %s. An unknown key is '
                .'refused rather than ignored, because a typo would otherwise drop a field silently.',
                $key,
                $path,
                $lineNo,
                implode(', ', MARKER_KEYS)
            ));
        }

        $fields[$key] = trim($match[2], '"');
    }

    foreach (['id', 'title', 'phase', 'state', 'size', 'tier'] as $required) {
        if (! isset($fields[$required])) {
            cannot_measure(sprintf(
                'the pipeline marker at %s:%d is missing the required key "%s".',
                $path,
                $lineNo,
                $required
            ));
        }
    }

    if (! in_array($fields['state'], STATES, true)) {
        cannot_measure(sprintf(
            'the marker at %s:%d declares state "%s"; the vocabulary is %s.',
            $path,
            $lineNo,
            $fields['state'],
            implode(', ', STATES)
        ));
    }

    if (! in_array($fields['size'], SIZES, true)) {
        cannot_measure(sprintf(
            'the marker at %s:%d declares size "%s"; the vocabulary is %s.',
            $path,
            $lineNo,
            $fields['size'],
            implode(', ', SIZES)
        ));
    }

    // ⛔ A `done` MARKER MUST CITE WHERE IT LANDED (M99, closing R-974db618). `--help` has promised
    //    this since the key existed — "a done state must cite where it landed" — and nothing asked for
    //    it, so the promise was documentation of a rule that was never enforced. A done row with no
    //    citation is the shape that lets a marker claim work is finished with nothing to check it
    //    against, and the testing-server notification marker this increment adds is exactly the kind
    //    of claim that must never be unattributable: it is what stops the notification being sent
    //    twice, so "who did it and when" is the whole of its value.
    if ($fields['state'] === 'done' && trim((string) ($fields['done'] ?? '')) === '') {
        cannot_measure(sprintf(
            'the marker at %s:%d is state "done" and cites nothing in `done=`. --help has promised '
            .'that a done state cites where it landed since the key existed; an unattributable done '
            .'row asserts that work is finished and offers nothing to check it against.',
            $path,
            $lineNo
        ));
    }

    // A non-ready row that does not say WHY is the invisibility this file exists to end.
    if (! in_array($fields['state'], ['ready', 'done'], true) && ($fields['blocker'] ?? '') === '') {
        cannot_measure(sprintf(
            'the marker at %s:%d is state "%s" and names no blocker. A held or blocked row without a '
            .'stated blocker is exactly the invisibility this pipeline exists to remove.',
            $path,
            $lineNo,
            $fields['state']
        ));
    }

    // ⛔ A HELD, DONE OR N/A MARKER MAY NOT ALSO AWAIT A DECISION (M93). The override only ever turns a
    //    ready or blocked row into a blocked one, so the key would be dead there — and a reader would take
    //    it as saying held work is merely waiting on an answer, which is the distinction P4 exists to keep.
    if (($fields['decision'] ?? '') !== '' && in_array($fields['state'], ['held', 'done', 'n/a'], true)) {
        cannot_measure(sprintf(
            'the marker at %s:%d is state "%s" and also names decision=%s. Only a ready or blocked marker '
            .'can await a decision; the state it declares already says why it is not work.',
            $path,
            $lineNo,
            $fields['state'],
            $fields['decision']
        ));
    }

    $fields += ['blocker' => '', 'done' => '', 'decision' => ''];
    $fields['awaits'] = $fields['decision'] === '' ? null : $fields['decision'];

    return $fields;
}

/**
 * The triage document, read ONCE. The defects and the decisions both come out of it, so a second shell-out
 * would re-run `state.php`, git and the directory walk for nothing.
 *
 * @return array<string, mixed>
 */
function read_triage(): array
{
    $raw = sh('php scripts/backlog-triage.php --json', $status);

    if ($status !== 0) {
        cannot_measure('backlog-triage.php --json exited '.$status.'. The defect half of the pipeline '
            .'cannot be derived, and a pipeline missing it would read as a much shorter queue.');
    }

    $decoded = json_decode($raw, true);

    if (! is_array($decoded) || ! isset($decoded['open'])) {
        cannot_measure('backlog-triage.php --json did not return an object with an "open" key.');
    }

    return $decoded;
}

/**
 * The defect ledger, in the order `backlog-triage.php` already computed. Never re-sorted here.
 *
 * @param  array<string, mixed>  $triage
 * @return list<array<string, mixed>>
 */
function read_defects(array $triage): array
{
    $out = [];

    foreach ($triage['open'] as $index => $row) {
        $liveness = $row['liveness'] ?? null;

        $out[] = [
            'id' => $row['id'],
            'class' => 'defect',
            'state' => $liveness === 'live' ? 'ready' : 'blocked',
            'blocker' => $liveness === 'live'
                ? ''
                : sprintf('precondition: row is %s', $liveness ?? 'unmarked'),
            'phase' => 'n/a',
            'size' => '',
            'done' => '',
            'tier' => $row['tier'] ?? null,
            'awaits' => $row['awaits'] ?? null,
            'decision' => '',
            'headline' => $row['headline'] ?? '',
            'source' => BACKLOG.':'.$row['line'],
            'rank' => $index,
        ];
    }

    return $out;
}

/**
 * The CLOSED ledger rows, as `done` rows — for the testing gate's denominator and for nothing else (M99).
 *
 * ⛔ THESE NEVER JOIN `$rows`, AND THAT IS DELIBERATE. `render_body()` prints every `done` row into the
 * "Off the line" table, so merging 204 closed ledger rows into the line would add 204 rows to a document
 * whose job is to show what is LEFT. They are handed to `testing_gate()` alone, which counts a tier's
 * total and needs to see the rows that tier has already finished.
 *
 * ⚠️ AND THEY ARE MAPPED TO `done` HERE RATHER THAN INHERITED. `read_defects()` above maps liveness to
 * state — live to ready, anything else to blocked — and a closed row still carries a liveness marker
 * (a struck row can read "Live"), so a closed row passed through THAT mapping would publish as READY
 * WORK. The state comes from being closed, never from the marker.
 *
 * @param  array<string, mixed>  $triage
 * @return list<array<string, mixed>>
 */
function read_closed_defects(array $triage): array
{
    $out = [];

    foreach ((array) ($triage['closed'] ?? []) as $row) {
        $out[] = [
            'id' => $row['id'],
            'class' => 'defect',
            'state' => 'done',
            'blocker' => '',
            'phase' => 'n/a',
            'size' => '',
            'done' => (string) ($row['closed_by'] ?? ''),
            'tier' => $row['tier'] ?? null,
            'awaits' => null,
            'decision' => '',
            'headline' => '',
            'source' => BACKLOG.':'.$row['line'],
            'rank' => PHP_INT_MAX,
        ];
    }

    return $out;
}

/**
 * One row per OPEN decision (M93), passed through the triage document from `state.php`'s parse.
 *
 * ⚠️ THE SOURCE CARRIES NO LINE NUMBER, AND THAT IS THE POINT. `docs/claims/decisions.md` sits inside
 * `ci.yml`'s `paths-ignore`, so recording an answer moves every heading below it without producing a run
 * — and this file is citation tier 1 with zero tolerance. A `path:N` here would rot silently on the first
 * answer. The heading id is stable for the life of the decision.
 *
 * @param  array<string, mixed>  $triage
 * @return list<array<string, mixed>>
 */
function read_decisions(array $triage): array
{
    $out = [];

    foreach ((array) ($triage['decisions']['open_rows'] ?? []) as $index => $decision) {
        $id = (string) ($decision['id'] ?? '');

        $out[] = [
            'id' => $id,
            'class' => 'decision',
            'state' => 'blocked',
            'blocker' => 'user: answer '.$id.' on the Decision Board',
            'phase' => 'n/a',
            'size' => '',
            'done' => '',
            'tier' => $decision['tier'] ?? null,
            'awaits' => null,
            'decision' => $id,
            'title' => (string) ($decision['title'] ?? ''),
            'source' => DECISIONS.'#'.$id,
            'rank' => $index,
        ];
    }

    return $out;
}

/**
 * Blocked on a decision by name — but only a row that was ready or blocked in the first place.
 *
 * ⛔ HELD, DONE AND N/A WIN. Turning a held row into a blocked one would drop it under
 * `pipeline-lint`'s held floor and out of P4's two-way coverage, and would tell a reader that held work
 * is merely waiting on an answer. An awaits naming an answered or unknown decision changes nothing
 * here; P7e refuses it by name instead.
 *
 * @param  array<string, mixed>  $row
 * @param  list<string>  $openDecisions
 * @return array<string, mixed>
 */
function apply_decision_override(array $row, array $openDecisions): array
{
    $awaits = (string) ($row['awaits'] ?? '');

    if ($awaits === '' || ! in_array($row['state'], ['ready', 'blocked'], true) || ! in_array($awaits, $openDecisions, true)) {
        return $row;
    }

    $row['state'] = 'blocked';
    $row['blocker'] = 'decision: '.$awaits;

    return $row;
}

/**
 * ⛔ TIER FIRST (M93), THEN READINESS, THEN PLAN WORK BEFORE DEFECT DEBT BEFORE QUESTIONS.
 *
 * The tier is the user's priority and the only key here that says what matters most. The class key is
 * still a DECISION, not a derivation: ordering defects first would rebuild the burial this file exists
 * to end, because the defect ledger is large and self-feeding while the plan items are few and invisible.
 *
 * ⚠️ EVERY LOOKUP HAS A DEFAULT. `pipeline-lint` shells this with stderr merged into the JSON it decodes,
 * so one undefined-index warning refuses the whole gate — and an untiered or mistyped row is ordinary.
 */
function compare_pipeline_rows(array $a, array $b): int
{
    $readiness = ['ready' => 0, 'blocked' => 1, 'held' => 2, 'done' => 3, 'n/a' => 4];
    $classRank = ['plan' => 0, 'defect' => 1, 'decision' => 2];

    return [tier_rank($a), $readiness[$a['state']] ?? 9, $classRank[$a['class']] ?? 9, phase_ordinal($a), $a['rank'] ?? 0, $a['id']]
        <=> [tier_rank($b), $readiness[$b['state']] ?? 9, $classRank[$b['class']] ?? 9, phase_ordinal($b), $b['rank'] ?? 0, $b['id']];
}

/** A tier's position, most urgent first; untiered and unknown values sort after every tier. */
function tier_rank(array $row): int
{
    $rank = array_search($row['tier'] ?? null, TIERS, true);

    return $rank === false ? count(TIERS) : (int) $rank;
}

function phase_ordinal(array $row): int
{
    return is_numeric($row['phase'] ?? null) ? (int) $row['phase'] : 99;
}

/**
 * @return array<string, int>
 */
function census(array $open): array
{
    $out = ['ready' => 0, 'blocked' => 0, 'held' => 0, 'plan' => 0, 'defect' => 0, 'decision' => 0, 'untiered' => 0]
        + array_fill_keys(TIERS, 0);

    foreach ($open as $row) {
        $tier = tier_rank($row) < count(TIERS) ? (string) $row['tier'] : 'untiered';

        $out[$row['state']] = ($out[$row['state']] ?? 0) + 1;
        $out[$row['class']] = ($out[$row['class']] ?? 0) + 1;
        $out[$tier] = ($out[$tier] ?? 0) + 1;
    }

    return $out;
}

/**
 * The before-testing gate, PINNED to the first tier rather than to whichever tier is currently open.
 *
 * ⛔ PINNED BECAUSE ITS ZERO IS AN EVENT. The user is told the app is ready for a testing server at the
 * moment this reads 0 — so it must keep reading the same tier after that tier empties, and never slide
 * on to the next one. Open counts ready and blocked work; decisions and held rows are listed beside it
 * and never block it, because both are the user's to move. Total counts every row the tier has ever
 * held, done and n/a included, so a finished tier reads "0 open of N" rather than "0 of 0".
 *
 * @param  list<array<string, mixed>>  $all  every row, done and n/a included
 * @return array{tier: string, open: int, total: int, waiting: list<string>, held: list<string>}
 */
function testing_gate(array $all): array
{
    $tier = TIERS[0];
    $inTier = array_values(array_filter($all, static fn (array $r): bool => ($r['tier'] ?? null) === $tier));
    $work = array_values(array_filter($inTier, static fn (array $r): bool => $r['class'] !== 'decision'));
    $ids = static fn (array $rows): array => array_values(array_map(static fn (array $r): string => (string) $r['id'], $rows));

    // ⛔ WHETHER THE USER HAS ALREADY BEEN TOLD IS A FACT IN THE TREE, NOT A MEMORY (M99). The zero
    //    is an EVENT, and an event that leaves no record fires forever: every `state.php` run and
    //    every generated hand-off went on ordering the next session to send a notification that M95
    //    sent on 2026-09-14, and PROGRESS.md carried that order on the trunk for four increments.
    //    The record is an ordinary `done` marker at its point of truth, found here by id.
    $notified = array_values(array_filter(
        $all,
        static fn (array $r): bool => (string) $r['id'] === NOTIFIED_MARKER_ID && $r['state'] === 'done'
    ));

    return [
        'tier' => $tier,
        'open' => count(array_filter($work, static fn (array $r): bool => in_array($r['state'], ['ready', 'blocked'], true))),
        'total' => count($work),
        'waiting' => $ids(array_filter($inTier, static fn (array $r): bool => $r['class'] === 'decision' && in_array($r['state'], ['ready', 'blocked'], true))),
        'held' => $ids(array_filter($work, static fn (array $r): bool => $r['state'] === 'held')),
        'notified' => $notified !== [],
        // ⚠️ THE CLAUSE, NOT THE WHOLE `done=` VALUE. A marker's `done=` carries the full reasoning —
        //    this one runs to several sentences — and the gate line is a one-line fact, so only the
        //    first clause is published. The rest stays at the marker, which is where it belongs.
        'notified_by' => $notified === [] ? '' : trim((string) strtok((string) ($notified[0]['done'] ?? ''), ';')),
    ];
}

/**
 * The most urgent tier that still has WORK in it, and that tier's rows — ready first, then blocked.
 *
 * ⚠️ HELD ROWS AND DECISIONS DO NOT MAKE A TIER OPEN. Both are the user's to move, so a tier holding only
 * those is skipped. An untiered row is reached only once every tier is empty of work.
 *
 * @param  list<array<string, mixed>>  $open  already sorted
 * @return array{tier: ?string, ready: list<array<string, mixed>>, blocked: list<array<string, mixed>>, decisions: list<array<string, mixed>>}
 */
function next_work(array $open): array
{
    $work = array_values(array_filter($open, static fn (array $r): bool => $r['state'] !== 'held' && $r['class'] !== 'decision'));

    if ($work === []) {
        return ['tier' => null, 'ready' => [], 'blocked' => [], 'decisions' => []];
    }

    $rank = min(array_map('tier_rank', $work));
    $inTier = static fn (array $r): bool => tier_rank($r) === $rank;

    $ready = array_values(array_filter($work, static fn (array $r): bool => $inTier($r) && $r['state'] === 'ready'));
    $blocked = array_values(array_filter($work, static fn (array $r): bool => $inTier($r) && $r['state'] === 'blocked'));

    return [
        'tier' => $rank < count(TIERS) ? TIERS[$rank] : 'untiered',
        'ready' => array_slice($ready, 0, NEXT_READY),
        'blocked' => array_slice($blocked, 0, NEXT_BLOCKED),
        // ⛔ THE UNTRUNCATED COUNTS RIDE ALONG SO THE CUT CAN BE STATED (M99). Without them
        //    `render_next()` cannot tell "this tier has five ready rows" from "this tier has five of
        //    eight ready rows", and neither could any reader of the document.
        'ready_total' => count($ready),
        'blocked_total' => count($blocked),
        'decisions' => array_values(array_filter($open, static fn (array $r): bool => $inTier($r) && $r['class'] === 'decision')),
    ];
}

/**
 * ⛔ The generated document must never be harvested by the next run. Every marker it prints is
 * indented; a marker at column 0 would make this file its own input.
 */
function assert_not_self_arming(string $document): void
{
    foreach (explode("\n", $document) as $i => $line) {
        if (str_starts_with($line, '<!-- pipeline:')) {
            cannot_measure(sprintf(
                'the generated document carries a line-start marker at line %d. It would be harvested '
                .'on the next run, making this file its own input.',
                $i + 1
            ));
        }
    }
}

function trunk_sha(): string
{
    $sha = trim(sh('git rev-parse origin/main', $status));

    if ($status !== 0 || $sha === '') {
        cannot_measure('git rev-parse origin/main failed; the provenance line would name no commit.');
    }

    return $sha;
}

// ---------------------------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------------------------

function render_banner(string $sha, array $open): string
{
    $counts = census($open);

    return implode("\n", [
        '# The pipeline',
        '',
        '**Generated by `scripts/pipeline.php`. Do not hand-edit — regenerate it.**',
        '',
        sprintf(
            '**Measured %s** against `origin/main` at `%s` · **%d row(s)** in the line — %d ready · '
            .'%d blocked · %d held · %d from the plan · %d from the defect ledger · %d open decision(s)',
            date('Y-m-d'),
            $sha,
            count($open),
            $counts['ready'],
            $counts['blocked'],
            $counts['held'],
            $counts['plan'],
            $counts['defect'],
            $counts['decision']
        ),
        '',
        '⛔ **The order is TIER first, then readiness.** A tier is the user\'s priority, written at each',
        'row\'s point of truth: '.implode(' → ', TIERS).'. Within a tier the order says only what can be',
        '*started*, and an untiered row sorts after every tier until it is given one.',
        '',
        '⛔ **A decision is a row.** It sits in the line `blocked`, waiting on the user, and a row whose',
        'remaining work IS that decision is blocked on it by name rather than published as ready.',
        '',
        '⛔ **A held row is unscheduled, not invisible.** It sits in the line, in its position, with its',
        'blocker named. Do not start one, and do not offer one as the next step — it becomes work only',
        'on the user\'s explicit signal. But it is counted, because excluding held work from the count',
        'is the mechanism that produced five separate realignments.',
        '',
        '⛔ **This file reports that a verdict is RECORDED, never that it is RIGHT.** A marker claiming',
        '`state=ready` on work that is actually blocked passes everything here.',
        '',
        '---',
        '',
    ]);
}

function render_body(array $open, array $all, array $scan, array $gate): string
{
    $out = render_testing_gate($gate);
    $out .= render_next(next_work($open));
    $out .= "\n## The line\n\n";
    $out .= "| # | id | Task | Tier | Source | Phase | State | Blocker | Size |\n";
    $out .= "|---|---|---|---|---|---|---|---|---|\n";

    foreach ($open as $i => $row) {
        $out .= sprintf(
            "| %d | `%s` | %s | %s | `%s` | %s | %s | %s | %s |\n",
            $i + 1,
            $row['id'],
            cell((string) ($row['headline'] ?? $row['title'] ?? '')),
            (string) ($row['tier'] ?? '') === '' ? '—' : cell((string) $row['tier']),
            $row['source'],
            ($row['phase'] ?? 'n/a') === 'n/a' ? '—' : $row['phase'],
            $row['state'],
            ($row['blocker'] ?? '') === '' ? '—' : cell((string) $row['blocker']),
            ($row['size'] ?? '') === '' ? '—' : $row['size']
        );
    }

    $done = array_values(array_filter($all, static fn (array $r): bool => $r['state'] === 'done'));
    $na = array_values(array_filter($all, static fn (array $r): bool => $r['state'] === 'n/a'));

    $out .= "\n## Off the line\n\n";
    $out .= sprintf(
        "%d row(s) recorded `done` and %d dispositioned `n/a`. They are not work and are listed here\n"
        ."rather than dropped, so that this file and its sources cannot disagree about what was decided.\n\n",
        count($done),
        count($na)
    );

    foreach (array_merge($done, $na) as $row) {
        $out .= sprintf("- `%s` — %s (`%s`)\n", $row['id'], $row['state'], $row['source']);
    }

    $out .= "\n## What this file cannot see\n\n";
    $out .= sprintf(
        "Scanned **%d file(s)**, and `scripts/pipeline-lint.php` gates this file on every push. It\n"
        ."refuses a hand edit, a roadmap phase claiming work in flight without naming a live row, a\n"
        ."second queue, a held row missing from either this line or the stop-list that guards unattended\n"
        ."work, a documented column that exists, is used by nothing and is scheduled nowhere, a row or\n"
        ."open decision carrying no tier or an unknown one, an open decision missing from the line (or a\n"
        ."decision row with no open decision behind it), and a row awaiting a decision that is answered\n"
        ."or does not exist.\n\n"
        ."⛔ **It is still a floor rather than a census.** The gate proves that what IS written down is\n"
        ."queued, tiered and consistent. It cannot prove that everything worth writing down has been —\n"
        ."which is why an open item found in any document is filed the moment it is found (`CLAUDE.md`).\n",
        $scan['files']
    );

    return $out;
}

/**
 * @param  array{tier: string, open: int, total: int, waiting: list<string>, held: list<string>}  $gate
 */
function render_testing_gate(array $gate): string
{
    $ids = static fn (array $list): string => $list === [] ? 'none' : '`'.implode('` · `', $list).'`';

    return "## Testing gate\n\n"
        .sprintf(
            "**Testing gate:** %s — %d open of %d · %d waiting on you · %d held\n\n",
            $gate['tier'],
            $gate['open'],
            $gate['total'],
            count($gate['waiting']),
            count($gate['held'])
        )
        .'Waiting on you: '.$ids($gate['waiting'])." — questions answered on the Decision Board. Shown, and never\n"
        ."blocking this count.\n"
        .'Held: '.$ids($gate['held'])." — unscheduled until the user signals, and never blocking this count either.\n"
        // ⛔ THIS LINE IS THE MACHINE-READABLE HALF, AND `scripts/state.php` PARSES IT (M99). The gate
        //    block is the only thing state.php reads to derive the gate — it never walks the corpus —
        //    so the notified fact has to be PUBLISHED here or no consumer can key on it.
        .sprintf(
            "Notified: %s\n\n",
            $gate['notified'] ?? false
                ? 'yes'.(($gate['notified_by'] ?? '') === '' ? '' : ', by '.$gate['notified_by'])
                    .'. Owed once, and already sent — no later session sends it again.'
                : 'no — owed by the session that closes the last open row in this tier, and by no other.'
        )
        // ⚠️ DELIBERATELY UNCONDITIONAL, AND RECORDED AS SUCH (M99, and the choice D52 asked for).
        //    This sentence and `scripts/loop.php`'s are RULE TEXT: they state when the obligation
        //    falls due, which is true on every run whatever the count. The IMPERATIVE copies — the
        //    ones that say *send it now* — are in `scripts/state.php` and `scripts/next.php`, and
        //    those are keyed on the Notified line above. Keying rule text would delete the rule.
        .'⛔ The session that closes the last open `'.$gate['tier']."` row tells the user the app is ready for a\n"
        ."testing server — a push notification and the Testing Server Checklist (`CLAUDE.md`).\n";
}

/**
 * @param  array{tier: ?string, ready: list<array<string, mixed>>, blocked: list<array<string, mixed>>, decisions: list<array<string, mixed>>}  $next
 */
function render_next(array $next): string
{
    $out = "\n## Next\n\n";

    if ($next['tier'] === null) {
        return $out."Nothing is open outside held rows and questions waiting on the user.\n";
    }

    $out .= 'From **'.$next['tier']."**, the most urgent tier with open work. Take work from here, and group\n"
        ."the rows of one tier under `D13`'s file-overlap rule — no two citing the same non-hub file, at most\n"
        ."one touching a hub.\n\n";

    foreach ($next['ready'] as $row) {
        $out .= sprintf("- `%s` — %s · ready\n", $row['id'], cell((string) ($row['headline'] ?? $row['title'] ?? '')));
    }

    foreach ($next['blocked'] as $row) {
        $out .= sprintf(
            "- `%s` — %s · blocked (%s)\n",
            $row['id'],
            cell((string) ($row['headline'] ?? $row['title'] ?? '')),
            cell((string) ($row['blocker'] ?? ''))
        );
    }

    foreach ($next['decisions'] as $row) {
        $out .= sprintf("- `%s` — %s · waiting on you\n", $row['id'], cell((string) ($row['title'] ?? '')));
    }

    // ⛔ A CUT THAT DOES NOT ANNOUNCE ITSELF IS THE DEFECT (M99). This section is what CLAUDE.md and
    //    the generated hand-off both point a session at, so a silent subset of a tier reads as the
    //    tier. Say what was left out, and say where the rest of it is.
    $hiddenReady = max(0, (int) ($next['ready_total'] ?? 0) - count($next['ready']));
    $hiddenBlocked = max(0, (int) ($next['blocked_total'] ?? 0) - count($next['blocked']));

    if ($hiddenReady > 0 || $hiddenBlocked > 0) {
        $out .= sprintf(
            "\n⚠️ **This section is CUT, and the rest of the tier is not shown here.** %d more ready and %d more\n"
            ."blocked `%s` row(s) are in **The line** below — read it rather than this section before\n"
            ."grouping a batch.\n",
            $hiddenReady,
            $hiddenBlocked,
            $next['tier']
        );
    }

    return $out;
}

function cell(string $text): string
{
    $text = str_replace(['|', "\n"], ['\\|', ' '], trim($text));

    return mb_strlen($text) > 150 ? mb_substr($text, 0, 149).'…' : $text;
}

/**
 * Compares the DERIVED BODY only. The provenance line names a commit, so comparing it would make
 * every subsequent commit read as drift — which is staleness, and a different question.
 */
function run_check(string $body): int
{
    if (! is_file(PIPELINE)) {
        fwrite(STDERR, 'pipeline: '.PIPELINE." does not exist yet. Generate it.\n");

        return 1;
    }

    $disk = (string) file_get_contents(PIPELINE);

    // ⚠️ ANCHORED ON A WHOLE HEADING LINE, never a substring. The banner is free to mention the gate,
    //    and a substring match would start the comparison inside the banner and read as drift forever.
    $at = strpos($disk, "\n## Testing gate\n");

    if ($at === false) {
        fwrite(STDERR, 'pipeline: '.PIPELINE." has no derived body to compare.\n");

        return 1;
    }

    if (substr($disk, $at + 1) === $body) {
        fwrite(STDOUT, 'pipeline: '.PIPELINE." is current.\n");

        return 0;
    }

    fwrite(STDERR, 'pipeline: '.PIPELINE." has DRIFTED from the tree. Regenerate it.\n");

    return 1;
}

// ---------------------------------------------------------------------------------------------
// Plumbing
// ---------------------------------------------------------------------------------------------

function sh(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command, $output, $status);

    return implode("\n", $output);
}

function cannot_measure(string $why): never
{
    fwrite(STDERR, "pipeline: CANNOT MEASURE — {$why}\n");

    exit(2);
}
