<?php

declare(strict_types=1);

/*
 * npm-audit judge (M72) — separates FETCHING advisories from JUDGING them.
 *
 * WHY THIS EXISTS.
 *
 * `npm audit --omit=dev --audit-level=high` exits 1 for two completely different outcomes:
 *
 *   (a) a high or critical advisory reaches the shipped runtime bundle, and
 *   (b) `npm error audit endpoint returned an error` — the registry could not be reached.
 *
 * One indistinguishable red. Measured twice on consecutive increments: M69's PR run 33818367732
 * (`npm warn audit network timeout`) and M70's POST-MERGE run on the trunk, 33852073344
 * (`npm warn audit 503 Service Unavailable`), the second of which reddened `main` while the other
 * five jobs went green and no JS manifest was in the diff.
 *
 * ⛔ THIS IS THE FALSE-RED TWIN OF A CLASS THIS REPOSITORY HAS ONLY EVER MET AS FALSE GREEN, AND IT
 * IS WORSE IN ONE SPECIFIC WAY. `I5`'s `steps: []`, Pint before its probe, M61's `e2e` wrong form and
 * M69's PHPStan-crash-exits-0 are all gates that report success without measuring. A false green is
 * discovered eventually. A false red is RE-RUN UNTIL IT PASSES, which teaches the operator to re-run
 * a red gate — the habit every one of those other rows exists to prevent. It happened twice.
 *
 * ⛔ WHY A RETRY LOOP IS THE WRONG FIX, RECORDED SO IT IS NOT TRIED. A retry around the network call
 * fixes the flake and PRESERVES THE CONFLATION: a genuine registry outage would still read as a clean
 * audit once it stopped erroring. The only honest shape separates fetching from judging, and fails
 * DIFFERENTLY when the advisories were never obtained.
 *
 * ⛔ THE DISCRIMINATOR IS THE PAYLOAD, NEVER THE EXIT CODE, AND IT KEYS POSITIVELY.
 * `npm audit --json` emits `metadata.vulnerabilities` (a per-severity count map) when it MEASURED,
 * and an object keyed `error` when it could not. This file asks "is there a severity count map?" and
 * never "is there no `error` key?" — because the negative form puts every UNRECOGNISED shape
 * (a future npm, a truncated file, an HTML error page) silently into CLEAN, which is the same defect
 * one layer up. An unrecognised shape is CANNOT MEASURE, by construction.
 *
 * THE THREE-WAY EXIT CONTRACT, reused rather than invented — `scripts/tracker-surgery.php` and
 * `scripts/pre-push-guard.php` already publish it:
 *
 *   0  judged, and clean at the blocking threshold
 *   1  a blocking advisory reaches production dependencies — the merge is blocked
 *   2  the advisories were never obtained, so NOTHING was judged
 *
 * ⚠️ EXIT 2 IS NOT A PASS AND THE CALLER DECIDES WHAT TO DO WITH IT. `ci.yml` renders it as a step
 * annotation and a job summary and does NOT block, which deliberately makes a required context green
 * while nothing was measured. That is a real member of the vacuous-success family and is filed as
 * `D16` plus its own backlog row, rather than left as a comment nobody re-reads. The alternative — a
 * separate non-required job — costs a runner and a second `npm install`.
 *
 * ⚠️ THE THRESHOLD LIVES HERE, NOT ON THE npm COMMAND LINE. `--audit-level=high` was an argument
 * nothing could drive; NPM_AUDIT_BLOCKING is a constant `scripts/mutate.php` can mutate, which is what
 * makes the threshold provable. The SCOPE stays where it was: production dependencies only, via
 * `--omit=dev` on the fetch. That flag's policy was locked with the user (PROGRESS_ARCHIVE.md:544,
 * commit 7154d5f / PR #61) and is not this increment's to move.
 *
 * Usage: php scripts/npm-audit-judge.php <report.json>
 */

const NPM_AUDIT_EXIT_OK = 0;
const NPM_AUDIT_EXIT_BLOCKED = 1;
const NPM_AUDIT_EXIT_CANNOT_MEASURE = 2;

