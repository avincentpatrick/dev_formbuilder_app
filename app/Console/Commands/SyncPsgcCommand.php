<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PsgcPlace;
use App\Services\Psgc\PsgcDataFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Copies the committed Philippine places into the `psgc_places` table — M186 (`R-70c7673a`, `D131`).
 *
 * `deploy.ps1` runs it inside the maintenance window, after `migrate --force` and BEFORE the row count compares, so that
 * deploy's count measures it too. Idempotent: a place already in the table is left exactly as it is unless its name, its
 * parent or its quarter changed, and only those rows are written. ⛔ It NEVER deletes: a place the PSA recoded keeps its
 * old quarter, so it drops out of the next publish (`PsgcLists` reads the newest quarter) and every published version that
 * chose it still reads it.
 *
 * The data is checked whole before anything is written (`PsgcDataFile::load()`), and a quarter older than the newest
 * already loaded is refused: the table only moves forward.
 */
final class SyncPsgcCommand extends Command
{
    private const int CHUNK = 1_000;

    protected $signature = 'psgc:sync
                            {--file= : Read this manifest instead of database/data/psgc/manifest.json}';

    protected $description = 'Copy the committed Philippine places into the psgc_places table (adds and corrects, never deletes)';

    public function handle(): int
    {
        $file = $this->option('file');
        $named = is_string($file) && $file !== '';
        $data = new PsgcDataFile($named ? $file : null);

        // Until the first quarter is committed there is nothing to copy, and that is not a failure: the deploy runs this
        // on every push, and the builder says the places are not on this server yet. A file NAMED with --file must exist.
        if (! $named && ! is_file($data->manifestPath())) {
            $this->info('psgc: no Philippine places are committed yet (database/data/psgc/manifest.json); nothing was copied.');

            return self::SUCCESS;
        }

        try {
            $data = $data->load();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->error('Nothing was copied.');

            return self::FAILURE;
        }

        $publication = $data['manifest']['publication'];
        $newest = PsgcPlace::query()->max('publication');

        if (is_string($newest) && strcmp($publication, $newest) < 0) {
            $this->error("The committed places are {$data['manifest']['label']}, older than the ".PsgcDataFile::label($newest).' already loaded. The table only moves forward; nothing was copied.');

            return self::FAILURE;
        }

        $held = [];
        foreach (PsgcPlace::query()->toBase()->get(['level', 'code', 'name', 'parent_code', 'publication']) as $place) {
            $held[$place->level.'|'.$place->code] = [$place->name, $place->parent_code, $place->publication];
        }

        $added = $updated = $unchanged = 0;
        $writes = [];
        $now = now();

        foreach ($data['rows'] as $row) {
            $current = $held[$row['level'].'|'.$row['code']] ?? null;

            if ($current === [$row['name'], $row['parent_code'], $publication]) {
                $unchanged++;

                continue;
            }

            $current === null ? $added++ : $updated++;
            $writes[] = [
                'id' => Uuid::uuid7()->toString(), // used only when the row is new; an existing row keeps its id
                'level' => $row['level'],
                'code' => $row['code'],
                'name' => $row['name'],
                'parent_code' => $row['parent_code'],
                'publication' => $publication,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($writes): void {
            foreach (array_chunk($writes, self::CHUNK) as $chunk) {
                PsgcPlace::query()->upsert($chunk, ['level', 'code'], ['name', 'parent_code', 'publication', 'updated_at']);
            }
        });

        $this->info(sprintf(
            'psgc: %s added, %s updated, %s unchanged; %s in the table, none removed (%s).',
            number_format($added),
            number_format($updated),
            number_format($unchanged),
            number_format(PsgcPlace::query()->count()),
            $data['manifest']['label'],
        ));

        return self::SUCCESS;
    }
}
