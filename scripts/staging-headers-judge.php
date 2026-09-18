<?php

declare(strict_types=1);

/*
 * Staging response-header judge (M101) — separates FETCHING the testing site from JUDGING it.
 *
 * WHY THIS EXISTS.
 *
 * The only thing keeping `staging.pitahc.gov.ph` out of search results is ONE line of Apache
 * configuration on ONE box: `Header always set X-Robots-Tag "noindex, nofollow, noarchive"`. It is in
 * no tracked file — `git grep` finds no `VirtualHost` anywhere in this repository, and the two
 * web-server files that ARE tracked (`docker/nginx/default.conf`, `public/.htaccess`) set no header at
 * all. No test and no lint read it. So any future vhost edit or Apache reinstall removes it silently,
 * and the first symptom is tester data in a public index. Googlebot is already fetching `/robots.txt`.
 *
 * ⛔ AND THE OBVIOUS FIX — "move it into the application's security-header middleware" — IS REFUTED BY
 * MEASUREMENT, WHICH IS WHY THIS FILE EXISTS INSTEAD. Measured from outside on 2026-09-19:
 * `/robots.txt` and `/favicon.ico` are STATIC FILES Apache serves without invoking PHP at all, and they
 * carry `X-Robots-Tag` while carrying none of the four headers `AppSecurityHeaders` sets. `/up` is the
 * same story one layer up: a PHP route outside every group that middleware is mounted on. No Laravel
 * middleware can put a header on a file PHP never sees, and `/robots.txt` is the one URL crawlers
 * actually fetch. Moving the header would LOSE it on five of the six paths below.
 *
 * ⚠️ THE HEADER IS RESPONSE-SCOPED, WHICH IS THE WHOLE REASON COVERAGE IS PROBED PER-PATH RATHER THAN
 * ONCE. An `X-Robots-Tag` on `/login` says nothing about `/robots.txt`. (HSTS, by contrast, is
 * HOST-scoped — one response carrying it covers the origin — which is why that question is `D54` and
 * not folded in here. If `D54` puts HSTS in the vhost, arming it is one entry in
 * STAGING_REQUIRED_HEADERS rather than a second gate.)
 *
 * THE THREE-WAY EXIT CONTRACT, reused rather than invented — `scripts/npm-audit-judge.php`,
 * `scripts/tracker-surgery.php` and `scripts/pre-push-guard.php` already publish it:
 *
 *   0  judged, and every required header is present on every probe
 *   1  a required header is missing or weakened — the merge is blocked
 *   2  the responses were never obtained, so NOTHING was judged
 *
 * ⛔ THE DISCRIMINATOR IS THE PAYLOAD, NEVER THE EXIT CODE OF `curl`, AND IT KEYS POSITIVELY. This file
 * asks "did we reach OUR site?" and never "is there no error?" — because the negative form puts every
 * UNRECOGNISED shape (a captive portal, a proxy error page, a truncated capture, a DNS hijack) silently
 * into CLEAN. The positive test is that the `/robots.txt` capture's body is BYTE-IDENTICAL to the
 * tracked `public/robots.txt`. Nothing else in a response is both stable and ours; a status line is
 * not, a `Server:` banner moves with an Apache upgrade, and an HTML body changes every deploy. As a
 * free side effect it also asserts the deployed tree still matches the repository for that file.
 *
 * ⛔ A 5xx IS CANNOT MEASURE, NOT A FAILURE. A 502 from something in front of the site tells us nothing
 * about the site's own header policy. 2xx, 3xx and 4xx are all genuine measurements — the 404 probe is
 * deliberate, because an error path is exactly where a header rule is most often forgotten.
 *
 * ⛔ AND IT REPORTS A FLOOR. `controller-gate` reported "passed" while seeing 49 of 97 files, because a
 * gate with no floor is green when it is blind. STAGING_MIN_PROBES and STAGING_IDENTITY_PROBE are that
 * floor: trimming the probe list down to the one path that is easiest to keep passing is refused here
 * rather than discovered later.
 *
 * ⚠️ NO CONTENT-HASHED PATH MAY EVER BE A PROBE. `/build/assets/app-<hash>.css` was in the first draft
 * of this list and would have gone red on the next deploy, because Vite renames it on every build. A
 * probe must be a path whose name is stable across deploys.
 *
 * ⚠️ THIS FILE MAKES NO NETWORK CALL. Fetching is a separate CI step that is allowed to fail, exactly
 * as `npm audit --json` is, because "the box is unreachable" and "the protection is gone" must not be
 * the same red. See `.github/workflows/ci.yml`.
 *
 * Usage:
 *   php scripts/staging-headers-judge.php --probes
 *   php scripts/staging-headers-judge.php --captures=<dir>
 */