/**
 * The severities that block a merge.
 *
 * Kept as a list rather than an ordinal comparison so that "high or critical" is one readable fact
 * instead of a threshold plus a severity ordering, and so a mutation of it is a one-token change.
 */
const NPM_AUDIT_BLOCKING = ['high', 'critical'];

/**
 * Every severity npm reports, in its own order.
 *
 * Used as the POSITIVE recognition test: a report whose `metadata.vulnerabilities` does not carry
 * every one of these keys is a shape this file does not understand, and an unrecognised shape is
 * CANNOT MEASURE rather than clean.
 */
const NPM_AUDIT_SEVERITIES = ['info', 'low', 'moderate', 'high', 'critical'];

const NPM_AUDIT_LONG_OPTS = ['help'];

$argv = $_SERVER['argv'] ?? [];
$arguments = array_slice($argv, 1);

// ── Refuse any unrecognised option, reading $argv rather than getopt().
//    getopt() SILENTLY DISCARDS every long option not in its allowlist and cannot report what it
//    discarded, so a typo'd flag falls straight through to the action. Three scripts in this
//    directory have that shape with a DESTRUCTIVE default and are filed as their own row; this one
//    only reads, but the discipline costs nothing and the refusal is the half that matters.
$positional = [];

foreach ($arguments as $argument) {
    if (! str_starts_with($argument, '-')) {
        $positional[] = $argument;

        continue;
    }

    $name = ltrim(explode('=', $argument, 2)[0], '-');

    if (! in_array($name, NPM_AUDIT_LONG_OPTS, true)) {
        fwrite(STDERR, "npm-audit-judge: unrecognised option '{$argument}'.\n\n".npm_audit_usage());

        exit(NPM_AUDIT_EXIT_CANNOT_MEASURE);
    }
}

// The --help arm comes AFTER the refusal, so `--help --bogus` refuses rather than exiting 0.
if (in_array('--help', $arguments, true)) {
    fwrite(STDOUT, npm_audit_usage());

    exit(NPM_AUDIT_EXIT_OK);
}

if (count($positional) !== 1) {
    fwrite(STDERR, "npm-audit-judge: expected exactly one report path, got ".count($positional).".\n\n".npm_audit_usage());

    exit(NPM_AUDIT_EXIT_CANNOT_MEASURE);
}

exit(npm_audit_judge($positional[0]));

/**
 * Judge one `npm audit --json` report.
 *
 * @return int one of the three NPM_AUDIT_EXIT_* codes
 */
function npm_audit_judge(string $path): int
{
    if (! is_file($path)) {
        return npm_audit_cannot_measure("the report is not a file: {$path}");
    }

    $body = file_get_contents($path);

    if ($body === false || trim($body) === '') {
        return npm_audit_cannot_measure(
            "the report is empty ({$path}). npm writes nothing to stdout when it cannot reach the ".
            'advisory endpoint at all, so an empty file is the outage and not a clean tree.'
        );
    }

    $report = json_decode($body, true);

    if (! is_array($report)) {
        return npm_audit_cannot_measure(
            'the report is not JSON: '.json_last_error_msg().'. Expected the object `npm audit --json` '.
            'writes to stdout.'
        );
    }

    // ⛔ POSITIVE RECOGNITION. Not `! isset($report['error'])` — see the block at the top of this file.
    $counts = $report['metadata']['vulnerabilities'] ?? null;

    if (! is_array($counts)) {
        return npm_audit_cannot_measure(npm_audit_describe_unmeasured($report).
            ' No `metadata.vulnerabilities` map is present, so no dependency was judged.');
    }

    $missing = array_values(array_diff(NPM_AUDIT_SEVERITIES, array_keys($counts)));

    if ($missing !== []) {
        return npm_audit_cannot_measure(
            '`metadata.vulnerabilities` is missing the severity key(s) '.implode(', ', $missing).
            '. This is a report shape this judge does not understand, and an unrecognised shape is '.
            'NOT a clean one.'
        );
    }

    $blocking = [];

    foreach (NPM_AUDIT_BLOCKING as $severity) {
        $found = (int) ($counts[$severity] ?? 0);

        if ($found > 0) {
            $blocking[$severity] = $found;
        }
    }

    $tally = [];

    foreach (NPM_AUDIT_SEVERITIES as $severity) {
        $tally[] = $severity.'='.(int) ($counts[$severity] ?? 0);
    }

    if ($blocking === []) {
        fwrite(STDOUT, "npm-audit-judge: JUDGED CLEAN at the blocking threshold (".
            implode(' · ', NPM_AUDIT_BLOCKING).").\n");
        fwrite(STDOUT, 'npm-audit-judge: production advisories — '.implode(' · ', $tally)."\n");

        return NPM_AUDIT_EXIT_OK;
    }

    $summary = [];

    foreach ($blocking as $severity => $found) {
        $summary[] = $found.' '.$severity;
    }

    fwrite(STDERR, 'npm-audit-judge: BLOCKED — '.implode(', ', $summary).
        " in PRODUCTION dependencies.\n");
    fwrite(STDERR, 'npm-audit-judge: production advisories — '.implode(' · ', $tally)."\n");

    foreach (npm_audit_blocking_packages($report) as $line) {
        fwrite(STDERR, '  - '.$line."\n");
    }

    return NPM_AUDIT_EXIT_BLOCKED;
}

