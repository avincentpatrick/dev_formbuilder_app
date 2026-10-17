<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The row census around a deploy's migration — M176 (`R-a11cc78a`, `D144`).
 *
 * `--write=<file>`: counts the rows of every base table in `public` and writes them, once the site is down and before
 * anything changes. `--compare=<file>`: counts again after `migrate` and FAILS, naming each table and both counts, when
 * a table holds fewer rows than before or is gone — `deploy.ps1` then leaves the site down, so no tester writes on top of
 * lost data before a restore is decided (`D144`). Growth is fine. The user (2026-10-16): *"always make sure that the
 * staging has data, do not remove data"*.
 *
 * EVERY base table, not a list: a table added next month is counted without anyone remembering to add it. Only the
 * tables that empty themselves by design are passed over (`config('deploy.census_exempt')`, each with its reason).
 * Counted through the deploy's privileged connection — a superuser, so row security hides nothing.
 */
final class DeployCensusCommand extends Command
{
    protected $signature = 'deploy:census
                            {--write= : Count every table and write the counts to this file}
                            {--compare= : Count again and fail if any table holds fewer rows than this file says}';

    protected $description = "Count every table's rows before a deploy's migration, and prove none shrank after it";

    public function handle(): int
    {
        $write = $this->option('write');
        $compare = $this->option('compare');

        if (is_string($write) === is_string($compare)) {
            $this->error('Give exactly one of --write=<file> or --compare=<file>.');

            return self::FAILURE;
        }

        return is_string($write) ? $this->write($write) : $this->compare((string) $compare);
    }

    private function write(string $file): int
    {
        $tables = $this->count();
        if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0775, true) && ! is_dir(dirname($file))) {
            $this->error("The census cannot be written: '{$file}' has no folder that can be made.");

            return self::FAILURE;
        }
        if (@file_put_contents($file, json_encode(['taken_at' => now()->toIso8601String(), 'tables' => $tables], JSON_PRETTY_PRINT)) === false) {
            $this->error("The census cannot be written to '{$file}'.");

            return self::FAILURE;
        }

        $this->info(sprintf('Counted %d tables, %d rows, into %s.', count($tables), array_sum($tables), $file));

        return self::SUCCESS;
    }

    private function compare(string $file): int
    {
        $before = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($before) || ! is_array($before['tables'] ?? null)) {
            $this->error("The census taken before the migration cannot be read ('{$file}'), so nothing proves the data survived.");

            return self::FAILURE;
        }

        $now = $this->count();
        $exempt = (array) config('deploy.census_exempt', []);
        $lost = [];
        foreach ($before['tables'] as $table => $count) {
            if (in_array($table, $exempt, true)) {
                continue;
            }
            if (! array_key_exists($table, $now)) {
                $lost[] = sprintf('%s: %d rows before, and the table is gone', $table, (int) $count);
            } elseif ($now[$table] < (int) $count) {
                $lost[] = sprintf('%s: %d rows before, %d after', $table, (int) $count, $now[$table]);
            }
        }

        if ($lost !== []) {
            $this->error('THE DATA DID NOT SURVIVE THE MIGRATION. These tables hold fewer rows than before it:');
            foreach ($lost as $line) {
                $this->line("  - {$line}");
            }
            $this->error('Leave the site down and restore from the deploy\'s backup (docs/deployment-infrastructure.md).');

            return self::FAILURE;
        }

        $this->info(sprintf('Every table holds at least as many rows as before the migration (%d tables).', count($now)));

        return self::SUCCESS;
    }

    /** @return array<string, int> every base table in `public`, with its row count */
    private function count(): array
    {
        $db = DB::connection((string) config('deploy.connection', 'pgsql_privileged'));
        $tables = [];
        foreach ($db->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $row) {
            $name = (string) $row->tablename;
            $tables[$name] = (int) $db->table($name)->count();
        }

        return $tables;
    }
}
