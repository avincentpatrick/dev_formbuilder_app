<?php

declare(strict_types=1);

/*
 * The boundary between the two applications in this repository (M192, ADR-0024, decision D177).
 *
 * WHY THIS EXISTS.
 *
 * Two developers share one trunk. Meridian (the repository root) is avincentpatrick's; BHW Connect
 * (`apps/bhw-connect/`) is jongsky25's; a few paths — `.github/`, `deploy.ps1`, BHW's `ops/` — are
 * shared, because a change there can take the other application down. D177 chose: a pull request
 * on your own side needs nobody; one that touches a path the OTHER developer owns needs that
 * developer's approval label. GitHub's "require review from code owners" cannot express it — with
 * one owner per side, an author could never approve their own pull request and both lanes would
 * stall — so this step does, in CI's `bhw-connect` job, on every pull request.
 *
 * THE RULE.
 *
 *   A changed path's OWNERS are those of the last `.github/CODEOWNERS` rule that matches it, as on
 *   GitHub. A path needs approval when its owners include anyone but the pull request's author, and
 *   the approval is the label `boundary-approved` whose MOST RECENT `labeled` event was made by one of
 *   those other owners. A migration file under BHW's `supabase/migrations/` whose first line is
 *   `-- bhw:destructive` is owned by Meridian's owners as well (`db/lint-migrations.mjs` lets such a
 *   file through to here): removing staging data is the other side's business too.
 *
 *   ⛔ THE OWNERSHIP MAP IS READ FROM THE BASE COMMIT, never from the pull request's head. A pull
 *   request that edits `CODEOWNERS` to name its own author cannot approve itself that way. And when
 *   the base has no `CODEOWNERS` at all — the pull request that introduces the file — the rule is not
 *   enforced, and the step says so.
 *
 *   ⛔ THE LABEL IS READ LIVE, not from the workflow's event payload: re-running the job after the
 *   label is added must see it, and a re-run replays the original payload.
 *
 *   ⚠️ TRUST-BASED, AND SAID SO (D177): a label applied before later commits still counts. The
 *   working agreement asks the approver to remove and re-apply it after new commits.
 *
 * USAGE (CI):
 *   php scripts/boundary-check.php --repo=<owner/name> --pr=<n> --author=<login> --base=<sha> --head=<sha>
 *     [--events-file=<json>]   the label events, instead of `gh api` (the controls use it)
 *
 * Changed paths are `git diff --name-only --no-renames <merge-base> <head>`, so a rename shows both of
 * its paths. The pattern dialect is the subset CODEOWNERS here uses: `*`, an anchored `/path`, a
 * directory `path/`, `*` and `**` inside a pattern, and an unanchored name matching at any depth.
 *
 * EXIT CODES: 0 within the boundary, or approved · 1 crosses it without approval · 2 cannot measure
 * (bad arguments, git or the API failing), which is never a pass.
 */

const BOUNDARY_LABEL = 'boundary-approved';
const DESTRUCTIVE_MARKER = '-- bhw:destructive';
const MIGRATIONS_PREFIX = 'apps/bhw-connect/supabase/migrations/';

$options = getopt('', ['repo:', 'pr:', 'author:', 'base:', 'head:', 'events-file:']);

foreach (['author', 'base', 'head'] as $required) {
    if (! is_string($options[$required] ?? null) || $options[$required] === '') {
        cannot_measure("--{$required} is required.");
    }
}

$author = strtolower((string) $options['author']);
$base = (string) $options['base'];
$head = (string) $options['head'];

$codeowners = git_show($base, '.github/CODEOWNERS');

if ($codeowners === null) {
    fwrite(STDOUT, "boundary: the base commit has no .github/CODEOWNERS, so the boundary is not enforced on this pull request.\n");
    exit(0);
}

$rules = parse_codeowners($codeowners);
$defaultOwners = default_owners($rules);
$mergeBase = git_line(['merge-base', $base, $head]);
$changed = git_lines(['diff', '--name-only', '--no-renames', $mergeBase, $head]);

$needs = [];

foreach ($changed as $path) {
    $owners = owners_of($path, $rules);

    if (str_starts_with($path, MIGRATIONS_PREFIX) && str_ends_with($path, '.sql')) {
        $body = git_show($head, $path);

        if ($body !== null && trim(explode("\n", $body, 2)[0]) === DESTRUCTIVE_MARKER) {
            $owners = array_values(array_unique(array_merge($owners, $defaultOwners)));
        }
    }

    $others = array_values(array_diff($owners, [$author]));

    if ($others !== []) {
        $needs[$path] = $others;
    }
}

if ($needs === []) {
    fwrite(STDOUT, sprintf("boundary: %d changed path(s), all on %s's own side.\n", count($changed), $author));
    exit(0);
}

$approver = label_approver($options);
$unapproved = array_filter($needs, static fn (array $others): bool => $approver === null || ! in_array($approver, $others, true));

foreach ($needs as $path => $others) {
    fwrite(STDOUT, sprintf(
        "boundary: %s %s — owned by %s\n",
        isset($unapproved[$path]) ? '[needs approval]' : '[approved]      ',
        $path,
        implode(', ', array_map(static fn (string $o): string => '@'.$o, $others))
    ));
}

if ($unapproved === []) {
    fwrite(STDOUT, sprintf("boundary: approved by @%s with the `%s` label.\n", $approver, BOUNDARY_LABEL));
    exit(0);
}

