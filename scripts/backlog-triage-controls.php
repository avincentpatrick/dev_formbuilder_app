<?php

declare(strict_types=1);

/*
 * Positive-control harness for scripts/backlog-triage.php's citation harvester (M108).
 *
 * WHY THIS EXISTS. `scripts/backlog-triage.php` had NO coverage of any kind — no Pest file, no host
 * control, nothing — and it is the ODD ONE OUT among its siblings: `pipeline-lint.php` has
 * `tests/Feature/Docs/PipelineLintControlsTest.php`, `mutate.php` has `MutateHarnessTest.php`,
 * `tracker-surgery.php` has `TrackerSurgeryHarnessTest.php`, `gate-baselines.php` has
 * `GateBaselinesTest.php`, `tracker-lint.php` has `tracker-lint-controls.php`. This script is the
 * one that derives the hub set `D13`'s whole grouping rule is computed from, and nothing measured it.
 *
 * ⛔ WHY A HOST SCRIPT AND NOT A tests/Feature/Docs/ PEST FILE. `backlog-triage.php` resolves the
 * trunk ref with `git rev-parse origin/main` unconditionally, BEFORE the `--json` branch, and **git
 * is not installed in the app container**. A Pest control would answer CANNOT MEASURE locally while
 * passing in CI. `citation-liveness-lint-controls.php`, `tracker-lint-controls.php` and
 * `pre-push-guard-controls.php` each refused that trade for the same reason; this is the fourth
 * instance and the same answer.
 *
 * ⛔ THE COST IS STATED RATHER THAN HIDDEN: `scripts/mutate.php` CANNOT DRIVE THIS. It runs Pest in a
 * container and nothing else. These controls are proved the way the other three were — by running
 * them against a DELIBERATELY REVERTED harvester and comparing the verdicts by hand. C1 is the
 * discriminator: against the pre-M108 harvester it exits 1. Recorded in the increment's release.
 *
 * HOW IT WORKS. It writes one throwaway git repository, copies the SHIPPED BYTES of the script into
 * <fixture>/scripts/, and runs it there with `--json`. The script takes its root from
 * `chdir(dirname(__DIR__))` of its own location, so the copy makes the fixture the repository root
 * and no `--root=` surface has to be added to the script under test. `scripts/state.php` is a STUB
 * printing whatever row census the case wants — the same seam `PipelineLintControlsTest` stubs the
 * generator at, applied one layer down.
 *
 * ⚠️ THE FIXTURE IS SYNTHETIC. A harness keyed to the live tree would change its own arithmetic every
 * time an increment edited a document, and would go red on ordinary work rather than on a defect.
 *
 * ⚠️ WHAT THIS PROVES AND WHAT IT DOES NOT. It proves the harvester resolves what it should and
 * refuses what it should. It proves NOTHING about whether the hub set that falls out is the right
 * input to `D13` — that question is `D15`'s and is open.
 *
 * Exit 0 = every case landed where it should. Exit 1 = a case landed elsewhere. Exit 2 = the harness
 * could not measure, which is never a pass.
 */

const GATE = 'scripts/backlog-triage.php';

$root = str_replace('\\', '/', dirname(__DIR__));
$gateBytes = @file_get_contents($root.'/'.GATE);

if ($gateBytes === false || trim((string) $gateBytes) === '') {
    cannot_measure(GATE.' is missing or empty, so there is nothing to control.');
}

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/m108-backlog-triage-'.getmypid();

if (is_dir($fixture)) {
    rrmdir($fixture);
}

build_fixture($fixture, (string) $gateBytes);

$failures = [];
$ran = 0;

/*
 * C1 — THE DISCRIMINATOR. A row that names its subject as a class contributes that class's file.
 * Against the pre-M108 harvester this case exits 1, because `candidate_tokens()` required a file
 * extension and a class name has none. This is the whole defect `R-7849e303` filed.
 */
check($failures, $ran, 'C1 a class citation is harvested',
    fn (): array => harvest($fixture, '`WidgetRenderService::draw()` drops a frame.'),
    ['app/Services/WidgetRenderService.php'], []);

