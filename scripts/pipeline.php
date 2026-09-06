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
| ⚠️ THE ORDER IS READINESS AND DEPENDENCY, NOT PRIORITY, and the difference is the same one
| `docs/backlog-triage.md` states about itself. It says what can be STARTED. It cannot say what
| matters most, and nothing here should be read as saying so.
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
const MARKER_KEYS = ['id', 'title', 'phase', 'state', 'size', 'blocker', 'done'];

const STATES = ['ready', 'blocked', 'held', 'done', 'n/a'];
const SIZES = ['S', 'M', 'L', 'XL'];

/**
 * Floors. Each fails as CANNOT MEASURE rather than as a short list.
 *
 * `controller-gate` once reported `passed` while scanning 49 of 97 files, and the container's
 * iterator was pinned at 87 of 114 migrations — a scan that goes blind reports a small number, not
 * an error, unless something asserts the floor.
 */
const MIN_SCANNED_FILES = 40;
const MIN_DEFECT_ROWS = 50;

$flags = ['dry-run', 'check', 'json', 'help'];
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
        '  php scripts/pipeline.php --help       this text',
        '',
        'A plan item enters by a marker at its point of truth, at column 0:',
        '  <!-- pipeline: id=<slug> title="<what it is>" phase=<0-4|n/a> state=<ready|blocked|held|done|n/a> size=<S|M|L|XL> -->',
        'A non-ready state must name a blocker. A done state must cite where it landed.',
        '',
        'Exit 0 = written, clean, or printed. 1 = drift or a refused argument. 2 = could not measure.',
        '',
    ])."\n");

    exit(0);
}

$dryRun = isset($opts['dry-run']);
$check = isset($opts['check']);
$json = isset($opts['json']);

// ---------------------------------------------------------------------------------------------
// Measurement
// ---------------------------------------------------------------------------------------------

$scan = scan_markers();
$markers = $scan['markers'];

if ($scan['files'] < MIN_SCANNED_FILES) {
    cannot_measure(sprintf(
        'the marker scan reached only %d file(s), under the floor of %d. A directory walk that has '
        .'gone blind returns a SHORT LIST rather than an error, so this refuses instead of writing '
        .'a pipeline missing whatever it could not see.',
        $scan['files'],
        MIN_SCANNED_FILES
    ));
}

$defects = read_defects();

if (count($defects) < MIN_DEFECT_ROWS) {
    cannot_measure(sprintf(
        'backlog-triage.php --json returned %d open row(s), under the floor of %d. An empty or short '
        .'ranking reads as a finished queue.',
        count($defects),
        MIN_DEFECT_ROWS
    ));
}

$rows = array_merge($markers, $defects);
usort($rows, 'compare_pipeline_rows');

$open = array_values(array_filter($rows, static fn (array $r): bool => ! in_array($r['state'], ['done', 'n/a'], true)));

$sha = trunk_sha();
$body = render_body($open, $rows, $scan);
$document = render_banner($sha, $open).$body;

assert_not_self_arming($document);

// ---------------------------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------------------------

if ($json) {
    fwrite(STDOUT, json_encode([
        'sha' => $sha,
        'files_scanned' => $scan['files'],
        'counts' => census($open),
        'rows' => $open,
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
    'pipeline: wrote %s from the tree at %s — %d row(s) in the line (%d ready, %d blocked, %d held), '
    ."%d plan marker(s) over %d file(s).\n",
    PIPELINE,
    substr($sha, 0, 7),
    count($open),
    $counts['ready'],
    $counts['blocked'],
    $counts['held'],
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
 * @return array{markers: list<array<string, mixed>>, files: int}
 */
function scan_markers(): array
{
    $markers = [];
    $files = 0;

    foreach (corpus() as $path) {
        $files++;
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

    return ['markers' => $markers, 'files' => $files];
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

    foreach (['id', 'title', 'phase', 'state', 'size'] as $required) {
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

    return $fields + ['blocker' => '', 'done' => ''];
}

/**
 * The defect ledger, in the order `backlog-triage.php` already computed. Never re-sorted here.
 *
 * @return list<array<string, mixed>>
 */
function read_defects(): array
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

    $out = [];

    foreach ($decoded['open'] as $index => $row) {
        $out[] = [
            'id' => $row['id'],
            'class' => 'defect',
            'state' => $row['liveness'] === 'live' ? 'ready' : 'blocked',
            'blocker' => $row['liveness'] === 'live'
                ? ''
                : sprintf('precondition: row is %s', $row['liveness']),
            'phase' => 'n/a',
            'size' => '',
            'done' => '',
            'headline' => $row['headline'],
            'source' => BACKLOG.':'.$row['line'],
            'rank' => $index,
        ];
    }

    return $out;
}

/**
 * ⚠️ Readiness first, then plan work before defect debt. That second key is a DECISION, not a
 * derivation: ordering defects first would rebuild the burial this file exists to end, because the
 * defect ledger is large and self-feeding while the plan items are few and invisible.
 */
function compare_pipeline_rows(array $a, array $b): int
{
    $readiness = ['ready' => 0, 'blocked' => 1, 'held' => 2, 'done' => 3, 'n/a' => 4];
    $classRank = ['plan' => 0, 'defect' => 1];

    return [$readiness[$a['state']], $classRank[$a['class']], phase_ordinal($a), $a['rank'] ?? 0, $a['id']]
        <=> [$readiness[$b['state']], $classRank[$b['class']], phase_ordinal($b), $b['rank'] ?? 0, $b['id']];
}

function phase_ordinal(array $row): int
{
    return is_numeric($row['phase']) ? (int) $row['phase'] : 99;
}

/**
 * @return array<string, int>
 */
function census(array $open): array
{
    $out = ['ready' => 0, 'blocked' => 0, 'held' => 0, 'plan' => 0, 'defect' => 0];

    foreach ($open as $row) {
        $out[$row['state']] = ($out[$row['state']] ?? 0) + 1;
        $out[$row['class']]++;
    }

    return $out;
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
            .'%d blocked · %d held · %d from the plan · %d from the defect ledger',
            date('Y-m-d'),
            $sha,
            count($open),
            $counts['ready'],
            $counts['blocked'],
            $counts['held'],
            $counts['plan'],
            $counts['defect']
        ),
        '',
        '⚠️ **This is a READINESS order, not a priority order.** It says what can be *started*. It',
        'cannot say what matters most, and nothing here should be read as saying so.',
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

function render_body(array $open, array $all, array $scan): string
{
    $out = "## The line\n\n";
    $out .= "| # | id | Task | Source | Phase | State | Blocker | Size |\n";
    $out .= "|---|---|---|---|---|---|---|---|\n";

    foreach ($open as $i => $row) {
        $out .= sprintf(
            "| %d | `%s` | %s | `%s` | %s | %s | %s | %s |\n",
            $i + 1,
            $row['id'],
            cell($row['headline'] ?? $row['title']),
            $row['source'],
            $row['phase'] === 'n/a' ? '—' : $row['phase'],
            $row['state'],
            $row['blocker'] === '' ? '—' : cell($row['blocker']),
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
        "Scanned **%d file(s)**. A plan obligation with no marker at its point of truth is invisible\n"
        ."here — that is what `scripts/pipeline-lint.php` exists to make impossible, and until it\n"
        ."lands this file is a floor rather than a census.\n",
        $scan['files']
    );

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
    $at = strpos($disk, '## The line');

    if ($at === false) {
        fwrite(STDERR, 'pipeline: '.PIPELINE." has no derived body to compare.\n");

        return 1;
    }

    if (substr($disk, $at) === $body) {
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
