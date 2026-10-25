<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Exceptions\Forms\PublishValidationException;
use App\Models\PsgcPlace;
use App\Services\Psgc\PsgcDataFile;
use App\Services\Psgc\PsgcTiers;

/**
 * A cascade's levels bound to the built-in Philippine places — M186 (`R-70c7673a`, `D131`).
 *
 * The Levels tab's "Philippine places (PSGC)" names each level's list `psgc:region`, `psgc:province`, `psgc:city`,
 * `psgc:barangay`. A name of that shape is never an uploaded list's (an upload's name is `[a-z0-9_-]`), so the cascade
 * is list-backed exactly as one fed by uploaded files is — the draft holds no options, publishing writes them into the
 * version, the public schema leaves them out and the runtime fetches them beside it — and only WHERE the rows come from
 * differs: the platform's `psgc_places`, the newest quarter loaded, instead of the version's own files. A published form
 * therefore keeps its quarter, and a later quarter changes the next publish only.
 *
 * The levels must run from the top without a gap — region; region and province; … all four — because every place's
 * parent is one level up. Each level's choices are in name order, numbers in natural order ("Barangay 2" before
 * "Barangay 10").
 */
final class PsgcLists
{
    public const string PREFIX = 'psgc:';

    /** @var array<string, list<array{code: string, name: string, parent_code: ?string}>>|null each level of the newest quarter, read once */
    private ?array $tiers = null;

    /** Is this a level's list name for the built-in places? */
    public static function isPsgcList(mixed $name): bool
    {
        return is_string($name) && str_starts_with($name, self::PREFIX);
    }

    /**
     * Does any of these levels take the built-in places?
     *
     * @param  array<array-key, mixed>  $levels
     */
    public static function declaresAny(array $levels): bool
    {
        foreach ($levels as $level) {
            if (is_array($level) && self::isPsgcList($level['list'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cascade's options from the newest quarter, in the materializer's option shape.
     *
     * @param  list<array<string, mixed>>  $levels
     * @return list<array{level: string, value: string, label: string, parent: ?string}>|PublishValidationException
     */
    public function options(string $fieldKey, array $levels): array|PublishValidationException
    {
        $tiers = [];
        foreach ($levels as $level) {
            $name = $level['list'] ?? null;
            if (! self::isPsgcList($name)) {
                return PublishValidationException::cascadingConfigInvalid($fieldKey, 'its levels take the Philippine places and a list file at once — choose one for every level');
            }
            $tiers[] = substr((string) $name, strlen(self::PREFIX));
        }

        if ($tiers !== array_slice(PsgcTiers::TIERS, 0, count($tiers))) {
            return PublishValidationException::cascadingConfigInvalid($fieldKey, 'the Philippine places run region, province or district, city or municipality, barangay — keep that order from the top, and remove levels only from the bottom');
        }

        $loaded = $this->tiers();
        if ($loaded === null) {
            return PublishValidationException::cascadingConfigInvalid($fieldKey, 'the Philippine places are not loaded on this server yet');
        }

        $options = [];
        foreach ($tiers as $index => $tier) {
            $key = (string) ($levels[$index]['key'] ?? '');
            foreach ($loaded[$tier] as $place) {
                $options[] = [
                    'level' => $key,
                    'value' => $place['code'],
                    'label' => $place['name'],
                    'parent' => $index === 0 ? null : $place['parent_code'],
                ];
            }
        }

        return $options;
    }

    /**
     * What the builder shows beside the preset — the quarter loaded and each level's count — or null before any is.
     *
     * @return array{label: string, counts: array<string, int>}|null
     */
    public function summary(): ?array
    {
        $newest = PsgcPlace::query()->max('publication');
        if (! is_string($newest)) {
            return null;
        }

        // A count by level, not the rows: the builder asks on every load, and the rows are 43,000.
        $counts = array_fill_keys(PsgcTiers::TIERS, 0);
        foreach (PsgcPlace::query()->where('publication', $newest)->groupBy('level')->toBase()->selectRaw('level, count(*) as places')->get() as $row) {
            $counts[(string) $row->level] = (int) $row->places;
        }

        return ['label' => PsgcDataFile::label($newest), 'counts' => $counts];
    }

    /** The PSA credited for the quarter this server holds — Settings → About shows it — or null before any is loaded. */
    public function credit(): ?string
    {
        $newest = PsgcPlace::query()->max('publication');

        return is_string($newest) ? PsgcDataFile::credit($newest) : null;
    }

    /**
     * The newest quarter's places at each level, name order — or null when the table is empty (a server whose deploy has
     * not yet copied them in).
     *
     * @return array<string, list<array{code: string, name: string, parent_code: ?string}>>|null
     */
    private function tiers(): ?array
    {
        if ($this->tiers !== null) {
            return $this->tiers;
        }

        $newest = PsgcPlace::query()->max('publication');
        if (! is_string($newest)) {
            return null;
        }

        $tiers = array_fill_keys(PsgcTiers::TIERS, []);
        foreach (PsgcPlace::query()->where('publication', $newest)->toBase()->get(['level', 'code', 'name', 'parent_code']) as $place) {
            $tiers[(string) $place->level][] = [
                'code' => (string) $place->code,
                'name' => (string) $place->name,
                'parent_code' => $place->parent_code === null ? null : (string) $place->parent_code,
            ];
        }

        foreach ($tiers as &$places) {
            usort($places, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['code'], $b['code']));
        }
        unset($places);

        return $this->tiers = $tiers;
    }
}
