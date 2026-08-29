<?php

declare(strict_types=1);

/*
 * Project state, derived from the tree (M42).
 *
 * WHY THIS EXISTS. Every number needed to start an increment has, at some point, been read out of a
 * sentence — and prose does not change when the tree does. Standing Rule 7(g) declared the next free
 * ADR number as 0021 from M15, the increment that SPENT 0021, until M38 corrected it 23 increments
 * later, sitting directly above its own sentence boasting it had done exactly this once before with
 * 0020. scripts/preflight.php derives the increment number by scraping the highest M<n> LITERAL out of
 * both claim files, so a forecast, a quoted hand-off, or a sentence about that very bug all read as a
 * spend — measured at +3, answering "next free is M42" when the truth was M40.
 *
 * So every figure below is derived from the filesystem or from git, and NONE is parsed out of a
 * sentence. Prose is scanned only to report which sentences now disagree with the tree.
 *
 * ⛔ THIS IS DELIBERATELY NOT A ci.yml STEP AND NOT PART OF `composer run quality` — the same status
 * as scripts/preflight.php, whose header says so in terms. It is an instrument you run at session open.
 * The merge-blocking half of this increment is R8 in scripts/tracker-lint.php, which gates CLAUDE.md.
 *
 * ⛔ THE ## RELEASED SCAN IS TRUNCATED AT ## Template BEFORE IT MATCHES ANYTHING, AND THAT TRUNCATION
 * IS THE WHOLE DIFFERENCE BETWEEN THIS AND A SEARCH. Both claim files carry a verbatim
 * "## RELEASED — <row name> (merged as PR #<n>, <sha>, 6/6)" line under their own Template heading —
 * an anchor quoted inside the file it anchors. That exact shape deleted 1,086 lines from PROGRESS.md
 * on 2026-08-16 (f565ac9). The truncation is then PROVEN rather than assumed: exactly one Template
 * heading per file, and NOTHING NUMBERED below it — which is the assertion that matters, because
 * lane-a.md's template example was moved out to TEMPLATE.md in M36 and lane-b.md's is still there, so
 * counting what truncation discards would be red on a correct tree.
 *
 * ⛔ AND THE MAXIMUM IS CROSS-CHECKED AGAINST A SECOND, INDEPENDENT SOURCE, because the failure mode
 * of a mis-truncated scan is SILENT — it yields a LOWER maximum, not an error, and a lower maximum is
 * a number collision. `gh pr list --state merged` is that second source. It is allowed to hold a
 * SMALLER set (M24 landed on main with no PR at all) but not a different maximum.
 *
 * Usage:
 *   php scripts/state.php                 # human report
 *   php scripts/state.php --json          # the document on STDOUT and NOTHING else
 *   php scripts/state.php --check         # exit 1 when a live declaration disagrees with the tree
 *   php scripts/state.php --no-rows       # omit the bulky lists: backlog rows and the prose scan
 *   php scripts/state.php --offline       # skip every network call; those fields report why
 *
 * Exit 0 = clean. Exit 1 = a --check violation. Exit 2 = COULD NOT MEASURE — never a silent skip, and
 * never a document containing a guessed field.
 */

const CLAIM_FILES = ['a' => 'docs/claims/lane-a.md', 'b' => 'docs/claims/lane-b.md'];
const TRACKER = 'PROGRESS.md';
const IMPERATIVES = 'CLAUDE.md';
const ADR_DIR = 'docs/adr';
const MIGRATION_DIR = 'database/migrations';
const BACKLOG = 'docs/feature-backlog.md';
const TRIAGE = 'docs/backlog-triage.md';
const DECISIONS = 'docs/claims/decisions.md';
const BASELINES = 'docs/gate-baselines.md';
const EXCEPTIONS_LOG = 'docs/ux/exceptions-log.md';

// ⛔ `ls` CANNOT TELL A RESERVATION FROM A DELETION, so the one gap in docs/adr/ is named here rather
//    than inferred. 0010 is held for H1d, the OCR provider bake-off, and five ADRs say so in their own
//    Related-ADRs line while declining to fill it. A SECOND gap is therefore not measurable: it means
//    either a deleted ADR or a second reservation, and guessing between those is how 0017 came to mean
//    two different documents (133 references across 66 files to repair).
const ADR_RESERVED = [10];

// An empty-scan floor, not a ratchet. A parse that matches nothing must never report "highest 0, so
// the next free number is M1" — four lint gates gained a floor in M36 for exactly this reason, after
// controller-gate printed `passed` while scanning 49 of 97 files.
const MIN_RELEASED_HEADINGS = 20;

// Lane A writes its own claim file and never lane-b.md; one writer each is what makes a claim conflict
// structurally impossible rather than merely unlikely. A disagreement inside lane-b.md is therefore
// REPORTED and never gated on — Lane A cannot fix it without breaking the rule that found it.
const LANE_A_WRITABLE = [TRACKER, IMPERATIVES, 'docs/claims/lane-a.md'];

