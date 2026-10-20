<?php

declare(strict_types=1);

/*
 * The testers' feedback, pulled into the development session (M175, `R-78f43f26`, `D139`; ADR-0023).
 *
 * WHY THIS EXISTS.
 *
 * Testers report problems from the Feedback button on the testing server. Until M175 a report reached the
 * session only when the user copied it across from the console. CLAUDE.md's "Start every session" now runs this
 * script: it downloads every New and Ongoing report — with its screenshot — through the platform support API,
 * prints a digest the session triages against the work queue, and pushes status changes back.
 *
 * WHAT IT MAY SEND ON ITS OWN — AND WHAT IT MAY NOT (`D139`).
 *
 *   --ongoing=<id>                      marks a report Ongoing. No note: the reporter is not told.
 *   --resolve=<id> --version=<sha7>     marks it Resolved with the stock "Fixed in version <sha7>, live on
 *                                       staging." — the one message a session sends a tester by itself, and
 *                                       only once the fix is deployed.
 *   --resolve=<id> --note="…"           a reply of its own; refused without --user-approved.
 *   --decline=<id> --note="…"           Declined; ALWAYS refused without --user-approved.
 *   --comment=<id> --note="…"           the support team's reply in the report's thread (M179); ALWAYS refused
 *                                       without --user-approved.
 *   --relay=<file> --tenant=<slug>      loads relayed comments by reference (M179, ADR-0023 §D7); ALWAYS refused
 *                                       without --user-approved. `--reporter=<email>` names the reporter.
 * Resolved and Declined tell the reporter and everyone who wrote in the thread (a notification and an email
 * quoting the note); so does a reply. Wherever an id is taken, a REFERENCE (`U4`, `R1-7a`) may stand in for it:
 * it is looked up, and anything but exactly one match is refused.
 *
 * THE THREADS (M179, `D151`).
 *
 * A tester can now say **Still a problem** on a fixed report, or comment on any. The pull shows those since the
 * last pull — REOPENED first, then NEW COMMENTS — from a state file beside the token
 * (`feedback-pull-state.json`, outside the repository), which only a pull advances; `--since=<ISO-8601>`
 * overrides it. Our own replies are never news. Relayed reports are summarised in one line rather than listed —
 * they are cited on their rows — unless one was reopened or commented on.
 *
 * THE TOKEN.
 *
 * A platform support token, issued on the console's Settings page and saved — by the user, never through the
 * chat — in a file OUTSIDE the repository: `%USERPROFILE%\.meridian\feedback-pull.json` (or `$HOME/.meridian/…`),
 * holding `{"url": "https://staging.pitahc.gov.ph", "token": "msp_…"}`. `--config=<file>` reads another. The
 * token is never printed. Screenshots are written only under `--out=<dir>` — the session's scratchpad.
 *
 * Host PHP, no `vendor/`: the curl extension, which on this host carries a CA bundle (`curl.cainfo`) where PHP's
 * own https stream wrapper does not (`openssl.cafile` is empty).
 *
 * EXIT CODES — distinct, so the session and `scripts/preflight.php` can tell what happened:
 *   0 done · 2 usage · 3 no token file (or an unreadable one) · 4 the API refused the token ·
 *   5 unreachable, or an unexpected answer · 6 needs the user's approval · 7 the lifecycle refused the step
 */

const FEEDBACK_PULL_EXIT_OK = 0;
const FEEDBACK_PULL_EXIT_USAGE = 2;
const FEEDBACK_PULL_EXIT_NO_CONFIG = 3;
const FEEDBACK_PULL_EXIT_REFUSED = 4;
const FEEDBACK_PULL_EXIT_UNREACHABLE = 5;
const FEEDBACK_PULL_EXIT_NEEDS_APPROVAL = 6;
const FEEDBACK_PULL_EXIT_INVALID_TRANSITION = 7;

const FEEDBACK_PULL_OPTIONS = ['config', 'out', 'count', 'ongoing', 'resolve', 'decline', 'comment', 'relay', 'tenant', 'reporter', 'since', 'version', 'note', 'user-approved', 'timeout', 'help'];

/** A relayed comment's reference, as the support API accepts it. */
const FEEDBACK_PULL_REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9.\-]{0,29}$/';

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$options = [];

