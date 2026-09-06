<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| docs/backlog-triage.md, derived from the tree rather than written by hand (M65).
|--------------------------------------------------------------------------
| CLAUDE.md sends every session to read `docs/backlog-triage.md` first for the ranked order. The file
| it sent them to was a hand-written census measured on 2026-08-28, and by the time this script was
| written it was 110 commits stale with its top three ranked items all closed and zero open `major`
| rows left in the tree. A reader following the instruction was handed a ranking whose first three
| entries no longer existed.
|
| ⛔ THE ROW THAT FILED THIS SAID "RE-RANK" AND THE DEFECT WAS THAT IT WAS HAND-WRITTEN AT ALL. A
| re-ranked hand-written census rots exactly as fast as the one it replaces — this one took 110
| commits. Deriving it is the move `scripts/next.php` already made for the hand-off and
| `scripts/gate-baselines.php` made for gate numbers, and it is the only form that cannot go stale
| silently: regenerating is one command, and `scripts/state.php` prints how far behind the file is.
|
| ⛔ THE NUMBERS COME FROM scripts/state.php AND ARE NOT RE-DERIVED HERE. One authority, referenced
| rather than copied — the argument is `scripts/loop.php`'s, which records that its own first draft
| hand-rolled a line scan of the backlog and that a second parser would have drifted from the first.
| The row TEXT is still read locally, by the line index state.php reports, because state.php returns
| a first-line-only title that truncates mid-sentence.
|
| ⚠️ THIS IS AN OPERABILITY ORDER, NOT A PRIORITY ORDER, and the distinction is the whole reason the
| previous file went wrong. It cannot tell you what matters most — severity would, and there are zero
| open `major` rows, so that field is dead. What it can tell you is which rows are still live, which
| carry citations that still resolve, and which share no files with each other. That is what the
| batching decision needs and it is all this file claims.
|
| ⛔ A PATH THIS SCRIPT CANNOT RESOLVE IS WRITTEN AS UNRESOLVED, NEVER GUESSED. An ambiguous bare
| filename — one basename matching two files in the tree — is reported unresolved rather than
| resolved to whichever came first. That is `scripts/gate-baselines.php`'s NOT FOUND doctrine: a
| visible gap is honest, a confident wrong answer is the whole defect.
|
| Usage:
|   php scripts/backlog-triage.php              # regenerate docs/backlog-triage.md
|   php scripts/backlog-triage.php --dry-run    # print it, write nothing
|   php scripts/backlog-triage.php --check      # exit 1 if the file on disk has drifted from the tree
|   php scripts/backlog-triage.php --json       # the ORDERED open rows + the hub set, for pipeline.php
|
| ⛔ AN UNRECOGNISED FLAG IS REFUSED, NOT IGNORED. getopt() discards what it does not know and the
| no-flag action rewrites a committed file, so a typo used to regenerate the document silently.
|
| ⚠️ --check IS DELIBERATELY WIRED INTO NOTHING. It is not in `composer run quality` and has no CI
| step. Making it a gate changes what a close-out is obliged to do, which is a decision rather than a
| fix, and it is filed as its own row instead of taken here. It compares the DERIVED BODY only — the
| provenance line names a commit and would make every subsequent commit read as drift.
|
| Exit 0 = written (or clean, under --check). Exit 1 = a --check drift, or a refusal. Exit 2 = could
| not measure.
*/

chdir(dirname(__DIR__));

const TRIAGE = 'docs/backlog-triage.md';
const BACKLOG = 'docs/feature-backlog.md';
const FROZEN = 'docs/backlog-triage-m37.md';

/** A file cited by at least this many open rows is structural, not a row's own subject. */
const HUB_THRESHOLD = 3;

/** The batch size `D13` fixed. */
const BATCH_MAX = 4;

/** Directories that are not this repository's own source. */
const SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'storage', 'public/build', '.idea', 'coverage'];

/** Every flag this script accepts. Read by getopt AND by the argv fence below. */
const FLAGS = ['dry-run', 'check', 'json', 'help'];

$opts = getopt('', FLAGS);