const STAGING_EXIT_OK = 0;
const STAGING_EXIT_BLOCKED = 1;
const STAGING_EXIT_CANNOT_MEASURE = 2;

/** The host the probes are fetched from. Here rather than in `ci.yml` so there is one authority. */
const STAGING_HOST = 'staging.pitahc.gov.ph';

/**
 * Header => the directives every probe's response must carry, lower-cased.
 *
 * A map rather than a single header name, because `D54` may add `Strict-Transport-Security` beside it;
 * arming that is then one entry here rather than a second gate. Kept as an explicit list rather than a
 * substring match so that a mutation of one directive is a one-token change.
 */
const STAGING_REQUIRED_HEADERS = [
    'X-Robots-Tag' => ['noindex', 'nofollow', 'noarchive'],
];

/**
 * The paths probed, in order. `--probes` prints these and the CI fetch step consumes that output, so
 * the list is never duplicated in a workflow file.
 *
 * ⛔ THE SPREAD IS THE POINT, NOT THE COUNT. Two static files Apache serves with no PHP, three PHP
 * routes (one of them outside every group `AppSecurityHeaders` is mounted on), and one 404. A list of
 * six ordinary pages would pass while telling us nothing about the paths that are actually exposed.
 */
const STAGING_PROBES = [
    '/',                      // PHP, 302 to /login
    '/login',                 // PHP, 200, inside the app's own header middleware
    '/up',                    // PHP, 200, OUTSIDE it — the health route carries no app headers
    '/robots.txt',            // static, and the identity anchor below
    '/favicon.ico',           // static
    '/nonexistent-static.txt', // PHP, 404 — an error path is where a header rule is forgotten
];

/** Refuse a probe list trimmed below this. A gate that probes one path is a gate that is nearly blind. */
const STAGING_MIN_PROBES = 4;

/** The probe whose body proves we reached our own site, and the tracked file it must equal. */
const STAGING_IDENTITY_PROBE = '/robots.txt';
const STAGING_IDENTITY_FILE = 'public/robots.txt';

const STAGING_LONG_OPTS = ['help', 'probes', 'captures'];

$argv = $_SERVER['argv'] ?? [];
$arguments = array_slice($argv, 1);

// ── Refuse any unrecognised option, reading $argv rather than getopt().
//    getopt() SILENTLY DISCARDS every long option not in its allowlist and cannot report what it
//    discarded, so a typo'd flag falls straight through to the action.
$captures = null;
$wantProbes = false;

foreach ($arguments as $argument) {
    if (! str_starts_with($argument, '-')) {
        fwrite(STDERR, "staging-headers-judge: unexpected positional argument '{$argument}'.\n\n".staging_usage());

        exit(STAGING_EXIT_CANNOT_MEASURE);
    }

    [$name, $value] = array_pad(explode('=', $argument, 2), 2, null);
    $name = ltrim((string) $name, '-');

    if (! in_array($name, STAGING_LONG_OPTS, true)) {
        fwrite(STDERR, "staging-headers-judge: unrecognised option '{$argument}'.\n\n".staging_usage());

        exit(STAGING_EXIT_CANNOT_MEASURE);
    }

    if ($name === 'captures') {
        $captures = (string) $value;
    }

    if ($name === 'probes') {
        $wantProbes = true;
    }
}

// The --help arm comes AFTER the refusal, so `--help --bogus` refuses rather than exiting 0.
if (in_array('--help', $arguments, true)) {
    fwrite(STDOUT, staging_usage());

    exit(STAGING_EXIT_OK);
}