// Every option is checked against the list, reading $argv rather than getopt(), which silently drops an
// unknown long option and would let a typo fall through to an action.
foreach ($arguments as $argument) {
    if (! str_starts_with($argument, '--')) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, "unexpected argument '{$argument}'.\n\n".feedback_pull_usage());
    }

    [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);

    if (! in_array($name, FEEDBACK_PULL_OPTIONS, true)) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, "unrecognised option '--{$name}'.\n\n".feedback_pull_usage());
    }

    $options[$name] = $value ?? true;
}

if (isset($options['help'])) {
    fwrite(STDOUT, feedback_pull_usage());

    exit(FEEDBACK_PULL_EXIT_OK);
}

$configPath = feedback_pull_config_path(is_string($options['config'] ?? null) ? $options['config'] : null);
$config = feedback_pull_config($configPath);
$timeout = is_string($options['timeout'] ?? null) ? max(1, (int) $options['timeout']) : 20;
$statePath = dirname($configPath).DIRECTORY_SEPARATOR.'feedback-pull-state.json';
$since = is_string($options['since'] ?? null) ? $options['since'] : feedback_pull_since($statePath, $config['url']);

// ── --count: one line for preflight ─────────────────────────────────────────────────────────────────────────
// `new=` first and unchanged (preflight's regex reads it); since M179 also the reopened reports and the members'
// new comments since the last pull. Never advances the state: only a pull does.
if (isset($options['count'])) {
    $page = feedback_pull_json($config, 'GET', '/feedback?status=new&source=button&per_page=1', null, min($timeout, 10));
    $threads = feedback_pull_threads($config, $since, min($timeout, 10));
    fwrite(STDOUT, sprintf(
        "new=%d reopened=%d comments=%d\n",
        (int) ($page['meta']['total'] ?? 0),
        count($threads['reopened']),
        count($threads['comments']),
    ));

    exit(FEEDBACK_PULL_EXIT_OK);
}

// ── --relay: load relayed comments by reference (M179, ADR-0023 §D7) ───────────────────────────────────────
if (isset($options['relay'])) {
    $file = is_string($options['relay']) ? $options['relay'] : '';
    $tenant = is_string($options['tenant'] ?? null) ? trim($options['tenant']) : '';
    if ($file === '' || ! is_file($file) || $tenant === '') {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, '--relay needs a file and a workspace: --relay=<file> --tenant=<slug>.');
    }
    if (! isset($options['user-approved'])) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_NEEDS_APPROVAL, 'A relay puts words in front of every member of the workspace, so it goes to the user first (D150). Nothing was sent. Re-run with --user-approved once the user has approved the text.');
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    $items = is_array($decoded) && is_array($decoded['items'] ?? null) ? array_values($decoded['items']) : null;
    if ($items === null || $items === []) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, "{$file} holds no \"items\".");
    }

    $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
    foreach (array_chunk($items, 100) as $batch) {
        $answer = feedback_pull_json($config, 'POST', '/feedback/relayed', array_filter([
            'tenant' => $tenant,
            'reporter_email' => is_string($options['reporter'] ?? null) ? $options['reporter'] : null,
            'items' => $batch,
        ], static fn (mixed $value): bool => $value !== null), $timeout);
        foreach ((array) ($answer['data'] ?? []) as $result) {
            $outcome = (string) ($result['outcome'] ?? '');
            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }
    }

    fwrite(STDOUT, sprintf("relayed %d item(s): %d created, %d updated, %d unchanged.\n", count($items), $tally['created'], $tally['updated'], $tally['unchanged']));

    exit(FEEDBACK_PULL_EXIT_OK);
}

// ── --comment: the support team's reply in a thread (M179) ─────────────────────────────────────────────────
if (isset($options['comment'])) {
    $note = is_string($options['note'] ?? null) ? trim($options['note']) : '';
    if ($note === '') {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, '--comment needs the reply: --comment=<id|reference> --note="…".');
    }
    if (! isset($options['user-approved'])) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_NEEDS_APPROVAL, 'A reply goes to the user first (D139). Nothing was sent. Re-run with --user-approved once the user has approved the words.');
    }

    $id = feedback_pull_report_id($config, 'comment', is_string($options['comment']) ? $options['comment'] : '', $timeout);
    feedback_pull_json($config, 'POST', '/feedback/'.rawurlencode($id).'/comments', ['body' => $note], $timeout);
    fwrite(STDOUT, 'feedback '.substr($id, 0, 8)." replied, and the reporter and the thread told.\n");

    exit(FEEDBACK_PULL_EXIT_OK);
}