// ⛔⛔ THE ONE CONSTRAINED FORM IN THIS PROJECT, AND IT TOOK THREE FAILED ATTEMPTS IN ONE SITTING TO
//     ARRIVE AT IT. A declaration and a quotation of a declaration are the same bytes: preflight reads
//     any M<n> literal in a claim file as a spend; R7 read a MENTION of its surgery marker as a
//     DECLARATION because the commit EXPLAINING the marker armed it; this script's first draft went red
//     on its own claim, and its second went red on every increment the hand-off narrates. Natural
//     language cannot carry a machine token safely.
//
//     So the token is not natural language, and it is POSITIONAL as well as structural: an anchored
//     bracket immediately after the hand-off arrow, rendered end to end by scripts/next.php out of this
//     script's figures. Prose quoting it later in the line cannot arm it, because the pattern only ever
//     matches at that one offset.
const HANDOFF_STATE_MARKER = '/^\*\*LANE ([AB]) NEXT PROMPT →\*\* `\[state ([^\]]*)\]/';

$root = dirname(__DIR__);
chdir($root);

$opts = getopt('', ['json', 'check', 'no-rows', 'offline']);
$asJson = isset($opts['json']);
$checking = isset($opts['check']);
$withRows = ! isset($opts['no-rows']);
$offline = isset($opts['offline']);

$state = [
    'increment' => derive_increment($offline),
    'adr' => derive_adr(),
    'migration' => derive_migration(),
    'exceptions' => derive_exceptions(),
    'claims' => derive_claims(),
    'decisions' => derive_decisions(),
    'backlog' => derive_backlog($withRows),
    'baselines' => derive_baselines(),
    'triage' => derive_triage(),
    'worktrees' => derive_worktrees(),
];

$state['declarations'] = scan_declarations($state);
$state['check'] = run_check($state);

// The prose scan is advisory and long — 226 literals today, most of them correct history. It is data,
// not noise, but it is far too heavy to inject into every session, so --no-rows drops it alongside the
// backlog rows and keeps the per-file summary that actually gets read.
$state['declaration_summary'] = summarise_declarations($state['declarations']);

if (! $withRows) {
    $state['declarations'] = null;
}

if ($asJson) {
    fwrite(STDOUT, json_encode(
        $state,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    )."\n");

    exit($checking && $state['check']['failures'] !== [] ? 1 : 0);
}

render($state);

exit($checking && $state['check']['failures'] !== [] ? 1 : 0);

// ── derivations ──────────────────────────────────────────────────────────────────────────────────

/**
 * The increment number, from the ## RELEASED headings of both claim files and from nothing else.
 *
 * @return array<string, mixed>
 */
function derive_increment(bool $offline): array
{
    $numbered = [];
    $unnumbered = [];
    $perFile = [];

    foreach (CLAIM_FILES as $lane => $path) {
        $body = read_tracked_file($path);

        // Prove the truncation boundary before using it. Exactly one Template heading, and exactly one
        // ## RELEASED heading after it — the verbatim example that would otherwise be matched.
        $templates = preg_match_all('/^## Template$/m', $body);

        if ($templates !== 1) {
            cannot_measure("{$path} has {$templates} '## Template' heading(s) — expected exactly 1. The ".
                           'RELEASED scan cannot be truncated safely, and an untruncated scan matches the '.
                           'template example that lives under it.');
        }

        $parts = preg_split('/^## Template$/m', $body, 2);
        $live = is_array($parts) ? (string) $parts[0] : '';
        $tail = is_array($parts) && isset($parts[1]) ? (string) $parts[1] : '';

        // ⛔ WHAT MUST BE ASSERTED IS THAT TRUNCATION DISCARDS NOTHING REAL, NOT THAT IT DISCARDS A
        //    FIXED NUMBER OF THINGS. lane-b.md still carries a verbatim RELEASED example under its
        //    Template heading; lane-a.md's was moved to TEMPLATE.md in M36 and its section holds none.
        //    Requiring exactly one would therefore be red on a correct tree. What is never acceptable
        //    is an M-NUMBERED heading below the boundary: that means either the template acquired a
        //    real number or a release was filed under it, and in both cases truncation is hiding a
        //    spend — which is a number collision, silently.
        $numberedAfter = preg_match_all('/^## RELEASED — `?M\d{1,3}`?[,\s]/m', $tail);

        if ($numberedAfter !== 0) {
            cannot_measure("{$path} has {$numberedAfter} NUMBERED RELEASED heading(s) below its Template ".
                           'heading. Truncating there would discard a real spend, and the failure mode of '.
                           'a discarded spend is a LOWER maximum rather than an error.');
        }

        $total = preg_match_all('/^## RELEASED/m', $live);
        $excluded = preg_match_all('/^## RELEASED/m', $tail);
        $found = preg_match_all('/^## RELEASED — `?M(\d{1,3})`?[,\s]/m', $live, $m) === false ? [] : $m[1];
        $perFile[$lane] = [
            'path' => $path,
            'released' => $total,
            'numbered' => count($found),
            'excluded_by_truncation' => $excluded,
        ];

        foreach ($found as $n) {
            $numbered[] = (int) $n;
        }

        // Two lane-a releases carry no M number at all. That is correct, not a parse failure — but it
        // is reported, because a NEW one would be an anomaly worth seeing.
        foreach (lines($live) as $line) {
            if (str_starts_with($line, '## RELEASED') && preg_match('/^## RELEASED — `?M\d{1,3}`?[,\s]/', $line) !== 1) {
                $unnumbered[] = trim($line);
            }
        }
    }

    if (count($numbered) < MIN_RELEASED_HEADINGS) {
        cannot_measure('found only '.count($numbered).' M-numbered RELEASED heading(s) across both claim '.
                       'files, below the floor of '.MIN_RELEASED_HEADINGS.'. A scan that matches almost '.
                       'nothing reports a LOW maximum, not an error, and a low maximum is a collision.');
    }

    $highest = max($numbered);
    [$remoteMax, $remoteReason] = $offline ? [null, 'skipped by --offline'] : remote_highest();

    return [
        'highest_released' => $highest,
        'next_free' => $highest + 1,
        'source' => 'the RELEASED headings of both claim files, truncated at the Template heading',
        'headings_numbered' => count($numbered),
        'headings_unnumbered' => $unnumbered,
        'per_file' => $perFile,
        'remote_max' => $remoteMax,
        'remote_reason' => $remoteReason,
        'sources_agree' => $remoteMax === null ? null : $remoteMax === $highest,
    ];
}

