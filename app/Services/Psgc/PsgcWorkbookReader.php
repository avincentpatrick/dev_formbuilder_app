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
 * ── WHAT THE PSA'S FILES ACTUALLY HOLD (measured on 2Q and 3Q 2026, 2026-10-26) ─────────────────────────────────
 * The 3Q 2026 places table starts in column B (columns are found by header, so it reads). Under the places sits a
 * FOOTNOTE row — its mark in the column before the codes ("a/"), its text in the code column ("As noted by the PSA Board,
 * Lucena City is treated as an HUC …") — and the mark is pasted onto the name it notes ("City of Lucenaa/"). A row with
 * text in the code column and neither a name nor a level is such a note: skipped and returned, never a place. A name
 * ending in a mark a footnote declares has the mark taken off, and that is returned too; a mark no footnote declares is
 * left alone, because then it is part of the name.
 *
 * The summary is read from the sheet whose name says "national summary" (else any "summary" but the provinces' one),
 * which the PSA prints as a TABLE: a header row naming PROV. · CITIES · MUN. · BGY., the country's totals in those
 * columns on the row reading PHILIPPINES, and one coded row per region under the header, so the regions are COUNTED.
 * (The layout first guessed — "Regions 18" lines — read Region I's province count, 4, as the number of regions.) What
 * cannot be read is left out; `psgc:build` refuses to finish without all five.
 */
final class PsgcWorkbookReader
{
    /** The summary's levels, as the PSA codes them in the places sheet. */
    public const array SUMMARY_LEVELS = ['Reg' => 'regions', 'Prov' => 'provinces', 'City' => 'cities', 'Mun' => 'municipalities', 'Bgy' => 'barangays'];

    /** The summary table's column for each level but the regions, by its header folded to letters ("PROV." → prov). */
    private const array SUMMARY_COLUMNS = ['Prov' => '/^prov/', 'City' => '/^cit/', 'Mun' => '/^mun/', 'Bgy' => '/^(bgy|barangay)/'];

    /** A footnote's mark, as the PSA writes one: "a/". */
    private const string FOOTNOTE_MARK = '/^[a-z]{1,2}\/$/';

    /**
     * @return array{places: list<array{code: string, name: string, level: string}>, summary: array<string, int>, sheets: list<string>, notes: list<string>, marks: list<array{code: string, mark: string, from: string, to: string}>}
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
        $read = null;
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
                    $read = $this->places($sheet->getRowIterator());
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

        if ($read === null) {
            throw new InvalidArgumentException('The workbook has no sheet named PSGC (it has '.implode(', ', $sheets).').');
        }

        return [...$read, 'summary' => $summary, 'sheets' => $sheets];
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
     * @return array{places: list<array{code: string, name: string, level: string}>, notes: list<string>, marks: list<array{code: string, mark: string, from: string, to: string}>}
     */
    private function places(iterable $rows): array
    {
        $columns = null;
        $places = [];
        $notes = [];
        $declared = [];
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
            if ($name === '' && $level === '' && preg_match('/^[0-9]{1,10}$/', $code) !== 1) {
                // A footnote under the places: its text in the code column, its mark in the column before.
                $mark = trim($cells[$columns['code'] - 1] ?? '');
                $notes[] = trim($mark.' '.$code);
                if (preg_match(self::FOOTNOTE_MARK, $mark) === 1) {
                    $declared[] = $mark;
                }

                continue;
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

        // A footnote's mark pasted onto the name it notes ("City of Lucenaa/") comes off — only a mark a footnote declares.
        $marks = [];
        foreach ($places as $i => $place) {
            foreach ($declared as $mark) {
                if (mb_strlen($place['name']) > mb_strlen($mark) && str_ends_with($place['name'], $mark)) {
                    $to = rtrim(mb_substr($place['name'], 0, -mb_strlen($mark)));
                    $marks[] = ['code' => $place['code'], 'mark' => $mark, 'from' => $place['name'], 'to' => $to];
                    $places[$i]['name'] = $to;

                    break;
                }
            }
        }

        return ['places' => $places, 'notes' => $notes, 'marks' => $marks];
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
     * Each level's total, from the PSA's National Summary table: the PHILIPPINES row's number in each level's column,
     * and the regions counted as the coded rows under the header (RR 00000000). Nothing is read before the header row.
     *
     * @param  iterable<mixed>  $rows
     * @return array<string, int> keyed by the PSA's level code, e.g. Reg
     */
    private function summary(iterable $rows): array
    {
        $columns = null;
        $totals = [];
        $regions = 0;

        foreach ($rows as $row) {
            $cells = array_values(array_map(static fn (mixed $v): string => trim(CellText::of($v)), $row->toArray()));

            if ($columns === null) {
                $columns = $this->summaryHeader($cells);

                continue;
            }

            $code = str_pad(str_replace([',', ' '], '', $cells[$columns['code']] ?? ''), 10, '0', STR_PAD_LEFT);
            if (preg_match('/^(?!00)[0-9]{2}0{8}$/', $code) === 1) {
                $regions++;
            }

            $folded = array_map(static fn (string $cell): string => (string) preg_replace('/[^a-z]/', '', mb_strtolower($cell)), $cells);
            if ($totals === [] && in_array('philippines', $folded, true)) {
                foreach ($columns['levels'] as $level => $i) {
                    $number = str_replace([',', ' '], '', $cells[$i] ?? '');
                    if (preg_match('/^[0-9]+$/', $number) === 1) {
                        $totals[$level] = (int) $number;
                    }
                }
            }
        }

        if ($regions > 0) {
            $totals = ['Reg' => $regions, ...$totals];
        }

        return $totals;
    }

    /**
     * The summary table's header row: a column for each of PROV. · CITIES · MUN. · BGY., and the CODE column (the
     * first, where none is named).
     *
     * @param  list<string>  $cells
     * @return array{code: int, levels: array<string, int>}|null
     */
    private function summaryHeader(array $cells): ?array
    {
        $levels = [];
        $code = 0;

        foreach ($cells as $i => $cell) {
            $folded = (string) preg_replace('/[^a-z]/', '', mb_strtolower($cell));
            if ($folded === 'code') {
                $code = $i;
            }
            foreach (self::SUMMARY_COLUMNS as $level => $pattern) {
                if (! isset($levels[$level]) && preg_match($pattern, $folded) === 1) {
                    $levels[$level] = $i;
                }
            }
        }

        return count($levels) === count(self::SUMMARY_COLUMNS) ? ['code' => $code, 'levels' => $levels] : null;
    }
}