$whom = array_values(array_unique(array_merge(...array_values($unapproved))));
fwrite(STDERR, sprintf(
    "boundary: this pull request by @%s changes %d path(s) the other side owns. It merges once %s applies the `%s` label%s, then re-run this job.\n",
    $author,
    count($unapproved),
    implode(' or ', array_map(static fn (string $o): string => '@'.$o, $whom)),
    BOUNDARY_LABEL,
    $approver === null ? '' : " (it was last applied by @{$approver}, who does not own them)"
));

exit(1);

// ---------------------------------------------------------------------------------------------

/**
 * @return list<array{pattern: string, owners: list<string>}>
 */
function parse_codeowners(string $text): array
{
    $rules = [];

    foreach (preg_split('/\r?\n/u', $text) ?: [] as $line) {
        $line = trim(preg_replace('/(^|\s)#.*$/u', '', $line) ?? '');

        if ($line === '') {
            continue;
        }

        $parts = preg_split('/\s+/u', $line) ?: [];
        $pattern = (string) array_shift($parts);
        $owners = array_values(array_map(
            static fn (string $o): string => strtolower(ltrim($o, '@')),
            array_filter($parts, static fn (string $o): bool => str_starts_with($o, '@'))
        ));
        $rules[] = ['pattern' => $pattern, 'owners' => $owners];
    }

    return $rules;
}

/**
 * The owners of the catch-all `*` rule — Meridian's, the side that owns everything not given away.
 *
 * @param  list<array{pattern: string, owners: list<string>}>  $rules
 * @return list<string>
 */
function default_owners(array $rules): array
{
    $owners = [];

    foreach ($rules as $rule) {
        if (in_array($rule['pattern'], ['*', '/*', '**'], true)) {
            $owners = $rule['owners'];
        }
    }

    return $owners;
}

/**
 * The owners of the LAST matching rule, as on GitHub.
 *
 * @param  list<array{pattern: string, owners: list<string>}>  $rules
 * @return list<string>
 */
function owners_of(string $path, array $rules): array
{
    $owners = [];

    foreach ($rules as $rule) {
        if (pattern_matches($rule['pattern'], $path)) {
            $owners = $rule['owners'];
        }
    }

    return $owners;
}

function pattern_matches(string $pattern, string $path): bool
{
    if ($pattern === '*' || $pattern === '/*' || $pattern === '**') {
        return true;
    }

    $anchored = str_starts_with($pattern, '/') || str_contains(rtrim($pattern, '/'), '/');
    $directory = str_ends_with($pattern, '/');
    $body = trim($pattern, '/');

    $regex = '';
    foreach (preg_split('/(\*\*|\*)/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
        $regex .= match ($piece) {
            '**' => '.*',
            '*' => '[^/]*',
            default => preg_quote($piece, '#'),
        };
    }

    $prefix = $anchored ? '^' : '(^|/)';
    $suffix = $directory ? '/' : '(/|$)';

    return preg_match('#'.$prefix.$regex.$suffix.'#u', $path) === 1;
}

/** The login that most recently applied the label, or null when the label is not on the pull request. */
function label_approver(array $options): ?string
{
    if (isset($options['events-file'])) {
        $raw = @file_get_contents((string) $options['events-file']);

        if ($raw === false) {
            cannot_measure('--events-file could not be read.');
        }
    } else {
        foreach (['repo', 'pr'] as $required) {
            if (! is_string($options[$required] ?? null) || $options[$required] === '') {
                cannot_measure("--{$required} is required to read the pull request's labels.");
            }
        }

        $output = [];
        $status = 0;
        // One JSON object per line, whatever the page count: `--paginate` runs the filter per page.
        exec(sprintf(
            'gh api --paginate %s --jq %s 2>&1',
            escapeshellarg("repos/{$options['repo']}/issues/{$options['pr']}/events"),
            escapeshellarg('.[] | {event, label: .label.name, actor: .actor.login} | @json')
        ), $output, $status);

        if ($status !== 0) {
            cannot_measure("gh api could not read the pull request's events:\n".implode("\n", $output));
        }

        $raw = '['.implode(',', array_filter(array_map('trim', $output), static fn (string $l): bool => $l !== '')).']';
    }

    $events = json_decode((string) $raw, true);

    if (! is_array($events)) {
        cannot_measure('the label events are not a JSON array.');
    }

    $state = null;

    foreach ($events as $event) {
        if (($event['label'] ?? null) !== BOUNDARY_LABEL) {
            continue;
        }

        if (($event['event'] ?? null) === 'labeled') {
            $state = strtolower((string) ($event['actor'] ?? ''));
        } elseif (($event['event'] ?? null) === 'unlabeled') {
            $state = null;
        }
    }

    return $state === '' ? null : $state;
}

function git_show(string $commit, string $path): ?string
{
    $output = [];
    $status = 0;
    exec(sprintf('git show %s 2>&1', escapeshellarg($commit.':'.$path)), $output, $status);

    return $status === 0 ? implode("\n", $output) : null;
}

function git_line(array $arguments): string
{
    $lines = git_lines($arguments);

    if ($lines === []) {
        cannot_measure('git '.implode(' ', $arguments).' printed nothing.');
    }

    return $lines[0];
}

/** @return list<string> */
function git_lines(array $arguments): array
{
    $output = [];
    $status = 0;
    exec('git '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1', $output, $status);

    if ($status !== 0) {
        cannot_measure('git '.implode(' ', $arguments)." failed:\n".implode("\n", $output));
    }

    return array_values(array_filter(array_map('trim', $output), static fn (string $l): bool => $l !== ''));
}

function cannot_measure(string $why): never
{
    fwrite(STDERR, "boundary: CANNOT MEASURE — {$why}\n");
    exit(2);
}
