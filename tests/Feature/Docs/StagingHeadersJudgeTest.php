<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Controls for `scripts/staging-headers-judge.php` (M101).
|--------------------------------------------------------------------------
| The judge exists because the only thing keeping `staging.pitahc.gov.ph` out of search results is one
| `Header always set X-Robots-Tag` line of Apache configuration on one box. It is in no tracked file —
| there is no `VirtualHost` anywhere in this repository — so any vhost edit or Apache reinstall removes
| it silently, and the first symptom is tester data in a public index. It is a merge-blocking control,
| so it needs controls of its own.
|
| ⛔ WHY THIS IS A PEST FILE AND NOT A `scripts/*-controls.php` SIBLING. `scripts/mutate.php` drives
| Pest in a container AND NOTHING ELSE, so a `scripts/` sibling could not be turned red by a deliberate
| defect — the decorative gate M43 measured. This is the same argument
| `tests/Feature/Docs/NpmAuditJudgeTest.php` records for itself, and it is why the judge's expectations
| live in constants rather than on a command line.
|
| ⚠️ THE CASE THAT CARRIES THE WHOLE DESIGN IS `foreign-body`. Every other case would also pass against
| a judge written the obvious wrong way — "is `X-Robots-Tag` in the captured headers?". That one would
| not: a captive portal, a proxy error page or a hijacked name answers 200 with no such header, and a
| judge with no identity test reports that as THE PROTECTION IS GONE. It is a false red, and this
| repository has measured that a false red is worse than a false green, because it is re-run until it
| passes and so teaches the operator to re-run a red gate (`scripts/npm-audit-judge.php:20-24`). Read
| `foreign-body` as the discriminator, not as an edge case.
|
| ⚠️ THE FIXTURES ARE REAL CAPTURES, taken from the live site on 2026-09-19, with `Set-Cookie` stripped
| — this repository is public (`D48`) and those lines carried live encrypted session payloads. Only the
| identity probe's body is read by the judge, so the other bodies are placeholders that say so.
|
| ⚠️ The exit code is read directly rather than through a pipe, because a pipe hides the exit status.
|
| ⚠️ Helpers are prefixed `stagingHeaders*` deliberately: Pest loads every file in a directory into one
| process, so a same-named file-scope helper is a fatal redeclaration. `npmAudit*`, `trackerSurgery*`,
| `documentedCommand*` and `documentedDefault*` are already taken in this directory.
*/

/** The three-way contract the judge publishes. Exit 2 is never a pass. */
const STAGING_HEADERS_JUDGE_OK = 0;

const STAGING_HEADERS_JUDGE_BLOCKED = 1;

const STAGING_HEADERS_JUDGE_CANNOT_MEASURE = 2;

/**
 * Run the judge over one argument list and return [exitCode, combined output].
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function stagingHeadersRun(array $arguments): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/staging-headers-judge.php'));

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $output = [];
    $status = 0;
    exec($command.' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

/**
 * Judge one committed fixture scenario.
 *
 * @return array{0: int, 1: string}
 */
function stagingHeadersJudge(string $scenario): array
{
    return stagingHeadersRun(['--captures='.base_path('tests/fixtures/staging-headers/'.$scenario)]);
}

/**
 * The probe list, read from the judge itself rather than restated here.
 *
 * @return list<string>
 */
function stagingHeadersProbes(): array
{
    [$status, $output] = stagingHeadersRun(['--probes']);

    expect($status)->toBe(STAGING_HEADERS_JUDGE_OK, $output);

    $lines = array_map(trim(...), explode("\n", $output));

    return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
}

it('judges a real capture of the live site as present, and reports a floor', function (): void {
    [$status, $output] = stagingHeadersJudge('measured');

    expect($status)->toBe(STAGING_HEADERS_JUDGE_OK, $output);
    expect($output)->toContain('JUDGED PRESENT');

    // ⛔ THE FLOOR IS THE HALF THAT MATTERS. `controller-gate` printed "passed" while seeing 49 of 97
    // files. A judge that measured two probes and said only "present" is that defect again.
    expect($output)->toContain('6 probe(s) declared, 6 measured');
    expect($output)->toContain('noindex, nofollow, noarchive');

    // M106 — the floor counts the POLICY, not just the probes. A header dropped from
    // STAGING_REQUIRED_HEADERS would leave every probe measured and this number one lower.
    expect($output)->toContain('2 required header(s)');
    expect($output)->toContain('Strict-Transport-Security: max-age=300');
});