/**
 * The second, independent maximum: merged pull-request titles.
 *
 * ⛔ THE TITLE MUST BEGIN WITH THE INCREMENT FORM, AND ONE OTHER GATE USED TO ASK FOR THE OPPOSITE.
 *    The anchor below is why: a title that does not START with `M<n>:` is silently invisible here,
 *    and the failure mode of an invisible spend is a LOWER maximum rather than an error — i.e. a
 *    number collision. Until M47, R7 in scripts/tracker-lint.php told anyone whose surgery marker
 *    had been eaten by a squash to "put it in the PR title", which would have prefixed exactly this
 *    string and dropped that pull request out of the cross-check. The two gates wanted incompatible
 *    first characters and neither file mentioned the other. R7 now accepts the marker behind the
 *    bullet a squash produces and no longer sends anyone here; if this anchor is ever loosened,
 *    that advice becomes safe again and R7's message should be revisited in the same commit.
 *
 * @return array{0: ?int, 1: ?string}
 */
function remote_highest(): array
{
    $status = 0;
    $json = sh('gh pr list --state merged --limit 300 --json title 2>&1', $status);

    if ($status !== 0) {
        return [null, 'gh exited '.$status.' — '.first_line($json)];
    }

    $prs = json_decode($json, true);

    if (! is_array($prs) || $prs === []) {
        return [null, 'gh returned no merged pull requests'];
    }

    $max = null;

    foreach ($prs as $pr) {
        if (is_array($pr) && preg_match('/^M(\d{1,3}):/', (string) ($pr['title'] ?? ''), $m) === 1) {
            $max = max($max ?? 0, (int) $m[1]);
        }
    }

    return $max === null ? [null, 'no merged pull-request title matched the M<n> form'] : [$max, null];
}

/**
 * @return array<string, mixed>
 */
function derive_adr(): array
{
    $numbers = [];

    foreach (list_dir(ADR_DIR) as $name) {
        if (preg_match('/^(\d{4})-.+\.md$/', $name, $m) === 1) {
            $numbers[] = (int) $m[1];
        }
    }

    if ($numbers === []) {
        cannot_measure('no numbered ADR found in '.ADR_DIR.' — the pattern is broken or the directory moved.');
    }

    sort($numbers);
    $highest = max($numbers);
    $gaps = array_values(array_diff(range(1, $highest), $numbers));

    if ($gaps !== ADR_RESERVED) {
        cannot_measure(ADR_DIR.' has gap(s) '.implode(', ', array_map('pad4', $gaps)).', expected exactly '.
                       implode(', ', array_map('pad4', ADR_RESERVED)).'. A gap is either a reservation or '.
                       'a deletion and a directory listing cannot tell them apart — settle it in the ADRs, '.
                       'then update ADR_RESERVED in this file.');
    }

    return [
        'next_free' => pad4($highest + 1),
        'highest' => pad4($highest),
        'count' => count($numbers),
        'reserved' => array_map('pad4', ADR_RESERVED),
        'reserved_note' => 'held for H1d, the OCR provider bake-off — a gap in the listing, not an allocation',
        'source' => 'the contents of '.ADR_DIR,
    ];
}

