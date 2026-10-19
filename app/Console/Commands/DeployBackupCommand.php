<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * The deploy's own backup — M176 (`R-a11cc78a`, `D137`, `D143`).
 *
 * `deploy.ps1` runs this once the site is down and before it replaces anything; if it fails, the deploy brings the site
 * back up on the old code and refuses to migrate. A deploy never again depends on someone remembering the backup block.
 *
 * `pg_dump --format=custom` with the deploy's privileged connection (a superuser, as `migrate` runs, so row security hides
 * nothing). ⛔ THE PASSWORD GOES IN THE CHILD'S ENVIRONMENT ONLY (`PGPASSWORD`), never on its command line, where any
 * process listing on the box would show it. Refused: a non-zero exit, and a file missing or too small to be a dump (a
 * failed run can leave an empty one). Keeps the newest `config('deploy.keep')` of its OWN dumps — `deploy-*.dump`, never a
 * dump someone put there by hand.
 *
 * ⚠️ UNPROVEN ON THE BOX UNTIL MERGE POINT 2 (`D143`): the runner is NETWORK SERVICE, and whether it can run `pg_dump` there
 * was never measured — which is why a failure here costs nothing but the deploy.
 */
final class DeployBackupCommand extends Command
{
    protected $signature = 'deploy:backup {--dir= : Where the dump goes (default: config deploy.backup_dir)}';

    protected $description = "Back up the database before a deploy's migration, or fail so the deploy refuses to migrate";

    public function handle(): int
    {
        $dir = (string) ($this->option('dir') ?: config('deploy.backup_dir'));
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("No backup was taken: the folder '{$dir}' does not exist and cannot be made.");

            return self::FAILURE;
        }

        /** @var array<string, mixed> $db */
        $db = (array) config('database.connections.'.config('deploy.connection', 'pgsql_privileged'));
        $file = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'deploy-'.now()->utc()->format('Ymd-His').'.dump';

        $process = new Process(
            [
                $this->pgDump(),
                '--format=custom',
                '--file='.$file,
                '--host='.(string) ($db['host'] ?? '127.0.0.1'),
                '--port='.(string) ($db['port'] ?? '5432'),
                '--username='.(string) ($db['username'] ?? ''),
                '--dbname='.(string) ($db['database'] ?? ''),
            ],
            null,
            ['PGPASSWORD' => (string) ($db['password'] ?? ''), 'PGSSLMODE' => (string) ($db['sslmode'] ?? 'prefer')],
            null,
            600,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($file);
            $this->error('No backup was taken: pg_dump failed (exit '.$process->getExitCode().'). '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $bytes = is_file($file) ? (int) filesize($file) : 0;
        if ($bytes < (int) config('deploy.min_bytes', 1024)) {
            @unlink($file);
            $this->error("No backup was taken: pg_dump left a file too small to be a dump ({$bytes} bytes).");

            return self::FAILURE;
        }

        $this->keepNewest($dir, max(1, (int) config('deploy.keep', 10)));
        $this->info("Backed up to {$file} ({$bytes} bytes).");

        return self::SUCCESS;
    }

    /** The configured `pg_dump`, else the newest PostgreSQL install's on Windows, else `pg_dump` on PATH. */
    private function pgDump(): string
    {
        $configured = config('deploy.pg_dump');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $installed = glob('C:/Program Files/PostgreSQL/*/bin/pg_dump.exe') ?: [];
        natsort($installed);

        return $installed === [] ? 'pg_dump' : (string) end($installed);
    }

    /** Remove this command's older dumps beyond the newest `$keep`; anything else in the folder is left alone. */
    private function keepNewest(string $dir, int $keep): void
    {
        $dumps = glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'deploy-*.dump') ?: [];
        usort($dumps, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
        foreach (array_slice($dumps, $keep) as $old) {
            @unlink($old);
        }
    }
}
