<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Psgc\PsgcDataFile;
use App\Services\Psgc\PsgcTiers;
use App\Services\Psgc\PsgcWorkbookReader;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Builds the committed Philippine places from the PSA's workbook — M186 (`R-70c7673a`, `D131`). An operator step, once a
 * quarter: download the PSGC Publication Datafile from psa.gov.ph in a browser (the site refuses scripts), run this, and
 * commit the two files it writes in a pull request; every deploy then copies them in (`psgc:sync`).
 *
 * ⛔ IT NEVER PASSES UNMEASURED. The workbook's place count at each of the PSA's levels must equal the totals its own
 * summary sheet prints — or, where that sheet cannot be read, the totals the operator types from the PSA's summary page
 * with `--expect`. A count that disagrees exits 1, naming it; a total that cannot be measured at all exits 2, a status
 * of its own, so a wrapper can never read "nothing to compare" as "compared and equal".
 */
final class BuildPsgcCommand extends Command
{
    /** The status for "the totals could not be measured" — distinct from a refusal. */
    public const int CANNOT_MEASURE = 2;

    protected $signature = 'psgc:build
                            {workbook : The PSA\'s PSGC Publication Datafile (.xlsx)}
                            {--publication= : The quarter it publishes, like 2026Q3}
                            {--released= : The day the PSA released it, like 2026-10-12}
                            {--expect= : Totals from the PSA\'s summary page where the sheet cannot be read, like regions=18,barangays=42010}
                            {--out= : Write here instead of database/data/psgc}';

    protected $description = 'Build the committed Philippine places (database/data/psgc) from the PSA\'s PSGC workbook';

    public function handle(PsgcWorkbookReader $reader): int
    {
        $workbook = (string) $this->argument('workbook');
        $publication = (string) $this->option('publication');
        $released = (string) $this->option('released');

        if (preg_match(PsgcDataFile::PUBLICATION, $publication) !== 1) {
            $this->error('Name the quarter with --publication, like 2026Q3.');

            return self::FAILURE;
        }
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $released) !== 1) {
            $this->error('Give the day the PSA released it with --released, like 2026-10-12.');

            return self::FAILURE;
        }

        try {
            $expected = $this->expected();
            $read = $reader->read($workbook);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $counts = PsgcTiers::psaCounts($read['places']);
        $this->line('Sheets: '.implode(' · ', $read['sheets']));
        $this->line('Places by the PSA\'s levels: '.implode(', ', array_map(static fn (string $l, int $n): string => "{$l} ".number_format($n), array_keys($counts), $counts)));

        $faults = [];
        $unmeasured = [];

        foreach (PsgcWorkbookReader::SUMMARY_LEVELS as $level => $words) {
            $sheet = $read['summary'][$level] ?? null;
            $typed = $expected[$level] ?? null;

            if ($sheet !== null && $typed !== null && $sheet !== $typed) {
                $faults[] = "the summary sheet counts {$sheet} {$words}, and --expect says {$typed}";

                continue;
            }

            $total = $sheet ?? $typed;
            if ($total === null) {
                $unmeasured[] = $words;

                continue;
            }

            $have = $counts[$level] ?? 0;
            if ($have !== $total) {
                $faults[] = 'the sheet holds '.number_format($have)." {$words}, and the ".($sheet !== null ? 'summary sheet' : '--expect total').' says '.number_format($total);
            } else {
                $this->line('  '.str_pad($words, 15).number_format($have).'  = '.($sheet !== null ? 'the summary sheet' : '--expect'));
            }
        }

        if ($faults !== []) {
            $this->error('Refused — '.implode('; ', $faults).'. Nothing was written.');

            return self::FAILURE;
        }
        if ($unmeasured !== []) {
            $this->error('Refused — the totals for '.implode(', ', $unmeasured).' could not be read from the summary sheet. Type them from the PSA\'s summary page with --expect (e.g. --expect=regions=18,provinces=82). Nothing was written.');

            return self::CANNOT_MEASURE;
        }

        try {
            $rows = PsgcTiers::build($read['places']);
            $out = (string) ($this->option('out') ?: database_path('data/psgc'));
            $manifest = PsgcDataFile::write($out, $rows, [
                'publication' => $publication,
                'released_on' => $released,
                'source' => basename($workbook),
            ], $counts);
        } catch (InvalidArgumentException $e) {
            $this->error('Refused — '.$e->getMessage().' Nothing was written.');

            return self::FAILURE;
        }

        /** @var array<string, int> $levels */
        $levels = $manifest['counts'];
        $this->info(sprintf(
            'psgc: wrote %s (%s) — %s; sha256 %s.',
            $out,
            (string) $manifest['label'],
            implode(', ', array_map(static fn (string $l, int $n): string => number_format($n)." {$l}", array_keys($levels), $levels)),
            (string) $manifest['sha256'],
        ));

        return self::SUCCESS;
    }

    /**
     * `--expect=regions=18,barangays=42010`, keyed by the PSA's level code.
     *
     * @return array<string, int>
     */
    private function expected(): array
    {
        $raw = trim((string) $this->option('expect'));
        if ($raw === '') {
            return [];
        }

        $byWord = array_flip(PsgcWorkbookReader::SUMMARY_LEVELS);
        $expected = [];

        foreach (explode(',', $raw) as $pair) {
            [$word, $number] = array_pad(explode('=', trim($pair), 2), 2, '');
            $level = $byWord[mb_strtolower(trim($word))] ?? null;
            if ($level === null || preg_match('/^[0-9]+$/', trim($number)) !== 1) {
                throw new InvalidArgumentException('--expect takes level=total pairs for '.implode(', ', PsgcWorkbookReader::SUMMARY_LEVELS)."; “{$pair}” is neither.");
            }
            $expected[$level] = (int) trim($number);
        }

        return $expected;
    }
}
