<?php

declare(strict_types=1);

/*
 * Positive-control harness for scripts/boundary-check.php (M192, ADR-0024, D177).
 *
 * WHY A HOST SCRIPT. The gate reads the commit graph (`git show <base>:.github/CODEOWNERS`,
 * `git merge-base`, `git diff`), and git is not installed in the app container — the answer
 * `backlog-triage-controls.php` and three siblings gave for the same reason. `scripts/mutate.php`
 * drives Pest only, so these controls are proved red by a COMMITTED mutation of the gate and a
 * revert, recorded in the increment's claim.
 *
 * HOW IT WORKS. One throwaway git repository holds a base commit with a CODEOWNERS of the shipped
 * shape (synthetic owners `@meridian` and `@bhw`, so the logic is tested apart from who the real
 * owners are). Each case branches from the base, commits its change, and runs the SHIPPED BYTES of the
 * gate from the fixture's root with the label events given as a file. B0 to B0d alone read the real
 * `.github/CODEOWNERS`, so the map that ships is held to the decision in force: D177's as D211 amended it.
 * From D198 until the handover (bhw-handover, M213) jongsky25's lines were set aside. The synthetic map keeps
 * two owners on `.github/` and `deploy.ps1`, because that still exercises a path with two owners.
 *
 * M214 added S1 to S10 for `scripts/ci-scope.php`, the same shape of gate over the same commit graph: it
 * decides whether Meridian's CI jobs skip themselves, so a wrong "yes" is a green check that judged nothing.
 *
 * Exit 0 = every case landed where it should. Exit 1 = a case landed elsewhere. Exit 2 = the harness
 * could not measure, which is never a pass.
 */

const GATE = 'scripts/boundary-check.php';
const SCOPE_GATE = 'scripts/ci-scope.php';

$root = str_replace('\\', '/', dirname(__DIR__));
$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/m192-boundary-'.getmypid();
rrmdir($fixture);
mkdir($fixture.'/scripts', 0o777, true);

foreach ([GATE, SCOPE_GATE] as $gate) {
    $gateBytes = @file_get_contents($root.'/'.$gate);

    if ($gateBytes === false || trim($gateBytes) === '') {
        cannot_measure($gate.' is missing or empty.');
    }

    file_put_contents($fixture.'/'.$gate, $gateBytes);
}

$failures = [];
$ran = 0;

$base0 = build_base($fixture, null);

check($failures, $ran, 'B1 a base with no CODEOWNERS enforces nothing, and says so',
    run_case($fixture, $base0, 'bhw', ['app/Kernel.php' => 'changed'], []),
    0, 'not enforced');

$base = build_base($fixture, <<<'OWNERS'
# synthetic owners, the shipped shape
*                          @meridian
/apps/bhw-connect/         @bhw
/apps/bhw-connect/ops/     @bhw @meridian
/.github/                  @meridian @bhw
/deploy.ps1                @meridian @bhw
OWNERS);

check($failures, $ran, 'B2 BHW changing only BHW needs nobody',
    run_case($fixture, $base, 'bhw', ['apps/bhw-connect/lib/x.ts' => 'changed'], []),
    0, 'own side');

check($failures, $ran, 'B3 Meridian changing only Meridian needs nobody',
    run_case($fixture, $base, 'meridian', ['app/Kernel.php' => 'changed'], []),
    0, 'own side');

check($failures, $ran, 'B4 BHW changing a Meridian file without the label is refused',
    run_case($fixture, $base, 'bhw', ['app/Kernel.php' => 'changed'], []),
    1, '@meridian');

check($failures, $ran, 'B5 ...and passes once Meridian applies the label',
    run_case($fixture, $base, 'bhw', ['app/Kernel.php' => 'changed'], [labeled('meridian')]),
    0, 'approved by @meridian');

check($failures, $ran, 'B6 the author applying the label to their own pull request approves nothing',
    run_case($fixture, $base, 'bhw', ['app/Kernel.php' => 'changed'], [labeled('bhw')]),
    1, 'does not own them');

check($failures, $ran, 'B7 a label removed after it was applied approves nothing',
    run_case($fixture, $base, 'bhw', ['app/Kernel.php' => 'changed'], [labeled('meridian'), unlabeled('meridian')]),
    1, '@meridian');

check($failures, $ran, 'B8 a shared path changed by Meridian needs BHW\'s label (last matching rule wins)',
    run_case($fixture, $base, 'meridian', ['apps/bhw-connect/ops/deploy-bhw.ps1' => 'changed', '.github/workflows/ci.yml' => 'changed'], []),
    1, '@bhw');

check($failures, $ran, 'B9 a pull request cannot rewrite the map that judges it',
    run_case($fixture, $base, 'bhw', [
        'app/Kernel.php' => 'changed',
        '.github/CODEOWNERS' => "*  @bhw\n",
    ], []),
    1, '@meridian');

