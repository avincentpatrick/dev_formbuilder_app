<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Support\Platform\ReleaseNotes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Copies the committed What's new notes into the `releases` table — M181 (`R-c7247dbf`, `D125` C).
 *
 * `deploy.ps1` runs it inside the maintenance window, after `migrate --force` and the row count, so the table always
 * holds the release that is live. Idempotent: a release already in the table is left exactly as it is unless its notes
 * changed in the file, and the date it went live (`released_at`, stamped by the copy that first added it) is never
 * rewritten. ⛔ It NEVER deletes: a release dropped from the file stays — the table is the release history. (Not the row
 * count's doing, as this once said: the count compares BEFORE this runs, on purpose, so it measures the migration alone.)
 *
 * The file is checked whole before anything is written (`ReleaseNotes::load()`), so a malformed file fails the step and
 * changes nothing.
 */
final class SyncReleasesCommand extends Command
{
    protected $signature = 'releases:sync
                            {--file= : Read this notes file instead of database/data/releases.json}';

    protected $description = "Copy the committed What's new notes into the releases table (adds and corrects, never deletes)";

    public function handle(): int
    {
        $file = $this->option('file');

        try {
            $releases = (new ReleaseNotes(is_string($file) && $file !== '' ? $file : null))->load();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->error('Nothing was copied.');

            return self::FAILURE;
        }

        $added = $updated = $unchanged = 0;

        DB::transaction(function () use ($releases, &$added, &$updated, &$unchanged): void {
            foreach ($releases as $notes) {
                $release = Release::query()->where('version', $notes['version'])->first();

                if ($release === null) {
                    Release::query()->create([...$notes, 'released_at' => now()]);
                    $added++;

                    continue;
                }

                // Compared with key order set aside: `jsonb` stores an object's keys in its own order (`href` before
                // `kind`), and Eloquent's dirty check on an array cast compares strictly, so an unchanged release
                // with a link would be rewritten on every deploy.
                if ($release->title === $notes['title'] && self::canonical($release->items) === self::canonical($notes['items'])) {
                    $unchanged++;

                    continue;
                }

                $release->fill(['title' => $notes['title'], 'items' => $notes['items']])->save();
                $updated++;
            }
        });

        $this->info(sprintf(
            'releases: %d added, %d updated, %d unchanged; %d in the table, none removed (newest %s).',
            $added,
            $updated,
            $unchanged,
            Release::query()->count(),
            $releases[0]['version'],
        ));

        return self::SUCCESS;
    }

    /**
     * The same value with every object's keys sorted, so two decodings of one JSON document compare equal.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $v): mixed => is_array($v) ? self::canonical($v) : $v, $value);
    }
}
