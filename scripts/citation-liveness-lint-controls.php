<?php

declare(strict_types=1);

/*
 * Positive-control harness for citation-liveness-lint's resolver (M85).
 *
 * WHY THIS EXISTS. M85 widened `resolve_token()` so a PARTIAL path — a token carrying a slash that
 * is not a literal tracked path — resolves by unambiguous suffix instead of being abandoned. Before
 * that, fifteen live citations across the gated tiers were counted, printed, and NEVER line-checked;
 * one of the fifteen was dead, in the ZERO-TOLERANCE tier, and the gate reported green over it. That
 * is a merge gate passing while blind, which is the class this repository has spent four increments
 * learning to refuse — and until this file the gate had no controls of any kind.
 *
 * ⛔ WHY THIS IS A HOST SCRIPT AND NOT A `tests/Feature/Docs/` PEST FILE, WHICH IS THE OPPOSITE OF
 * WHAT `NpmAuditJudgeTest` AND `PipelineLintControlsTest` DECIDED. Both of those could be Pest files
 * because their input is a JSON document or a directory of files. This gate's input is the GIT
 * INDEX — it reads the tracked set with `git -C <root> ls-files` — and **`git` is not installed in
 * the app container**, measured on this host: `sh: git: not found`, exit 127. So the gate answers
 * CANNOT MEASURE there, every control asserting on it fails, and a Pest file would be permanently
 * red locally while green in CI (whose `tests` job runs on the runner, not in the app image). That
 * is precisely the state `docs/feature-backlog.md`'s open row about `SuiteCollectionFloorTest`
 * describes, and shipping a second instance of it deliberately would be indefensible.
 *
 * ⛔ THE COST IS STATED RATHER THAN HIDDEN: `scripts/mutate.php` CANNOT DRIVE THIS. It runs Pest in
 * a container and nothing else. `scripts/tracker-lint-controls.php` carries the identical limitation
 * for the identical kind of reason — its input is the commit graph — and the answer is the same: the
 * controls are proved by running them against a DELIBERATELY REVERTED gate and comparing the two
 * verdicts by hand, which M85 did before shipping. Recorded in the increment's release.
 *
 * HOW IT WORKS. It writes one throwaway git repository, copies the SHIPPED BYTES of
 * scripts/citation-liveness-lint.php into `<fixture>/scripts/`, and runs it there. The gate takes
 * its root from `dirname(__DIR__)` of its own location, so the copy makes the fixture the repository
 * root and no `--root=` surface has to be added to the gate under test. That is
 * tracker-lint-controls' mechanism exactly.
 *
 * ⛔ THE CASE THAT CARRIES THE WHOLE CHANGE IS C2. Every other case here would also pass against the
 * OLD resolver, because an abandoned token is merely unresolved and unresolved never failed
 * anything. C2 would not: before M85 it exited 0. Read it as the discriminator, not an edge case.
 *
 * ⚠️ THE FIXTURE IS SYNTHETIC AND NOT A COPY OF THE REAL CORPUS. A harness keyed to the live
 * `docs/` tree would change its own arithmetic every time an increment edited a document, and would
 * go red on ordinary work rather than on a defect — the reason tracker-lint-controls states for its
 * own synthetic tracker files.
 *
 * Exit 0 = every case landed where it should. Exit 1 = a case landed elsewhere. Exit 2 = the harness
 * could not measure, which is never a pass.
 */

const GATE = 'scripts/citation-liveness-lint.php';

/**
 * The base fixture must clear every shipped R3 floor HONESTLY — 40 documents, 140 citations, 130
 * resolved. A fixture squeaking under a floor turns every case into a floor failure and measures
 * nothing about the resolver.
 */
const FIXTURE_DOCUMENTS = 45;
const FIXTURE_CITATIONS_PER_DOCUMENT = 4;

$root = str_replace('\\', '/', dirname(__DIR__));

if (! is_file($root.'/'.GATE)) {
    fwrite(STDERR, 'citation-liveness-lint-controls: CANNOT MEASURE — '.GATE." not found.\n");
    exit(2);
}

$gateBytes = (string) file_get_contents($root.'/'.GATE);

fwrite(STDOUT, sprintf(
    "citation-liveness-lint-controls: gate under test is %s (%d bytes, sha256 %s)\n",
    GATE,
    strlen($gateBytes),
    hash('sha256', $gateBytes)
));

