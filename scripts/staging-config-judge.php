<?php

declare(strict_types=1);

/*
 * Staging Apache configuration judge (M251, R-a3918bf4) — the JUDGING half of
 * `scripts/staging-config-probe.ps1`.
 *
 * WHY THIS EXISTS.
 *
 * `docs/deployment-infrastructure.md` section 8.3 says what the testing box's Apache configuration holds:
 * which vhost carries the crawl header and HSTS, the certificate traps, the port-80 redirect, the global
 * `ServerName`. Until `M251` all of it was prose that nothing checked. `scripts/staging-headers-judge.php` sees
 * only what the site SENDS over 443. It cannot see the port-80 vhost, because inbound 80 is closed and this
 * network does not hairpin, and it cannot see a certificate trap until the certificate it guards has already
 * failed. The probe prints what the configuration files actually say, and this file rules on that print.
 *
 * THE THREE-WAY EXIT CONTRACT, the one `scripts/staging-headers-judge.php` already publishes:
 *
 *   0  judged, and every expectation below holds
 *   1  an expectation does not hold: the box has drifted from the runbook, or the runbook from the box
 *   2  the report was not recognised, so NOTHING was judged
 *
 * ⛔ RECOGNITION IS POSITIVE. A report counts only from its signature line to its `end|<count>` line, and the
 * count must equal the records between them. Every record must be a kind this file knows, every `d|` must sit
 * in a file a `file|` record named, every section must close, and the server's own vhost map (`httpd -S`) must
 * be present. A console that wraps a long line, a paste cut short, the v1 probe, or a page of something else is
 * exit 2 and never a verdict, because an unrecognised shape is not a clean one. Text before the signature and
 * after the `end` line is ignored: that is where a console prompt lands.
 *
 * ⛔ IT RULES BY VHOST, NEVER BY LINE NUMBER. A vhost is the block `httpd -S` says answers the site's name on
 * that port, and that block must be one the report contains, with that address. So a second block answering
 * the name, or a block the probe never read, is caught rather than skipped. Line numbers appear in the output
 * only to point at a line.
 *
 * ⛔ A DIRECTIVE COUNTS FOR A VHOST ONLY WHEN NOTHING NARROWER SITS BETWEEN THEM. A `Header` inside a
 * `<Location>` covers one path, and reading it as vhost-wide would pass a crawl header that guards a single
 * URL. `<IfModule>` and its kin do not narrow, unless negated. This is why the probe prints every section line,
 * and why its v1, which printed only `<VirtualHost>` and `<MDomain>`, is refused.
 *
 * ⚠️ THE HEADER POLICY BELOW STATES THE SAME DECISIONS AS `STAGING_REQUIRED_HEADERS` IN
 * `scripts/staging-headers-judge.php`: the crawl header's three directives, and `D54`'s HSTS. The two judge
 * different things, what the file says and what the site sends, so a change to either decision edits both.
 * The nightly headers judge is what catches a box whose file and response disagree.
 *
 * ⚠️ THIS FILE MAKES NO NETWORK CALL AND READS NOTHING ON THE BOX. The probe runs there, by hand, after any
 * Apache edit. Its floor is `tests/Feature/Docs/StagingConfigJudgeTest.php`, for the same reason the headers
 * judge's floor is its own Pest file rather than `docs/gate-baselines.md`.
 *
 * Usage:
 *   php scripts/staging-config-judge.php --report=<file>
 */

const CONFIG_EXIT_OK = 0;
const CONFIG_EXIT_BLOCKED = 1;
const CONFIG_EXIT_CANNOT_MEASURE = 2;

/** The probe's signature, version included. v1 printed no section lines, so it cannot be judged by vhost scope. */
const CONFIG_SIGNATURE = 'meridian-config-probe|v2|';

/** The site's name: the `<MDomain>`, and the `ServerName` of each vhost below. */
const CONFIG_SITE = 'iconnect.com.de';

/**
 * The vhosts the site must have, by address.
 *
 *   headers  the headers it must send, each judged by CONFIG_HEADER_POLICY
 *   exact    directives it must carry with exactly these arguments
 *   present  directives it must carry with any value
 */
const CONFIG_VHOSTS = [
    '*:443' => [
        'headers' => ['X-Robots-Tag', 'Strict-Transport-Security'],
        'exact' => ['SSLEngine on'],
        // Trap 3: the floor certificate pair stays in the vhost permanently.
        'present' => ['SSLCertificateFile', 'SSLCertificateKeyFile'],
    ],
    '*:80' => [
        // R-5e9f620f: the redirect is a response too, and a crawler that follows an http:// link reads it.
        'headers' => ['X-Robots-Tag'],
        'exact' => ['Redirect permanent / https://'.CONFIG_SITE.'/'],
        'present' => [],
    ],
];

/**
 * Header => the policy its one `Header always set` line must satisfy. Tokens lower-cased.
 *
 *   separator  the header's own delimiter: comma for `X-Robots-Tag`, semicolon for HSTS (RFC 6797)
 *   require    directives that must be in the value. Never empty.
 *   forbid     directives that must not be. `D54`: no `includeSubDomains`, no `preload`.
 */