it('blocks when the header is absent from the one URL crawlers actually fetch', function (): void {
    [$status, $output] = stagingHeadersJudge('missing-header');

    expect($status)->toBe(STAGING_HEADERS_JUDGE_BLOCKED, $output);
    expect($output)->toContain('BLOCKED');

    // Naming the path is what makes the red actionable instead of a count, and asserting it here is
    // what turns a mutation of the failure reporting red rather than leaving it silently cosmetic.
    expect($output)->toContain('/robots.txt');
    expect($output)->toContain('ABSENT');

    // ⛔ M106 — NAMING THE HEADER IS WHAT STOPS THIS ARM PASSING FOR THE WRONG REASON. Once a second
    // required header existed, "BLOCKED ... ABSENT" was satisfiable by EITHER of them, and this fixture
    // would have gone on reporting green about a crawl header it was no longer measuring.
    expect($output)->toContain('X-Robots-Tag is ABSENT');
    expect($output)->not->toContain('Strict-Transport-Security is ABSENT');
});

it('blocks when the directives are WEAKENED rather than absent', function (): void {
    // A header that is present but no longer says `noarchive` is a real change of posture. A judge
    // that only asks "is the header there?" passes this, which is why the directives are a list.
    [$status, $output] = stagingHeadersJudge('weakened');

    expect($status)->toBe(STAGING_HEADERS_JUDGE_BLOCKED, $output);
    expect($output)->toContain('noarchive');
    expect($output)->toContain('X-Robots-Tag');
    expect($output)->not->toContain('Strict-Transport-Security is');
});

/*
|--------------------------------------------------------------------------
| M106 — the transport header, and the two things the old policy shape could not say.
|--------------------------------------------------------------------------
|
| ⛔ `D54` OPTION A AND THIS JUDGE'S OWN DOCBLOCK BOTH PRICED ARMING HSTS AT "ONE ENTRY IN
| STAGING_REQUIRED_HEADERS", AND BOTH WERE WRONG. The map modelled REQUIRED tokens only, so
| "no `includeSubDomains`, no `preload`" — half of what `D54` decided — had nowhere to live; and the
| tokeniser split on `,` while HSTS is SEMICOLON-delimited, so `max-age=300; includeSubDomains` was
| reported as MISSING `max-age=300` while plainly carrying it. A true red for a false reason is worse
| than a miss, because it sends the reader to the wrong line. The three cases below are the two new
| powers and the ordinary absence.
*/

it('blocks when the transport header is absent, and says so about the RIGHT header', function (): void {
    [$status, $output] = stagingHeadersJudge('hsts-missing');

    expect($status)->toBe(STAGING_HEADERS_JUDGE_BLOCKED, $output);
    expect($output)->toContain('Strict-Transport-Security is ABSENT');
    // The crawl header is intact in this fixture, so a judge that conflated the two would name it here.
    expect($output)->not->toContain('X-Robots-Tag is ABSENT');
});

it('blocks on a max-age that is not the one D54 chose', function (): void {
    // `max-age=60` is still HSTS and still "present". The value is the control, so presence is not enough.
    [$status, $output] = stagingHeadersJudge('hsts-weakened');

    expect($status)->toBe(STAGING_HEADERS_JUDGE_BLOCKED, $output);
    expect($output)->toContain("Strict-Transport-Security is 'max-age=60', missing max-age=300");
});

it('blocks on includeSubDomains and preload, which a required-token model could not express', function (): void {
    // ⛔ THE CASE THE OLD SHAPE COULD NOT HAVE FAILED, AND THE ONE WITH THE WORST BLAST RADIUS. A preload
    // submission is effectively irreversible on browser timescales and would outlive this staging host.
    [$status, $output] = stagingHeadersJudge('hsts-forbidden');

    expect($status)->toBe(STAGING_HEADERS_JUDGE_BLOCKED, $output);
    expect($output)->toContain('FORBIDDEN directive(s) present: includesubdomains, preload');

    // ⚠️ AND IT MUST NOT ALSO CLAIM `max-age=300` IS MISSING. It is right there in the value. That
    // false second line is exactly what the comma tokeniser produced, and asserting its ABSENCE is what
    // pins the per-header separator rather than leaving it a cosmetic detail.
    expect($output)->not->toContain('missing max-age=300');
});