// ── A status change ────────────────────────────────────────────────────────────────────────────────────────
foreach (['ongoing' => 'reviewed', 'resolve' => 'resolved', 'decline' => 'wont_fix'] as $verb => $status) {
    if (! isset($options[$verb])) {
        continue;
    }

    $note = is_string($options['note'] ?? null) ? trim($options['note']) : null;
    $version = is_string($options['version'] ?? null) ? trim($options['version']) : null;
    $approved = isset($options['user-approved']);

    if ($verb === 'ongoing') {
        // Ongoing tells the reporter nothing, so nothing is said: a note here would be a reply nobody reads.
        $body = ['status' => $status];
    } elseif ($verb === 'resolve' && $note === null && $version !== null && $version !== '') {
        $body = ['status' => $status, 'note' => "Fixed in version {$version}, live on staging."];
    } elseif ($note !== null && $note !== '' && $approved) {
        $body = ['status' => $status, 'note' => $note];
    } elseif ($note !== null && $note !== '') {
        feedback_pull_fail(
            FEEDBACK_PULL_EXIT_NEEDS_APPROVAL,
            "--{$verb} with a reply of its own goes to the user first (D139). Nothing was sent. Re-run with --user-approved once the user has approved the words."
        );
    } else {
        feedback_pull_fail(
            FEEDBACK_PULL_EXIT_NEEDS_APPROVAL,
            $verb === 'decline'
                ? '--decline needs --note="…" and the user\'s approval (--user-approved). Nothing was sent.'
                : '--resolve needs --version=<sha7> for the stock fixed note, or --note="…" with --user-approved. Nothing was sent.'
        );
    }

    if ($verb === 'decline' && ! $approved) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_NEEDS_APPROVAL, 'A decline always goes to the user first (D139). Nothing was sent.');
    }

    // An id, or a reference looked up to exactly one report (M179) — only once the step itself is allowed.
    $id = feedback_pull_report_id($config, $verb, is_string($options[$verb]) ? $options[$verb] : '', $timeout);

    $answer = feedback_pull_json($config, 'PATCH', '/feedback/'.rawurlencode($id), $body, $timeout);
    $label = ['reviewed' => 'Ongoing', 'resolved' => 'Resolved', 'confirmed' => 'Confirmed working', 'wont_fix' => 'Declined'][$status];
    fwrite(STDOUT, 'feedback '.substr($id, 0, 8)." marked {$label}".(isset($body['note']) ? ', with the note sent to the reporter' : '').".\n");
    unset($answer);

    exit(FEEDBACK_PULL_EXIT_OK);
}

// ── The pull ───────────────────────────────────────────────────────────────────────────────────────────────
$out = is_string($options['out'] ?? null) ? rtrim($options['out'], '/\\') : null;
if ($out === null || $out === '') {
    feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, "--out=<dir> is required for a pull (the session's scratchpad).\n\n".feedback_pull_usage());
}
if (! is_dir($out) && ! @mkdir($out, 0700, true) && ! is_dir($out)) {
    feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, "cannot create {$out}.");
}

$rows = [];
$page = 1;
do {
    // Reports sent with the Feedback button (M179): relayed ones are summarised below, not re-triaged each time.
    $answer = feedback_pull_json($config, 'GET', '/feedback?status=new,reviewed&source=button&per_page=100&page='.$page, null, $timeout);
    foreach ((array) ($answer['data'] ?? []) as $row) {
        $rows[] = $row;
    }
    $last = (int) ($answer['meta']['last_page'] ?? 1);
    $page++;
} while ($page <= $last);

foreach ($rows as $i => $row) {
    if (($row['has_screenshot'] ?? false) !== true) {
        continue;
    }
    $bytes = feedback_pull_request($config, 'GET', '/feedback/'.rawurlencode((string) $row['id']).'/screenshot', null, $timeout);
    if ($bytes['status'] === 200) {
        $file = $out.'/'.$row['id'].'.png';
        file_put_contents($file, $bytes['body']);
        $rows[$i]['screenshot_file'] = $file;
    } else {
        $rows[$i]['screenshot_file'] = null;
        $rows[$i]['screenshot_note'] = $bytes['status'] === 409 ? 'still being scanned' : 'not available ('.$bytes['status'].')';
    }
}