const CONFIG_HEADER_POLICY = [
    'X-Robots-Tag' => [
        'separator' => ',',
        'require' => ['noindex', 'nofollow', 'noarchive'],
        'forbid' => [],
    ],
    'Strict-Transport-Security' => [
        'separator' => ';',
        'require' => ['max-age=300'],
        'forbid' => ['includesubdomains', 'preload'],
    ],
];

/**
 * Directives that must hold outside every vhost, or inside the site's own `<MDomain>`. Trap 1 is the first. The
 * second is the only challenge type that can reach this box, since inbound 80 is closed.
 */
const CONFIG_MD_REQUIRED = [
    'MDPrivateKeys secp256r1',
    'MDCAChallenges tls-alpn-01',
];

/** Trap 2: these must appear nowhere in the live configuration. */
const CONFIG_FORBIDDEN = ['MDCertificateFile', 'MDCertificateKeyFile'];

/** The global `ServerName` `M102` set in `conf/httpd.conf`. Section 8.3 says why it is not the site's name. */
const CONFIG_GLOBAL_SERVERNAME = 'localhost';

/** The file every report must name, because Apache reads nothing before it. */
const CONFIG_MAIN_FILE = 'conf/httpd.conf';

/** Sections that do not narrow what they contain, when they are not negated. */
const CONFIG_TRANSPARENT_SECTIONS = ['ifmodule', 'ifdefine', 'ifversion', 'iffile', 'ifdirective', 'ifsection'];

const CONFIG_LONG_OPTS = ['help', 'report'];

$argv = $_SERVER['argv'] ?? [];
$arguments = array_slice($argv, 1);

// Refuse any unrecognised option, reading $argv rather than getopt(), which silently discards a long option
// it was not told about, so a typo'd flag would fall through to the action.
$reportPath = null;

foreach ($arguments as $argument) {
    if (! str_starts_with($argument, '-')) {
        fwrite(STDERR, "staging-config-judge: unexpected positional argument '{$argument}'.\n\n".config_usage());

        exit(CONFIG_EXIT_CANNOT_MEASURE);
    }

    [$name, $value] = array_pad(explode('=', $argument, 2), 2, null);
    $name = ltrim((string) $name, '-');

    if (! in_array($name, CONFIG_LONG_OPTS, true)) {
        fwrite(STDERR, "staging-config-judge: unrecognised option '{$argument}'.\n\n".config_usage());

        exit(CONFIG_EXIT_CANNOT_MEASURE);
    }

    if ($name === 'report') {
        $reportPath = (string) $value;
    }
}

// The --help arm comes AFTER the refusal, so `--help --bogus` refuses rather than exiting 0.
if (in_array('--help', $arguments, true)) {
    fwrite(STDOUT, config_usage());

    exit(CONFIG_EXIT_OK);
}

if ($reportPath === null || $reportPath === '') {
    fwrite(STDERR, "staging-config-judge: --report=<file> is required.\n\n".config_usage());

    exit(CONFIG_EXIT_CANNOT_MEASURE);
}

exit(config_judge($reportPath));

/** Judge one saved probe report. */
function config_judge(string $path): int
{
    if (! is_file($path)) {
        return config_cannot_measure("the report is not a file: {$path}");
    }

    $parsed = config_parse((string) file_get_contents($path));

    if (is_string($parsed)) {
        return config_cannot_measure($parsed);
    }

    $model = config_model($parsed);

    if (is_string($model)) {
        return config_cannot_measure($model);
    }

    $failures = [];
    $report = [];

    config_judge_includes($parsed, $model, $failures, $report);
    config_judge_global($model, $failures, $report);
    config_judge_md($model, $failures, $report);

    foreach (CONFIG_VHOSTS as $address => $expectation) {
        $verdict = config_judge_vhost($address, $expectation, $parsed, $model, $failures, $report);

        if ($verdict !== null) {
            return config_cannot_measure($verdict);
        }
    }

    $floor = sprintf(
        '%d file(s) read, %d directive(s), %d section(s), %d vhost(s) in the files, %d name(s) in the server map, %d expectation(s) - site %s',
        count($parsed['files']),
        count($model['directives']),
        count($model['sections']),
        count($model['vhosts']),
        count($parsed['map']),
        config_expectation_count(),
        CONFIG_SITE,
    );

    $inert = array_map(
        static fn (array $record): string => $record['path'].':'.$record['line'],
        $parsed['inert'],
    );

    if ($failures !== []) {
        fwrite(STDERR, "staging-config-judge: BLOCKED - the box's Apache configuration does not hold what section 8.3 says.\n");
        fwrite(STDERR, 'staging-config-judge: '.$floor."\n");

        foreach ($failures as $line) {
            fwrite(STDERR, '  - '.$line."\n");
        }

        if ($inert !== []) {
            fwrite(STDERR, '  (inert, not counted: X-Robots-Tag in files no Include names - '.implode(', ', $inert).")\n");
        }

        fwrite(STDERR, "staging-config-judge: see docs/deployment-infrastructure.md section 8.3, and fix the box or the runbook.\n");

        return CONFIG_EXIT_BLOCKED;
    }

    fwrite(STDOUT, "staging-config-judge: JUDGED - every expectation holds.\n");
    fwrite(STDOUT, 'staging-config-judge: '.$floor."\n");

    foreach ($report as $line) {
        fwrite(STDOUT, '  - '.$line."\n");
    }

    if ($inert !== []) {
        fwrite(STDOUT, '  (inert, not counted: X-Robots-Tag in files no Include names - '.implode(', ', $inert).")\n");
    }

    return CONFIG_EXIT_OK;
}

