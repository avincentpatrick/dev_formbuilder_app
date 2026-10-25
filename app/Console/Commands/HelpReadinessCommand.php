<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Forms\FieldReadiness;
use Illuminate\Console\Command;

/**
 * M187 (`R-3e6358ed`) — writes Help's table of what each question type can do on each surface, from the rules that
 * decide it ({@see FieldReadiness}). Run it after changing any of those rules; `FieldReadinessTableTest` fails until
 * the committed file matches. `--check` writes nothing and exits 1 when the committed file is behind.
 */
final class HelpReadinessCommand extends Command
{
    protected $signature = 'help:readiness
                            {--check : Exit 1 if the committed table differs from the rules, writing nothing}';

    protected $description = 'Write the Help page\'s table of what each question type can do, from the rules that decide it';

    public function handle(FieldReadiness $readiness): int
    {
        $path = base_path(FieldReadiness::PATH);
        $expected = $readiness->json();
        $committed = is_file($path) ? file_get_contents($path) : false;

        if ($this->option('check')) {
            if ($committed === $expected) {
                $this->info('The readiness table matches the rules.');

                return self::SUCCESS;
            }

            $this->error('The readiness table is behind the rules. Run: php artisan help:readiness');

            return self::FAILURE;
        }

        file_put_contents($path, $expected);
        $this->info('Wrote '.FieldReadiness::PATH.' ('.count($readiness->rows()).' types).');

        return self::SUCCESS;
    }
}