file_put_contents($out.'/feedback.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$counts = array_count_values(array_map(static fn (array $r): string => (string) ($r['status_label'] ?? $r['status'] ?? '?'), $rows));
fwrite(STDOUT, sprintf("feedback: %d report(s) — %s. Full rows in %s\n", count($rows), $counts === [] ? 'none' : implode(', ', array_map(
    static fn (string $label, int $n): string => "{$n} {$label}",
    array_keys($counts),
    array_values($counts),
)), $out.'/feedback.json'));

foreach ($rows as $row) {
    fwrite(STDOUT, "\n".str_repeat('─', 78)."\n");
    fwrite(STDOUT, sprintf(
        "%s  %s · %s · %s · %s\n",
        substr((string) $row['id'], 0, 8),
        $row['type_label'] ?? 'No kind',
        $row['status_label'] ?? $row['status'] ?? '?',
        $row['tenant_name'] ?? '?',
        $row['submitted_at'] ?? '?',
    ));
    fwrite(STDOUT, '  id:       '.$row['id']."\n");
    fwrite(STDOUT, '  from:     '.(($row['reporter']['name'] ?? 'Former member').' <'.($row['reporter']['email'] ?? '—').'>')
        .(isset($row['reporter_role_label']) && $row['reporter_role_label'] !== null ? ' · '.$row['reporter_role_label'] : '')."\n");
    fwrite(STDOUT, '  page:     '.($row['page_title'] ?? $row['route'] ?? '—').(isset($row['page_url']) && $row['page_url'] !== null ? '  '.$row['page_url'] : '')."\n");
    if (isset($row['screenshot_file']) && $row['screenshot_file'] !== null) {
        fwrite(STDOUT, '  picture:  '.$row['screenshot_file']."\n");
    } elseif (isset($row['screenshot_note'])) {
        fwrite(STDOUT, '  picture:  '.$row['screenshot_note']."\n");
    }
    if (isset($row['resolution_note']) && $row['resolution_note'] !== null) {
        fwrite(STDOUT, '  replied:  '.$row['resolution_note']."\n");
    }
    fwrite(STDOUT, "  remarks:\n    ".str_replace("\n", "\n    ", trim((string) ($row['remarks'] ?? '')))."\n");
}

// ── The threads since the last pull (M179, `D151`) — triaged like a new report ────────────────────────────
$threads = feedback_pull_threads($config, $since, $timeout);
file_put_contents($out.'/threads.json', json_encode($threads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

fwrite(STDOUT, "\n".str_repeat('═', 78)."\n");
if ($threads['reopened'] === [] && $threads['comments'] === []) {
    fwrite(STDOUT, "threads: no reopened reports and no new comments since {$since}.\n");
} else {
    fwrite(STDOUT, sprintf("threads since %s: %d REOPENED, %d new comment(s) — triage each like a new report.\n", $since, count($threads['reopened']), count($threads['comments'])));
    foreach ($threads['reopened'] as $entry) {
        fwrite(STDOUT, "\nREOPENED  ".feedback_pull_thread_line($entry)."\n");
    }
    foreach ($threads['comments'] as $entry) {
        fwrite(STDOUT, "\nCOMMENT   ".feedback_pull_thread_line($entry)."\n");
    }
}

// The relayed reports in one line: they are cited on their rows, and listed above only when reopened or commented.
$relayed = feedback_pull_json($config, 'GET', '/feedback?source=relayed&per_page=100', null, $timeout);
$relayedCounts = array_count_values(array_map(static fn (array $r): string => (string) ($r['status_label'] ?? $r['status'] ?? '?'), (array) ($relayed['data'] ?? [])));
fwrite(STDOUT, sprintf("relayed: %d — %s.\n", (int) ($relayed['meta']['total'] ?? count((array) ($relayed['data'] ?? []))), $relayedCounts === [] ? 'none' : implode(', ', array_map(
    static fn (string $label, int $n): string => "{$n} {$label}",
    array_keys($relayedCounts),
    array_values($relayedCounts),
))));

// Advance the state only after a pull that read everything above — to the newest member message seen, so a
// comment written while this ran is still news next time.
if (! isset($options['since']) && $threads['newest'] !== null) {
    feedback_pull_save_since($statePath, $config['url'], $threads['newest']);
}

exit(FEEDBACK_PULL_EXIT_OK);

// ── Helpers ────────────────────────────────────────────────────────────────────────────────────────────────

/** The token file's path: `--config`, else `%USERPROFILE%\.meridian\feedback-pull.json`. */
function feedback_pull_config_path(?string $path): string
{
    if ($path !== null) {
        return $path;
    }

    $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';

    return rtrim((string) $home, '/\\').DIRECTORY_SEPARATOR.'.meridian'.DIRECTORY_SEPARATOR.'feedback-pull.json';
}

/**
 * Where the last pull left off, for this server (M179). With no state yet, the last fortnight — the window a
 * session is likely to have missed.
 */
function feedback_pull_since(string $statePath, string $url): string
{
    $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
    $since = is_array($state) && is_string($state[$url]['comments_since'] ?? null) ? $state[$url]['comments_since'] : null;

    return $since ?? gmdate('Y-m-d\TH:i:s\Z', time() - 14 * 86400);
}

function feedback_pull_save_since(string $statePath, string $url, string $since): void
{
    $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
    $state = is_array($state) ? $state : [];
    $state[$url] = ['comments_since' => $since];

    file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * The reports with a MEMBER's message newer than `$since`, split into REOPENED (a tester said Still a problem) and
 * new COMMENTS — our own replies are never news (M179). Filtered here too, by each message's own time, so a
 * server that answers with the whole thread still yields only what is new.
 *
 * @param  array{url: string, token: string}  $config
 * @return array{reopened: list<array<string, mixed>>, comments: list<array<string, mixed>>, newest: ?string}
 */
function feedback_pull_threads(array $config, string $since, int $timeout): array
{
    $reopened = [];
    $comments = [];
    $newest = null;
    $page = 1;

    do {
        $answer = feedback_pull_json($config, 'GET', '/feedback?per_page=100&page='.$page.'&commented_since='.rawurlencode($since), null, $timeout);
        foreach ((array) ($answer['data'] ?? []) as $row) {
            foreach ((array) ($row['comments'] ?? []) as $comment) {
                $at = (string) ($comment['created_at'] ?? '');
                if (($comment['author_kind'] ?? '') !== 'member' || strtotime($at) <= strtotime($since)) {
                    continue;
                }
                $newest = $newest === null || strtotime($at) > strtotime($newest) ? $at : $newest;
                $entry = ['report' => $row, 'comment' => $comment];
                if (($comment['action'] ?? null) === 'reopened') {
                    $reopened[] = $entry;
                } elseif (($comment['action'] ?? null) === null) {
                    $comments[] = $entry;
                }
            }
        }
        $last = (int) ($answer['meta']['last_page'] ?? 1);
        $page++;
    } while ($page <= $last);

    return ['reopened' => $reopened, 'comments' => $comments, 'newest' => $newest];
}

/** @param array{report: array<string, mixed>, comment: array<string, mixed>} $entry */
function feedback_pull_thread_line(array $entry): string
{
    $report = $entry['report'];
    $comment = $entry['comment'];
    $name = is_array($comment['author'] ?? null)
        ? ($comment['author']['name'] ?? '?').' <'.($comment['author']['email'] ?? '—').'>'
        : 'Former member';

    return sprintf(
        "%s  %s · %s · %s\n  id:       %s\n  by:       %s · %s\n  says:\n    %s",
        substr((string) ($report['id'] ?? ''), 0, 8),
        $report['reference'] ?? 'button report',
        $report['title'] ?? $report['page_title'] ?? $report['route'] ?? '—',
        $report['status_label'] ?? $report['status'] ?? '?',
        $report['id'] ?? '?',
        $name,
        $comment['created_at'] ?? '?',
        str_replace("\n", "\n    ", trim((string) ($comment['body'] ?? ''))),
    );
}

/**
 * A report's id from what the session typed: an id as it is, a reference looked up to EXACTLY one report (M179).
 *
 * @param  array{url: string, token: string}  $config
 */
function feedback_pull_report_id(array $config, string $verb, string $given, int $timeout): string
{
    // An id is uuid-shaped: eight hex digits and a hyphen, then the rest. No reference can start that way.
    if (preg_match('/^[0-9a-f]{8}-[0-9A-Za-z-]{8,56}$/i', $given) === 1) {
        return $given;
    }

    if (preg_match(FEEDBACK_PULL_REFERENCE, $given) !== 1) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, "--{$verb} needs a report id or a reference: --{$verb}=<id|reference>.");
    }

    $found = (array) (feedback_pull_json($config, 'GET', '/feedback?per_page=2&reference='.rawurlencode($given), null, $timeout)['data'] ?? []);
    if (count($found) !== 1) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, count($found) === 0
            ? "no report has the reference {$given}."
            : "the reference {$given} names more than one report (in more than one workspace) — use the id.");
    }

    return (string) $found[0]['id'];
}

