<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M175 (`R-78f43f26`, `D139`) — scripts/feedback-pull.php, driven end to end over real HTTP.
|--------------------------------------------------------------------------
| The development session runs this at every start (CLAUDE.md "Start every session") to read the testers'
| feedback and push status changes back. It is host PHP with no `vendor/`, so it is tested the way the other
| scripts are — a Pest file that runs it as a process — against `tests/fixtures/feedback-pull/router.php`
| served by `php -S` on the loopback. The token is generated per run, never committed.
|
| Three things carry the weight:
|  · WHAT IT SENDS ON ITS OWN IS ONLY WHAT `D139` ALLOWS: Ongoing with no note, and Resolved with the stock
|    "Fixed in version X, live on staging." Any other reply, and every Declined, needs `--user-approved`;
|    without it nothing is sent at all.
|  · A FAILURE SAYS WHICH ONE, by exit code — no config (3), refused (4), unreachable (5), approval needed (6),
|    a step the lifecycle refuses (7) — so the session and `preflight` can tell them apart.
|  · THE SECRET STAYS IN ITS FILE: the token is read from a file outside the repository and never printed.
*/

const FEEDBACK_PULL_OK = 0;

const FEEDBACK_PULL_NO_CONFIG = 3;

const FEEDBACK_PULL_REFUSED = 4;

const FEEDBACK_PULL_UNREACHABLE = 5;

const FEEDBACK_PULL_NEEDS_APPROVAL = 6;

const FEEDBACK_PULL_INVALID_TRANSITION = 7;

/** A free loopback port, by asking the kernel for one. */
function feedbackPullFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

beforeEach(function (): void {
    $this->scratch = sys_get_temp_dir().'/feedback-pull-'.Str::random(8);
    mkdir($this->scratch, 0777, true);
    $this->token = 'msp_'.Str::random(48);
    $this->log = $this->scratch.'/patches.log';
    $this->port = feedbackPullFreePort();

    $this->server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, base_path('tests/fixtures/feedback-pull/router.php')],
        [0 => ['pipe', 'r'], 1 => ['file', $this->scratch.'/server.out', 'a'], 2 => ['file', $this->scratch.'/server.err', 'a']],
        $pipes,
        null,
        ['FEEDBACK_PULL_FIXTURE_TOKEN' => $this->token, 'FEEDBACK_PULL_FIXTURE_LOG' => $this->log] + getenv(),
    );

    // Wait for the server to accept, rather than for a fixed time.
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $probe = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
        if ($probe !== false) {
            fclose($probe);
            break;
        }
        usleep(50_000);
    }

    $this->config = $this->scratch.'/feedback-pull.json';
    file_put_contents($this->config, json_encode(['url' => 'http://127.0.0.1:'.$this->port, 'token' => $this->token]));
});

afterEach(function (): void {
    if (is_resource($this->server)) {
        proc_terminate($this->server);
        proc_close($this->server);
    }
});

/**
 * Run the script and return [exit code, combined output].
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function feedbackPull(array $arguments): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/feedback-pull.php'));
    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $output = [];
    $status = 0;
    exec($command.' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

/** @return list<array{id: string, body: array<string, mixed>}> every PATCH the fixture received */
function feedbackPullPatches(string $log): array
{
    if (! is_file($log)) {
        return [];
    }

    return array_values(array_map(
        fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_filter(explode("\n", (string) file_get_contents($log))),
    ));
}

it('says where it looked when there is no token file, and exits 3', function (): void {
    [$status, $output] = feedbackPull(['--config='.$this->scratch.'/missing.json', '--count']);

    expect($status)->toBe(FEEDBACK_PULL_NO_CONFIG)
        ->and($output)->toContain('missing.json');
});

