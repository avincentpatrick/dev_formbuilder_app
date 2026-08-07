<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Support\Export\SpreadsheetCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * A thin openspout wrapper that writes the three XLSForm sheets (`survey` / `choices` / `settings`) into an
 * already-opened XLSX writer (Increment G7). XLSForm requires multiple sheets, so only the XLSX writer (which
 * extends openspout's multi-sheet base) is usable — CSV cannot represent a workbook. Each sheet is a
 * `{headers, rows}` pair; a row is a header-keyed map whose missing columns render as blanks, so callers only
 * populate the cells they have.
 */
final class XlsformWorkbookWriter
{
    /**
     * @param  array<string, array{headers: list<string>, rows: list<array<string, scalar|null>>}>  $sheets
     *                                                                                                       keyed by sheet name, in write order
     */
    public function write(XlsxWriter $writer, array $sheets): void
    {
        $first = true;
        foreach ($sheets as $name => $sheet) {
            $current = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $current->setName($name);
            $first = false;

            $writer->addRow(Row::fromValues(SpreadsheetCell::row($sheet['headers'])));
            foreach ($sheet['rows'] as $row) {
                $writer->addRow(Row::fromValues(SpreadsheetCell::row($this->align($sheet['headers'], $row))));
            }
        }
    }

    /**
     * Convenience for tests / non-streaming callers: render a workbook to raw XLSX bytes via a temp file.
     *
     * @param  array<string, array{headers: list<string>, rows: list<array<string, scalar|null>>}>  $sheets
     */
    public function writeToString(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsform').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $this->write($writer, $sheets);
        $writer->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Project a header-keyed row onto the ordered header list, blanks for absent columns.
     *
     * @param  list<string>  $headers
     * @param  array<string, scalar|null>  $row
     * @return list<scalar|null>
     */
    private function align(array $headers, array $row): array
    {
        return array_map(static fn (string $header): string|int|float|bool => $row[$header] ?? '', $headers);
    }
}
