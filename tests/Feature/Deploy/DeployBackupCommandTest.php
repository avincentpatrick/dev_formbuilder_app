<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M176 (`R-a11cc78a`, `D143`) — a deploy takes its own backup, and refuses to migrate without one.
|--------------------------------------------------------------------------
| `deploy:backup` runs `pg_dump` with the privileged connection's credentials — the password in the child's
| ENVIRONMENT only, never on its command line, where any process listing on the box would show it — and fails
| when `pg_dump` fails or leaves no real file. `deploy.ps1` then brings the site back up on the old code and
| stops. A fake `pg_dump` stands in here (the app container ships none, and the real one is the box's to prove at
| merge point 2): a shell script that records what it was given and writes, or fails, as each case asks.
*/

function fakePgDump(string $dir, int $exit = 0, int $bytes = 4096): string
{
    $script = $dir.'/fake-pg-dump';
    file_put_contents($script, <<<SH
        #!/bin/sh
        printf '%s\\n' "\$@" > "{$dir}/args.txt"
        printf '%s' "\$PGPASSWORD" > "{$dir}/password.txt"
        for arg in "\$@"; do
            case "\$arg" in --file=*) out="\${arg#--file=}" ;; esac
        done
        if [ "{$exit}" != "0" ]; then echo "pg_dump: error: connection refused" >&2; exit {$exit}; fi
        head -c {$bytes} /dev/zero > "\$out"
        SH);
    chmod($script, 0755);

    return $script;
}

beforeEach(function (): void {
    $this->dir = storage_path('framework/testing/backup-'.Str::random(8));
    mkdir($this->dir.'/dumps', 0777, true);
    config()->set('deploy.backup_dir', $this->dir.'/dumps');
    config()->set('database.connections.pgsql_privileged.password', 'not-on-the-command-line');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('writes a custom-format dump through pg_dump, the password in its environment and never in its arguments', function (): void {
    config()->set('deploy.pg_dump', fakePgDump($this->dir));

    $this->artisan('deploy:backup')->expectsOutputToContain('Backed up to')->assertSuccessful();

    $dumps = glob($this->dir.'/dumps/deploy-*.dump') ?: [];
    expect($dumps)->toHaveCount(1);
    expect(filesize($dumps[0]))->toBe(4096);
    $args = (string) file_get_contents($this->dir.'/args.txt');
    expect($args)->toContain('--format=custom')
        ->and($args)->toContain('--dbname='.config('database.connections.pgsql_privileged.database'))
        ->and($args)->toContain('--username='.config('database.connections.pgsql_privileged.username'))
        ->and(str_contains($args, 'not-on-the-command-line'))->toBeFalse();
    expect(file_get_contents($this->dir.'/password.txt'))->toBe('not-on-the-command-line');
});

it('refuses when pg_dump fails, and says why', function (): void {
    config()->set('deploy.pg_dump', fakePgDump($this->dir, exit: 1));

    $this->artisan('deploy:backup')->expectsOutputToContain('connection refused')->assertFailed();
});

it('refuses a dump too small to be one, and leaves no half-file behind', function (): void {
    config()->set('deploy.pg_dump', fakePgDump($this->dir, bytes: 10));

    $this->artisan('deploy:backup')->expectsOutputToContain('too small')->assertFailed();
    expect(glob($this->dir.'/dumps/deploy-*.dump') ?: [])->toBe([]);
});

it('keeps the newest backups and removes only its own older ones', function (): void {
    config()->set('deploy.pg_dump', fakePgDump($this->dir));
    config()->set('deploy.keep', 3);
    foreach (range(1, 4) as $i) {
        touch($this->dir."/dumps/deploy-2026010{$i}-000000.dump", strtotime("2026-01-0{$i}"));
    }
    file_put_contents($this->dir.'/dumps/staging-by-hand.dump', 'kept');

    $this->artisan('deploy:backup')->assertSuccessful();

    $left = array_map('basename', glob($this->dir.'/dumps/*.dump') ?: []);
    sort($left);
    expect($left)->toHaveCount(4)
        ->and($left)->toContain('staging-by-hand.dump')
        ->and($left)->toContain('deploy-20260104-000000.dump')
        ->and(in_array('deploy-20260101-000000.dump', $left, true))->toBeFalse();
});