/*
 * C2 — A FULLY-QUALIFIED NAME RESOLVES TOO, and it is the case the separator normalisation could
 * have eaten: `candidate_tokens()` rewrites the namespace separator to a forward slash before its
 * own regex runs, so an FQCN arrives looking like a path that has no extension.
 */
check($failures, $ran, 'C2 a fully-qualified name is harvested',
    fn (): array => harvest($fixture, 'See `App\Services\WidgetRenderService` for the shape.'),
    ['app/Services/WidgetRenderService.php'], []);

/*
 * C3 — THE ARM THAT MUST NOT HAVE BEEN DISARMED, and the reason the predicate has three conjuncts
 * rather than one. `Form` and `Report` are ordinary English AND real class basenames; `M74` is an
 * increment id; `DROP_LIMIT` is a constant. A harvester that credited any of them would make the row
 * touch files it never cited — and `Form` is itself a hub, so that row would become unbatchable.
 *
 * ⛔ WITHOUT THIS CASE, C1 CANNOT BE DISTINGUISHED FROM "everything StudlyCase is harvested".
 */
check($failures, $ran, 'C3 prose words, ids and constants are NOT harvested',
    fn (): array => harvest($fixture, 'The `Form` and the `Report` were filed by `M74` under `DROP_LIMIT`.'),
    [], []);

/*
 * C4 — THE FILE MUST ACTUALLY DECLARE THE NAME. `app/Services/GhostOfAService.php` exists and
 * declares something else, so a multi-word phrase that happens to match a basename is refused by
 * reading the FILE rather than the token. The two guards fail independently, which is the point.
 */
check($failures, $ran, 'C4 a basename whose file declares something else is refused',
    fn (): array => harvest($fixture, 'Nothing here really uses `GhostOfAService` at all.'),
    [], []);

/*
 * C5 — AN AMBIGUOUS BASENAME IS NEVER GUESSED. Two files in the fixture are named
 * `DuplicateService.php`, so the basename index holds two hits and `resolve_token()` must return
 * null. This is the "guessing is the worse failure" invariant, which had no test.
 */
check($failures, $ran, 'C5 an ambiguous class basename resolves to nothing',
    fn (): array => harvest($fixture, 'Both copies of `DuplicateService` disagree.'),
    [], []);

/*
 * C6 — THE CLASS ARM NEVER WRITES TO `unresolved`. A class-shaped word naming no file must leave the
 * unresolved list alone, because `citation_health()` reads it and ranks on it — so a stray
 * `PostgreSQL` in prose would otherwise re-order the queue for a word nobody cited. This is what
 * makes the widening monotonic rather than a scheduling change in disguise.
 */
check($failures, $ran, 'C6 an unknown class name does not pollute the unresolved list',
    fn (): array => harvest($fixture, 'Tuned for `PostgreSQL` and `ElasticSearch` throughput.'),
    [], []);

/*
 * C7 — THE PATH ARM STILL WORKS, both directions. A real path resolves and a mistyped one is
 * recorded as unresolved rather than dropped. If the class arm had broken the path arm, every case
 * above could still pass.
 */
check($failures, $ran, 'C7 the path arm still resolves and still reports a miss',
    fn (): array => harvest($fixture, 'See `app/Services/WidgetRenderService.php` and `app/Nope/Missing.php`.'),
    ['app/Services/WidgetRenderService.php'], ['app/Nope/Missing.php']);

/*
 * C8 — A NESTED CHECKOUT IS NOT PART OF THIS REPOSITORY. `M99` measured a `.kilo/worktrees/<name>/`
 * taking the hub set from 49 files to 37 and moving 69 rows' path lists, because every basename
 * became ambiguous. The guard is one line — a directory carrying its own `.git` is skipped — and it
 * had no test. Here the nested copy would make `WidgetRenderService` ambiguous and C1 would break.
 */
