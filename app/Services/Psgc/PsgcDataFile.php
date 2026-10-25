<?php

declare(strict_types=1);

namespace App\Services\Psgc;

use InvalidArgumentException;
use JsonException;

/**
 * The Philippine places as committed — M186 (`R-70c7673a`, `D131`).
 *
 * Two files in `database/data/psgc/`: `places.csv` (`level,code,name,parent_code`, one row per place per level, region
 * first and each level by code) and `manifest.json`, which names the quarter, credits the Philippine Statistics Authority,
 * and holds the CSV's SHA-256 and its count at each level. `psgc:build` writes both from the PSA workbook; `psgc:sync`
 * reads them on every deploy. They live in `database/data/`, never under `docs/`, because a push that changes only
 * `docs/` takes `deploy.ps1`'s no-window path, which runs no artisan step.
 *
 * `load()` refuses the WHOLE data on the first thing wrong with it — a hash that does not match, a count that does not,
 * a row whose parent is not one level up — so a quarter is never half-copied.
 */
final class PsgcDataFile
{
    /** A quarter as stored: the year, then Q and the quarter — ordered as text, so the newest is the greatest. */
    public const string PUBLICATION = '/^[0-9]{4}Q[1-4]$/';

    private const array MANIFEST_KEYS = ['publication', 'label', 'released_on', 'source', 'credit', 'file', 'sha256', 'counts', 'psa_counts'];

    public function __construct(private readonly ?string $manifest = null) {}

    public function manifestPath(): string
    {
        return $this->manifest ?? database_path('data/psgc/manifest.json');
    }

    /** `2026Q3` as a person reads it: `3Q 2026`, the PSA's own way of naming a quarter. */
    public static function label(string $publication): string
    {
        return substr($publication, 5, 1).'Q '.substr($publication, 0, 4);
    }

    /** The PSA credited, as the manifest and Settings → About both say it. */
    public static function credit(string $publication): string
    {
        return 'Philippine Standard Geographic Code (PSGC), '.self::label($publication).'. Philippine Statistics Authority.';
    }

    /**
     * The manifest and every row, each checked against the other.
     *
     * @return array{manifest: array{publication: string, label: string, released_on: string, source: string, credit: string, file: string, sha256: string, counts: array<string, int>, psa_counts: array<string, int>}, rows: list<array{level: string, code: string, name: string, parent_code: ?string}>}
     *
     * @throws InvalidArgumentException naming the first thing wrong
     */
    public function load(): array
    {
        $manifest = $this->manifest();
        $csv = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.$manifest['file'];
        $bytes = is_file($csv) ? file_get_contents($csv) : false;

        if ($bytes === false) {
            throw new InvalidArgumentException("The places file '{$csv}' cannot be read.");
        }
        if (hash('sha256', $bytes) !== $manifest['sha256']) {
            throw new InvalidArgumentException("The places file's SHA-256 is not the one manifest.json records: the file was changed after psgc:build wrote it (a line-ending conversion does this too).");
        }

        $rows = self::parse($bytes);
        $counts = array_fill_keys(PsgcTiers::TIERS, 0);
        foreach ($rows as $row) {
            $counts[$row['level']]++;
        }
        if ($counts !== $manifest['counts']) {
            throw new InvalidArgumentException('The places file holds '.self::describe($counts).', and manifest.json records '.self::describe($manifest['counts']).'.');
        }

        return ['manifest' => $manifest, 'rows' => $rows];
    }

    /**
     * Write the CSV and its manifest, the hash and the counts measured from what was written.
     *
     * @param  list<array{level: string, code: string, name: string, parent_code: ?string}>  $rows
     * @param  array{publication: string, released_on: string, source: string}  $about
     * @param  array<string, int>  $psaCounts
     * @return array<string, mixed> the manifest written
     */
    public static function write(string $directory, array $rows, array $about, array $psaCounts): array
    {
        self::checkRows($rows);

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new InvalidArgumentException('The places file could not be written.');
        }
        fputcsv($stream, ['level', 'code', 'name', 'parent_code'], ',', '"', '', "\n");
        foreach ($rows as $row) {
            fputcsv($stream, [$row['level'], $row['code'], $row['name'], $row['parent_code'] ?? ''], ',', '"', '', "\n");
        }
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        $counts = array_fill_keys(PsgcTiers::TIERS, 0);
        foreach ($rows as $row) {
            $counts[$row['level']]++;
        }