// getopt() silently DISCARDS what it does not recognise, and this script's no-flag action REWRITES a
// committed file — so a mistyped flag regenerated the document while the operator believed they had
// asked for something else. Reading $argv is the only way to see what getopt() threw away. Refuse
// rather than fall through to the write.
foreach (array_slice($argv, 1) as $argument) {
    if (! in_array(ltrim($argument, '-'), FLAGS, true)) {
        fwrite(STDERR, sprintf(
            'backlog-triage: unrecognised argument %s. Known: --%s. Refusing rather than falling '
            ."through to the default, which rewrites %s.\n",
            $argument,
            implode(' --', FLAGS),
            TRIAGE
        ));

        exit(1);
    }
}

// --help prints and exits BEFORE any measurement. The row that filed this named the write-on-help
// path specifically: an operator reaching for usage text used to dirty the tree and be told it
// succeeded.
if (isset($opts['help'])) {
    fwrite(STDOUT, implode("\n", [
        'backlog-triage — regenerate '.TRIAGE.' from the tree.',
        '',
        '  php scripts/backlog-triage.php              rewrite '.TRIAGE,
        '  php scripts/backlog-triage.php --dry-run    print it, write nothing',
        '  php scripts/backlog-triage.php --check      exit 1 if the file on disk has drifted',
        '  php scripts/backlog-triage.php --json       the ordered open rows + hub set, for pipeline.php',
        '  php scripts/backlog-triage.php --help       this text',
        '',
        'Exit 0 = written, clean, or printed. 1 = drift or a refused argument. 2 = could not measure.',
        'THE DEFAULT ACTION WRITES. An unrecognised flag is refused rather than falling through to it.',
        '',
    ])."\n");

    exit(0);
}

$dryRun = isset($opts['dry-run']);
$check = isset($opts['check']);
$json = isset($opts['json']);

// ---------------------------------------------------------------------------------------------
// Measurement
// ---------------------------------------------------------------------------------------------

$state = read_state();
$rows = $state['backlog']['severity_rows'] ?? [];

if ($rows === []) {
    cannot_measure('scripts/state.php returned no severity rows. The backlog parser is blind, and a '.
        'ranking built over zero rows would be an empty file that looks like an empty queue.');
}

$lines = explode("\n", (string) file_get_contents(BACKLOG));
$index = build_basename_index();

$open = [];

foreach ($rows as $row) {
    if ($row['state'] !== 'open') {
        continue;
    }

    $body = row_body($lines, (int) $row['line']);
    $harvest = harvest_paths($body, $index);

    $open[] = $row + [
        'body' => $body,
        'headline' => headline($body),
        'paths' => $harvest['resolved'],
        'unresolved' => $harvest['unresolved'],
    ];
}

if ($open === []) {
    cannot_measure('no OPEN rows were parsed out of '.BACKLOG.'. Every table below would be empty and '.
        'would read as a finished queue rather than as a broken parser.');
}

$hubs = derive_hubs($open);

foreach ($open as $i => $row) {
    $open[$i]['nonHub'] = array_values(array_diff($row['paths'], $hubs));
    $open[$i]['touchesHub'] = array_values(array_intersect($row['paths'], $hubs));
}

usort($open, 'compare_rows');

$sha = trunk_sha();
$body = render_body($open, $hubs, $state);
$document = render_banner($sha, $state).$body;

// ---------------------------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------------------------

