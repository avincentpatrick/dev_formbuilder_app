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
 * gate from the fixture's root with the label events given as a file. B0 alone reads the real
 * `.github/CODEOWNERS`, so the map that ships is held to D177 too.
 *
 * Exit 0 = every case landed where it should. Exit 1 = a case landed elsewhere. Exit 2 = the harness
 * could not measure, which is never a pass.
 */

const GATE = 'scripts/boundary-check.php';

$root = str_replace('\\', '/', dirname(__DIR__));
$gateBytes = @file_get_contents($root.'/'.GATE);

if ($gateBytes === false || trim($gateBytes) === '') {
    cannot_measure(GATE.' is missing or empty.');
}

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/m192-boundary-'.getmypid();
rrmdir($fixture);
mkdir($fixture.'/scripts', 0o777, true);
file_put_contents($fixture.'/'.GATE, $gateBytes);

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

// B0, last, against the shipped file: who owns the paths D177 names.
$shipped = @file_get_contents($root.'/.github/CODEOWNERS');
if ($shipped === false) {
    cannot_measure('.github/CODEOWNERS does not exist.');
}
$base0b = build_base($fixture, $shipped);
check($failures, $ran, 'B0 the shipped map: BHW-only is BHW\'s, Meridian-only is Meridian\'s, and ops/, .github/ and deploy.ps1 are shared',
    run_case($fixture, $base0b, 'jongsky25', ['apps/bhw-connect/lib/x.ts' => 'changed', 'apps/bhw-connect/ops/start.ps1' => 'changed', 'deploy.ps1' => 'changed', '.github/workflows/ci.yml' => 'changed', 'app/Kernel.php' => 'changed'], []),
    1, null, [
        'apps/bhw-connect/ops/start.ps1 — owned by @avincentpatrick',
        'deploy.ps1 — owned by @avincentpatrick',
        '.github/workflows/ci.yml — owned by @avincentpatrick',
        'app/Kernel.php — owned by @avincentpatrick',
    ], ['apps/bhw-connect/lib/x.ts']);

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
    git($repo, ['commit', '--quiet', '--no-gpg-sign', '-m', 'case']);
    $head = trim(git($repo, ['rev-parse', 'HEAD']));

    $eventsFile = $fixture.'/events.json';
    file_put_contents($eventsFile, json_encode($events));

    $process = proc_open(
        [PHP_BINARY, $fixture.'/'.GATE, '--author='.$author, '--base='.$base, '--head='.$head, '--events-file='.$eventsFile],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repo
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