/**
 * @return array<string, mixed>
 */
function derive_migration(): array
{
    $prefixes = [];

    foreach (list_dir(MIGRATION_DIR) as $name) {
        if (preg_match('/^(\d{4}_\d{2}_\d{2})_(\d{6})_.+\.php$/', $name, $m) === 1) {
            $prefixes[] = $m[1].'_'.$m[2];
        }
    }

    if ($prefixes === []) {
        cannot_measure('no migration matched the prefix pattern in '.MIGRATION_DIR.'.');
    }

    // ⛔ The date part is NOT frozen and the counter is NOT globally monotonic — it restarts per date.
    //    Take the maximum over the WHOLE prefix string; never hardcode a date.
    sort($prefixes);
    $highest = (string) end($prefixes);
    $date = substr($highest, 0, 10);
    $counter = (int) substr($highest, 11, 6);

    $seen = [];
    $duplicates = [];

    foreach ($prefixes as $prefix) {
        if (isset($seen[$prefix])) {
            $duplicates[$prefix] = true;
        }

        $seen[$prefix] = true;
    }

    return [
        'next_free' => sprintf('%s_%06d', $date, $counter + 1),
        'highest' => $highest,
        'files' => count($prefixes),
        'unique_prefixes' => count($seen),
        'duplicate_prefixes' => array_keys($duplicates),
        'duplicate_note' => $duplicates === []
            ? null
            : 'two-lane collisions, kept visible rather than tidied away — ADR-0018 records them',
        'source' => 'the contents of '.MIGRATION_DIR,
    ];
}

/**
 * The design-system exceptions log is a third shared namespace, and the log warns about exactly this
 * failure in its own words: a log that miscounts its own entries is the failure mode it exists to
 * prevent. Its entries are `## #<n> — …` headings, and its bodies cite other entries by number
 * mid-sentence, so the scan is line-anchored for the same reason every other scan here is.
 *
 * @return array<string, mixed>
 */
function derive_exceptions(): array
{
    if (! is_file(EXCEPTIONS_LOG)) {
        return [
            'next_free' => null,
            'reason' => EXCEPTIONS_LOG.' does not exist',
            'count' => null,
            'source' => EXCEPTIONS_LOG,
        ];
    }

    $found = preg_match_all('/^## #(\d+) /m', read_tracked_file(EXCEPTIONS_LOG), $m) === false ? [] : $m[1];

    if ($found === []) {
        return [
            'next_free' => null,
            'reason' => 'no `## #<n>` entry heading matched — the pattern is broken, which is NOT a zero',
            'count' => 0,
            'source' => EXCEPTIONS_LOG,
        ];
    }

    return [
        'next_free' => '#'.(max(array_map('intval', $found)) + 1),
        'reason' => null,
        'count' => count($found),
        'source' => EXCEPTIONS_LOG,
    ];
}

/**
 * Claim status is read from origin/main, not the working tree: an unpushed claim does not exist (M14),
 * and reading it from disk would show a session its own uncommitted intentions as though published.
 *
 * @return array<string, mixed>
 */
function derive_claims(): array
{
    $out = [];

    foreach (CLAIM_FILES as $lane => $path) {
        $status = first_line_matching(show_from_main($path), '/^## Status:/');
        $out['lane_'.$lane] = [
            'file' => $path,
            'status' => $status === null ? null : trim($status),
            'reason' => $status === null ? 'no Status heading found on origin/main' : null,
            'active' => $status !== null && str_contains($status, 'ACTIVE CLAIM'),
        ];
    }

    $out['source'] = 'git show origin/main — an unpushed claim does not exist';

    return $out;
}

/**
 * @return array<string, mixed>
 */
function derive_decisions(): array
{
    $body = read_tracked_file(DECISIONS);
    $parts = preg_split('/^## ANSWERED$/m', $body, 2);

    if (! is_array($parts) || ! isset($parts[1])) {
        cannot_measure(DECISIONS.' has no ANSWERED heading — the open/answered split cannot be made.');
    }

    $ids = static function (string $section): array {
        return preg_match_all('/^### D(\d+) — /m', $section, $m) === false ? [] : array_map('intval', $m[1]);
    };

    $open = $ids((string) $parts[0]);
    $answered = $ids((string) $parts[1]);
    sort($open);
    sort($answered);

    return [
        'open' => array_map(static fn (int $n): string => 'D'.$n, $open),
        'answered' => array_map(static fn (int $n): string => 'D'.$n, $answered),
        'source' => DECISIONS.', split on its ANSWERED heading',
    ];
}