check($failures, $ran, 'B10 a destructive BHW migration needs Meridian\'s label',
    run_case($fixture, $base, 'bhw', ['apps/bhw-connect/supabase/migrations/29990101000000_drop.sql' => "-- bhw:destructive\nDROP TABLE t;\n"], []),
    1, '@meridian');

check($failures, $ran, 'B11 an additive BHW migration needs nobody',
    run_case($fixture, $base, 'bhw', ['apps/bhw-connect/supabase/migrations/29990101000001_add.sql' => "CREATE TABLE t (id int);\n"], []),
    0, 'own side');

check($failures, $ran, 'B12 a rename out of Meridian into apps/ shows both paths and is refused',
    run_case($fixture, $base, 'bhw', ['app/Kernel.php' => null, 'apps/bhw-connect/lib/Kernel.php' => 'base kernel'], []),
    1, 'app/Kernel.php');

// B13 and B14 (M214): a commit the clone does not hold is "cannot measure". The base's half used to read as a
// base with no CODEOWNERS, and passed.
$missing = str_repeat('0123456789', 4);
$realHead = commit_case($fixture, $base, ['app/Kernel.php' => 'changed']);

check($failures, $ran, 'B13 a base commit the clone does not hold cannot be measured, and never passes',
    run_gate($fixture, GATE, ['--author=bhw', '--base='.$missing, '--head='.$realHead]),
    2, 'CANNOT MEASURE');

check($failures, $ran, 'B14 a head commit the clone does not hold cannot be measured, and never passes',
    run_gate($fixture, GATE, ['--author=bhw', '--base='.$base, '--head='.$missing]),
    2, 'CANNOT MEASURE');

// S1 to S10 (M214): which pull requests may skip Meridian's jobs. A wrong "yes" is the dangerous direction, so
// nearly every case is a way of nearly being BHW-only.
check($failures, $ran, 'S1 a pull request changing only BHW Connect is BHW-only',
    run_scope_case($fixture, $base, ['apps/bhw-connect/lib/x.ts' => 'changed', 'apps/bhw-connect/docs/x.md' => 'changed']),
    0, 'bhw_only=true');

check($failures, $ran, 'S2 ...and one Meridian file among them is not',
    run_scope_case($fixture, $base, ['apps/bhw-connect/lib/x.ts' => 'changed', 'app/Kernel.php' => 'changed']),
    0, 'bhw_only=false', [], ['bhw_only=true']);

check($failures, $ran, 'S3 BHW\'s ops/ is shared, and a Meridian test reads it, so alone it is not BHW-only',
    run_scope_case($fixture, $base, ['apps/bhw-connect/ops/start.ps1' => 'changed']),
    0, 'bhw_only=false', [], ['bhw_only=true']);

check($failures, $ran, 'S4 ...and ops/ beside BHW\'s own files is not either, whichever sorts first',
    run_scope_case($fixture, $base, ['apps/bhw-connect/lib/x.ts' => 'changed', 'apps/bhw-connect/ops/deploy-bhw.ps1' => 'changed']),
    0, 'bhw_only=false', [], ['bhw_only=true']);

check($failures, $ran, 'S5 a rename out of Meridian into apps/ shows both paths and is not BHW-only',
    run_scope_case($fixture, $base, ['app/Kernel.php' => null, 'apps/bhw-connect/lib/Kernel.php' => 'base kernel']),
    0, 'bhw_only=false', [], ['bhw_only=true']);

check($failures, $ran, 'S6 a workflow change is not BHW-only',
    run_scope_case($fixture, $base, ['.github/workflows/ci.yml' => 'changed']),
    0, 'bhw_only=false', [], ['bhw_only=true']);

check($failures, $ran, 'S7 a look-alike folder is not BHW-only',
    run_scope_case($fixture, $base, ['apps/bhw-connect-two/x.ts' => 'changed']),
    0, 'bhw_only=false', [], ['bhw_only=true']);

check($failures, $ran, 'S8 an empty diff is never a reason to skip',
    run_scope_case($fixture, $base, []),
    0, 'bhw_only=false', [], ['bhw_only=true']);

check($failures, $ran, 'S9 a push always runs in full, whatever it changed',
    run_gate($fixture, SCOPE_GATE, ['--event=push']),
    0, 'bhw_only=false', [], ['bhw_only=true']);

$scopeHead = commit_case($fixture, $base, ['apps/bhw-connect/lib/x.ts' => 'changed']);

check($failures, $ran, 'S10 a commit the clone does not hold cannot be measured and prints no answer, so every job runs',
    run_gate($fixture, SCOPE_GATE, ['--event=pull_request', '--base='.$missing, '--head='.$scopeHead]),
    2, 'CANNOT MEASURE', [], ['bhw_only=']);

