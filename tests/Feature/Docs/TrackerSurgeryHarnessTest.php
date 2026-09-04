<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Controls for the tracker-surgery verification harness (M71).
|--------------------------------------------------------------------------
| `scripts/tracker-surgery.php` is a check, and a check nobody has turned red is a check nobody has
| tested. Every case below is a DELIBERATE DEFECT that the harness must catch, plus the refusals it
| must make instead of passing. The harness's own docblock names the four historical failures each
| proof exists for; these are the executable half of that argument.
|
| ⛔ WHY THIS IS A PEST FILE AND NOT A `scripts/*-controls.php` SIBLING. Three reasons, and the third
| decided it, exactly as `DocumentedCommandDriftTest.php` records for the same choice:
| (1) `php artisan test` discovers `tests/Feature`, so this needs no `composer.json` alias, no
|     `quality` entry and no `ci.yml` step — where a `scripts/` sibling needs all three, and no CI job
|     runs the `quality` aggregate.
| (2) Keeping `.github/workflows/ci.yml` out of the diff is what keeps this increment's row inside
|     `D13`'s budget of one hub-touching row.
| (3) `scripts/mutate.php` drives Pest in a container and nothing else. A `scripts/` sibling would have
|     to reimplement its discipline by hand at the call site — which is the weaker form M42 filed a row
|     about, and it would be this increment hand-rolling a harness while building one.
|
| ⚠️ EVERY CASE DRIVES THE HARNESS THROUGH ITS FILE-SNAPSHOT MODE, WHICH NEEDS NO GIT AT ALL. That is
| not a convenience: a throwaway git fixture inherits the host's `core.autocrlf` and ends up measuring
| the host rather than the code (M49 lost three rules to exactly that). The git path is one `git show`
| into the same comparison these cases already cover.
|
| ⚠️ Helpers are prefixed `trackerSurgery*` deliberately: Pest loads every file in a directory into one
| process, so a same-named file-scope helper is a fatal redeclaration. `documentedCommand*` and
| `documentedDefault*` are already taken in this directory.
*/

/** Exit codes the harness publishes. A refusal is 2 and is never a pass. */
const TRACKER_SURGERY_OK = 0;

const TRACKER_SURGERY_FAILED = 1;

const TRACKER_SURGERY_CANNOT_MEASURE = 2;

/** A scratch directory unique to one case, cleaned up by the caller. */
function trackerSurgeryScratch(string $case): string
{
    $dir = sys_get_temp_dir().'/m71-surgery-'.$case.'-'.getmypid();

    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }

    return $dir;
}

function trackerSurgeryWrite(string $dir, string $name, string $body): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, $body);

    return $path;
}

/**
 * Run the harness over four file snapshots and return [exitCode, output].
 *
 * The exit code is read directly rather than through a pipe, because a pipe hides the exit status —
 * which is the trap this repository has now met in three separate gates.
 */
function trackerSurgeryRun(array $paths, int $addedBytes): array
{
    $command = sprintf(
        '%s %s --tracker=%s --archive=%s --before-tracker=%s --before-archive=%s --added-bytes=%d 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(base_path('scripts/tracker-surgery.php')),
        escapeshellarg($paths['tracker']),
        escapeshellarg($paths['archive']),
        escapeshellarg($paths['beforeTracker']),
        escapeshellarg($paths['beforeArchive']),
        $addedBytes
    );

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    return [$status, implode("\n", $output)];
}

/**
 * A correct surgery: three bullets move out of the tracker and into the archive under a new heading.
 *
 * Returns the four snapshot paths plus the exact byte count the move ADDS, which is the heading and
 * its trailing newline — stated, never inferred, because inferring it makes A2 unfailable.
 */