/**
 * @return array<string, mixed>
 */
function derive_backlog(bool $withRows): array
{
    $lines = lines(read_tracked_file(BACKLOG));
    $rows = [];
    $current = null;

    foreach ($lines as $i => $line) {
        // ⛔ Rows are frequently NOT separated by a blank line, so a paragraph split is simply wrong.
        if (str_starts_with($line, '- ')) {
            if ($current !== null) {
                $rows[] = finish_row($current);
            }

            $current = ['line' => $i + 1, 'first' => $line, 'body' => $line];

            continue;
        }

        if ($line !== '' && $line[0] === '#') {
            if ($current !== null) {
                $rows[] = finish_row($current);
                $current = null;
            }

            continue;
        }

        if ($current !== null) {
            $current['body'] .= "\n".$line;
        }
    }

    if ($current !== null) {
        $rows[] = finish_row($current);
    }

    $open = array_values(array_filter($rows, static fn (array $r): bool => $r['severity'] !== null));
    $counts = ['major' => 0, 'minor' => 0, 'nit' => 0];

    foreach ($open as $row) {
        $counts[(string) $row['severity']]++;
    }

    return [
        'open' => count($open),
        'by_severity' => $counts,
        'total_bullets' => count($rows),
        'rows' => $withRows ? $open : null,
        'rows_reason' => $withRows ? null : 'omitted by --no-rows',
        'source' => BACKLOG,
    ];
}

/**
 * @param  array{line: int, first: string, body: string}  $row
 * @return array<string, mixed>
 */
function finish_row(array $row): array
{
    // ⛔ THE SEPARATOR IS U+00B7 MIDDLE DOT, NOT AN EM DASH. The same rows use an em dash elsewhere, so
    //    splitting on the dash a reader notices first mis-fires on nearly every row.
    $severity = null;
    $title = $row['first'];

    if (preg_match('/^- \*\*`(major|minor|nit)` \x{00B7} (.*)$/u', $row['first'], $m) === 1) {
        $severity = $m[1];
        $title = $m[2];
    }

    // The id hashes the title WITHOUT its severity token. A minor-to-major promotion is a decision
    // somebody just took deliberately, and re-identifying the row there would fire the "this row
    // changed, re-triage it" signal on the one change that needs no re-triage.
    $provenance = null;

    if (preg_match('/\b[Ff](?:iled|ound) (?:by|in)\s+\**`?([A-Z]\d{1,3}[a-z]?\d?)`?/u', $row['body'], $m) === 1) {
        $provenance = $m[1];
    }

    return [
        'id' => 'R-'.substr(sha1(rtrim($title)), 0, 8),
        'severity' => $severity,
        'title' => rtrim($title),
        'line' => $row['line'],
        'provenance' => $provenance,
    ];
}

/**
 * @return array<string, mixed>
 */
function derive_baselines(): array
{
    $body = read_tracked_file(BASELINES);
    $line = first_line_matching($body, '/^\*\*Measured from/');

    if ($line === null) {
        return [
            'run_id' => null, 'sha' => null, 'event' => null, 'branch' => null,
            'is_push_on_main' => null, 'commits_behind_main' => null,
            'commits_behind_reason' => 'no provenance line in '.BASELINES,
            'stale' => null, 'source' => BASELINES,
        ];
    }

    $run = preg_match('/runs\/(\d+)/', $line, $m) === 1 ? $m[1] : null;
    $sha = preg_match('/sha `([0-9a-f]{7,40})`/', $line, $m) === 1 ? $m[1] : null;
    $event = null;
    $branch = null;

    if (preg_match('/`(push|schedule|workflow_dispatch|pull_request)` on `([^`]+)`/', $line, $m) === 1) {
        $event = $m[1];
        $branch = $m[2];
    }

    $behind = null;
    $behindReason = null;

    if ($sha === null) {
        $behindReason = 'no sha in the provenance line';
    } else {
        $status = 0;
        $count = trim(sh('git rev-list --count '.escapeshellarg($sha.'..origin/main').' 2>&1', $status));

        if ($status === 0 && ctype_digit($count)) {
            $behind = (int) $count;
        } else {
            $behindReason = 'git could not count from that sha — '.first_line($count);
        }
    }

    return [
        'run_id' => $run,
        'sha' => $sha,
        'event' => $event,
        'branch' => $branch,
        'is_push_on_main' => $event === null ? null : ($event !== 'pull_request' && $branch === 'main'),
        'commits_behind_main' => $behind,
        'commits_behind_reason' => $behindReason,
        'stale' => $behind === null ? null : $behind > 0,
        'source' => BASELINES.', generated by scripts/gate-baselines.php',
    ];
}

/**
 * @return array<string, mixed>
 */