/**
 * Read the records between the signature and the end line, or say why they cannot be read.
 *
 * @return array{root: string, files: array<string, array{hash: string, lines: int}>, directives: list<array{path: string, line: int, text: string}>, inert: list<array{path: string, line: int}>, missing: list<string>, map: list<array{port: string, name: string, path: string, line: int}>}|string
 */
function config_parse(string $raw): array|string
{
    // A report saved by Windows PowerShell's `>` is UTF-16, and one saved by `Out-File -Encoding utf8` opens
    // with a byte-order mark. Neither is a different report.
    if (str_starts_with($raw, chr(0xFF).chr(0xFE))) {
        $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    }

    $raw = str_replace(chr(0xEF).chr(0xBB).chr(0xBF), '', $raw);
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $raw));
    $lines = array_map(static fn (string $line): string => rtrim($line, " \t"), $lines);

    $start = null;

    foreach ($lines as $index => $line) {
        if (str_starts_with($line, CONFIG_SIGNATURE)) {
            $start = $index;

            break;
        }

        if (str_starts_with($line, 'meridian-config-probe|')) {
            return 'this is a report from another version of the probe ('.explode('|', $line)[1].'), and this judge '
                .'reads '.rtrim(CONFIG_SIGNATURE, '|').' only. Re-run the current scripts/staging-config-probe.ps1.';
        }
    }

    if ($start === null) {
        return 'no probe signature ('.rtrim(CONFIG_SIGNATURE, '|').') in the report, so it is not a probe report.';
    }

    $stamp = substr($lines[$start], strlen(CONFIG_SIGNATURE));

    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', $stamp) !== 1) {
        return "the signature line carries no UTC time: '{$lines[$start]}'.";
    }

    $parsed = ['root' => '', 'files' => [], 'directives' => [], 'inert' => [], 'missing' => [], 'map' => []];
    $sMap = [];
    $records = 1;
    $end = null;

    for ($index = $start + 1; $index < count($lines); $index++) {
        $line = $lines[$index];
        $kind = explode('|', $line, 2)[0];

        // Nothing but blank lines left: the report stops here, and the missing end line below says so.
        if ($line === '' && implode('', array_slice($lines, $index)) === '') {
            break;
        }

        if ($kind === 'end') {
            $end = $line;

            break;
        }

        $records++;
        $where = 'report line '.($index + 1);

        switch ($kind) {
            case 'root':
                $fields = explode('|', $line, 2);

                if ($parsed['root'] !== '' || $parsed['files'] !== [] || trim($fields[1] ?? '') === '') {
                    return "{$where}: a root record that is repeated, empty or after the first file.";
                }

                $parsed['root'] = $fields[1];

                break;

            case 'file':
                $fields = explode('|', $line);

                if (count($fields) !== 4
                    || $fields[1] === ''
                    || strlen($fields[2]) !== 64
                    || ! ctype_xdigit($fields[2])
                    || ! ctype_digit($fields[3])) {
                    return "{$where}: a file record that is not file|<path>|<sha256>|<lines>: '{$line}'.";
                }

                $parsed['files'][strtolower($fields[1])] = ['hash' => $fields[2], 'lines' => (int) $fields[3]];

                break;

            case 'd':
                $fields = explode('|', $line, 4);

                if (count($fields) !== 4 || ! ctype_digit($fields[2]) || trim($fields[3]) === '') {
                    return "{$where}: a directive record that is not d|<path>|<line>|<text>: '{$line}'.";
                }

                $file = $parsed['files'][strtolower($fields[1])] ?? null;

                if ($file === null || (int) $fields[2] < 1 || (int) $fields[2] > $file['lines']) {
                    return "{$where}: a directive in a file no file record named, or past its last line: '{$line}'.";
                }

                $parsed['directives'][] = ['path' => $fields[1], 'line' => (int) $fields[2], 'text' => trim($fields[3])];

                break;

            case 'x':
                $fields = explode('|', $line);

                if (count($fields) !== 3 || $fields[1] === '' || ! ctype_digit($fields[2])) {
                    return "{$where}: an inert-copy record that is not x|<path>|<line>: '{$line}'.";
                }

                $parsed['inert'][] = ['path' => $fields[1], 'line' => (int) $fields[2]];

                break;

            case 'missing':
                $fields = explode('|', $line, 2);
                $parsed['missing'][] = $fields[1] ?? '';

                break;

            case 's':
                $sMap[] = explode('|', $line, 2)[1] ?? '';

                break;

            default:
                return "{$where} is not a record this judge knows: '{$line}'. A console that wraps a long line "
                    .'splits a record in two, so copy the report through a file or the clipboard, never by '
                    .'selecting console text.';
        }
    }

    if ($end === null) {
        return 'the report has no end line, so it was cut short and cannot be judged.';
    }

    $count = explode('|', $end, 2)[1] ?? '';

    if (! ctype_digit($count) || (int) $count !== $records) {
        return "the end line says {$count} record(s) and the report holds {$records}, so part of it is missing "
            .'or was added.';
    }

    if ($parsed['root'] === '') {
        return 'the report names no Apache root.';
    }

    if (! isset($parsed['files'][strtolower(CONFIG_MAIN_FILE)])) {
        return 'the report holds no '.CONFIG_MAIN_FILE.', the file Apache reads first, so nothing it says can be placed.';
    }

    $map = config_parse_map($sMap, $parsed['root']);

    if (is_string($map)) {
        return $map;
    }

    $parsed['map'] = $map;

    return $parsed;
}