check($failures, $ran, 'C8 a nested checkout does not make every basename ambiguous',
    function () use ($fixture): array {
        $nested = $fixture.'/.nested/worktree';
        mkdir($nested.'/app/Services', 0o777, true);
        file_put_contents($nested.'/.git', "gitdir: elsewhere\n");
        file_put_contents(
            $nested.'/app/Services/WidgetRenderService.php',
            "<?php\n\nclass WidgetRenderService {}\n"
        );

        try {
            return harvest($fixture, '`WidgetRenderService::draw()` drops a frame.');
        } finally {
            rrmdir($fixture.'/.nested');
        }
    },
    ['app/Services/WidgetRenderService.php'], []);

// ---------------------------------------------------------------------------------------------

rrmdir($fixture);

if ($failures !== []) {
    fwrite(STDERR, sprintf(
        "backlog-triage-controls: FAILED — %d of %d case(s) landed elsewhere:\n  %s\n",
        count($failures),
        $ran,
        implode("\n  ", $failures)
    ));

    exit(1);
}

fwrite(STDOUT, sprintf("backlog-triage-controls: passed (%d cases, all landing where they should).\n", $ran));

exit(0);

// ---------------------------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------------------------

/**
 * Run the harvester over one synthetic row body and return what it resolved.
 *
 * @return array{resolved: list<string>, unresolved: list<string>}
 */
function harvest(string $fixture, string $body): array
{
    file_put_contents($fixture.'/docs/feature-backlog.md', backlog_with($body));

    $status = 0;
    $output = [];
    exec(
        escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/scripts/backlog-triage.php').' --json 2>&1',
        $output,
        $status
    );

    $raw = implode("\n", $output);

    if ($status !== 0) {
        cannot_measure("the script under test exited {$status} on --json:\n".$raw);
    }

    $decoded = json_decode($raw, true);

    if (! is_array($decoded) || ! isset($decoded['open'][0])) {
        cannot_measure("the script under test did not return an open row:\n".$raw);
    }

    $row = $decoded['open'][0];

    return [
        'resolved' => array_values((array) ($row['paths'] ?? [])),
        'unresolved' => array_values((array) ($row['unresolved'] ?? [])),
    ];
}

/**
 * ⛔ IT COMPARES SETS, NOT SUBSTRINGS. A case asserting only "contains the path" would pass while the
 * harvester also credited five files nobody cited, which is the over-collection half.
 *
 * @param  list<string>  $failures
 * @param  callable(): array{resolved: list<string>, unresolved: list<string>}  $run
 * @param  list<string>  $resolved
 * @param  list<string>  $unresolved
 */
function check(array &$failures, int &$ran, string $name, callable $run, array $resolved, array $unresolved): void
{
    $ran++;
    $got = $run();

    sort($got['resolved']);
    sort($got['unresolved']);
    sort($resolved);
    sort($unresolved);

    if ($got['resolved'] === $resolved && $got['unresolved'] === $unresolved) {
        fwrite(STDOUT, "backlog-triage-controls: [ok]   {$name}\n");

        return;
    }

    $failures[] = sprintf(
        '%s — resolved expected [%s] got [%s]; unresolved expected [%s] got [%s]',
        $name,
        implode(', ', $resolved),
        implode(', ', $got['resolved']),
        implode(', ', $unresolved),
        implode(', ', $got['unresolved'])
    );

    fwrite(STDOUT, "backlog-triage-controls: [FAIL] {$name}\n");
}

/** One open `minor` row whose body is whatever the case is measuring. */
function backlog_with(string $body): string
{
    return "# Fixture backlog\n\n## Open\n\n- **`minor` · A fixture row.** ".$body
        ."\n  **Live.** Filed by `M1`. **Tier: after-launch.**\n";
}