/**
 * Name the packages behind a blocking verdict, so the failure is actionable rather than a count.
 *
 * @param  array<string, mixed>  $report
 * @return list<string>
 */
function npm_audit_blocking_packages(array $report): array
{
    $found = [];
    $entries = $report['vulnerabilities'] ?? null;

    if (! is_array($entries)) {
        return $found;
    }

    foreach ($entries as $name => $entry) {
        if (! is_array($entry)) {
            continue;
        }

        $severity = is_string($entry['severity'] ?? null) ? $entry['severity'] : '';

        if (! in_array($severity, NPM_AUDIT_BLOCKING, true)) {
            continue;
        }

        $range = is_string($entry['range'] ?? null) ? $entry['range'] : '(range unreported)';
        $found[] = (string) $name.' — '.$severity.' — '.$range;
    }

    sort($found);

    return $found;
}

/**
 * Say what npm reported instead of a measurement, when it said anything at all.
 *
 * @param  array<string, mixed>  $report
 */
function npm_audit_describe_unmeasured(array $report): string
{
    $error = $report['error'] ?? null;

    if (is_array($error)) {
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'no code';
        $summary = is_string($error['summary'] ?? null) ? $error['summary'] : 'no summary';

        return "npm reported an error instead of a report: {$code} — {$summary}.";
    }

    return 'npm reported keys ['.implode(', ', array_keys($report)).'] and no advisory counts.';
}

/** Refuse loudly. Exit 2 is never a pass — a judge that cannot see the advisories has judged nothing. */
function npm_audit_cannot_measure(string $why): int
{
    fwrite(STDERR, "npm-audit-judge: CANNOT MEASURE — {$why}\n");
    fwrite(STDERR, "npm-audit-judge: this is exit 2 and NOT a pass. Nothing was judged, so nothing\n");
    fwrite(STDERR, "                 about this tree's dependencies has been established.\n");

    return NPM_AUDIT_EXIT_CANNOT_MEASURE;
}

function npm_audit_usage(): string
{
    return "Usage: php scripts/npm-audit-judge.php <report.json>\n\n".
        "Judges a report written by `npm audit --json --omit=dev`. It does NOT fetch: fetching and\n".
        "judging are separate steps precisely because npm's exit code cannot tell an unreachable\n".
        "registry from a vulnerable dependency.\n\n".
        "  0  judged, and clean at the blocking threshold (".implode(', ', NPM_AUDIT_BLOCKING).")\n".
        "  1  a blocking advisory reaches production dependencies — the merge is blocked\n".
        "  2  the advisories were never obtained, so NOTHING was judged\n\n".
        "See the block at the top of this file for why exit 2 exists and why the recognition test is\n".
        "positive rather than negative.\n";
}