/**
 * Read `httpd -S`'s lines into [port, name, path, line] entries, or say why they cannot be read.
 *
 * Two shapes exist. A port with one vhost prints `*:80 name (file:line)`. A port with several prints
 * `*:443 is a NameVirtualHost`, then `default server name (file:line)` and `port 443 namevhost name (file:line)`.
 *
 * @param  list<string>  $lines
 * @return list<array{port: string, name: string, path: string, line: int}>|string
 */
function config_parse_map(array $lines, string $root): array|string
{
    if ($lines === []) {
        return "the report holds no line of httpd -S, the server's own vhost map, so no vhost can be placed.";
    }

    $entries = [];

    foreach ($lines as $line) {
        if (str_starts_with($line, 'httpd.exe not found')) {
            return "the probe could not run httpd -S ({$line}), so the server's own vhost map is missing.";
        }

        $words = explode(' ', $line);

        if (count($words) === 4 && $words[1] === 'is' && $words[2] === 'a' && $words[3] === 'NameVirtualHost') {
            continue;
        }

        $place = config_parse_place($line, $root);

        if ($place === null) {
            return "a line of httpd -S this judge does not recognise: '{$line}'.";
        }

        if (count($words) >= 3 && $words[0] === 'default' && $words[1] === 'server') {
            continue;
        }

        if (count($words) >= 5 && $words[0] === 'port' && ctype_digit($words[1]) && $words[2] === 'namevhost') {
            $entries[] = ['port' => $words[1], 'name' => config_host($words[3]), 'path' => $place[0], 'line' => $place[1]];

            continue;
        }

        $colon = strrpos($words[0], ':');

        if (count($words) >= 3 && $colon !== false && ctype_digit(substr($words[0], $colon + 1))) {
            $entries[] = ['port' => substr($words[0], $colon + 1), 'name' => config_host($words[1]), 'path' => $place[0], 'line' => $place[1]];

            continue;
        }

        return "a line of httpd -S this judge does not recognise: '{$line}'.";
    }

    return $entries;
}

/**
 * The `(file:line)` that ends a line of `httpd -S`, the file made relative to the Apache root as the probe's
 * own records are.
 *
 * @return array{0: string, 1: int}|null
 */
function config_parse_place(string $line, string $root): ?array
{
    $open = strrpos($line, ' (');

    if ($open === false || ! str_ends_with($line, ')')) {
        return null;
    }

    $inner = substr($line, $open + 2, -1);
    $colon = strrpos($inner, ':');

    if ($colon === false || ! ctype_digit(substr($inner, $colon + 1))) {
        return null;
    }

    return [config_relative(substr($inner, 0, $colon), $root), (int) substr($inner, $colon + 1)];
}

/** A path under the Apache root, relative to it, as the probe prints its own records. */
function config_relative(string $path, string $root): string
{
    $prefix = rtrim($root, '/').'/';

    if (str_starts_with(strtolower($path), strtolower($prefix))) {
        return substr($path, strlen($prefix));
    }

    return $path;
}

/**
 * Place every directive in its sections, and gather the vhosts.
 *
 * @param  array{directives: list<array{path: string, line: int, text: string}>}  $parsed
 * @return array{directives: list<array{path: string, line: int, text: string, name: string, args: string, stack: list<array{tag: string, args: list<string>, path: string, line: int, text: string}>}>, sections: list<array{tag: string, args: list<string>, path: string, line: int, text: string, stack: list<array{tag: string, args: list<string>, path: string, line: int, text: string}>}>, vhosts: list<array{tag: string, args: list<string>, path: string, line: int, text: string}>}|string
 */
function config_model(array $parsed): array|string
{
    $stacks = [];
    $directives = [];
    $sections = [];
    $vhosts = [];

    foreach ($parsed['directives'] as $record) {
        $key = strtolower($record['path']);
        $stack = $stacks[$key] ?? [];
        $text = $record['text'];
        $where = $record['path'].':'.$record['line'];

        if (str_starts_with($text, '</')) {
            $tag = strtolower(trim(substr($text, 2), ' >'));
            $top = array_pop($stack);

            if ($top === null || $top['tag'] !== $tag) {
                return "{$where} closes <{$tag}> where no such section is open, so the sections cannot be placed.";
            }

            $stacks[$key] = $stack;

            continue;
        }

        if (str_starts_with($text, '<')) {
            if (! str_ends_with($text, '>')) {
                return "{$where} opens a section on a line that does not end it: '{$text}'.";
            }

            $tokens = config_tokens(substr($text, 1, -1));
            $section = [
                'tag' => strtolower($tokens[0] ?? ''),
                'args' => array_slice($tokens, 1),
                'path' => $record['path'],
                'line' => $record['line'],
                'text' => $text,
            ];

            if ($section['tag'] === 'virtualhost') {
                foreach ($stack as $outer) {
                    if ($outer['tag'] === 'virtualhost') {
                        return "{$where} opens a vhost inside another, which Apache refuses, so this report is not a live configuration.";
                    }
                }

                $vhosts[] = $section;
            }

            $sections[] = $section + ['stack' => $stack];
            $stack[] = $section;
            $stacks[$key] = $stack;

            continue;
        }

        $tokens = explode(' ', $text, 2);
        $directives[] = $record + [
            'name' => strtolower($tokens[0]),
            'args' => trim($tokens[1] ?? ''),
            'stack' => $stack,
        ];
    }

    foreach ($stacks as $stack) {
        if ($stack !== []) {
            $open = end($stack);

            return "{$open['path']}:{$open['line']} opens <{$open['tag']}> and the file never closes it.";
        }
    }

    return ['directives' => $directives, 'sections' => $sections, 'vhosts' => $vhosts];
}