// B0 to B0d, last, against the shipped file: who owns what. D177's map as D211 amended it: BHW is jongsky25's,
// Meridian avincentpatrick's, BHW's ops/ both, and `.github/`, `deploy.ps1` and the working agreement avincentpatrick's.
// The gate prints, for each path, the owners OTHER than the author — the people whose label it needs.
$shipped = @file_get_contents($root.'/.github/CODEOWNERS');
if ($shipped === false) {
    cannot_measure('.github/CODEOWNERS does not exist.');
}
$base0b = build_base($fixture, $shipped);
check($failures, $ran, 'B0 the shipped map (D177, D211): jongsky25 changes BHW Connect\'s own files with nobody\'s label',
    run_case($fixture, $base0b, 'jongsky25', ['apps/bhw-connect/lib/x.ts' => 'changed', 'apps/bhw-connect/docs/x.md' => 'changed'], []),
    0, 'all on jongsky25\'s own side');

check($failures, $ran, 'B0b the shipped map (D177, D211): avincentpatrick needs jongsky25\'s label for BHW\'s paths and its ops/, and nobody\'s for the rest',
    run_case($fixture, $base0b, 'avincentpatrick', ['apps/bhw-connect/lib/x.ts' => 'changed', 'apps/bhw-connect/ops/start.ps1' => 'changed', 'deploy.ps1' => 'changed', '.github/workflows/ci.yml' => 'changed', 'docs/working-agreement.md' => 'changed', 'app/Kernel.php' => 'changed'], []),
    1, null, [
        'apps/bhw-connect/lib/x.ts — owned by @jongsky25',
        'apps/bhw-connect/ops/start.ps1 — owned by @jongsky25',
    ], ['deploy.ps1 — owned by', '.github/workflows/ci.yml — owned by', 'docs/working-agreement.md — owned by', 'app/Kernel.php — owned by', 'owned by @avincentpatrick']);

// D211: this is the point of the decision, so it gets a case of its own that passes with no label at all.
check($failures, $ran, 'B0d the shipped map (D211): avincentpatrick changes `.github/`, `deploy.ps1`, the working agreement and Meridian with nobody\'s label',
    run_case($fixture, $base0b, 'avincentpatrick', ['deploy.ps1' => 'changed', '.github/workflows/ci.yml' => 'changed', 'docs/working-agreement.md' => 'changed', 'app/Kernel.php' => 'changed'], []),
    0, 'all on avincentpatrick\'s own side');

check($failures, $ran, 'B0c the shipped map (D177, D211): jongsky25 needs avincentpatrick\'s label for ops/, `.github/`, `deploy.ps1`, the working agreement and Meridian',
    run_case($fixture, $base0b, 'jongsky25', ['apps/bhw-connect/ops/start.ps1' => 'changed', 'deploy.ps1' => 'changed', '.github/workflows/ci.yml' => 'changed', 'docs/working-agreement.md' => 'changed', 'app/Kernel.php' => 'changed'], []),
    1, null, [
        'apps/bhw-connect/ops/start.ps1 — owned by @avincentpatrick',
        'deploy.ps1 — owned by @avincentpatrick',
        '.github/workflows/ci.yml — owned by @avincentpatrick',
        'docs/working-agreement.md — owned by @avincentpatrick',
        'app/Kernel.php — owned by @avincentpatrick',
    ], ['owned by @jongsky25']);

rrmdir($fixture);

