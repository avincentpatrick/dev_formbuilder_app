<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Controls for `scripts/staging-config-judge.php` and `scripts/staging-config-probe.ps1` (M251, R-a3918bf4).
|--------------------------------------------------------------------------
| The testing box's Apache configuration lives in no tracked file. `docs/deployment-infrastructure.md` section
| 8.3 describes it, and until M251 nothing checked that description: the crawl header on both vhosts, D54's HSTS,
| the three certificate traps, the <MDomain>, the port-80 redirect and the global ServerName. The probe prints
| what the box has; the judge rules on that print; this file is the judge's floor, for the same reason
| `StagingHeadersJudgeTest.php` is the headers judge's (`scripts/mutate.php` drives Pest and nothing else).
|
| ⚠️ `measured.txt` IS THE REAL REPORT, copied out of Notepad on the box on 2026-11-25, right after M251 added
| the port-80 `X-Robots-Tag` line. Its three `check|` lines came from that one-off change block, not from the
| probe, and they stay: they are the proof that text ahead of the signature is ignored. `v1.txt` is the first
| real report (2026-11-24), console prompt included. Every other fixture is `measured.txt` with ONE line-level
| edit, and each BLOCKED fixture is asserted to fail exactly one expectation, so none can pass for a reason
| other than the one its name gives. `MDContactEmail` is redacted by the probe itself, because this repository is
| public (`D48`); the last case below keeps it that way.
|
| ⚠️ Helpers are prefixed `stagingConfig*`: Pest loads every file in this directory into one process, so a
| same-named file-scope helper is a fatal redeclaration (`stagingHeaders*`, `npmAudit*` and others are taken).
*/

const STAGING_CONFIG_JUDGE_OK = 0;

const STAGING_CONFIG_JUDGE_BLOCKED = 1;

const STAGING_CONFIG_JUDGE_CANNOT_MEASURE = 2;

/**
 * Run the judge over one argument list and return [exitCode, combined output].
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function stagingConfigRun(array $arguments): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/staging-config-judge.php'));

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $output = [];
    $status = 0;
    exec($command.' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

/**
 * Judge one committed fixture.
 *
 * @return array{0: int, 1: string}
 */
function stagingConfigJudge(string $scenario): array
{
    return stagingConfigRun(['--report='.stagingConfigFixture($scenario)]);
}

function stagingConfigFixture(string $scenario): string
{
    return base_path('tests/fixtures/staging-config/'.$scenario.'.txt');
}

/**
 * Judge bytes that are not a committed fixture: a re-encoding of `measured.txt`, written to a temporary file.
 *
 * @return array{0: int, 1: string}
 */
function stagingConfigJudgeBytes(string $bytes): array
{
    $path = (string) tempnam(sys_get_temp_dir(), 'staging-config-');

    try {
        file_put_contents($path, $bytes);

        return stagingConfigRun(['--report='.$path]);
    } finally {
        @unlink($path);
    }
}

/** The probe's code with its comment lines removed, so the header's own warnings are not read as code. */
function stagingConfigProbeCode(): string
{
    $lines = explode("\n", (string) file_get_contents(base_path('scripts/staging-config-probe.ps1')));

    return implode("\n", array_filter($lines, static fn (string $line): bool => ! str_starts_with(ltrim($line), '#')));
}

it('judges the real report from the box as holding, and reports a floor', function (): void {
    [$status, $output] = stagingConfigJudge('measured');

    expect($status)->toBe(STAGING_CONFIG_JUDGE_OK, $output);
    expect($output)->toContain('JUDGED - every expectation holds.');

    // ⛔ THE FLOOR IS THE HALF THAT MATTERS. A judge that read one file and found nothing to object to would
    // also say "holds". Sixteen expectations is the policy's size; a constant trimmed to nothing moves it.
    expect($output)->toContain('5 file(s) read, 25 directive(s), 22 section(s), 2 vhost(s) in the files, 2 name(s) in the server map, 16 expectation(s) - site iconnect.com.de');

    // R-5e9f620f: the port-80 redirect carries the crawl header, and the judge names the block it read.
    expect($output)->toContain('*:80 vhost (conf/extra/httpd-vhosts.conf:47) - X-Robots-Tag: noindex, nofollow, noarchive (:51)');
    expect($output)->toContain('*:80 vhost for iconnect.com.de at conf/extra/httpd-vhosts.conf:47, and httpd -S names the same block');
    expect($output)->toContain('*:443 vhost (conf/extra/meridian.conf:13) - Strict-Transport-Security: max-age=300 (:23)');

    // The three backups that also carry the line are reported as inert, never counted as protection.
    expect($output)->toContain('(inert, not counted: X-Robots-Tag in files no Include names - conf/extra/meridian.conf.bak-20260920-182015:22');
});