        $manifest = [
            'publication' => $about['publication'],
            'label' => self::label($about['publication']),
            'released_on' => $about['released_on'],
            'source' => $about['source'],
            'credit' => self::credit($about['publication']),
            'file' => 'places.csv',
            'sha256' => hash('sha256', $bytes),
            'counts' => $counts,
            'psa_counts' => $psaCounts,
        ];

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException("The directory '{$directory}' could not be made.");
        }
        file_put_contents($directory.DIRECTORY_SEPARATOR.'places.csv', $bytes);
        file_put_contents($directory.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $manifest;
    }

    /**
     * @return array{publication: string, label: string, released_on: string, source: string, credit: string, file: string, sha256: string, counts: array<string, int>, psa_counts: array<string, int>}
     */
    private function manifest(): array
    {
        $path = $this->manifestPath();
        $raw = is_file($path) ? file_get_contents($path) : false;

        if ($raw === false) {
            throw new InvalidArgumentException("The places manifest '{$path}' cannot be read.");
        }

        try {
            $manifest = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("The places manifest is not valid JSON: {$e->getMessage()}.");
        }

        if (! is_array($manifest) || array_keys($manifest) !== self::MANIFEST_KEYS) {
            throw new InvalidArgumentException('The places manifest must hold, in order: '.implode(', ', self::MANIFEST_KEYS).'.');
        }
        if (! is_string($manifest['publication']) || preg_match(self::PUBLICATION, $manifest['publication']) !== 1) {
            throw new InvalidArgumentException('The places manifest names its quarter like 2026Q3.');
        }
        foreach (['label', 'released_on', 'source', 'credit', 'file', 'sha256'] as $key) {
            if (! is_string($manifest[$key]) || $manifest[$key] === '') {
                throw new InvalidArgumentException("The places manifest's {$key} must be words.");
            }
        }
        if (basename($manifest['file']) !== $manifest['file']) {
            throw new InvalidArgumentException('The places manifest names a file beside it, never a path.');
        }
        if (! is_array($manifest['counts']) || array_keys($manifest['counts']) !== PsgcTiers::TIERS || ! is_array($manifest['psa_counts'])) {
            throw new InvalidArgumentException('The places manifest counts each of the four levels, in order.');
        }

        /** @var array{publication: string, label: string, released_on: string, source: string, credit: string, file: string, sha256: string, counts: array<string, int>, psa_counts: array<string, int>} $manifest */
        return $manifest;
    }

    /**
     * @return list<array{level: string, code: string, name: string, parent_code: ?string}>
     */
    private static function parse(string $bytes): array
    {
        $lines = explode("\n", rtrim($bytes, "\n"));
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');

        if ($header !== ['level', 'code', 'name', 'parent_code']) {
            throw new InvalidArgumentException('The places file must start with the header level,code,name,parent_code.');
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) !== 4) {
                throw new InvalidArgumentException('Line '.($i + 2).' of the places file does not have four columns.');
            }
            [$level, $code, $name, $parent] = array_map('strval', $cells);
            $rows[] = ['level' => $level, 'code' => $code, 'name' => $name, 'parent_code' => $parent === '' ? null : $parent];
        }

        self::checkRows($rows);

        return $rows;
    }

    /**
     * Each row a known level, a ten-digit code once per level, a name, and — below a region — a parent one level up.
     *
     * @param  list<array{level: string, code: string, name: string, parent_code: ?string}>  $rows
     */
    private static function checkRows(array $rows): void
    {
        $seen = array_fill_keys(PsgcTiers::TIERS, []);
        $above = array_combine(PsgcTiers::TIERS, [null, 'region', 'province', 'city']);

        foreach ($rows as $row) {
            $level = $row['level'];
            $where = "Place {$row['code']} at the {$level} level";

            if (! array_key_exists($level, $seen)) {
                throw new InvalidArgumentException("Place {$row['code']} has the level “{$level}”; the levels are ".implode(', ', PsgcTiers::TIERS).'.');
            }
            if (preg_match('/^[0-9]{10}$/', $row['code']) !== 1) {
                throw new InvalidArgumentException("“{$row['code']}” at the {$level} level is not a ten-digit code.");
            }
            if (isset($seen[$level][$row['code']])) {
                throw new InvalidArgumentException("{$where} appears twice.");
            }
            if (trim($row['name']) === '' || mb_strlen($row['name']) > 255 || preg_match('/[\r\n]/', $row['name']) === 1) {
                throw new InvalidArgumentException("{$where} has no name, a name longer than 255 characters, or one that breaks a line.");
            }

            $parentLevel = $above[$level];
            if ($parentLevel === null && $row['parent_code'] !== null) {
                throw new InvalidArgumentException("{$where} has a parent; a region has none.");
            }
            if ($parentLevel !== null && ($row['parent_code'] === null || ! isset($seen[$parentLevel][$row['parent_code']]))) {
                throw new InvalidArgumentException("{$where} names “{$row['parent_code']}” as its {$parentLevel}, which the file does not hold above it.");
            }

            $seen[$level][$row['code']] = true;
        }
    }

    /** @param  array<string, int>  $counts */
    private static function describe(array $counts): string
    {
        return implode(', ', array_map(static fn (string $level, int $n): string => number_format($n).' '.$level, array_keys($counts), $counts));
    }
}