function derive_triage(): array
{
    $line = first_line_matching(read_tracked_file(TRIAGE), '/^\*\*Measured /');
    $sha = $line !== null && preg_match('/at `([0-9a-f]{7,40})`/', $line, $m) === 1 ? $m[1] : null;
    $behind = null;

    if ($sha !== null) {
        $status = 0;
        $count = trim(sh('git rev-list --count '.escapeshellarg($sha.'..origin/main').' 2>&1', $status));

        if ($status === 0 && ctype_digit($count)) {
            $behind = (int) $count;
        }
    }

    return [
        'sha' => $sha,
        'commits_behind_main' => $behind,
        'note' => 'a point-in-time census, not a live derivation — the row counts above are the tree',
        'source' => TRIAGE,
    ];
}

/**
 * @return list<string>
 */
function derive_worktrees(): array
{
    return array_values(array_filter(array_map('trim', explode("\n", sh('git worktree list 2>&1')))));
}

// ── prose, scanned only to report where it disagrees ─────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $state
 * @return list<array<string, mixed>>
 */
function scan_declarations(array $state): array
{
    $found = [];

    foreach (array_merge([TRACKER, IMPERATIVES], array_values(CLAIM_FILES)) as $path) {
        if (! is_file($path)) {
            continue;
        }

        foreach (lines((string) file_get_contents($path)) as $i => $line) {
            if (stripos($line, 'next free') === false) {
                continue;
            }

            foreach (claimed_values($line) as $claim) {
                $derived = match ($claim['kind']) {
                    'adr' => (string) $state['adr']['next_free'],
                    'migration' => (string) $state['migration']['next_free'],
                    default => 'M'.$state['increment']['next_free'],
                };

                $found[] = [
                    'file' => $path,
                    'line' => $i + 1,
                    'kind' => $claim['kind'],
                    'claimed' => $claim['value'],
                    'derived' => $derived,
                    'agrees' => $claim['value'] === $derived,
                    'writable_by_lane_a' => in_array($path, LANE_A_WRITABLE, true),
                ];
            }
        }
    }

    return $found;
}

/**
 * @param  list<array<string, mixed>>  $declarations
 * @return array<string, array{literals: int, disagreeing: int}>
 */
function summarise_declarations(array $declarations): array
{
    $summary = [];

    foreach ($declarations as $declaration) {
        $file = (string) $declaration['file'];
        $summary[$file] ??= ['literals' => 0, 'disagreeing' => 0];
        $summary[$file]['literals']++;
        $summary[$file]['disagreeing'] += $declaration['agrees'] === false ? 1 : 0;
    }

    return $summary;
}

/**
 * Every namespace-shaped literal sitting on a line that also says "next free".
 *
 * @return list<array{kind: string, value: string}>
 */
function claimed_values(string $line): array
{
    $out = [];

    if (preg_match_all('/\b(\d{4}_\d{2}_\d{2}_\d{6})\b/', $line, $m) !== false) {
        foreach ($m[1] as $value) {
            $out[] = ['kind' => 'migration', 'value' => $value];
        }
    }

    if (preg_match_all('/\bM(\d{1,3})\b/', $line, $m) !== false) {
        foreach ($m[1] as $value) {
            $out[] = ['kind' => 'increment', 'value' => 'M'.$value];
        }
    }

    if (stripos($line, 'adr') !== false && preg_match_all('/`(\d{4})`/', $line, $m) !== false) {
        foreach ($m[1] as $value) {
            $out[] = ['kind' => 'adr', 'value' => $value];
        }
    }

    return $out;
}

/**
 * ⛔ THE CHECK IS STRUCTURAL, NOT TEXTUAL, AND THE REASON IS THE ONE M41 PAID FOR. Inside PROGRESS.md a
 * live declaration and a dated quotation of a past one are TEXTUALLY IDENTICAL — the constitution's own
 * forward claim and a RELEASED bullet recording what was true two months ago differ only in which is
 * still true. A mention is indistinguishable from a declaration unless the FORM is constrained, so this
 * gates only the two places where the form IS constrained by structure: a claim file's Status block, and
 * the tracker's hand-off lines. Everything else is reported and never gated.
 *
 * @param  array<string, mixed>  $state
 * @return array<string, mixed>
 */