it('downloads New and Ongoing reports with their screenshots, and prints a digest without the token', function (): void {
    $out = $this->scratch.'/pull';

    [$status, $output] = feedbackPull(['--config='.$this->config, '--out='.$out]);

    expect($status)->toBe(FEEDBACK_PULL_OK)
        ->and($output)->toContain('0192e2e0')
        ->and($output)->toContain('Bug')
        ->and($output)->toContain('Suggestion')
        ->and($output)->toContain('Builder — Household survey')
        ->and($output)->toContain('tess@staging.example.test')
        ->and($output)->toContain('Dragging a question does nothing.')
        ->and($output)->not->toContain($this->token)
        ->and(file_get_contents($out.'/0192e2e0-0000-7000-8000-000000000001.png'))->toBe('FIXTURE-PNG-BYTES')
        ->and(is_file($out.'/0192e2e0-0000-7000-8000-000000000002.png'))->toBeFalse()
        ->and(json_decode((string) file_get_contents($out.'/feedback.json'), true))->toHaveCount(2);
});

it('counts New reports in one line, for preflight', function (): void {
    [$status, $output] = feedbackPull(['--config='.$this->config, '--count']);

    expect($status)->toBe(FEEDBACK_PULL_OK)
        ->and(trim($output))->toBe('new=3');
});

it('tells a refused token from an unreachable server, by exit code', function (): void {
    file_put_contents($this->config, json_encode(['url' => 'http://127.0.0.1:'.$this->port, 'token' => 'msp_'.Str::random(48)]));
    [$refused] = feedbackPull(['--config='.$this->config, '--count']);

    file_put_contents($this->config, json_encode(['url' => 'http://127.0.0.1:'.feedbackPullFreePort(), 'token' => $this->token]));
    [$unreachable] = feedbackPull(['--config='.$this->config, '--count']);

    expect($refused)->toBe(FEEDBACK_PULL_REFUSED)
        ->and($unreachable)->toBe(FEEDBACK_PULL_UNREACHABLE);
});

it('marks a report Ongoing with no note, and Resolved with the stock fixed note', function (): void {
    $id = '0192e2e0-0000-7000-8000-000000000001';

    expect(feedbackPull(['--config='.$this->config, '--ongoing='.$id])[0])->toBe(FEEDBACK_PULL_OK)
        ->and(feedbackPull(['--config='.$this->config, '--resolve='.$id, '--version=abc1234'])[0])->toBe(FEEDBACK_PULL_OK);

    $patches = feedbackPullPatches($this->log);
    expect($patches)->toHaveCount(2)
        ->and($patches[0]['body'])->toBe(['status' => 'reviewed'])
        ->and($patches[1]['body'])->toBe(['status' => 'resolved', 'note' => 'Fixed in version abc1234, live on staging.']);
});

it('sends a reply of its own or a decline only with the user\'s approval, and nothing without it', function (): void {
    $id = '0192e2e0-0000-7000-8000-000000000002';

    [$custom] = feedbackPull(['--config='.$this->config, '--resolve='.$id, '--note=We changed the order.']);
    [$decline] = feedbackPull(['--config='.$this->config, '--decline='.$id, '--note=Out of scope for the pilot.']);
    expect($custom)->toBe(FEEDBACK_PULL_NEEDS_APPROVAL)
        ->and($decline)->toBe(FEEDBACK_PULL_NEEDS_APPROVAL)
        ->and(feedbackPullPatches($this->log))->toBe([]);

    [$approved] = feedbackPull(['--config='.$this->config, '--decline='.$id, '--note=Out of scope for the pilot.', '--user-approved']);
    expect($approved)->toBe(FEEDBACK_PULL_OK)
        ->and(feedbackPullPatches($this->log)[0]['body'])->toBe(['status' => 'wont_fix', 'note' => 'Out of scope for the pilot.']);
});

it('says when the lifecycle refuses a step, and exits 7', function (): void {
    [$status, $output] = feedbackPull(['--config='.$this->config, '--ongoing=0192e2e0-0000-7000-8000-00000000refuse']);

    expect($status)->toBe(FEEDBACK_PULL_INVALID_TRANSITION)
        ->and($output)->toContain('cannot go from Resolved to Declined');
});