/** @return array{url: string, token: string} */
function feedback_pull_config(string $path): array
{
    if (! is_file($path)) {
        feedback_pull_fail(
            FEEDBACK_PULL_EXIT_NO_CONFIG,
            "no token file at {$path}. Create a support token on the console's Settings page and save "
            .'{"url": "https://<workspace host>", "token": "msp_…"} there.'
        );
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    $url = is_array($decoded) && is_string($decoded['url'] ?? null) ? rtrim($decoded['url'], '/') : '';
    $token = is_array($decoded) && is_string($decoded['token'] ?? null) ? trim($decoded['token']) : '';

    if ($url === '' || $token === '' || preg_match('#^https?://#', $url) !== 1) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_NO_CONFIG, "the token file at {$path} needs a \"url\" (http or https) and a \"token\".");
    }

    return ['url' => $url, 'token' => $token];
}

/**
 * One request; the raw status and body.
 *
 * @param  array{url: string, token: string}  $config
 * @param  array<string, mixed>|null  $body
 * @return array{status: int, body: string}
 */
function feedback_pull_request(array $config, string $method, string $path, ?array $body, int $timeout): array
{
    if (! function_exists('curl_init')) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_UNREACHABLE, 'the PHP curl extension is not loaded.');
    }

    $curl = curl_init($config['url'].'/api/platform/v1'.$path);
    $headers = ['Accept: application/json', 'Authorization: Bearer '.$config['token']];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false || $status === 0) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_UNREACHABLE, 'could not reach '.$config['url'].': '.($error !== '' ? $error : 'no answer').'.');
    }

    return ['status' => $status, 'body' => (string) $response];
}