/**
 * @param  array{missing: list<string>}  $parsed
 * @param  array{directives: list<array{path: string, line: int, text: string, name: string, args: string}>}  $model
 * @param  list<string>  $failures
 * @param  list<string>  $report
 */
function config_judge_includes(array $parsed, array $model, array &$failures, array &$report): void
{
    $includes = 0;

    foreach ($model['directives'] as $directive) {
        if ($directive['name'] !== 'include' && $directive['name'] !== 'includeoptional') {
            continue;
        }

        $includes++;

        if (str_contains($directive['args'], '*') || str_contains($directive['args'], '?')) {
            $failures[] = "{$directive['path']}:{$directive['line']} - an Include names a pattern rather than a file "
                ."('{$directive['text']}'), so a copy dropped beside the site file would go live";
        }
    }

    foreach ($parsed['missing'] as $missing) {
        $failures[] = "an Include names a file that is not there: {$missing}";
    }

    $report[] = "{$includes} Include line(s), each naming one file that exists";
}

/**
 * @param  array{directives: list<array{path: string, line: int, text: string, name: string, args: string, stack: list<array{tag: string, args: list<string>}>}>}  $model
 * @param  list<string>  $failures
 * @param  list<string>  $report
 */
function config_judge_global(array $model, array &$failures, array &$report): void
{
    $last = null;

    foreach ($model['directives'] as $directive) {
        if ($directive['name'] === 'servername'
            && strtolower($directive['path']) === strtolower(CONFIG_MAIN_FILE)
            && config_is_global($directive['stack'])) {
            $last = $directive;
        }
    }

    if ($last === null) {
        $failures[] = CONFIG_MAIN_FILE.' - no global ServerName, so every httpd -t prints AH00558 again (section 8.3)';

        return;
    }

    if (strtolower($last['args']) !== strtolower(CONFIG_GLOBAL_SERVERNAME)) {
        $failures[] = "{$last['path']}:{$last['line']} - the global ServerName is '{$last['args']}', not '"
            .CONFIG_GLOBAL_SERVERNAME."' (section 8.3 says why it must not be the site's name)";

        return;
    }

    $report[] = "global ServerName {$last['args']} ({$last['path']}:{$last['line']})";
}

/**
 * @param  array{directives: list<array{path: string, line: int, text: string, name: string, args: string, stack: list<array{tag: string, args: list<string>}>}>, sections: list<array{tag: string, args: list<string>, path: string, line: int, stack: list<array{tag: string, args: list<string>}>}>}  $model
 * @param  list<string>  $failures
 * @param  list<string>  $report
 */
function config_judge_md(array $model, array &$failures, array &$report): void
{
    $domain = null;

    foreach ($model['sections'] as $section) {
        if ($section['tag'] === 'mdomain' && config_host($section['args'][0] ?? '') === CONFIG_SITE && config_is_global($section['stack'])) {
            $domain = "{$section['path']}:{$section['line']}";
        }
    }

    foreach ($model['directives'] as $directive) {
        if ($directive['name'] === 'mdomain' && config_host(explode(' ', $directive['args'])[0]) === CONFIG_SITE && config_is_global($directive['stack'])) {
            $domain = "{$directive['path']}:{$directive['line']}";
        }
    }

    if ($domain === null) {
        $failures[] = 'no <MDomain '.CONFIG_SITE.'> outside every vhost, so nothing renews the site\'s certificate';
    } else {
        $report[] = '<MDomain '.CONFIG_SITE."> ({$domain})";
    }

    foreach (CONFIG_MD_REQUIRED as $required) {
        $name = strtolower(explode(' ', $required, 2)[0]);
        $found = [];

        foreach ($model['directives'] as $directive) {
            if ($directive['name'] !== $name) {
                continue;
            }

            if (! config_is_global($directive['stack']) && ! config_is_site_mdomain($directive['stack'])) {
                continue;
            }

            $found[] = $directive;
        }

        if ($found === []) {
            $failures[] = "{$required} is ABSENT, outside every vhost and inside the site's <MDomain> alike";

            continue;
        }

        foreach ($found as $directive) {
            if (strtolower($directive['text']) !== strtolower($required)) {
                $failures[] = "{$directive['path']}:{$directive['line']} - '{$directive['text']}' where section 8.3 requires '{$required}'";

                continue 2;
            }
        }

        $first = $found[0];
        $report[] = "{$required} ({$first['path']}:{$first['line']})";
    }

    $forbidden = array_map('strtolower', CONFIG_FORBIDDEN);

    foreach ($model['directives'] as $directive) {
        if (in_array($directive['name'], $forbidden, true)) {
            $failures[] = "{$directive['path']}:{$directive['line']} - FORBIDDEN '{$directive['text']}': a pinned pair is "
                .'served for ever and renewal re-triggers into the duplicate-certificate limit (trap 2)';
        }
    }

    $report[] = 'none of '.implode(', ', CONFIG_FORBIDDEN).' anywhere';
}

