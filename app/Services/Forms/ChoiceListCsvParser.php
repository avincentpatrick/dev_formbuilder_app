<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Support\Spreadsheet\CellText;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads one choice list from a CSV file in Kobo's own format (M141, `R-f69aab42`, `D95`) — or, since M184 (`R-3e7c81bb`),
 * from the first sheet of an Excel workbook (`.xlsx`) laid out the same way. The name stays: the format is Kobo's CSV
 * format, whichever file carries it.
 *
 * The file has a `name` column — a code, unique in the file — and a `label` column (Kobo's `label::English (en)`
 * counts when there is no plain `label`). A file for a level below the first adds a column named after the level
 * above; which column that is, is decided at publish (`ChoiceListMaterializer`), so every column is kept here.
 *
 * ── WHAT A REAL FILE LOOKS LIKE, AND WHY EACH BRANCH EXISTS ────────────────────────────────────────────────
 *  - **Excel on Windows saves CSV as Windows-1252**, not UTF-8, unless "CSV UTF-8" is picked — and Philippine place
 *    names carry `ñ` ("Parañaque", "Las Piñas"). Bytes that are not valid UTF-8 are read as Windows-1252.
 *  - **A byte-order mark** leads a "CSV UTF-8" file; it is dropped so the first header is `name`, not `\u{FEFF}name`.
 *  - **The delimiter** is a comma, a semicolon in regions that use a decimal comma, or a tab; the header line decides.
 *  - **Rows may be ragged**; a short row is padded and an over-long one cut to the header.
 *  - **A workbook is a zip**, so it is told from a CSV by its first four bytes, never by its name. Only its first sheet is
 *    read, with blank rows kept so "Row N" is the row Excel shows. ⚠️ A code Excel stored as a NUMBER has already lost
 *    its leading zero ({@see CellText}); only a cell stored as text keeps `01` — the upload says so (`R-1059e9bd`).
 *  - **The row and column caps are enforced while reading**, not after: a 10 MB workbook unpacks to far more than 10 MB.
 *
 * ⛔ A NAME MUST BE UNIQUE IN ITS FILE, AND THAT IS NOT TIDINESS. Both validation engines key a cascade level's
 * options by value, so a repeated value silently takes the later row's parent — and barangay NAMES repeat across
 * cities ("Poblacion"). So the file's `name` must be a code (a PSGC code, say), and a repeat is refused here, naming
 * both rows, rather than discovered when an answer is rejected.
 */
final class ChoiceListCsvParser
{
    /** Above a PSGC barangay list (about 42,000) with room to spare. */
    public const int MAX_ROWS = 60_000;

    public const int MAX_COLUMNS = 30;

    private const int MAX_NAME = 255;

    private const int MAX_LABEL = 500;

    /** The local-file header every zip — so every `.xlsx` — starts with. */
    private const string ZIP_SIGNATURE = "PK\x03\x04";

    /**
     * @return array{columns: list<string>, rows: list<list<string>>}
     *
     * @throws ValidationException under the `file` key, with a message an author can act on
     */
    public function parse(string $path): array
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || trim($bytes) === '') {
            throw self::invalid('The file is empty.');
        }

        $table = str_starts_with($bytes, self::ZIP_SIGNATURE) ? $this->readWorkbook($path) : $this->readCsv($bytes);
        $header = array_map(static fn (string $cell): string => mb_strtolower(trim($cell)), array_shift($table) ?? []);

        while ($header !== [] && end($header) === '') {
            array_pop($header); // trailing empty header cells, which a spreadsheet often adds
        }

        if (count($header) > self::MAX_COLUMNS) {
            throw self::invalid('The file has more than '.self::MAX_COLUMNS.' columns.');
        }

        $nameAt = array_search('name', $header, true);
        $labelAt = array_search('label', $header, true);

        if ($labelAt === false) {
            foreach ($header as $index => $column) {
                if (str_starts_with($column, 'label')) {
                    $labelAt = $index;
                    break;
                }
            }
        }

        if ($nameAt === false || $labelAt === false) {
            throw self::invalid('The first row must name the columns, and they must include “name” and “label”.');
        }

        if (count($header) !== count(array_unique($header))) {
            throw self::invalid('Two columns have the same name.');
        }

        $width = count($header);
        $rows = [];
        $seen = [];

        foreach ($table as $index => $cells) {
            $line = $index + 2; // the header is line 1, and a spreadsheet counts from 1
            $cells = array_map(static fn (string $cell): string => trim($cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            $cells = array_slice(array_pad($cells, $width, ''), 0, $width);
            $name = $cells[$nameAt];

            if ($name === '') {
                throw self::invalid("Row {$line} has no name.");
            }

            if (mb_strlen($name) > self::MAX_NAME || mb_strlen($cells[$labelAt]) > self::MAX_LABEL) {
                throw self::invalid("Row {$line} has a name or label that is too long.");
            }

            if (isset($seen[$name])) {
                throw self::invalid("“{$name}” is on rows {$seen[$name]} and {$line}. Each name must be a code used once in the file.");
            }

            $seen[$name] = $line;

            if ($cells[$labelAt] === '') {
                $cells[$labelAt] = $name;
            }

            $rows[] = $cells;

            if (count($rows) > self::MAX_ROWS) {
                throw self::tooManyRows();
            }
        }

        if ($rows === []) {
            throw self::invalid('The file has no rows under its header.');
        }

        return ['columns' => $header, 'rows' => $rows];
    }

    /**
     * Every row of the first sheet as strings, blank rows kept.
     *
     * @return list<list<string>>
     */
    private function readWorkbook(string $path): array
    {
        $options = new XlsxOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        return $this->rows(new XlsxReader($options), $path);
    }

    /**
     * Every row as strings, read by OpenSpout from a temporary copy of the normalised bytes.
     *
     * @return list<list<string>>
     */
    private function readCsv(string $bytes): array
    {
        if (str_starts_with($bytes, "\u{FEFF}")) {
            $bytes = substr($bytes, 3);
        }

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($bytes, "\n");
        $firstLine = $firstLine === false ? '' : $firstLine;
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        $options = new CsvOptions;
        $options->FIELD_DELIMITER = (string) array_key_first($counts);
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        $path = tempnam(sys_get_temp_dir(), 'choice-list-');

        if ($path === false) {
            throw self::invalid('The file could not be read.');
        }

        file_put_contents($path, $bytes);

        try {
            return $this->rows(new CsvReader($options), $path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The first sheet's rows, each cut to one column past the cap — enough for the header check to refuse a wide file —
     * and refused as soon as more rows hold anything than the cap allows.
     *
     * @return list<list<string>>
     */
    private function rows(ReaderInterface $reader, string $path): array
    {
        $table = [];
        $filled = 0;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(CellText::of(...), array_slice($row->toArray(), 0, self::MAX_COLUMNS + 1));

                    if (implode('', $cells) !== '' && ++$filled > self::MAX_ROWS + 1) {
                        throw self::tooManyRows(); // the header is the one more
                    }

                    $table[] = $cells;
                }
                break;
            }
        } catch (OpenSpoutException) {
            throw self::invalid('The file could not be read. Save it as a CSV file or an Excel workbook (.xlsx), and try again.');
        } finally {
            $reader->close();
        }

        return $table;
    }

    private static function tooManyRows(): ValidationException
    {
        return self::invalid('The file has more than '.number_format(self::MAX_ROWS).' rows.');
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => [$message]]);
    }
}