function run_check(array $state): array
{
    $failures = [];
    $warnings = [];
    $expected = [
        'next' => 'M'.$state['increment']['next_free'],
        'adr' => (string) $state['adr']['next_free'],
        'migration' => (string) $state['migration']['next_free'],
    ];

    foreach (lines(read_tracked_file(TRACKER)) as $i => $line) {
        if (preg_match('/^\*\*LANE ([AB]) NEXT PROMPT/', $line, $lane) !== 1) {
            continue;
        }

        if (preg_match(HANDOFF_STATE_MARKER, $line, $m) !== 1) {
            $warnings[] = sprintf(
                '%s:%d — lane %s\'s hand-off is hand-written: it carries no `[state …]` block, so nothing '.
                'about it can be checked. Regenerate it with scripts/next.php.',
                TRACKER, $i + 1, $lane[1],
            );

            continue;
        }

        foreach (parse_state_marker($m[2]) as $key => $value) {
            if (! isset($expected[$key])) {
                $failures[] = sprintf('%s:%d — unknown key `%s` in the state block', TRACKER, $i + 1, $key);

                continue;
            }

            if ($value !== $expected[$key]) {
                $failures[] = sprintf(
                    '%s:%d — the state block says %s=%s; the tree says %s',
                    TRACKER, $i + 1, $key, $value, $expected[$key],
                );
            }
        }

        foreach (array_diff(array_keys($expected), array_keys(parse_state_marker($m[2]))) as $missing) {
            $failures[] = sprintf('%s:%d — the state block omits `%s`', TRACKER, $i + 1, $missing);
        }
    }

    // CLAUDE.md carries no namespace literal at all, by contract: it is the imperative layer and every
    // number in it would be a copy. Its merge-blocking twin is R8 in scripts/tracker-lint.php.
    if (is_file(IMPERATIVES)) {
        foreach (lines((string) file_get_contents(IMPERATIVES)) as $i => $line) {
            if (preg_match('/\b\d{4}_\d{2}_\d{2}_\d{6}\b|\bM\d{1,3}\b|§D\d+/u', $line) === 1) {
                $failures[] = IMPERATIVES.':'.($i + 1).' carries a namespace literal; it is a pointer file';
            }
        }
    }

    return ['failures' => $failures, 'warnings' => $warnings];
}

/**
 * @return array<string, string>
 */
function parse_state_marker(string $body): array
{
    $out = [];

    foreach (preg_split('/\s+/', trim($body)) ?: [] as $pair) {
        if ($pair !== '' && str_contains($pair, '=')) {
            [$key, $value] = explode('=', $pair, 2);
            $out[$key] = $value;
        }
    }

    return $out;
}

// ── rendering ────────────────────────────────────────────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $state
 */
function render(array $state): void
{
    section('Increment');
    info('highest released', 'M'.$state['increment']['highest_released']);
    info('next free', 'M'.$state['increment']['next_free']);
    info('derived from', (string) $state['increment']['source']);
    info('numbered RELEASED headings', (string) $state['increment']['headings_numbered']);

    if ($state['increment']['sources_agree'] === true) {
        pass('merged pull-request titles agree independently (max M'.$state['increment']['remote_max'].')');
    } elseif ($state['increment']['sources_agree'] === false) {
        fail('merged pull-request titles top out at M'.$state['increment']['remote_max'].' — the sources disagree.');
        note('A mis-truncated RELEASED scan fails SILENTLY, by returning a LOWER maximum.');
    } else {
        warn('no independent cross-check: '.(string) $state['increment']['remote_reason']);
    }

    foreach ($state['increment']['headings_unnumbered'] as $heading) {
        note('release with no number (correct, not a parse failure): '.$heading);
    }

    section('Namespaces');
    info('next free ADR', $state['adr']['next_free'].'  (highest '.$state['adr']['highest'].', '.$state['adr']['count'].' files)');
    note('reserved, NOT free: '.implode(', ', $state['adr']['reserved']).' — '.$state['adr']['reserved_note']);
    info('next free migration prefix', (string) $state['migration']['next_free']);
    info('next free exceptions entry', (string) ($state['exceptions']['next_free'] ?? 'NOT FOUND — '.$state['exceptions']['reason']));
    info('migrations', $state['migration']['files'].' files, '.$state['migration']['unique_prefixes'].' unique prefixes');

    if ($state['migration']['duplicate_prefixes'] !== []) {
        warn(count($state['migration']['duplicate_prefixes']).' duplicate prefix(es): '.implode(', ', $state['migration']['duplicate_prefixes']));
        note((string) $state['migration']['duplicate_note']);
    }

    section('Claims');

    foreach (['a', 'b'] as $lane) {
        info('lane-'.$lane, (string) ($state['claims']['lane_'.$lane]['status'] ?? $state['claims']['lane_'.$lane]['reason']));
    }

    info('worktrees', "\n".indent(implode("\n", $state['worktrees'])));

    section('Queue');
    info('open backlog rows', $state['backlog']['open'].'  ('.$state['backlog']['by_severity']['major'].' major, '.
                              $state['backlog']['by_severity']['minor'].' minor, '.$state['backlog']['by_severity']['nit'].' nit)');
    info('open decisions', $state['decisions']['open'] === [] ? '(none)' : implode(', ', $state['decisions']['open']));
    info('answered decisions', implode(', ', $state['decisions']['answered']));

    if ($state['triage']['commits_behind_main'] !== null) {
        info('triage census', $state['triage']['sha'].', '.$state['triage']['commits_behind_main'].' commits behind main');
        note((string) $state['triage']['note']);
    }

    section('Gate baselines');

    if ($state['baselines']['stale'] === true) {
        warn('measured at '.$state['baselines']['sha'].', '.$state['baselines']['commits_behind_main'].
             ' commits behind origin/main — regenerate it from a post-merge run');
    } elseif ($state['baselines']['stale'] === false) {
        pass('measured at the head of origin/main');
    } else {
        warn('staleness not measurable: '.(string) $state['baselines']['commits_behind_reason']);
    }

    info('run', (string) ($state['baselines']['run_id'] ?? 'NOT FOUND'));
    info('push on main', $state['baselines']['is_push_on_main'] === true
        ? 'yes'
        : 'NO — a branch run measures a proposal, not the trunk');

    section('Declarations in prose (advisory — never gated)');

    foreach ($state['declaration_summary'] as $file => $counts) {
        info($file, $counts['literals'].' literal(s), '.$counts['disagreeing'].' disagreeing with the tree');
    }

    note('These are NOT failures. Most sit inside dated RELEASED bullets and are correct AS HISTORY —');
    note('a dated record is not a stale forward claim, and rewriting one would falsify the log. This');
    note('scan cannot tell a declaration from a quotation of one, which is why nothing here is gated.');

    section('Result');

    foreach ($state['check']['warnings'] as $message) {
        warn((string) $message);
    }

    if ($state['check']['failures'] === []) {
        fwrite(STDOUT, "state: OK — every gated declaration agrees with the tree.\n");

        return;
    }

    foreach ($state['check']['failures'] as $message) {
        fail((string) $message);
    }

    fwrite(STDERR, 'state: '.count($state['check']['failures'])." live declaration(s) disagree with the tree.\n");
}