/**
 * Judge one vhost. Returns null when it was judged, or the reason it could not be placed.
 *
 * @param  array{headers: list<string>, exact: list<string>, present: list<string>}  $expectation
 * @param  array{map: list<array{port: string, name: string, path: string, line: int}>}  $parsed
 * @param  array{directives: list<array{path: string, line: int, text: string, name: string, args: string, stack: list<array{tag: string, args: list<string>, path: string, line: int, text: string}>}>, vhosts: list<array{tag: string, args: list<string>, path: string, line: int, text: string}>}  $model
 * @param  list<string>  $failures
 * @param  list<string>  $report
 */
function config_judge_vhost(string $address, array $expectation, array $parsed, array $model, array &$failures, array &$report): ?string
{
    $port = substr($address, (int) strrpos($address, ':') + 1);
    $serving = null;

    foreach ($parsed['map'] as $entry) {
        if ($entry['port'] === $port && $entry['name'] === CONFIG_SITE) {
            $serving = $entry;

            break;
        }
    }

    // Every vhost in the files that claims the site's name on this address.
    $claiming = [];

    foreach ($model['vhosts'] as $vhost) {
        if (! in_array(strtolower($address), array_map('strtolower', $vhost['args']), true)) {
            continue;
        }

        foreach (config_in_vhost($model, $vhost) as $directive) {
            if ($directive['name'] === 'servername' && config_host($directive['args']) === CONFIG_SITE) {
                $claiming[] = $vhost;

                break;
            }
        }
    }

    if ($serving === null) {
        if ($claiming === []) {
            $failures[] = "no {$address} vhost answers ".CONFIG_SITE.', in the files or in the server map';

            return null;
        }

        $first = $claiming[0];

        return "{$first['path']}:{$first['line']} is a {$address} vhost for ".CONFIG_SITE.', but httpd -S does not map '
            ."that name on port {$port}, so the report contradicts itself.";
    }

    $vhost = null;

    foreach ($model['vhosts'] as $candidate) {
        if (strtolower($candidate['path']) === strtolower($serving['path']) && $candidate['line'] === $serving['line']) {
            $vhost = $candidate;
        }
    }

    if ($vhost === null) {
        return 'httpd -S serves '.CONFIG_SITE." on port {$port} from {$serving['path']}:{$serving['line']}, a block "
            .'this report does not contain, so what serves the site was never read.';
    }

    if (! in_array(strtolower($address), array_map('strtolower', $vhost['args']), true)) {
        return 'httpd -S serves '.CONFIG_SITE." on port {$port} from {$vhost['path']}:{$vhost['line']}, which is "
            ."'{$vhost['text']}' rather than a {$address} vhost.";
    }

    $at = "{$vhost['path']}:{$vhost['line']}";

    foreach ($claiming as $other) {
        if ($other !== $vhost) {
            $failures[] = "{$other['path']}:{$other['line']} - a second {$address} vhost names ".CONFIG_SITE.'. The '
                ."server answers from {$at}, so this one is dead configuration an edit could land in";
        }
    }

    $inside = config_in_vhost($model, $vhost);
    $wide = array_values(array_filter($inside, static fn (array $directive): bool => config_narrowed_by($directive['stack'], $vhost) === null));

    foreach ($expectation['headers'] as $header) {
        config_judge_header($address, $at, $header, $inside, $vhost, $failures, $report);
    }

    foreach ($expectation['exact'] as $exact) {
        $match = null;

        foreach ($wide as $directive) {
            if (strtolower($directive['text']) === strtolower($exact)) {
                $match = $directive;
            }
        }

        if ($match === null) {
            $failures[] = "{$address} vhost ({$at}) - '{$exact}' is ABSENT";

            continue;
        }

        $report[] = "{$address} vhost ({$at}) - {$exact} (:{$match['line']})";
    }

    foreach ($expectation['present'] as $present) {
        $match = null;

        foreach ($wide as $directive) {
            if ($directive['name'] === strtolower($present) && $directive['args'] !== '') {
                $match = $directive;
            }
        }

        if ($match === null) {
            $failures[] = "{$address} vhost ({$at}) - {$present} is ABSENT, and without the floor pair every request "
                .'is a 503 behind a trust error (trap 3)';

            continue;
        }

        $report[] = "{$address} vhost ({$at}) - {$present} present (:{$match['line']})";
    }

    $report[] = "{$address} vhost for ".CONFIG_SITE." at {$at}, and httpd -S names the same block";

    return null;
}

