<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Two workflow shapes no test could otherwise see (M214, ADR-0024, D210).
|--------------------------------------------------------------------------
| `.github/workflows/boundary.yml` runs under a trigger that hands a job the base repository's token, and
| `ci.yml`'s fast path lets a required check report success with nothing run. Each is safe only in one exact
| shape, and a one-word change to either is green everywhere else. This file holds the shapes.
|
| ⛔ THIS RUNS FROM THE PULL REQUEST'S OWN COPY, SO IT CATCHES MISTAKES AND NOT MALICE. A pull request can
| edit this file too. What stops that is the check itself, from main's copy: `.github/` and `tests/` are not
| the other owner's to change without the label. Do not read a green here as the fence.
|
| ⛔ IT READS WHAT RUNS. The workflows explain themselves in comments that name the very tokens forbidden here,
| which is this repository's token-in-prose trap (`SecretScanDepthTest` records two instances), so every arm
| reads the file with its comment lines removed. No YAML extension is loaded in the app container, so these are
| bounded scans that fail closed when the bracketing finds nothing.
|
| The arms are proved red by `scripts/mutate.php`, recorded in M214's claim. The behaviour they protect was
| measured on a throwaway repository, recorded there too. The logic of who owns what and who may skip is
| `scripts/boundary-check-controls.php`, a host script, because it reads the commit graph.
*/

/** A workflow's text with its comment lines removed. */
function boundaryWorkflowCode(string $file): string
{
    $path = base_path('.github/workflows/'.$file);
    expect(is_file($path))->toBeTrue("{$file} does not exist, so this control is reading nothing");

    return implode("\n", array_filter(
        explode("\n", (string) file_get_contents($path)),
        static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')
    ));
}

/**
 * The named job's text in ci.yml, comments removed, up to the next job at the same indentation.
 *
 * @return list<string>
 */
function ciJobLines(string $job): array
{
    $lines = explode("\n", boundaryWorkflowCode('ci.yml'));
    $start = array_search('  '.$job.':', $lines, true);
    expect($start)->not->toBeFalse("no job '{$job}' in ci.yml — this control is reading nothing");

    $body = [];
    for ($i = $start + 1; $i < count($lines); $i++) {
        if (preg_match('/^  [a-z0-9-]+:$/', $lines[$i]) === 1) {
            break;
        }
        $body[] = $lines[$i];
    }

    return $body;
}

it('takes the boundary from the base branch, and re-runs it when the approval changes', function (): void {
    $code = boundaryWorkflowCode('boundary.yml');

    // ⛔ THE ONE WORD THE WHOLE FENCE RESTS ON. The plain pull-request trigger takes the file from the head.
    expect($code)->toContain('pull_request_target:')
        ->and($code)->toContain('branches: [main]')
        ->and(preg_match('/^  pull_request:/m', $code))->toBe(0, 'boundary.yml also runs from the head\'s own copy');

    foreach (['opened', 'synchronize', 'reopened', 'edited', 'labeled', 'unlabeled'] as $type) {
        expect(preg_match('/^    types: \[.*\b'.$type.'\b.*\]$/m', $code))->toBe(1, "boundary.yml does not run on `{$type}`");
    }
});

it('never checks out or runs the pull request under that trigger', function (): void {
    $code = boundaryWorkflowCode('boundary.yml');

    // Naming a ref is how this trigger turns into running the head's code with the base's token.
    expect(preg_match('/^\s+ref:/m', $code))->toBe(0, 'the checkout names a ref, so the judge may not be main\'s copy')
        ->and(preg_match('/^\s+repository:/m', $code))->toBe(0, 'the checkout names a repository')
        ->and($code)->toContain('persist-credentials: false')
        ->and($code)->toContain('fetch-depth: 0');

    // A moved tag would run under this token, so the one action here is pinned to a commit.
    preg_match_all('/^\s*(?:- )?uses: (\S+)$/m', $code, $uses);
    expect($uses[1])->toHaveCount(1);
    expect($uses[1][0])->toMatch('/^actions\/checkout@[0-9a-f]{40}$/');

    expect($code)->toContain('php scripts/boundary-check.php')
        ->and($code)->toContain('git fetch --no-tags origin "$HEAD_SHA"');

    // Nothing else may execute: no install, no build, no script from the head.
    expect(preg_match('/\b(composer|npm|npx|bash|sh|node|make)\s/', $code))->toBe(0, 'boundary.yml runs a tool beyond git and php');
});