/**
 * One request whose answer must be JSON; refusals and failures exit with their own codes.
 *
 * @param  array{url: string, token: string}  $config
 * @param  array<string, mixed>|null  $body
 * @return array<string, mixed>
 */
function feedback_pull_json(array $config, string $method, string $path, ?array $body, int $timeout): array
{
    $answer = feedback_pull_request($config, $method, $path, $body, $timeout);
    $decoded = json_decode($answer['body'], true);
    $message = is_array($decoded) && is_string($decoded['error']['message'] ?? null) ? $decoded['error']['message'] : null;

    if ($answer['status'] === 401 || $answer['status'] === 403) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_REFUSED, 'the API refused the token (HTTP '.$answer['status'].'). It may be expired or revoked — create a new one on the console\'s Settings page.');
    }
    if ($answer['status'] === 422 && is_array($decoded) && ($decoded['error']['code'] ?? null) === 'invalid_transition') {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_INVALID_TRANSITION, (string) $message);
    }
    if ($answer['status'] < 200 || $answer['status'] >= 300 || ! is_array($decoded)) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_UNREACHABLE, 'unexpected answer (HTTP '.$answer['status'].')'.($message !== null ? ": {$message}" : '').'.');
    }

    return $decoded;
}

function feedback_pull_fail(int $code, string $message): never
{
    fwrite(STDERR, 'feedback-pull: '.$message."\n");

    exit($code);
}

function feedback_pull_usage(): string
{
    return <<<'TXT'
        Usage:
          php scripts/feedback-pull.php --out=<scratchpad dir>          pull New + Ongoing, with screenshots, and print a digest
          php scripts/feedback-pull.php --count                         print new=<N> (for preflight)
          php scripts/feedback-pull.php --ongoing=<id>                  mark a report Ongoing (no note)
          php scripts/feedback-pull.php --resolve=<id> --version=<sha7> mark it Resolved: "Fixed in version <sha7>, live on staging."
          php scripts/feedback-pull.php --resolve=<id> --note="…" --user-approved
          php scripts/feedback-pull.php --decline=<id> --note="…" --user-approved
          php scripts/feedback-pull.php --comment=<id> --note="…" --user-approved     the support team's reply
          php scripts/feedback-pull.php --relay=<file> --tenant=<slug> [--reporter=<email>] --user-approved
        Wherever an <id> is taken, a reference (U4, R1-7a) may stand in for it.
        Options: --config=<file> (default %USERPROFILE%\.meridian\feedback-pull.json) · --timeout=<seconds>
                 --since=<ISO-8601> (threads newer than this, instead of the state beside the token file)
        Exit: 0 done · 2 usage · 3 no token file · 4 token refused · 5 unreachable · 6 needs approval · 7 step refused

        TXT;
}