// The ordered rows, for a consumer that must not re-derive them. `scripts/pipeline.php` reads this:
// the ranking is computed once, here, and consumed as an array index — never sorted a second time by
// a caller, which is how two orderings drift apart.
if ($json) {
    fwrite(STDOUT, json_encode([
        'sha' => $sha,
        'hub_threshold' => HUB_THRESHOLD,
        'hubs' => array_values($hubs),
        'open' => array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'line' => $row['line'],
            'severity' => $row['severity'],
            'liveness' => $row['liveness'],
            'provenance' => $row['provenance'],
            'headline' => $row['headline'],
            'paths' => $row['paths'],
            'non_hub' => $row['nonHub'],
            'touches_hub' => $row['touchesHub'],
            'unresolved' => $row['unresolved'],
        ], $open),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'
');

    exit(0);
}

if ($check) {
    exit(run_check($body));
}

if ($dryRun) {
    fwrite(STDOUT, $document);

    exit(0);
}

file_put_contents(TRIAGE, $document);
fwrite(STDOUT, sprintf(
    "backlog-triage: wrote %s from the tree at %s — %d open rows, %d hub file(s).\n",
    TRIAGE,
    substr($sha, 0, 7),
    count($open),
    count($hubs)
));

exit(0);

// ---------------------------------------------------------------------------------------------
// Measurement helpers
// ---------------------------------------------------------------------------------------------

/**
 * One authority, referenced rather than copied: the row census is not re-parsed here.
 *
 * @return array<string, mixed>
 */
function read_state(): array
{
    $status = 0;
    $json = sh('php '.escapeshellarg('scripts/state.php').' --json --no-rows --offline 2>&1', $status);
    $state = json_decode($json, true);

    if ($status !== 0 || ! is_array($state)) {
        cannot_measure("scripts/state.php exited {$status}.\n      ".trim($json));
    }

    return $state;
}

/**
 * ⛔ THE SHA NAMES THE TREE THE CENSUS WAS MEASURED AGAINST, AND IT MUST BE ON THE TRUNK.
 *
 * `scripts/state.php` reports this file's staleness as `git rev-list --count <sha>..origin/main`. A
 * sha that is not an ancestor of `origin/main` makes that count unmeasurable — and the triage branch
 * of that report has no `else`, so it prints NOTHING AT ALL rather than a warning. Stamping the
 * branch HEAD would therefore read a confident `0 commits behind` while the branch is ahead, and
 * then degrade to silence the moment a squash merge discards the commit.
 *
 * ⚠️ SO THIS ASKS FOR `origin/main` AND NOTHING ELSE — it does not stamp `HEAD` and then check it.
 * An earlier draft of this docblock claimed an ancestry ASSERTION that the code does not make, which
 * is the defect an open row in this very backlog names: a comment describing a control that is not
 * there is worse than no comment, because it is what the next reader checks instead of the code.
 * Reading the trunk ref directly is the stronger form anyway — there is no branch sha to reject,
 * because none is ever a candidate. What it must still refuse is an UNRESOLVABLE ref, which is what
 * the guard below does.
 */
function trunk_sha(): string
{
    $status = 0;
    $sha = trim(sh('git rev-parse origin/main 2>&1', $status));

    if ($status !== 0 || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
        cannot_measure('could not resolve origin/main. Run `git fetch` first — the provenance line '.
            'must name a commit that is on the trunk.');
    }

    return $sha;
}

/**
 * The row's text, taken by the line index `state.php` reports and stopping where a row stops.
 *
 * A heading ends a row, and rows are frequently NOT separated by a blank line — the same reason
 * `scripts/state.php` and `tests/Feature/Docs/BacklogProvenanceTest.php` both split this way.
 *
 * @param  list<string>  $lines
 */
function row_body(array $lines, int $start): string
{
    $out = [];

    for ($i = $start - 1; $i < count($lines); $i++) {
        $line = $lines[$i];

        if ($i > $start - 1 && (str_starts_with($line, '- ') || ($line !== '' && $line[0] === '#'))) {
            break;
        }

        $out[] = $line;
    }

    return implode("\n", $out);
}

/**
 * The row's title — the whole of it, flattened.
 *
 * ⛔ IT IS TAKEN FROM THE BOLD DELIMITER AND NEVER FROM THE FIRST FULL STOP. Two reasons, both
 * measured on this corpus rather than anticipated. `state.php`'s `title` is the first LINE only and
 * truncates mid-sentence, so it cannot be used. And the obvious repair — cut at the first `.` —
 * cuts `` `tracker-lint` R8 guards `CLAUDE.md` `` down to *"guards `CLAUDE."*, because a filename
 * carries a full stop. The title's real terminator is the closing `**` that opened the bullet, and
 * it is exact.
 */
function headline(string $body): string
{
    $flat = trim((string) preg_replace('/\s+/u', ' ', $body));

    $title = preg_match(
        '/^- (?:✅ )?(?:~~)?\*\*(?:CLOSED BY.*?— )?`(?:major|minor|nit)` \x{00B7} (.*?)\*\*/u',
        $flat,
        $m
    ) === 1 ? $m[1] : $flat;

    $title = trim((string) preg_replace('/~~/u', '', $title));

    return mb_strimwidth($title, 0, 160, '…');
}

/**
 * Every path the row cites that still exists, plus the ones that do not.
 *
 * ⛔ THE PATTERN IS THIS SCRIPT'S OWN AND IS DELIBERATELY NOT SHARED WITH scripts/citation-liveness-lint.php.
 * That gate asks *is this cited LINE alive*; this asks *which FILES does this row touch*. Deriving
 * from an authority is only safer than copying when the authority's semantics answer your question,
 * and here they do not — a shared regex would be a copy wearing borrowed confidence.
 *
 * @param  array<string, list<string>>  $index
 * @return array{resolved: list<string>, unresolved: list<string>}
 */
function harvest_paths(string $body, array $index): array
{
    $resolved = [];
    $unresolved = [];

    if (preg_match_all('/`([^`]+)`/u', $body, $spans) !== false) {
        foreach ($spans[1] as $span) {
            foreach (candidate_tokens($span) as $token) {
                $hit = resolve_token($token, $index);

                if ($hit === null) {
                    $unresolved[$token] = true;

                    continue;
                }

                $resolved[$hit] = true;
            }
        }
    }

    return [
        'resolved' => array_values(array_unique(array_keys($resolved))),
        'unresolved' => array_values(array_unique(array_keys($unresolved))),
    ];
}

/**
 * Path-shaped tokens inside one inline code span.
 *
 * ⚠️ SEPARATORS ARE NORMALISED TO `/` BEFORE ANYTHING ELSE. Measured while building this: the corpus
 * carries both `scripts\state.php` and `scripts/state.php`, and counting them apart put one file in
 * two buckets and understated its hub degree.
 *
 * @return list<string>
 */
function candidate_tokens(string $span): array
{
    $span = str_replace('\\', '/', $span);
    $out = [];

    if (preg_match_all('#[A-Za-z0-9_@./-]+\.(?:php|ts|tsx|js|jsx|vue|json|yml|yaml|md|css|scss|sh|blade\.php|sql|env|example)\b#u', $span, $m) !== false) {
        foreach ($m[0] as $token) {
            $out[] = rtrim($token, '.,;:');
        }
    }

    return array_values(array_unique($out));
}

/**
 * A token resolved to a repo-relative path, or null.
 *
 * Two arms, and the second is what makes this column useful rather than mostly blank: a full path is
 * checked directly, while a BARE FILENAME is looked up in the basename index. ⛔ A basename matching
 * more than one file is UNRESOLVED, never resolved to the first hit.
 *
 * @param  array<string, list<string>>  $index
 */
function resolve_token(string $token, array $index): ?string
{
    if (str_contains($token, '/')) {
        return is_file($token) ? $token : null;
    }

    $hits = $index[$token] ?? [];

    return count($hits) === 1 ? $hits[0] : null;
}

/**
 * basename => every repo-relative path carrying it.
 *
 * @return array<string, list<string>>
 */
function build_basename_index(): array
{
    $index = [];
    $stack = ['.'];

    while ($stack !== []) {
        $dir = array_pop($stack);
        $entries = @scandir($dir);

        if ($entries === false) {
            continue;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir === '.' ? $entry : $dir.'/'.$entry;

            if (in_array($path, SKIP_DIRS, true) || in_array($entry, SKIP_DIRS, true)) {
                continue;
            }

            if (is_dir($path)) {
                $stack[] = $path;

                continue;
            }

            $index[basename($path)][] = $path;
        }
    }

    return $index;
}

/**
 * Files cited by HUB_THRESHOLD or more open rows — derived, never listed.
 *
 * A hard-coded hub list is a second census that goes stale beside the first one. The threshold is
 * printed in the generated file so the batch proposal can be checked by hand.
 *
 * @param  list<array<string, mixed>>  $open
 * @return list<string>
 */
function derive_hubs(array $open): array
{
    $degree = [];

    foreach ($open as $row) {
        foreach ($row['paths'] as $path) {
            $degree[$path] = ($degree[$path] ?? 0) + 1;
        }
    }

    $hubs = array_keys(array_filter($degree, static fn (int $n): bool => $n >= HUB_THRESHOLD));
    sort($hubs);

    return $hubs;
}

/**
 * The operability order, in four derived keys.
 *
 * Severity is absent on purpose: there are zero open `major` rows, so it separates nothing.
 *
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 */
function compare_rows(array $a, array $b): int
{
    $rank = ['live' => 0, 'latent' => 1, 'not-live' => 2, null => 3];

    return ($rank[$a['liveness']] <=> $rank[$b['liveness']])
        ?: (citation_health($b) <=> citation_health($a))
        ?: (count($a['nonHub']) <=> count($b['nonHub']))
        ?: (filer_ordinal($a['provenance']) <=> filer_ordinal($b['provenance']))
        ?: ($a['line'] <=> $b['line']);
}

/** 2 = every cited path resolves · 1 = some resolve · 0 = nothing usable. */
function citation_health(array $row): int
{
    if ($row['paths'] === []) {
        return 0;
    }

    return $row['unresolved'] === [] ? 2 : 1;
}

/** Oldest debt first. `M3` before `M63`; a letter series sorts before a later one. */
function filer_ordinal(?string $filer): int
{
    if ($filer === null || preg_match('/^([A-Z])(\d{1,3})/', $filer, $m) !== 1) {
        return PHP_INT_MAX;
    }

    return (ord($m[1]) * 1000) + (int) $m[2];
}

// ---------------------------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $state
 */
function render_banner(string $sha, array $state): string
{
    $date = date('Y-m-d');
    $backlog = $state['backlog'];
    $frozen = FROZEN;

    // ⛔ The provenance line must be the FIRST line in the file matching /^\*\*Measured /, and must
    //    carry the sha inside backticks — that is the shape scripts/state.php parses to report how
    //    stale this file is. Nothing else in the header may open with those bytes.
    return <<<MARKDOWN
        # Backlog triage

        **Generated by `scripts/backlog-triage.php`. Do not hand-edit — regenerate it.**

        **Measured {$date}** against `origin/main` at `{$sha}` · {$backlog['open']} open rows · {$backlog['by_severity']['major']} `major` · {$backlog['by_severity']['minor']} `minor` · {$backlog['by_severity']['nit']} `nit`

        ⚠️ **This is an OPERABILITY order, not a priority order, and the difference is why the file it
        replaced went wrong.** It cannot tell you what matters most. Severity would, and there are zero
        open `major` rows, so that field separates nothing. What it derives is which rows are still
        **live**, whose **citations still resolve**, and which rows **share no files** with each other —
        which is what picking a batch needs, and is all this file claims.

        ⛔ **Liveness here is a marker a human wrote, not a measurement this script made.** It is gated
        for presence by `tests/Feature/Docs/BacklogProvenanceTest.php` and reported by
        `scripts/state.php`; neither checks that a verdict is *right*. A row marked live that is dead
        passes everything. The mutation harness is what settles a row, and settling it is the job of
        whichever increment takes it.

        📌 **The 2026-08-28 census this file replaced is preserved byte-for-byte in `{$frozen}`.**
        Its findings about the sweep's own accuracy, and the brief it ran under, are not reproduced here
        and are not stale — they are a dated record and are still worth reading before any fan-out.

        MARKDOWN;
}

/**
 * @param  list<array<string, mixed>>  $open
 * @param  list<string>  $hubs
 * @param  array<string, mixed>  $state
 */
function render_body(array $open, array $hubs, array $state): string
{
    $buckets = ['live' => [], 'latent' => [], 'not-live' => [], 'unmarked' => []];

    foreach ($open as $row) {
        $buckets[$row['liveness'] ?? 'unmarked'][] = $row;
    }

    $out = "\n---\n\n## Census\n\n";
    $out .= "| | Rows |\n|---|---|\n";
    $out .= '| Open | **'.count($open)."** |\n";
    $out .= '| — still `live` | **'.count($buckets['live'])."** |\n";
    $out .= '| — `latent`, needs a stated precondition | '.count($buckets['latent'])." |\n";
    $out .= '| — `not live`, not a defect in this tree | '.count($buckets['not-live'])." |\n";

    if ($buckets['unmarked'] !== []) {
        $out .= '| — ⛔ **UNMARKED** | **'.count($buckets['unmarked'])."** |\n";
    }

    $out .= '| Severity bullets, ever | '.$state['backlog']['severity_bullets']." |\n";

    $out .= "\n## The queue — live rows, most operable first\n\n";
    $out .= "Ordered by citation health, then by how few non-hub files the row touches, then oldest\n";
    $out .= "debt first. `cites` lists only the files a batch would collide on; hub files are excluded\n";
    $out .= "from it and listed separately below.\n\n";
    $out .= render_table($buckets['live'], $hubs);

    if ($buckets['latent'] !== []) {
        $out .= "\n## Latent — real, but each needs a stated precondition first\n\n";
        $out .= "Not queue work until the precondition holds. The row says what it is.\n\n";
        $out .= render_table($buckets['latent'], $hubs);
    }

    if ($buckets['not-live'] !== []) {
        $out .= "\n## Not live — listed, not hidden\n\n";
        $out .= "These are stated limits, deliberate decisions, blind spots and deployment obligations\n";
        $out .= "rather than defects in this tree. `scripts/loop.php` already refuses them, and they are\n";
        $out .= "listed here rather than dropped so that this file and that stop rule cannot disagree.\n\n";
        $out .= render_table($buckets['not-live'], $hubs);
    }

    if ($buckets['unmarked'] !== []) {
        $out .= "\n## ⛔ Unmarked — a verdict nobody has recorded\n\n";
        $out .= "The liveness gate requires a marker on every open row, so this table should be empty.\n\n";
        $out .= render_table($buckets['unmarked'], $hubs);
    }

    $out .= render_hubs($hubs, $open);
    $out .= render_batch($buckets['live'], $hubs);
    $out .= render_blind($open);

    return $out;
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @param  list<string>  $hubs
 */
function render_table(array $rows, array $hubs): string
{
    $out = "| Row | Filed | Line | Cites (non-hub) |\n|---|---|---|---|\n";

    foreach ($rows as $row) {
        $cites = $row['nonHub'] === []
            ? ($row['paths'] === [] ? '— *no file harvested*' : '*hub files only*')
            : '`'.implode('` · `', array_slice($row['nonHub'], 0, 4)).'`'.
              (count($row['nonHub']) > 4 ? ' +'.(count($row['nonHub']) - 4) : '');

        $out .= sprintf(
            "| %s | `%s` | %d | %s |\n",
            str_replace('|', '\\|', $row['headline']),
            $row['provenance'] ?? '(unattributed)',
            $row['line'],
            str_replace('|', '\\|', $cites)
        );
    }

    return $out;
}

/**
 * @param  list<string>  $hubs
 * @param  list<array<string, mixed>>  $open
 */
function render_hubs(array $hubs, array $open): string
{
    $out = "\n## Hub files — a batch may touch at most one\n\n";
    $out .= 'Derived, not listed: a file cited by **'.HUB_THRESHOLD." or more** open rows. These are\n";
    $out .= "mostly meta-files rather than product code, which is why the rows that share them are not\n";
    $out .= "really coupled — and why excluding them is what makes the queue above separable.\n\n";

    if ($hubs === []) {
        return $out."*No file reaches the threshold.*\n";
    }

    $degree = [];

    foreach ($open as $row) {
        foreach ($row['paths'] as $path) {
            $degree[$path] = ($degree[$path] ?? 0) + 1;
        }
    }

    $out .= "| File | Open rows citing it |\n|---|---|\n";
    arsort($degree);

    foreach ($degree as $path => $n) {
        if (in_array($path, $hubs, true)) {
            $out .= "| `{$path}` | {$n} |\n";
        }
    }

    return $out;
}

/**
 * The `D13` batch proposal — a PROPOSAL, restated with its rule, never a schedule.
 *
 * @param  list<array<string, mixed>>  $live
 * @param  list<string>  $hubs
 */
function render_batch(array $live, array $hubs): string
{
    $picked = [];
    $taken = [];
    $hubUsed = false;

    foreach ($live as $row) {
        if (count($picked) >= BATCH_MAX) {
            break;
        }

        if (array_intersect($row['nonHub'], $taken) !== []) {
            continue;
        }

        if ($row['touchesHub'] !== [] && $hubUsed) {
            continue;
        }

        $picked[] = $row;
        $taken = array_merge($taken, $row['nonHub']);
        $hubUsed = $hubUsed || $row['touchesHub'] !== [];
    }

    $out = "\n## Suggested next batch\n\n";
    $out .= '`D13` fixes the rule: **3–4 rows, no two citing the same non-hub file, at most one row '.
        "touching a hub file.**\nThis is the greedy pick off the top of the queue above — a proposal to ".
        "check, not a schedule.\nA row whose files were not harvested cannot be checked for collision and ".
        "is not proposed.\n\n";

    if ($picked === []) {
        return $out."*No batch could be formed.*\n";
    }

    foreach ($picked as $row) {
        $out .= sprintf(
            "- **%s:%d** — `%s` · %s\n",
            BACKLOG,
            $row['line'],
            $row['provenance'] ?? '(unattributed)',
            $row['nonHub'] === [] ? '*no non-hub file*' : '`'.implode('` · `', $row['nonHub']).'`'
        );
    }

    return $out;
}

/**
 * @param  list<array<string, mixed>>  $open
 */
function render_blind(array $open): string
{
    $none = array_values(array_filter($open, static fn (array $r): bool => $r['paths'] === []));
    $partial = array_values(array_filter($open, static fn (array $r): bool => $r['paths'] !== [] && $r['unresolved'] !== []));

    $out = "\n## What this file cannot see\n\n";
    $out .= '**'.count($none).' open row(s) cite no file this script could resolve**, and **'.count($partial).
        " carry at least one citation that does not resolve.** They are ranked below rows whose citations\n";
    $out .= "all resolve, because a row must be re-derived before it is worked rather than merely worked —\n";
    $out .= "but the ranking is the only consequence: an unresolvable citation is not evidence the row is\n";
    $out .= "wrong. **Batch these by hand**, since a collision check needs files to compare.\n\n";

    if ($none !== []) {
        $out .= "Rows with no harvested file:\n\n";

        foreach ($none as $row) {
            $out .= sprintf("- `%s:%d` — %s\n", BACKLOG, $row['line'], str_replace('|', '\\|', $row['headline']));
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------------------------
// --check
// ---------------------------------------------------------------------------------------------

/**
 * Compare the DERIVED BODY only. The provenance line names a commit, so comparing it would make
 * every commit after a regeneration read as drift — which is staleness, and `state.php` already
 * reports that.
 */
function run_check(string $body): int
{
    if (! is_file(TRIAGE)) {
        fwrite(STDERR, 'backlog-triage: '.TRIAGE." does not exist.\n");

        return 1;
    }

    $disk = (string) file_get_contents(TRIAGE);
    $marker = "\n---\n\n## Census\n";
    $at = strpos($disk, $marker);

    if ($at === false) {
        fwrite(STDERR, 'backlog-triage: '.TRIAGE." has no derived body — it is hand-written or from an older generator.\n");

        return 1;
    }

    if (substr($disk, $at) === $body) {
        fwrite(STDOUT, 'backlog-triage: '.TRIAGE." agrees with the tree.\n");

        return 0;
    }

    fwrite(STDERR, 'backlog-triage: '.TRIAGE." has DRIFTED from the tree. Regenerate it.\n");

    return 1;
}

// ---------------------------------------------------------------------------------------------
// Plumbing
// ---------------------------------------------------------------------------------------------

function sh(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command, $output, $status);

    return implode("\n", $output);
}

function cannot_measure(string $why): never
{
    fwrite(STDERR, "backlog-triage: CANNOT MEASURE — {$why}\n");

    exit(2);
}