// ── helpers ──────────────────────────────────────────────────────────────────────────────────────

function section(string $title): void
{
    fwrite(STDOUT, "\n== {$title} ".str_repeat('=', max(0, 74 - strlen($title)))."\n");
}

function pass(string $message): void
{
    fwrite(STDOUT, "  [ok]    {$message}\n");
}

function fail(string $message): void
{
    fwrite(STDOUT, "  [FAIL]  {$message}\n");
}

function warn(string $message): void
{
    fwrite(STDOUT, "  [warn]  {$message}\n");
}

function info(string $key, string $value): void
{
    fwrite(STDOUT, sprintf("  %-38s %s\n", $key, $value));
}

function note(string $message): void
{
    fwrite(STDOUT, "          {$message}\n");
}

function indent(string $text): string
{
    return implode("\n", array_map(static fn (string $l): string => '          '.$l, explode("\n", trim($text))));
}

/**
 * @return never
 */
function cannot_measure(string $message)
{
    fwrite(STDERR, "state: CANNOT MEASURE — {$message}\n");
    exit(2);
}

function read_tracked_file(string $path): string
{
    if (! is_file($path)) {
        cannot_measure("{$path} does not exist.");
    }

    $body = (string) file_get_contents($path);

    if (trim($body) === '') {
        cannot_measure("{$path} is empty.");
    }

    return $body;
}

/**
 * @return list<string>
 */
function list_dir(string $path): array
{
    if (! is_dir($path)) {
        cannot_measure("{$path} does not exist.");
    }

    return array_values(array_diff(scandir($path) ?: [], ['.', '..']));
}

function pad4(int $number): string
{
    return str_pad((string) $number, 4, '0', STR_PAD_LEFT);
}

function sh(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command, $output, $status);

    return implode("\n", $output);
}

function show_from_main(string $path): string
{
    return sh('git show '.escapeshellarg('origin/main:'.$path).' 2>&1');
}

function first_line_matching(string $haystack, string $pattern): ?string
{
    foreach (explode("\n", $haystack) as $line) {
        if (preg_match($pattern, $line) === 1) {
            return $line;
        }
    }

    return null;
}

function first_line(string $text): string
{
    $lines = explode("\n", trim($text));

    return trim($lines[0]);
}

/**
 * ⛔ NEVER preg_split ON \R HERE, AND THIS IS MEASURED RATHER THAN CAUTIOUS. Without the /u modifier
 * PCRE's \R matches the single BYTE 0x85 as a Unicode NEL — and 0x85 is the third byte of the UTF-8
 * encoding of ✅ (E2 9C 85), which this corpus is full of. A first draft of this script split
 * docs/claims/lane-a.md into 2,297 lines where the file has 2,273, so every line number it reported
 * after the first check mark was wrong by a growing offset. Both tracker files are asserted CR-free by
 * scripts/tracker-lint.php R5, so explode on a newline is exact and cannot drift.
 *
 * @return list<string>
 */
function lines(string $body): array
{
    return explode("\n", $body);
}