if ($wantProbes) {
    foreach (STAGING_PROBES as $probe) {
        fwrite(STDOUT, $probe."\n");
    }

    exit(STAGING_EXIT_OK);
}

if ($captures === null || $captures === '') {
    fwrite(STDERR, "staging-headers-judge: --captures=<dir> or --probes is required.\n\n".staging_usage());

    exit(STAGING_EXIT_CANNOT_MEASURE);
}

exit(staging_judge($captures));

/**
 * Judge one directory of captures.
 *
 * The fetch step writes `<n>.headers` and `<n>.body` for the n-th probe, 1-based, in the order
 * `--probes` printed them.
 */
function staging_judge(string $dir): int
{
    // ── The floor comes first. A shrunken probe list must be refused BEFORE anything is judged,
    //    because the judging would otherwise pass and report a smaller, happier number.
    if (count(STAGING_PROBES) < STAGING_MIN_PROBES) {
        return staging_cannot_measure(sprintf(
            'the probe list holds %d path(s), under the floor of %d. A gate that probes fewer paths '.
            'than this is not measuring the coverage it exists to measure.',
            count(STAGING_PROBES),
            STAGING_MIN_PROBES,
        ));
    }

    if (! in_array(STAGING_IDENTITY_PROBE, STAGING_PROBES, true)) {
        return staging_cannot_measure(
            'the identity probe '.STAGING_IDENTITY_PROBE.' is not in the probe list, so nothing can '.
            'prove these responses came from our own site rather than from a portal or a proxy.'
        );
    }

    if (! is_dir($dir)) {
        return staging_cannot_measure("the capture directory is not a directory: {$dir}");
    }

    if (STAGING_REQUIRED_HEADERS === []) {
        return staging_cannot_measure('no header is required, so this judge would pass on anything.');
    }

    $identity = staging_identity_body();

    if ($identity === null) {
        return staging_cannot_measure(
            'the tracked '.STAGING_IDENTITY_FILE.' could not be read, so the identity of the captured '.
            'responses cannot be established.'
        );
    }

    $measured = 0;
    $failures = [];
    $report = [];

    foreach (STAGING_PROBES as $index => $probe) {
        $n = $index + 1;
        $headerPath = $dir.DIRECTORY_SEPARATOR.$n.'.headers';
        $bodyPath = $dir.DIRECTORY_SEPARATOR.$n.'.body';

        if (! is_file($headerPath)) {
            return staging_cannot_measure(
                "no capture for probe {$n} ({$probe}) at {$headerPath}. curl writes nothing when it ".
                'cannot connect at all, so an absent capture is the outage and not a clean response.'
            );
        }

        $raw = (string) file_get_contents($headerPath);
        $status = staging_status_of($raw);

        if ($status === null) {
            return staging_cannot_measure(
                "the capture for probe {$n} ({$probe}) carries no HTTP status line. This is a shape ".
                'this judge does not understand, and an unrecognised shape is NOT a clean one.'
            );
        }

        if ($status >= 500) {
            return staging_cannot_measure(
                "probe {$n} ({$probe}) answered {$status}. A 5xx says nothing about this site's own ".
                'header policy, so nothing has been judged.'
            );
        }

        if ($probe === STAGING_IDENTITY_PROBE) {
            $body = is_file($bodyPath) ? (string) file_get_contents($bodyPath) : null;

            if ($body !== $identity) {
                return staging_cannot_measure(sprintf(
                    'the body served at %s is not byte-identical to the tracked %s (%d byte(s) served, '.
                    '%d tracked). These responses cannot be attributed to our own site, so nothing has '.
                    'been judged — this is NOT a finding about the header.',
                    $probe,
                    STAGING_IDENTITY_FILE,
                    $body === null ? -1 : strlen($body),
                    strlen($identity),
                ));
            }
        }

        $headers = staging_headers_of($raw);
        $measured++;

        foreach (STAGING_REQUIRED_HEADERS as $header => $directives) {
            $value = $headers[strtolower($header)] ?? null;

            if ($value === null) {
                $failures[] = "{$probe} — {$header} is ABSENT (HTTP {$status})";

                continue;
            }

            $present = array_map(
                static fn (string $part): string => strtolower(trim($part)),
                explode(',', $value),
            );

            $missing = array_values(array_diff($directives, $present));

            if ($missing !== []) {
                $failures[] = "{$probe} — {$header} is '{$value}', missing ".implode(', ', $missing);

                continue;
            }

            $report[] = "{$probe} — {$header}: {$value}";
        }
    }

    $floor = sprintf(
        '%d probe(s) declared, %d measured, %d required header(s) — host %s',
        count(STAGING_PROBES),
        $measured,
        count(STAGING_REQUIRED_HEADERS),
        STAGING_HOST,
    );

    if ($failures !== []) {
        fwrite(STDERR, "staging-headers-judge: BLOCKED — a required header is missing or weakened.\n");
        fwrite(STDERR, 'staging-headers-judge: '.$floor."\n");

        foreach ($failures as $line) {
            fwrite(STDERR, '  - '.$line."\n");
        }

        fwrite(STDERR, "staging-headers-judge: this protection exists only as vhost configuration on the\n");
        fwrite(STDERR, "                      box. See docs/deployment-infrastructure.md section 8.3.\n");

        return STAGING_EXIT_BLOCKED;
    }

    fwrite(STDOUT, "staging-headers-judge: JUDGED PRESENT on every probe.\n");
    fwrite(STDOUT, 'staging-headers-judge: '.$floor."\n");

    foreach ($report as $line) {
        fwrite(STDOUT, '  - '.$line."\n");
    }

    return STAGING_EXIT_OK;
}