function trackerSurgeryFixture(string $case): array
{
    $dir = trackerSurgeryScratch($case);

    $keep = "# Tracker\n\n## Current Status\n\n- newest bullet, stays\n";
    // ⚠️ Two IDENTICAL blank lines and two IDENTICAL bullets, deliberately. M45's 134 moved lines had
    // only 130 distinct hashes and M48's had 84 blank lines sharing one, so a fixture with all-unique
    // lines cannot tell a counted multiset from a set — which is the defect that would drop 83 lines.
    $moving = "- older bullet A\n\n- older bullet A\n\n- older bullet B\n";

    $addedHeading = "## Archived status bullets\n";

    $beforeTracker = $keep.$moving;
    $afterTracker = $keep;

    $beforeArchive = "# Archive\n";
    $afterArchive = "# Archive\n".$addedHeading.$moving;

    return [
        'dir' => $dir,
        'addedBytes' => strlen($addedHeading),
        'moving' => $moving,
        'paths' => [
            'tracker' => trackerSurgeryWrite($dir, 'tracker-after.md', $afterTracker),
            'archive' => trackerSurgeryWrite($dir, 'archive-after.md', $afterArchive),
            'beforeTracker' => trackerSurgeryWrite($dir, 'tracker-before.md', $beforeTracker),
            'beforeArchive' => trackerSurgeryWrite($dir, 'archive-before.md', $beforeArchive),
        ],
    ];
}

it('VERIFIES a correct surgery — the baseline every case below is measured against', function (): void {
    // ⛔ WITHOUT THIS, EVERY RED BELOW IS AMBIGUOUS. A harness that refuses everything would satisfy all
    // seven negative cases and be completely useless; this is the case that says the fixture shape is
    // one the harness accepts, so a red elsewhere is about the defect and not about the fixture.
    $fixture = trackerSurgeryFixture('ok');

    [$status, $output] = trackerSurgeryRun($fixture['paths'], $fixture['addedBytes']);

    expect($status)->toBe(TRACKER_SURGERY_OK, $output);
    expect($output)->toContain('VERIFIED');
    expect($output)->toContain('A2 byte conservation — exact, with no tolerance');
});

it('CATCHES a dropped line — one that leaves the tracker and never reaches the archive', function (): void {
    // The 2026-08-16 incident in miniature: a splice that removes more than it inserts, which "the
    // archive got bigger" cannot see and which is why CLAUDE.md forbids proving a move that way.
    $fixture = trackerSurgeryFixture('dropped');
    $dir = $fixture['dir'];

    $mangled = str_replace("- older bullet B\n", '', "# Archive\n## Archived status bullets\n".$fixture['moving']);
    $fixture['paths']['archive'] = trackerSurgeryWrite($dir, 'archive-after.md', $mangled);

    [$status, $output] = trackerSurgeryRun($fixture['paths'], $fixture['addedBytes']);

    expect($status)->toBe(TRACKER_SURGERY_FAILED, $output);
    expect($output)->toContain('A1 multiset');
});

it('CATCHES a changed byte — a line that arrives in the archive almost identical', function (): void {
    // A1 and A4 must BOTH fail here, and that they fail together is the point: they share no code, so
    // a wrong multiset implementation cannot make the pair agree.
    $fixture = trackerSurgeryFixture('changed');
    $dir = $fixture['dir'];

    $mangled = "# Archive\n## Archived status bullets\n".str_replace('bullet B', 'bullet b', $fixture['moving']);
    $fixture['paths']['archive'] = trackerSurgeryWrite($dir, 'archive-after.md', $mangled);

    [$status, $output] = trackerSurgeryRun($fixture['paths'], $fixture['addedBytes']);

    expect($status)->toBe(TRACKER_SURGERY_FAILED, $output);
    expect($output)->toContain('A1 multiset');
    expect($output)->toContain('A4 slice hash');
});

it('CATCHES a multiplicity collision that a SET comparison would pass', function (): void {
    // ⛔ THE CASE THAT DISTINGUISHES A COUNTED MULTISET FROM A SET, AND THE ONE WITH A REAL BODY COUNT
    // BEHIND IT. The fixture moves the line "- older bullet A" TWICE and two identical blank lines; the
    // archive here receives ONE of each. Every DISTINCT hash is still present, so a set comparison — and
    // "the archive got bigger" — both report success while two lines have been destroyed. M48's surgery
    // had 84 blank lines sharing a single hash, so this is the shape that would have lost 83 of them.
    $fixture = trackerSurgeryFixture('multiplicity');
    $dir = $fixture['dir'];

    $deduped = "# Archive\n## Archived status bullets\n- older bullet A\n\n- older bullet B\n";
    $fixture['paths']['archive'] = trackerSurgeryWrite($dir, 'archive-after.md', $deduped);

    [$status, $output] = trackerSurgeryRun($fixture['paths'], $fixture['addedBytes']);

    expect($status)->toBe(TRACKER_SURGERY_FAILED, $output);
    expect($output)->toContain('A1 multiset');
});