it('reports an unreachable box as CANNOT MEASURE rather than as a missing header', function (): void {
    // The conflation this shape exists to prevent: "the site is down" and "the protection is gone"
    // must never be the same red. curl writes nothing when it cannot connect at all, so an empty
    // capture IS the outage — the succeeds-on-empty-input family, measured five times in this repo.
    [$status, $output] = stagingHeadersJudge('unreachable');

    expect($status)->toBe(STAGING_HEADERS_JUDGE_CANNOT_MEASURE, $output);
    expect($status)->not->toBe(STAGING_HEADERS_JUDGE_BLOCKED, 'an outage must not read as a missing header');
    expect($output)->toContain('CANNOT MEASURE');
});

it('reports a body that is not ours as CANNOT MEASURE, never as a finding', function (): void {
    // ⛔ THE DISCRIMINATOR. This fixture answers 200 with no `X-Robots-Tag` and a body that is NOT our
    // `public/robots.txt` — a captive portal, a proxy error page or a hijacked name. A judge keying on
    // the header alone calls that BLOCKED and sends someone hunting for a deleted vhost line that was
    // never deleted. Positive recognition is what makes it exit 2 instead. If this case is ever
    // weakened, the gate reverts to producing false reds.
    [$status, $output] = stagingHeadersJudge('foreign-body');

    expect($status)->toBe(STAGING_HEADERS_JUDGE_CANNOT_MEASURE, $output);
    expect($status)->not->toBe(STAGING_HEADERS_JUDGE_BLOCKED, 'an unattributable response is not a finding');
    expect($output)->toContain('CANNOT MEASURE');
});

it('refuses a missing capture directory instead of reading it as clean', function (): void {
    [$status, $output] = stagingHeadersRun(['--captures='.base_path('tests/fixtures/staging-headers/no-such-dir')]);

    expect($status)->toBe(STAGING_HEADERS_JUDGE_CANNOT_MEASURE, $output);
    expect($status)->not->toBe(STAGING_HEADERS_JUDGE_OK, 'nothing measured is never clean');
});

it('keeps a probe list that spans the mechanisms it is there to tell apart', function (): void {
    $probes = stagingHeadersProbes();

    // The spread is the point, not the count. Two files Apache serves with no PHP at all, a PHP route
    // that sits OUTSIDE every group `AppSecurityHeaders` is mounted on, and an error path. Measured
    // 2026-09-19: `/up`, `/robots.txt` and `/favicon.ico` carry `X-Robots-Tag` and carry none of the
    // four headers that middleware sets — which is the evidence that no middleware can replace the
    // vhost line, and this list is what keeps that evidence under a gate.
    expect($probes)->toContain('https://staging.pitahc.gov.ph/robots.txt');
    expect($probes)->toContain('https://staging.pitahc.gov.ph/favicon.ico');
    expect($probes)->toContain('https://staging.pitahc.gov.ph/up');
    expect($probes)->toContain('https://staging.pitahc.gov.ph/nonexistent-static.txt');
    expect(count($probes))->toBeGreaterThanOrEqual(4);

    // ⛔ AND THEY ARE ABSOLUTE. `ci.yml` fetches exactly what this prints, so a bare path would oblige
    // the workflow to carry its own copy of the host. Asserting the scheme here is what stops that
    // second copy being reintroduced by someone tidying the output.
    foreach ($probes as $probe) {
        expect($probe)->toStartWith('https://');
    }
});

it('never probes a content-hashed build path', function (): void {
    // ⚠️ A REGRESSION GUARD FOR A DEFECT CAUGHT IN DRAFT, not a hypothetical. A hashed asset path was
    // in the first probe list and would have gone red on the next deploy, because Vite renames that
    // file on every build. A false red on a merge gate is the thing this repository least wants, so
    // the trap is pinned here rather than left as a comment nobody re-reads.
    foreach (stagingHeadersProbes() as $probe) {
        expect($probe)->not->toContain('/build/');
    }
});

it('refuses an unrecognised option rather than silently ignoring it', function (): void {
    // getopt() discards unknown long options and cannot report what it discarded, so a typo'd flag
    // would otherwise fall straight through to the action.
    [$status, $output] = stagingHeadersRun([
        '--captures='.base_path('tests/fixtures/staging-headers/measured'),
        '--bogus',
    ]);

    expect($status)->toBe(STAGING_HEADERS_JUDGE_CANNOT_MEASURE, $output);
    expect($output)->toContain('unrecognised option');
});