/**
 * Judge one header in one vhost: one `Header always set` line, vhost-wide, unconditional, whose value meets the
 * policy, and no other `Header` line in the vhost touching the same header.
 *
 * @param  list<array{path: string, line: int, text: string, name: string, args: string, stack: list<array{tag: string, args: list<string>, path: string, line: int, text: string}>}>  $inside
 * @param  array{path: string, line: int}  $vhost
 * @param  list<string>  $failures
 * @param  list<string>  $report
 */
function config_judge_header(string $address, string $at, string $header, array $inside, array $vhost, array &$failures, array &$report): void
{
    $policy = CONFIG_HEADER_POLICY[$header] ?? null;

    if ($policy === null || $policy['require'] === [] || array_intersect($policy['require'], $policy['forbid']) !== []) {
        $failures[] = "the policy for {$header} is missing, requires nothing or contradicts itself, so it judges nothing";

        return;
    }

    $touching = [];

    foreach ($inside as $directive) {
        if ($directive['name'] !== 'header') {
            continue;
        }

        $parts = config_header_parts($directive['args']);

        if (strtolower($parts['header']) === strtolower($header)) {
            $touching[] = $directive + ['parts' => $parts];
        }
    }

    $setting = array_values(array_filter(
        $touching,
        static fn (array $directive): bool => config_narrowed_by($directive['stack'], $vhost) === null,
    ));

    if ($touching === []) {
        $failures[] = "{$address} vhost ({$at}) - {$header} is ABSENT";

        return;
    }

    if ($setting === []) {
        $narrow = config_narrowed_by($touching[0]['stack'], $vhost);
        $failures[] = "{$address} vhost ({$at}) - {$header} is set only inside '{$narrow['text']}' (:{$narrow['line']}), "
            .'which covers part of the vhost';

        return;
    }

    $judged = $setting[0];
    $parts = $judged['parts'];
    $line = ":{$judged['line']}";

    foreach ($touching as $directive) {
        if ($directive !== $judged) {
            $failures[] = "{$address} vhost ({$at}) - a second Header line touches {$header} (:{$directive['line']}): '{$directive['text']}'";
        }
    }

    if ($parts['condition'] !== 'always') {
        $failures[] = "{$address} vhost ({$at}) - {$header} is set without 'always' ({$line}), so a redirect or an error "
            .'response goes out without it';
    }

    if ($parts['action'] !== 'set') {
        $failures[] = "{$address} vhost ({$at}) - {$header} uses '{$parts['action']}' rather than 'set' ({$line})";
    }

    if ($parts['extra'] !== []) {
        $failures[] = "{$address} vhost ({$at}) - {$header} carries a condition ({$line}: ".implode(' ', $parts['extra'])
            .'), so some responses go out without it';
    }

    $present = array_map(
        static fn (string $part): string => strtolower(trim($part)),
        explode($policy['separator'], $parts['value']),
    );

    $missing = array_values(array_diff($policy['require'], $present));
    $forbidden = array_values(array_intersect($policy['forbid'], $present));

    if ($missing !== []) {
        $failures[] = "{$address} vhost ({$at}) - {$header} is '{$parts['value']}' ({$line}), missing ".implode(', ', $missing);
    }

    if ($forbidden !== []) {
        $failures[] = "{$address} vhost ({$at}) - {$header} is '{$parts['value']}' ({$line}), FORBIDDEN directive(s) present: "
            .implode(', ', $forbidden);
    }

    if ($missing === [] && $forbidden === [] && $parts['condition'] === 'always' && $parts['action'] === 'set' && $parts['extra'] === []) {
        $report[] = "{$address} vhost ({$at}) - {$header}: {$parts['value']} ({$line})";
    }
}

/**
 * The parts of a `Header` directive's arguments: `[condition] action header [value] [early|env=..|expr=..]`.
 *
 * @return array{condition: string, action: string, header: string, value: string, extra: list<string>}
 */
function config_header_parts(string $args): array
{
    $tokens = config_tokens($args);
    $condition = 'onsuccess';

    if (in_array(strtolower($tokens[0] ?? ''), ['always', 'onsuccess'], true)) {
        $condition = strtolower((string) array_shift($tokens));
    }

    return [
        'condition' => $condition,
        'action' => strtolower($tokens[0] ?? ''),
        'header' => $tokens[1] ?? '',
        'value' => $tokens[2] ?? '',
        'extra' => array_slice($tokens, 3),
    ];
}

/**
 * Every directive inside one vhost, at any depth.
 *
 * @param  array{directives: list<array{path: string, line: int, text: string, name: string, args: string, stack: list<array{tag: string, args: list<string>, path: string, line: int, text: string}>}>}  $model
 * @param  array{path: string, line: int}  $vhost
 * @return list<array{path: string, line: int, text: string, name: string, args: string, stack: list<array{tag: string, args: list<string>, path: string, line: int, text: string}>}>
 */
function config_in_vhost(array $model, array $vhost): array
{
    return array_values(array_filter($model['directives'], static function (array $directive) use ($vhost): bool {
        foreach ($directive['stack'] as $section) {
            if ($section['tag'] === 'virtualhost' && $section['path'] === $vhost['path'] && $section['line'] === $vhost['line']) {
                return true;
            }
        }

        return false;
    }));
}