it('CATCHES byte conservation off by the JOIN SEAM — M41 failed here by exactly one byte', function (): void {
    // M41's first run reported a one-byte discrepancy against a CORRECT tree because its formula omitted
    // the newline joining the old archive tail to the first inserted line. Understating the added bytes
    // by one is that failure reproduced, and A2 has no tolerance by design: a conservation check with a
    // fudge factor is not a conservation check.
    $fixture = trackerSurgeryFixture('seam');

    [$status, $output] = trackerSurgeryRun($fixture['paths'], $fixture['addedBytes'] - 1);

    expect($status)->toBe(TRACKER_SURGERY_FAILED, $output);
    expect($output)->toContain('A2 byte conservation');
    expect($output)->toContain('JOIN SEAM');
});

it('REFUSES empty input rather than reporting success on it', function (): void {
    // ⛔ THE M48 FAMILY, AND THIS PROJECT HAS NOW MEASURED IT SIX TIMES. Three of M48's splices read a
    // missing file, wrote a blank line and REPORTED SUCCESS. Exit 2, and specifically NOT exit 0 — a
    // refusal that is indistinguishable from a pass is the defect wearing the other face.
    $fixture = trackerSurgeryFixture('empty');
    $dir = $fixture['dir'];

    $fixture['paths']['beforeTracker'] = trackerSurgeryWrite($dir, 'tracker-before.md', '');

    [$status, $output] = trackerSurgeryRun($fixture['paths'], $fixture['addedBytes']);

    expect($status)->toBe(TRACKER_SURGERY_CANNOT_MEASURE, $output);
    expect($status)->not->toBe(TRACKER_SURGERY_OK);
    expect($output)->toContain('CANNOT MEASURE');
});

it('REFUSES a no-op, so a surgery that never happened cannot report VERIFIED', function (): void {
    // The vacuous success in its purest form: run the harness against a tree where nothing moved. Every
    // proof is trivially satisfiable, and an implementation that simply checked "no contradictions" would
    // print VERIFIED over an operation that did not occur.
    $fixture = trackerSurgeryFixture('noop');
    $dir = $fixture['dir'];

    $unchanged = file_get_contents($fixture['paths']['beforeTracker']);
    $fixture['paths']['tracker'] = trackerSurgeryWrite($dir, 'tracker-after.md', (string) $unchanged);
    $fixture['paths']['archive'] = trackerSurgeryWrite($dir, 'archive-after.md', "# Archive\n");

    [$status, $output] = trackerSurgeryRun($fixture['paths'], 0);

    expect($status)->toBe(TRACKER_SURGERY_CANNOT_MEASURE, $output);
    expect($output)->toContain('lost no lines');
});

it('REFUSES an unstated --added-bytes rather than inferring it from the residual', function (): void {
    // ⛔ THE ARGUMENT FOR THE FLAG EXISTING AT ALL. If the harness derived the added bytes from the
    // difference it is checking, A2 becomes an identity: it would hold for every possible surgery,
    // including one that destroyed half the archive. Stating the number is what makes it falsifiable.
    $fixture = trackerSurgeryFixture('unstated');

    $command = sprintf(
        '%s %s --tracker=%s --archive=%s --before-tracker=%s --before-archive=%s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(base_path('scripts/tracker-surgery.php')),
        escapeshellarg($fixture['paths']['tracker']),
        escapeshellarg($fixture['paths']['archive']),
        escapeshellarg($fixture['paths']['beforeTracker']),
        escapeshellarg($fixture['paths']['beforeArchive'])
    );

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    expect($status)->toBe(TRACKER_SURGERY_CANNOT_MEASURE, implode("\n", $output));
    expect(implode("\n", $output))->toContain('--added-bytes');
});