it('blocks on one drifted expectation and names it', function (string $scenario, string $finding): void {
    [$status, $output] = stagingConfigJudge($scenario);

    expect($status)->toBe(STAGING_CONFIG_JUDGE_BLOCKED, $output);
    expect($output)->toContain('BLOCKED');
    expect($output)->toContain($finding);

    // Exactly one failure line: the fixture changes one line, so a second finding means the judge is reading
    // something other than what the scenario's name says.
    expect(substr_count($output, "\n  - "))->toBe(1, $output);
})->with([
    // R-5e9f620f: the line M251 added on the box, and the two ways it can stop covering the 301.
    'port 80 loses its crawl header' => ['port80-header-missing', '*:80 vhost (conf/extra/httpd-vhosts.conf:47) - X-Robots-Tag is ABSENT'],
    'port 80 header without always' => ['port80-without-always', "X-Robots-Tag is set without 'always' (:51), so a redirect or an error response goes out without it"],
    'port 80 redirects elsewhere' => ['redirect-elsewhere', "'Redirect permanent / https://iconnect.com.de/' is ABSENT"],

    // ⛔ SCOPE. A header inside a <Location> guards one path, and the same line read without its section is a pass.
    'header only inside a Location' => ['header-in-location', "X-Robots-Tag is set only inside '<Location \"/bhw-connect\">' (:37), which covers part of the vhost"],
    'header inside a negated IfModule' => ['header-in-negated-ifmodule', "X-Robots-Tag is set only inside '<IfModule !headers_module>' (:51)"],

    // The values the nightly headers judge also holds, read here from the file rather than the response.
    'crawl header weakened' => ['robots-weakened', "X-Robots-Tag is 'noindex, nofollow' (:22), missing noarchive"],
    'HSTS widened against D54' => ['hsts-forbidden', 'FORBIDDEN directive(s) present: includesubdomains, preload'],
    'a second Header line unsets it' => ['second-header-line', "a second Header line touches X-Robots-Tag (:24): 'Header unset X-Robots-Tag'"],

    // Section 8.3's three certificate traps, one each.
    'trap 1: an RSA key' => ['md-private-keys', "'MDPrivateKeys RSA 2048' where section 8.3 requires 'MDPrivateKeys secp256r1'"],
    'trap 2: a pinned certificate' => ['md-certificate-file', "conf/extra/meridian.conf:9 - FORBIDDEN 'MDCertificateFile"],
    'trap 3: the floor key dropped' => ['ssl-key-missing', 'SSLCertificateKeyFile is ABSENT, and without the floor pair every request is a 503 behind a trust error (trap 3)'],

    'no MDomain for the site' => ['mdomain-elsewhere', 'no <MDomain iconnect.com.de> outside every vhost'],
    'the global ServerName is the site' => ['global-servername', "the global ServerName is 'iconnect.com.de', not 'localhost'"],
    'an Include names a pattern' => ['include-pattern', "an Include names a pattern rather than a file ('Include conf/extra/*.conf')"],
    'an Include names a missing file' => ['include-missing', 'an Include names a file that is not there: conf/extra/gone.conf'],

    // D216 left exactly one *:443 block for the site. A second one is dead configuration an edit could land in.
    'a second vhost claims the site' => ['second-vhost', 'conf/extra/httpd-ssl.conf:120 - a second *:443 vhost names iconnect.com.de'],
]);

it('reads a header inside a plain IfModule as covering the whole vhost', function (): void {
    // The other half of the scope rule. A judge that treated every section as narrowing would refuse the most
    // common way Apache configuration is written, and a gate that is red on correct configuration is re-run
    // until someone stops reading it.
    [$status, $output] = stagingConfigJudge('header-in-ifmodule');

    expect($status)->toBe(STAGING_CONFIG_JUDGE_OK, $output);
    expect($output)->toContain('*:80 vhost (conf/extra/httpd-vhosts.conf:47) - X-Robots-Tag: noindex, nofollow, noarchive (:52)');
});

it('refuses a report it cannot recognise as CANNOT MEASURE, never as a verdict', function (string $scenario, string $reason): void {
    [$status, $output] = stagingConfigJudge($scenario);

    expect($status)->toBe(STAGING_CONFIG_JUDGE_CANNOT_MEASURE, $output);
    expect($status)->not->toBe(STAGING_CONFIG_JUDGE_OK, 'nothing measured is never clean');
    expect($output)->toContain('CANNOT MEASURE');
    expect($output)->toContain($reason);
})->with([
    'something else entirely' => ['foreign', 'no probe signature (meridian-config-probe|v2) in the report'],
    // The first real report. It printed no section lines, so it cannot be judged by scope.
    'the v1 probe' => ['v1', 'this is a report from another version of the probe (v1)'],
    'a paste cut short' => ['truncated', 'the report has no end line, so it was cut short'],
    'a record lost in the middle' => ['count-wrong', 'the end line says 81 record(s) and the report holds 80'],
    'a console that wrapped a line' => ['wrapped', "is not a record this judge knows: 'rts/iconnect.com.de-chain.pem\"'"],
    'the map points at a block not read' => ['map-elsewhere', 'from conf/extra/meridian.conf:70, a block this report does not contain'],
    'a section never closed' => ['unclosed', 'conf/extra/meridian.conf:13 opens <virtualhost> and the file never closes it'],
    'no server map at all' => ['no-map', "the report holds no line of httpd -S, the server's own vhost map"],
]);