/** The tracked identity file's bytes, or null when it cannot be read. */
function staging_identity_body(): ?string
{
    $path = dirname(__DIR__).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, STAGING_IDENTITY_FILE);

    if (! is_file($path)) {
        return null;
    }

    $body = file_get_contents($path);

    return $body === false ? null : $body;
}

/** The status code of a capture, or null when it carries no status line at all. */
function staging_status_of(string $raw): ?int
{
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        if (preg_match('#^HTTP/[0-9.]+\s+([0-9]{3})#', trim($line), $m) === 1) {
            return (int) $m[1];
        }
    }

    return null;
}

/**
 * The headers of a capture, keyed lower-case.
 *
 * A repeated header keeps the LAST value, which is what a client sees for the single-valued headers
 * this judge reads.
 *
 * @return array<string, string>
 */
function staging_headers_of(string $raw): array
{
    $headers = [];

    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        if (! str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));

        if ($name === '' || str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
            continue;
        }

        $headers[$name] = trim($value);
    }

    return $headers;
}

/** Refuse loudly. Exit 2 is never a pass — a judge that never saw the responses has judged nothing. */
function staging_cannot_measure(string $why): int
{
    fwrite(STDERR, "staging-headers-judge: CANNOT MEASURE — {$why}\n");
    fwrite(STDERR, "staging-headers-judge: this is exit 2 and NOT a pass. Nothing was judged, so nothing\n");
    fwrite(STDERR, "                      about the testing site's headers has been established.\n");

    return STAGING_EXIT_CANNOT_MEASURE;
}

function staging_usage(): string
{
    $headers = implode(', ', array_keys(STAGING_REQUIRED_HEADERS));

    return "Usage: php scripts/staging-headers-judge.php --probes\n".
        "       php scripts/staging-headers-judge.php --captures=<dir>\n\n".
        "Judges responses already captured from ".STAGING_HOST.". It does NOT fetch: fetching and\n".
        "judging are separate steps precisely because an unreachable box and a missing header must\n".
        "not be the same red.\n\n".
        "  --probes           print the paths to fetch, one per line, in capture order\n".
        "  --captures=<dir>   judge <dir>/<n>.headers and <dir>/<n>.body for the n-th probe\n\n".
        "  0  judged, and every required header is present on every probe ({$headers})\n".
        "  1  a required header is missing or weakened — the merge is blocked\n".
        "  2  the responses were never obtained, so NOTHING was judged\n\n".
        "See the block at the top of this file for why exit 2 exists, why the recognition test is\n".
        "positive, and why no content-hashed path may ever be a probe.\n";
}