it('keeps event text out of run lines, and has no secret, no write scope and no way to be skipped', function (): void {
    $lines = explode("\n", boundaryWorkflowCode('boundary.yml'));
    $code = implode("\n", $lines);

    // Inside the `run:` block, an expression would paste an attacker-chosen branch name or title into the shell.
    $inRun = false;
    $runLines = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s+run: \|$/', $line) === 1) {
            $inRun = true;

            continue;
        }
        if ($inRun && $line !== '') {
            $runLines[] = $line;
        }
    }
    expect($runLines)->not->toBe([], 'no run block found — this arm is reading nothing');
    foreach ($runLines as $line) {
        expect($line)->not->toContain('${{');
    }

    expect($code)->not->toContain('secrets.');

    // A skipped job reports success on a required check, so nothing here may skip, and no path filter either.
    expect(preg_match('/^\s+if:/m', $code))->toBe(0, 'boundary.yml can skip itself, and a skipped job passes a required check')
        ->and(preg_match('/^\s+paths(-ignore)?:/m', $code))->toBe(0, 'a filtered workflow leaves a required check waiting forever');

    expect($code)->toContain('contents: read')
        ->and($code)->toContain('issues: read')
        ->and($code)->toContain('pull-requests: read')
        ->and($code)->not->toContain('write');
});

it('names one required check, in one workflow, and has left ci.yml', function (): void {
    $name = 'name: Boundary (from main)';

    expect(boundaryWorkflowCode('boundary.yml'))->toContain('    '.$name);

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $file) {
        if (basename($file) !== 'boundary.yml') {
            // Reusing the name is half of the bypass ADR-0024 records, and GitHub calls the result ambiguous.
            expect(str_contains((string) file_get_contents($file), $name))->toBeFalse(basename($file).' reuses the required check\'s name');
        }
    }

    // The old step is what could be deleted. Two copies would also leave a stale red needing a re-run by hand.
    expect(boundaryWorkflowCode('ci.yml'))->not->toContain('boundary-check.php');
});

it('asks one job whether a pull request is BHW-only, and always scans for secrets', function (): void {
    $scope = implode("\n", ciJobLines('scope'));

    expect($scope)->toContain('php scripts/ci-scope.php')
        ->and($scope)->toContain('bhw_only: ${{ steps.scope.outputs.bhw_only }}')
        ->and($scope)->toContain('fetch-depth: 0');

    // BHW's own job must not depend on the answer: it is what does judge a BHW-only pull request.
    expect(implode("\n", ciJobLines('bhw-connect')))->not->toContain('needs:');
});

it('skips a Meridian job only on the literal answer, so a failed detection job runs everything', function (string $job): void {
    $head = implode("\n", array_slice(ciJobLines($job), 0, 4));

    expect($head)->toContain('    needs: scope')
        // ⛔ Dropping `!cancelled()` skips the job when `scope` fails, and a skipped job passes a required check.
        // Testing `== 'false'` the other way round would do the same with an empty output.
        ->and($head)->toContain("    if: \${{ !cancelled() && needs.scope.outputs.bhw_only != 'true' }}");
})->with(['tests', 'frontend', 'design-system-a11y', 'contract-tests', 'e2e']);

it('keeps static analysis running for the secret scan, and guards every other step that can run on a pull request', function (): void {
    $lines = ciJobLines('static-analysis');
    $head = implode("\n", array_slice($lines, 0, 4));

    expect($head)->toContain('    needs: scope')
        ->and($head)->toContain('    if: ${{ !cancelled() }}')
        ->and($head)->not->toContain('bhw_only');

    $steps = [];
    $current = null;
    foreach ($lines as $line) {
        if (preg_match('/^      - name: (.+)$/', $line, $match) === 1) {
            $current = $match[1];
            $steps[$current] = [];
        } elseif (preg_match('/^      - uses: /', $line) === 1) {
            $current = null;
        } elseif ($current !== null) {
            $steps[$current][] = $line;
        }
    }

    expect(count($steps))->toBeGreaterThan(20, 'the step scan found too few steps, so it is not reading the job');

    $guard = "        if: needs.scope.outputs.bhw_only != 'true'";
    foreach ($steps as $name => $body) {
        $if = array_values(array_filter($body, static fn (string $line): bool => str_starts_with($line, '        if: ')));

        if ($name === 'Secret scan (gitleaks)') {
            expect($if)->toBe([], 'the secret scan can skip itself: a leak in BHW\'s files is this repository\'s leak');
        } elseif ($if === []) {
            throw new RuntimeException("static-analysis's step '{$name}' has no condition, so it runs on a BHW-only pull request with nothing installed.");
        } elseif ($if[0] !== $guard) {
            // The two nightly steps carry their own condition, which a pull request can never meet.
            expect(str_contains($if[0], "github.event_name == 'schedule'"))->toBeTrue("static-analysis's step '{$name}' has an unexpected condition: {$if[0]}");
        }
    }
});