it('reads the report however Windows saved it', function (string $encoding): void {
    // `Out-File -Encoding utf8` writes a byte-order mark, Windows PowerShell's `>` writes UTF-16, and a paste
    // from the console carries the prompt after the end line. None of them is a different report.
    $measured = (string) file_get_contents(stagingConfigFixture('measured'));

    $bytes = match ($encoding) {
        'utf8-bom' => chr(0xEF).chr(0xBB).chr(0xBF).$measured,
        'utf16le' => chr(0xFF).chr(0xFE).mb_convert_encoding(str_replace("\n", "\r\n", $measured), 'UTF-16LE', 'UTF-8'),
        'console-prompt' => $measured.'PS C:'.chr(92).'Users'.chr(92).'Administrator> '."\n",
    };

    [$status, $output] = stagingConfigJudgeBytes($bytes);

    expect($status)->toBe(STAGING_CONFIG_JUDGE_OK, $output);
    expect($output)->toContain('16 expectation(s)');
})->with(['utf8-bom', 'utf16le', 'console-prompt']);

it('refuses a missing report and an unrecognised option rather than reading either as clean', function (): void {
    [$status, $output] = stagingConfigRun(['--report='.stagingConfigFixture('no-such-report')]);
    expect($status)->toBe(STAGING_CONFIG_JUDGE_CANNOT_MEASURE, $output);
    expect($output)->toContain('the report is not a file');

    [$status, $output] = stagingConfigRun(['--report='.stagingConfigFixture('measured'), '--bogus']);
    expect($status)->toBe(STAGING_CONFIG_JUDGE_CANNOT_MEASURE, $output);
    expect($output)->toContain("unrecognised option '--bogus'");

    [$status, $output] = stagingConfigRun([]);
    expect($status)->toBe(STAGING_CONFIG_JUDGE_CANNOT_MEASURE, $output);
    expect($output)->toContain('--report=<file> is required');
});

it('keeps the probe read-only', function (): void {
    // The probe is pasted into an administrator console on the box that serves the testers. It must be safe to
    // run at any hour, so it may read, hash and ask `httpd -S` for the map, and nothing else.
    $code = stagingConfigProbeCode();

    expect(preg_match_all('/\b(?:Set|Remove|Restart|Stop|Start|New|Out|Add|Copy|Move|Rename|Clear|Install|Uninstall|Invoke|Export|Write)-[A-Za-z]/i', $code, $verbs))
        ->toBe(0, 'a cmdlet that changes something: '.implode(', ', $verbs[0]));
    expect(preg_match_all('/\]::(?:Write|Append|Delete|Move|Copy|Create|Replace)/i', $code, $writes))
        ->toBe(0, 'a .NET call that changes something: '.implode(', ', $writes[0]));
    expect(preg_match('/\s>>?\s*[$A-Za-z"\']/', $code))->toBe(0, 'output redirected into a file');

    // `httpd -S` parses the configuration without starting anything. `-k` would restart the server.
    preg_match_all('/& \$httpd (\S+)/', $code, $calls);
    expect($calls[1])->toBe(['-S']);
});

it('keeps the probe pasteable into Windows PowerShell 5.1', function (): void {
    // Windows PowerShell reads a file with no byte-order mark as the ANSI code page, so one non-ASCII character
    // (a dash, an arrow, a quote mark) turns into mojibake on the box and can end a string early.
    expect(mb_check_encoding((string) file_get_contents(base_path('scripts/staging-config-probe.ps1')), 'ASCII'))->toBeTrue();
});

it('keeps the probe and the judge on the same signature', function (): void {
    // A probe bumped to a new version with the judge left behind reports CANNOT MEASURE on the box, every time.
    preg_match("/const CONFIG_SIGNATURE = '([^']+)';/", (string) file_get_contents(base_path('scripts/staging-config-judge.php')), $match);

    expect($match[1] ?? null)->toBe('meridian-config-probe|v2|');
    expect(stagingConfigProbeCode())->toContain("\$records.Add('".$match[1]."' + [DateTime]::UtcNow");
});

it('keeps the contact address out of every report', function (): void {
    // The fixtures are committed to a public repository (D48), and the address is the operator's own.
    expect(stagingConfigProbeCode())->toContain("if (\$key -eq 'mdcontactemail') { \$text = 'MDContactEmail <redacted>' }");

    foreach (glob(base_path('tests/fixtures/staging-config/*.txt')) ?: [] as $fixture) {
        expect((string) file_get_contents($fixture))->not->toContain('@', basename($fixture));
    }
});