function build_fixture(string $fixture, string $gateBytes): void
{
    foreach (['scripts', 'docs', 'app/Services', 'app/Duplicated'] as $dir) {
        mkdir($fixture.'/'.$dir, 0o777, true);
    }

    file_put_contents($fixture.'/'.GATE, $gateBytes);
    file_put_contents($fixture.'/scripts/state.php', state_stub());

    // The subject of C1, C2 and C8.
    file_put_contents(
        $fixture.'/app/Services/WidgetRenderService.php',
        "<?php\n\nnamespace App\\Services;\n\nclass WidgetRenderService\n{\n}\n"
    );

    // C4 — the basename matches, the declaration does not.
    file_put_contents(
        $fixture.'/app/Services/GhostOfAService.php',
        "<?php\n\nnamespace App\\Services;\n\nclass SomethingElseEntirely\n{\n}\n"
    );

    // C3 — real files behind the prose words, so the case proves the PREDICATE refuses them rather
    // than the index simply not holding them.
    file_put_contents($fixture.'/app/Services/Form.php', "<?php\n\nclass Form\n{\n}\n");
    file_put_contents($fixture.'/app/Services/Report.php', "<?php\n\nclass Report\n{\n}\n");

    // C5 — two files, one basename.
    file_put_contents($fixture.'/app/Services/DuplicateService.php', "<?php\n\nclass DuplicateService\n{\n}\n");
    file_put_contents($fixture.'/app/Duplicated/DuplicateService.php', "<?php\n\nclass DuplicateService\n{\n}\n");

    file_put_contents($fixture.'/docs/feature-backlog.md', backlog_with('Nothing cited.'));
    file_put_contents($fixture.'/docs/backlog-triage.md', "# Triage\n\nPlaceholder.\n");
    file_put_contents($fixture.'/docs/backlog-triage-m37.md', "# Frozen census\n\nPlaceholder.\n");

    // The script resolves `origin/main` before it does anything else, so the fixture needs a repo
    // with that ref. This is the whole reason these controls cannot be a Pest file.
    run_in($fixture, 'git init --quiet');
    run_in($fixture, 'git config user.email controls@example.invalid');
    run_in($fixture, 'git config user.name Controls');
    run_in($fixture, 'git add -A');
    run_in($fixture, 'git commit --quiet -m fixture --no-gpg-sign');
    run_in($fixture, 'git update-ref refs/remotes/origin/main HEAD');
}

/**
 * A stub census naming one open row at the fixture backlog's bullet line.
 *
 * ⛔ THE SHAPE IS THE CONTRACT, AND IT IS COPIED FROM `scripts/state.php --json --no-rows --offline`
 * rather than invented. If that contract changes, this stub stops matching and these controls answer
 * CANNOT MEASURE — which is the correct failure, and better than a harness that keeps passing over a
 * census the real script would no longer understand.
 */
function state_stub(): string
{
    $row = [
        'id' => 'R-fixture1',
        'severity' => 'minor',
        'title' => 'A fixture row.',
        'line' => 5,
        'provenance' => 'M1',
        'provenance_recorded' => true,
        'state' => 'open',
        'closed_by' => null,
        'liveness' => 'live',
        'tier' => 'after-launch',
        'awaits' => null,
    ];

    $payload = [
        'backlog' => [
            'open' => 1,
            'by_severity' => ['major' => 0, 'minor' => 1, 'nit' => 0],
            'ever_by_severity' => ['major' => 0, 'minor' => 1, 'nit' => 0],
            'severity_bullets' => 1,
            'total_bullets' => 1,
            'severity_rows' => [$row],
            'source' => 'stub',
        ],
        'decisions' => ['open' => [], 'answered' => [], 'open_rows' => []],
    ];

    return "<?php\n\nfwrite(STDOUT, ".var_export(json_encode($payload), true).");\n";
}

function run_in(string $dir, string $command): void
{
    $status = 0;
    $output = [];
    exec('cd '.escapeshellarg($dir).' && '.$command.' 2>&1', $output, $status);

    if ($status !== 0) {
        cannot_measure("could not prepare the fixture: {$command}\n".implode("\n", $output));
    }
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
        $path = $dir.'/'.$entry;
        is_dir($path) && ! is_link($path) ? rrmdir($path) : @chmod($path, 0o666) && @unlink($path);
    }

    @rmdir($dir);
}

function cannot_measure(string $why): never
{
    fwrite(STDERR, "backlog-triage-controls: CANNOT MEASURE — {$why}\n");

    exit(2);
}
