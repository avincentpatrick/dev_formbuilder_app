<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

use App\Services\Ocr\OcrText;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * The sheet of correct answers, one row per scan (M136): read from `.xlsx` or `.csv`, its columns matched to questions.
 *
 * ── WHAT A COLUMN MAY BE CALLED ────────────────────────────────────────────────────────────────────────────
 * The template `ocr:bakeoff-layout` writes names each column by its field key, but a person making their own sheet will
 * use the question's words, so a header is matched to a key, then to a key with its punctuation lost (`OcrText::key()`,
 * the stamp comparison), then to the printed label. The scan column is `file` (or `scan`, `image`, `photo`); an optional
 * `condition` column (`clean`, `photo`, `bad`, …) groups the summary.
 *
 * Nothing about a column is guessed: a header that matches no question is listed, and so is a question with no column,
 * so the report says what it did NOT score as plainly as what it did.
 *
 * A row whose scan cell starts with `#` is a note, not an answer — the template's second row explains each column.
 */
final class OcrAnswerSheet
{
    private const array SCAN_HEADERS = ['file', 'filename', 'file name', 'scan', 'image', 'photo', 'sheet'];

    private const array CONDITION_HEADERS = ['condition', 'quality'];

    /**
     * @param  list<array{row: int, scan: string, condition: string|null, cells: array<string, mixed>}>  $rows  cells by field key
     * @param  array<string, string>  $columns  field key => the header it was read from
     * @param  list<string>  $unknownHeaders
     */
    private function __construct(
        public readonly array $rows,
        public readonly array $columns,
        public readonly array $unknownHeaders,
    ) {}

    /**
     * @param  list<OcrBakeoffQuestion>  $questions  every question any version of the form prints
     */
    public static function read(string $path, array $questions): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("No answer sheet at {$path}.");
        }

        $table = self::table($path);
        if ($table === []) {
            throw new InvalidArgumentException("{$path} is empty.");
        }

        $headers = array_map(static fn (mixed $h): string => trim(ltrim(is_scalar($h) ? (string) $h : '', "\u{FEFF}")), array_shift($table));

        $scanAt = null;
        $conditionAt = null;
        $columns = [];
        $at = [];
        $unknown = [];
        foreach ($headers as $i => $header) {
            if ($header === '') {
                continue;
            }
            $normal = OcrText::normal($header);
            if ($scanAt === null && in_array($normal, self::SCAN_HEADERS, true)) {
                $scanAt = $i;

                continue;
            }
            if ($conditionAt === null && in_array($normal, self::CONDITION_HEADERS, true)) {
                $conditionAt = $i;

                continue;
            }
            $key = self::keyFor($header, $questions);
            if ($key === null || isset($columns[$key])) {
                $unknown[] = $header;

                continue;
            }
            $columns[$key] = $header;
            $at[$key] = $i;
        }

        if ($scanAt === null) {
            throw new InvalidArgumentException("{$path} has no `file` column naming each scan.");
        }

        $rows = [];
        foreach ($table as $n => $cells) {
            $scan = $cells[$scanAt] ?? null;
            $scan = is_scalar($scan) ? trim((string) $scan) : '';
            if ($scan === '' || str_starts_with($scan, '#')) {
                continue;
            }
            $condition = $conditionAt === null ? null : ($cells[$conditionAt] ?? null);
            $byKey = [];
            foreach ($at as $key => $i) {
                $byKey[$key] = $cells[$i] ?? null;
            }
            $rows[] = [
                'row' => $n + 2, // the header is row 1, and people count from 1
                'scan' => $scan,
                'condition' => is_scalar($condition) && trim((string) $condition) !== '' ? trim((string) $condition) : null,
                'cells' => $byKey,
            ];
        }

        return new self($rows, $columns, $unknown);
    }

    /**
     * The answer row for a scan, matched by name with case and extension ignored.
     *
     * @return array{row: int, scan: string, condition: string|null, cells: array<string, mixed>}|null
     */
    public function rowFor(string $scanName): ?array
    {
        $want = self::stem($scanName);
        foreach ($this->rows as $row) {
            if (self::stem($row['scan']) === $want) {
                return $row;
            }
        }

        return null;
    }

    public static function stem(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $dot = strrpos($base, '.');

        return mb_strtolower($dot === false || $dot === 0 ? $base : substr($base, 0, $dot));
    }

    /** @param  list<OcrBakeoffQuestion>  $questions */
    private static function keyFor(string $header, array $questions): ?string
    {
        foreach ($questions as $question) {
            if (strtolower($header) === strtolower($question->key)) {
                return $question->key;
            }
        }
        foreach ($questions as $question) {
            if (OcrText::key($header) !== '' && OcrText::key($header) === OcrText::key($question->key)) {
                return $question->key;
            }
        }
        foreach ($questions as $question) {
            if (OcrText::normal($header) !== '' && OcrText::normal($header) === OcrText::normal($question->label)) {
                return $question->key;
            }
        }

        return null;
    }

    /**
     * Every row of the first sheet as a list of raw cells — strings from a CSV, and from a workbook whatever the cell
     * holds (a date typed into Excel arrives as a date, which is exact).
     *
     * @return list<list<mixed>>
     */
    private static function table(string $path): array
    {
        $isCsv = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv';

        // Empty rows are kept so a row number in the report is the one the spreadsheet shows.
        if ($isCsv) {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = self::delimiter($path);
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new CsvReader($options);
        } else {
            $options = new XlsxOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new XlsxReader($options);
        }

        $reader->open($path);
        $table = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $table[] = $row->toArray();
                }
                break; // the first sheet only
            }
        } finally {
            $reader->close();
        }

        return $table;
    }

    /** A spreadsheet saved as CSV in some regions separates with a semicolon; read the header line to tell. */
    private static function delimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $first = $handle === false ? '' : (string) fgets($handle);
        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }
}