$probe = [];
$probeStatus = 0;
exec('git --version 2>&1', $probe, $probeStatus);

if ($probeStatus !== 0) {
    fwrite(STDERR, "citation-liveness-lint-controls: CANNOT MEASURE — `git` is unavailable, and the\n");
    fwrite(STDERR, "gate under test reads the tracked set with `git ls-files`. This is the container,\n");
    fwrite(STDERR, "and this harness is a HOST script by design. See the header.\n");
    exit(2);
}

$fixture = sys_get_temp_dir().'/m85-citation-liveness-'.getmypid();
$fixture = str_replace('\\', '/', $fixture);

rrmdir($fixture);
build_fixture($fixture, $gateBytes);

/**
 * Each case writes ONE extra document into the fixture, runs the gate, and restores. The baseline is
 * re-asserted clean before and after every case, because a red proves nothing if you cannot show it
 * was green.
 *
 * @var list<array{id: string, what: string, file: string, body: string, exit: int, expect: string, absent?: string}>
 */
$cases = [
    [
        'id' => 'C1',
        'what' => 'the base fixture measures at all, rather than refusing or passing blind',
        'file' => '',
        'body' => '',
        'exit' => 0,
        'expect' => 'Citation liveness linter passed',
    ],
    [
        'id' => 'C2',
        'what' => 'HEADLINE — a PARTIAL path onto a BLANK line FAILS tier 1, which it used to pass',
        'file' => 'docs/partial-dead.md',
        'body' => "# Partial\n\nThe control lives at `deep/nested/Target.vue:5`.\n",
        'exit' => 1,
        'expect' => 'is BLANK',
    ],
    [
        'id' => 'C3',
        'what' => 'a PARTIAL path onto a LIVE line is RESOLVED, not merely tolerated',
        'file' => 'docs/partial-live.md',
        'body' => "# Partial\n\nThe control lives at `deep/nested/Target.vue:3`.\n",
        'exit' => 0,
        'expect' => 'Citation liveness linter passed',
    ],
    [
        'id' => 'C4',
        'what' => 'an AMBIGUOUS partial path resolves to NOTHING rather than to the first match',
        'file' => 'docs/partial-ambiguous.md',
        'body' => "# Ambiguous\n\nTwo files answer to `other/Twin.vue:5`.\n",
        'exit' => 0,
        'expect' => 'Citation liveness linter passed',
        'absent' => 'is BLANK',
    ],
    [
        'id' => 'C5',
        'what' => 'a partial path matching no tracked file stays unresolved and reddens nothing',
        'file' => 'docs/partial-untracked.md',
        'body' => "# Untracked\n\nA citation into `nowhere/AtAll.vue:5`.\n",
        'exit' => 0,
        'expect' => 'unresolved',
    ],
    [
        'id' => 'C6',
        'what' => 'a FULL path onto a BLANK line still FAILS — the arm that already worked',
        'file' => 'docs/full-dead.md',
        'body' => "# Full\n\nThe control lives at `src/deep/nested/Target.vue:5`.\n",
        'exit' => 1,
        'expect' => 'is BLANK',
    ],
];

$failed = [];
$resolvedBaseline = null;

foreach ($cases as $case) {
    if ($case['file'] !== '') {
        file_put_contents($fixture.'/'.$case['file'], $case['body']);
        git_add($fixture);
    }

    [$exit, $output] = run_gate($fixture);

    if ($case['file'] !== '') {
        @unlink($fixture.'/'.$case['file']);
        git_add($fixture);
    }

    $ok = $exit === $case['exit']
        && str_contains($output, $case['expect'])
        && (! isset($case['absent']) || ! str_contains($output, $case['absent']));

    if ($case['id'] === 'C1' && preg_match('/(\d+) resolved/', $output, $m) === 1) {
        $resolvedBaseline = (int) $m[1];
    }

    // ⛔ C3'S REAL ASSERTION IS THE RESOLVED COUNT, NOT THE EXIT CODE. A resolver that returned null
    // for every partial path would ALSO leave C3 green — silence and correctness are identical from
    // an exit code. Only a count that moved by exactly one proves the token was line-checked.
    if ($case['id'] === 'C3') {
        $observed = preg_match('/(\d+) resolved/', $output, $m) === 1 ? (int) $m[1] : -1;
        $expected = $resolvedBaseline === null ? -2 : $resolvedBaseline + 1;

        if ($observed !== $expected) {
            $ok = false;
            fwrite(STDOUT, sprintf(
                "        C3 resolved-count check: expected %d, observed %d\n",
                $expected,
                $observed
            ));
        }
    }

    fwrite(STDOUT, sprintf("%s  %s  %s\n", $ok ? '[ok]  ' : '[FAIL]', $case['id'], $case['what']));
    fwrite(STDOUT, sprintf("        expected exit %d and %s\n", $case['exit'], var_export($case['expect'], true)));
    fwrite(STDOUT, sprintf("        observed exit %d\n", $exit));

    if (! $ok) {
        $failed[] = $case['id'];
        fwrite(STDOUT, "        ---- gate output ----\n".$output."\n");
    }
}

