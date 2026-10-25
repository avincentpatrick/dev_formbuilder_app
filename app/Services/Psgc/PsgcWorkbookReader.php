<?php

declare(strict_types=1);

namespace App\Services\Psgc;

use App\Support\Spreadsheet\CellText;
use InvalidArgumentException;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * The PSA's PSGC Publication Datafile, read — M186 (`R-70c7673a`, `D131`).
 *
 * The workbook is downloaded from psa.gov.ph in a browser (the site refuses scripts) and handed to `psgc:build`. It holds
 * several sheets; the places are on the one named `PSGC`, whose columns are found by their header text rather than by
 * position — "10-digit PSGC", "Name", "Geographic Level" — so a quarter that adds a column still reads.
 *
 * ⚠️ A CODE EXCEL STORED AS A NUMBER HAS LOST ITS LEADING ZERO (`CellText`): region 01's 0100000000 arrives as 100000000.
 * Every PSGC code is exactly ten digits, so a shorter one is padded back; anything else is refused by name.
 *
 * The summary is read from the sheet whose name says "national summary" (else any "summary" but the provinces' one):
 * a row whose first words name a level — Regions, Provinces, Cities, Municipalities, Barangays — and the first number
 * after them. What cannot be read is left out; `psgc:build` refuses to finish without all five.
 */
final class PsgcWorkbookReader
{
    /** The summary's levels, as the PSA codes them in the places sheet. */
    public const array SUMMARY_LEVELS = ['Reg' => 'regions', 'Prov' => 'provinces', 'City' => 'cities', 'Mun' => 'municipalities', 'Bgy' => 'barangays'];

    private const array SUMMARY_WORDS = [
        'Reg' => '/^regions?\b/',
        'Prov' => '/^provinces?\b/',
        'City' => '/^cit(y|ies)\b/',
        'Mun' => '/^municipalit(y|ies)\b/',
        'Bgy' => '/^barangays?\b/',
    ];

    /**
     * @return array{places: list<array{code: string, name: string, level: string}>, summary: array<string, int>, sheets: list<string>}
     *
     * @throws InvalidArgumentException when the workbook cannot be read or has no places sheet
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("The workbook '{$path}' does not exist.");
        }

        $options = new XlsxOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true; // so a refusal's row number is the sheet's
        $reader = new XlsxReader($options);
        $places = null;
        $summary = [];
        $summarySheet = null;
        $sheets = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $name = trim($sheet->getName());
                $sheets[] = $name;
                $lower = mb_strtolower($name);

                if ($lower === 'psgc') {
                    $places = $this->places($sheet->getRowIterator());
                } elseif ($this->isSummary($lower, $summarySheet)) {
                    $found = $this->summary($sheet->getRowIterator());
                    if ($found !== [] && ($summarySheet === null || str_contains($lower, 'national'))) {
                        $summary = $found;
                        $summarySheet = $lower;
                    }
                }
            }
        } catch (OpenSpoutException $e) {
            throw new InvalidArgumentException("The workbook could not be read ({$e->getMessage()}). Download it again from psa.gov.ph and save it as it came.");
        } finally {
            $reader->close();
        }

        if ($places === null) {
            throw new InvalidArgumentException('The workbook has no sheet named PSGC (it has '.implode(', ', $sheets).').');
        }

        return ['places' => $places, 'summary' => $summary, 'sheets' => $sheets];
    }

    /** A summary sheet is read unless a national one has been read already; the provinces' summary is not the country's. */
    private function isSummary(string $lower, ?string $already): bool
    {
        if (! str_contains($lower, 'summary') || str_contains($lower, 'prov')) {
            return false;
        }

        return $already === null || ! str_contains($already, 'national');
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return list<array{code: string, name: string, level: string}>
     */
    private function places(iterable $rows): array
    {
        $columns = null;
        $places = [];
        $line = 0;

        foreach ($rows as $row) {
            $line++;
            $cells = array_values(array_map(CellText::of(...), $row->toArray())); // by position: the header row's index is a column

            if ($columns === null) {
                $columns = $this->header($cells);
                if ($columns === null && $line >= 30) {
                    throw new InvalidArgumentException('The PSGC sheet has no header row naming “10-digit PSGC”, “Name” and “Geographic Level” in its first 30 rows.');
                }

                continue;
            }

            $code = trim($cells[$columns['code']] ?? '');
            $name = trim((string) preg_replace('/\s+/u', ' ', $cells[$columns['name']] ?? ''));
            $level = trim($cells[$columns['level']] ?? '');

            if ($code === '' && $name === '' && $level === '') {
                continue; // a blank row
            }
            if (preg_match('/^[0-9]{1,10}$/', $code) !== 1) {
                throw new InvalidArgumentException("Row {$line} of the PSGC sheet has “{$code}” where a ten-digit code belongs.");
            }
            if ($name === '') {
                throw new InvalidArgumentException("Row {$line} of the PSGC sheet (code {$code}) has no name.");
            }

            $places[] = ['code' => str_pad($code, 10, '0', STR_PAD_LEFT), 'name' => $name, 'level' => $level];
        }

        if ($columns === null) {
            throw new InvalidArgumentException('The PSGC sheet has no header row naming “10-digit PSGC”, “Name” and “Geographic Level”.');
        }

        return $places;
    }

    /**
     * @param  list<string>  $cells
     * @return array{code: int, name: int, level: int}|null
     */
    private function header(array $cells): ?array
    {
        $found = [];

        foreach ($cells as $i => $cell) {
            $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $cell)));

            if (! isset($found['code']) && (str_contains($text, '10-digit') || str_contains($text, '10 digit') || $text === 'psgc')) {
                $found['code'] = $i;
            } elseif (! isset($found['name']) && $text === 'name') {
                $found['name'] = $i;
            } elseif (! isset($found['level']) && str_contains($text, 'geographic level')) {
                $found['level'] = $i;
            }
        }

        return isset($found['code'], $found['name'], $found['level'])
            ? ['code' => $found['code'], 'name' => $found['name'], 'level' => $found['level']]
            : null;
    }

    /**
     * Each level's total, from a row whose first words name the level and the first whole number after them.
     *
     * @param  iterable<mixed>  $rows
     * @return array<string, int> keyed by the PSA's level code, e.g. Reg
     */
    private function summary(iterable $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $cells = array_values(array_map(static fn (mixed $v): string => trim(CellText::of($v)), $row->toArray()));

            foreach ($cells as $i => $cell) {
                $text = mb_strtolower($cell);
                foreach (self::SUMMARY_WORDS as $level => $pattern) {
                    if (isset($totals[$level]) || preg_match($pattern, $text) !== 1) {
                        continue;
                    }
                    foreach (array_slice($cells, $i + 1) as $after) {
                        $number = str_replace([',', ' '], '', $after);
                        if (preg_match('/^[0-9]+$/', $number) === 1) {
                            $totals[$level] = (int) $number;
                            break;
                        }
                    }
                }
            }
        }

        return $totals;
    }
}