if ($failures !== []) {
    fwrite(STDERR, sprintf("boundary-check-controls: FAILED — %d of %d case(s) landed elsewhere:\n  %s\n", count($failures), $ran, implode("\n  ", $failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("boundary-check-controls: passed (%d cases, all landing where they should).\n", $ran));
exit(0);

// ---------------------------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------------------------

/** A fresh repository whose one commit holds the files every case starts from, and CODEOWNERS when given. */
function build_base(string $fixture, ?string $codeowners): string
{
    $repo = $fixture.'/repo';
    rrmdir($repo);
    mkdir($repo, 0o777, true);

    $files = [
        'app/Kernel.php' => 'base kernel',
        'apps/bhw-connect/lib/x.ts' => 'base',
        'apps/bhw-connect/ops/deploy-bhw.ps1' => 'base',
        'apps/bhw-connect/ops/start.ps1' => 'base',
        '.github/workflows/ci.yml' => 'base',
        'deploy.ps1' => 'base',
    ];

    if ($codeowners !== null) {
        $files['.github/CODEOWNERS'] = $codeowners;
    }

    foreach ($files as $path => $body) {
        write($repo.'/'.$path, $body);
    }

    git($repo, ['init', '--quiet', '--initial-branch=base']);
    git($repo, ['config', 'user.email', 'controls@example.invalid']);
    git($repo, ['config', 'user.name', 'Controls']);
    git($repo, ['add', '-A']);
    git($repo, ['commit', '--quiet', '--no-gpg-sign', '-m', 'base']);

    return trim(git($repo, ['rev-parse', 'HEAD']));
}

/**
 * Branch from the base, apply the change (a null body deletes), commit, and run the gate.
 *
 * @param  array<string, ?string>  $changes
 * @param  list<array<string, string>>  $events
 * @return array{0: int, 1: string}
 */
function run_case(string $fixture, string $base, string $author, array $changes, array $events): array
{
    $head = commit_case($fixture, $base, $changes);

    $eventsFile = $fixture.'/events.json';
    file_put_contents($eventsFile, json_encode($events));

    return run_gate($fixture, GATE, ['--author='.$author, '--base='.$base, '--head='.$head, '--events-file='.$eventsFile]);
}

/**
 * Branch from the base, apply the change (a null body deletes), commit, and ask which jobs may skip.
 *
 * @param  array<string, ?string>  $changes
 * @return array{0: int, 1: string}
 */
function run_scope_case(string $fixture, string $base, array $changes): array
{
    $head = commit_case($fixture, $base, $changes);

    return run_gate($fixture, SCOPE_GATE, ['--event=pull_request', '--base='.$base, '--head='.$head]);
}

/**
 * Branch from the base, apply the change (a null body deletes), and commit. An empty change makes an empty commit.
 *
 * @param  array<string, ?string>  $changes
 */
function commit_case(string $fixture, string $base, array $changes): string
{
    $repo = $fixture.'/repo';
    git($repo, ['checkout', '--quiet', '-B', 'case', $base]);

    foreach ($changes as $path => $body) {
        if ($body === null) {
            git($repo, ['rm', '--quiet', $path]);
        } else {
            write($repo.'/'.$path, $body);
        }
    }

    git($repo, ['add', '-A']);
    git($repo, ['commit', '--quiet', '--no-gpg-sign', '--allow-empty', '-m', 'case']);

    return trim(git($repo, ['rev-parse', 'HEAD']));
}

/**
 * Run the shipped bytes of a gate from the fixture's repository.
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function run_gate(string $fixture, string $gate, array $arguments): array
{
    $process = proc_open(
        array_merge([PHP_BINARY, $fixture.'/'.$gate], $arguments),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $fixture.'/repo'
    );

    if (! is_resource($process)) {
        cannot_measure('could not start the gate.');
    }

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    return [$status, (string) $output];
}

/**
 * @param  array{0: int, 1: string}  $result
 * @param  list<string>  $mustContain
 * @param  list<string>  $mustNotContain
 */
function check(array &$failures, int &$ran, string $name, array $result, int $exit, ?string $expect, array $mustContain = [], array $mustNotContain = []): void
{
    $ran++;
    [$status, $output] = $result;
    $ok = $status === $exit && ($expect === null || str_contains($output, $expect));

    foreach ($mustContain as $needle) {
        $ok = $ok && str_contains($output, $needle);
    }

    foreach ($mustNotContain as $needle) {
        $ok = $ok && ! str_contains($output, $needle);
    }

    if ($ok) {
        fwrite(STDOUT, "boundary-check-controls: [ok]   {$name}\n");

        return;
    }

    $failures[] = "{$name} — expected exit {$exit}, got {$status}";
    fwrite(STDOUT, "boundary-check-controls: [FAIL] {$name}\n        ---- gate output ----\n{$output}\n");
}

/** @return array<string, string> */
function labeled(string $actor): array
{
    return ['event' => 'labeled', 'label' => 'boundary-approved', 'actor' => $actor];
}

/** @return array<string, string> */
function unlabeled(string $actor): array
{
    return ['event' => 'unlabeled', 'label' => 'boundary-approved', 'actor' => $actor];
}

function write(string $path, string $body): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }

    file_put_contents($path, $body);
}

/** @param  list<string>  $arguments */
function git(string $repo, array $arguments): string
{
    $process = proc_open(array_merge(['git'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);

    if (! is_resource($process)) {
        cannot_measure('could not start git.');
    }

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        cannot_measure('git '.implode(' ', $arguments)." failed in the fixture:\n".$err);
    }

    return (string) $out;
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if ($item->isDir() && ! $item->isLink()) {
            rmdir($item->getPathname());
        } else {
            @chmod($item->getPathname(), 0o666);
            unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

function cannot_measure(string $why): never
{
    fwrite(STDERR, "boundary-check-controls: CANNOT MEASURE — {$why}\n");
    exit(2);
}
