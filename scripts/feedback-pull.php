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
 * Resolved and Declined tell the reporter (a notification and an email quoting the note).
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

const FEEDBACK_PULL_OPTIONS = ['config', 'out', 'count', 'ongoing', 'resolve', 'decline', 'version', 'note', 'user-approved', 'timeout', 'help'];

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

$config = feedback_pull_config(is_string($options['config'] ?? null) ? $options['config'] : null);
$timeout = is_string($options['timeout'] ?? null) ? max(1, (int) $options['timeout']) : 20;

// ── --count: one line for preflight ─────────────────────────────────────────────────────────────────────────
if (isset($options['count'])) {
    $page = feedback_pull_json($config, 'GET', '/feedback?status=new&per_page=1', null, min($timeout, 10));
    fwrite(STDOUT, 'new='.(int) ($page['meta']['total'] ?? 0)."\n");

    exit(FEEDBACK_PULL_EXIT_OK);
}

// ── A status change ────────────────────────────────────────────────────────────────────────────────────────
foreach (['ongoing' => 'reviewed', 'resolve' => 'resolved', 'decline' => 'wont_fix'] as $verb => $status) {
    if (! isset($options[$verb])) {
        continue;
    }

    $id = is_string($options[$verb]) ? $options[$verb] : '';
    if (preg_match('/^[0-9A-Za-z-]{8,64}$/', $id) !== 1) {
        feedback_pull_fail(FEEDBACK_PULL_EXIT_USAGE, "--{$verb} needs a report id: --{$verb}=<id>.");
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

    $answer = feedback_pull_json($config, 'PATCH', '/feedback/'.rawurlencode($id), $body, $timeout);
    $label = ['reviewed' => 'Ongoing', 'resolved' => 'Resolved', 'wont_fix' => 'Declined'][$status];
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
    $answer = feedback_pull_json($config, 'GET', '/feedback?status=new,reviewed&per_page=100&page='.$page, null, $timeout);
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

exit(FEEDBACK_PULL_EXIT_OK);

// ── Helpers ────────────────────────────────────────────────────────────────────────────────────────────────

/** @return array{url: string, token: string} */
function feedback_pull_config(?string $path): array
{
    if ($path === null) {
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        $path = rtrim((string) $home, '/\\').DIRECTORY_SEPARATOR.'.meridian'.DIRECTORY_SEPARATOR.'feedback-pull.json';
    }

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
        Options: --config=<file> (default %USERPROFILE%\.meridian\feedback-pull.json) · --timeout=<seconds>
        Exit: 0 done · 2 usage · 3 no token file · 4 token refused · 5 unreachable · 6 needs approval · 7 step refused

        TXT;
}