/**
 * The first section between a vhost and a directive that narrows what the directive covers, or null.
 *
 * @param  list<array{tag: string, args: list<string>, path: string, line: int, text: string}>  $stack
 * @param  array{path: string, line: int}  $vhost
 * @return array{tag: string, args: list<string>, path: string, line: int, text: string}|null
 */
function config_narrowed_by(array $stack, array $vhost): ?array
{
    $inside = false;

    foreach ($stack as $section) {
        if ($section['tag'] === 'virtualhost' && $section['path'] === $vhost['path'] && $section['line'] === $vhost['line']) {
            $inside = true;

            continue;
        }

        if ($inside && ! config_is_transparent($section)) {
            return $section;
        }
    }

    return null;
}

/**
 * Outside every vhost and `<MDomain>`, and inside nothing that narrows.
 *
 * @param  list<array{tag: string, args: list<string>}>  $stack
 */
function config_is_global(array $stack): bool
{
    foreach ($stack as $section) {
        if (! config_is_transparent($section)) {
            return false;
        }
    }

    return true;
}

/**
 * Directly inside the site's own `<MDomain>`, with nothing narrower around it.
 *
 * @param  list<array{tag: string, args: list<string>}>  $stack
 */
function config_is_site_mdomain(array $stack): bool
{
    $found = false;

    foreach ($stack as $section) {
        if ($section['tag'] === 'mdomain' && config_host($section['args'][0] ?? '') === CONFIG_SITE && ! $found) {
            $found = true;

            continue;
        }

        if (! config_is_transparent($section)) {
            return false;
        }
    }

    return $found;
}

/** @param  array{tag: string, args: list<string>}  $section */
function config_is_transparent(array $section): bool
{
    return in_array($section['tag'], CONFIG_TRANSPARENT_SECTIONS, true)
        && ! str_starts_with($section['args'][0] ?? '', '!');
}

/** A host name as Apache compares it: lower-case, no scheme, no port. */
function config_host(string $value): string
{
    $value = strtolower(trim($value, " \"'"));
    $scheme = strpos($value, '://');

    if ($scheme !== false) {
        $value = substr($value, $scheme + 3);
    }

    $colon = strrpos($value, ':');

    if ($colon !== false && ctype_digit(substr($value, $colon + 1))) {
        $value = substr($value, 0, $colon);
    }

    return $value;
}

/**
 * Split directive arguments as Apache does: on spaces, keeping a quoted run whole and dropping its quotes.
 *
 * Written as a loop rather than a pattern so that no escape sequence has to survive a tool that collapses
 * doubled backslashes.
 *
 * @return list<string>
 */
function config_tokens(string $args): array
{
    $tokens = [];
    $length = strlen($args);
    $escape = chr(92);
    $i = 0;

    while ($i < $length) {
        $char = $args[$i];

        if ($char === ' ' || $char === "\t") {
            $i++;

            continue;
        }

        $token = '';

        if ($char === '"' || $char === "'") {
            $i++;

            while ($i < $length && $args[$i] !== $char) {
                if ($args[$i] === $escape && $i + 1 < $length) {
                    $i++;
                }

                $token .= $args[$i];
                $i++;
            }

            $i++;
            $tokens[] = $token;

            continue;
        }

        while ($i < $length && $args[$i] !== ' ' && $args[$i] !== "\t") {
            $token .= $args[$i];
            $i++;
        }

        $tokens[] = $token;
    }

    return $tokens;
}

/** How many expectations this judge holds, so the floor line shows a policy trimmed to nothing. */
function config_expectation_count(): int
{
    // The Include rule, the global ServerName and the <MDomain>, then the rest by what they list.
    $count = 3 + count(CONFIG_MD_REQUIRED) + count(CONFIG_FORBIDDEN);

    foreach (CONFIG_VHOSTS as $expectation) {
        $count += 1 + count($expectation['headers']) + count($expectation['exact']) + count($expectation['present']);
    }

    return $count;
}

/** Refuse loudly. Exit 2 is never a pass: a judge that could not read the report has judged nothing. */
function config_cannot_measure(string $why): int
{
    fwrite(STDERR, "staging-config-judge: CANNOT MEASURE - {$why}\n");
    fwrite(STDERR, "staging-config-judge: this is exit 2 and NOT a pass. Nothing was judged, so nothing about the\n");
    fwrite(STDERR, "                      box's Apache configuration has been established.\n");

    return CONFIG_EXIT_CANNOT_MEASURE;
}

function config_usage(): string
{
    return "Usage: php scripts/staging-config-judge.php --report=<file>\n\n".
        'Judges a report scripts/staging-config-probe.ps1 printed on the testing box for '.CONFIG_SITE.". It does\n".
        "NOT read the box: the probe runs there, by hand, after any Apache edit.\n\n".
        "  --report=<file>   the probe's output, saved as it came (text before its signature and after its\n".
        "                    end line is ignored)\n\n".
        "  0  judged, and every expectation holds\n".
        "  1  an expectation does not hold - the box and section 8.3 disagree\n".
        "  2  the report was not recognised, so NOTHING was judged\n\n".
        "See the block at the top of this file for why recognition is positive and why it rules by vhost.\n";
}