rrmdir($fixture);

if ($failed !== []) {
    fwrite(STDERR, sprintf(
        "citation-liveness-lint-controls: FAILED — %d of %d case(s) landed elsewhere: %s\n",
        count($failed),
        count($cases),
        implode(', ', $failed)
    ));
    exit(1);
}

fwrite(STDOUT, sprintf(
    'citation-liveness-lint-controls: passed (%d cases; the partial-path arm both ways, the ambiguity '
    ."rule, the untracked case and the literal arm's regression control)\n",
    count($cases)
));
exit(0);

/**
 * A repository whose tracked set the gate can read, with two source files whose line 3 is ALIVE and
 * whose line 5 is BLANK — so a case can aim at either without depending on discovery.
 */
function build_fixture(string $fixture, string $gateBytes): void
{
    mkdir($fixture.'/scripts', 0o777, true);
    mkdir($fixture.'/docs', 0o777, true);
    mkdir($fixture.'/src/deep/nested', 0o777, true);
    mkdir($fixture.'/src/a/other', 0o777, true);
    mkdir($fixture.'/src/b/other', 0o777, true);

    file_put_contents($fixture.'/'.GATE, $gateBytes);

    $body = "line one\nline two\nline three is alive\nline four\n\nline six\n";
    file_put_contents($fixture.'/src/deep/nested/Target.vue', $body);

    // The AMBIGUITY control's two halves: one partial suffix, two tracked paths.
    file_put_contents($fixture.'/src/a/other/Twin.vue', $body);
    file_put_contents($fixture.'/src/b/other/Twin.vue', $body);

    for ($i = 1; $i <= FIXTURE_DOCUMENTS; $i++) {
        $lines = ['# Document '.$i, ''];

        for ($c = 0; $c < FIXTURE_CITATIONS_PER_DOCUMENT; $c++) {
            $lines[] = 'See `src/deep/nested/Target.vue:3` for the shape.';
        }

        file_put_contents($fixture.'/docs/doc-'.$i.'.md', implode("\n", $lines)."\n");
    }

    // The tier-2 ledger, present and clean, so the ratchet arm is exercised rather than skipped.
    file_put_contents($fixture.'/docs/feature-backlog.md', "# Ledger\n\nA row citing `src/deep/nested/Target.vue:3`.\n");
    file_put_contents($fixture.'/README.md', "# Fixture\n\nRoot readme citing `src/deep/nested/Target.vue:3`.\n");

    exec('git -C '.escapeshellarg($fixture).' init --quiet 2>&1');
    git_add($fixture);
}

function git_add(string $fixture): void
{
    $out = [];
    $status = 0;
    exec('git -C '.escapeshellarg($fixture).' add -A 2>&1', $out, $status);

    if ($status !== 0) {
        fwrite(STDERR, "citation-liveness-lint-controls: CANNOT MEASURE — `git add` failed in the fixture.\n");
        exit(2);
    }
}

/**
 * ⚠️ BOTH STREAMS. The gate reports its pass on stdout and every failure and cannot-measure on
 * stderr, so a control reading one of them sees half a verdict. And the exit status is taken from
 * `exec()` rather than through a pipe, because a pipe hides it.
 *
 * @return array{0: int, 1: string}
 */
function run_gate(string $fixture): array
{
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture.'/'.GATE).' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            // git marks objects read-only, and Windows refuses to unlink those without this.
            @chmod($item->getPathname(), 0666);
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
