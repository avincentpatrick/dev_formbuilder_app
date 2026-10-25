<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Psgc\PsgcDataFile;
use App\Services\Psgc\PsgcTiers;
use Illuminate\Support\Facades\Artisan;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * A small Philippine places fixture shaped like the PSA's workbook — M186 (`R-70c7673a`).
 *
 * Every shape the build handles is here once: a region whose code starts 01 (written as a NUMBER, so its leading zero is
 * lost as Excel loses it), a province, a city and a municipality under it, a barangay named like its municipality, the
 * National Capital Region with a district no city is coded under, the City of Manila outside any province with a
 * sub-municipality whose barangays sort "1, 2, 10", Pateros (a municipality outside any province), and the special
 * geographic area — a place with NO level, as the PSA codes it — with its municipality and barangay.
 *
 * ── THE WORKBOOK, AS MEASURED ON THE PSA'S OWN FILES (2Q and 3Q 2026, 2026-10-26) ──────────────────────────────
 * The fixture was first written from third-party descriptions; the real files differed, and the workbook below now
 * follows them: sheets Metadata · National Summary · Prov Sum · PSGC; the places sheet's header on row 1 with the table
 * starting in column B (3Q 2026); a footnote row under the places with its mark in column A and its text in the CODE
 * column, and the mark pasted onto the name it notes ("City of Lucenaa/"); the National Summary a TABLE — a header row
 * `CODE · · REGION · PROV. · CITIES · MUN. · BGY.`, a `PHILIPPINES` totals row, one coded row per region — whose first
 * region row's first number is that region's province count, never the number of regions; and a Prov Sum sheet whose
 * totals are not the country's.
 */
final class PsgcFixture
{
    /** @return list<array{code: string, name: string, level: string}> */
    public static function places(): array
    {
        return [
            ['code' => '0100000000', 'name' => 'Region I (Ilocos Region)', 'level' => 'Reg'],
            ['code' => '0102800000', 'name' => 'Ilocos Norte', 'level' => 'Prov'],
            ['code' => '0102801000', 'name' => 'Adams', 'level' => 'Mun'],
            ['code' => '0102801001', 'name' => 'Adams', 'level' => 'Bgy'],
            ['code' => '0102805000', 'name' => 'City of Batac', 'level' => 'City'],
            ['code' => '0102805001', 'name' => 'Aglipay', 'level' => 'Bgy'],
            ['code' => '1300000000', 'name' => 'National Capital Region (NCR)', 'level' => 'Reg'],
            ['code' => '1380000000', 'name' => 'NCR, First District', 'level' => 'Dist'],
            ['code' => '1380600000', 'name' => 'City of Manila', 'level' => 'City'],
            ['code' => '1380601000', 'name' => 'Tondo I/II', 'level' => 'SubMun'],
            ['code' => '1380601010', 'name' => 'Barangay 10', 'level' => 'Bgy'],
            ['code' => '1380601001', 'name' => 'Barangay 1', 'level' => 'Bgy'],
            ['code' => '1380601002', 'name' => 'Barangay 2', 'level' => 'Bgy'],
            ['code' => '1381701000', 'name' => 'Pateros', 'level' => 'Mun'],
            ['code' => '1381701001', 'name' => 'Aguho', 'level' => 'Bgy'],
            ['code' => '1900000000', 'name' => 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)', 'level' => 'Reg'],
            ['code' => '1999900000', 'name' => 'Special Geographic Area', 'level' => ''],
            ['code' => '1999908000', 'name' => 'Tugunan', 'level' => 'Mun'],
            ['code' => '1999908001', 'name' => 'Balong', 'level' => 'Bgy'],
        ];
    }

    /** The PSA's summary for {@see places()}: what its National Summary sheet would print. */
    public const array SUMMARY = ['Regions' => 3, 'Provinces' => 1, 'Cities' => 2, 'Municipalities' => 3, 'Barangays' => 7];

    /** The place whose name the workbook's footnote marks, as the 3Q 2026 file marks "City of Lucena". */
    public const string MARKED = '0102805000';

    /**
     * Write the fixture as the PSA's workbook (measured — see the class docblock): Metadata, the National Summary table,
     * a Prov Sum sheet, and the places — or the summary left out or in another layout, another sheet name, or no
     * footnote, for the refusals.
     *
     * @param  list<array{code: string, name: string, level: string}>|null  $places
     * @param  array<string, int>|null  $summary  null leaves the summary sheet out
     * @param  'table'|'lines'  $summaryLayout  'lines' is the layout first guessed, which the PSA does not use
     * @param  string|null  $footnote  the mark the footnote declares and pastes onto {@see MARKED}'s name; null, no footnote
     */
    public static function workbook(
        string $path,
        ?array $places = null,
        ?array $summary = self::SUMMARY,
        string $sheet = 'PSGC',
        string $summaryLayout = 'table',
        ?string $footnote = 'a/',
    ): void {
        $places ??= self::places();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Metadata');
        $writer->addRow(Row::fromValues(['Title:', 'Philippine Standard Geographic Code (PSGC) — a test fixture']));
        $writer->addRow(Row::fromValues(['Publication date:', '30 September 2026']));

        if ($summary !== null) {
            $writer->addNewSheetAndMakeItCurrent()->setName('National Summary');
            $summaryLayout === 'lines' ? self::summaryLines($writer, $summary) : self::summaryTable($writer, $summary, $places);
        }

        // The provinces' summary: its PHILIPPINES row is not the country's count of anything the build compares.
        $writer->addNewSheetAndMakeItCurrent()->setName('Prov Sum');
        $writer->addRow(Row::fromValues(['CODE', 'NAME', 'PROV.', 'CITIES', 'MUN.', 'BGY.']));
        $writer->addRow(Row::fromValues(['', 'PHILIPPINES', '81', '146', '1488', '42046']));

        $writer->addNewSheetAndMakeItCurrent()->setName($sheet);
        // The 3Q 2026 file starts its table in column B; column A holds only a footnote's mark.
        $writer->addRow(Row::fromValues(['', '10-digit PSGC', 'Name', 'Correspondence Code', 'Geographic Level', 'Old names']));
        foreach ($places as $place) {
            // A code beginning 0 written as a number, as Excel stores one typed into a General cell.
            $code = str_starts_with($place['code'], '0') ? (int) $place['code'] : $place['code'];
            $name = $footnote !== null && $place['code'] === self::MARKED ? $place['name'].$footnote : $place['name'];
            $writer->addRow(Row::fromValues(['', $code, $name, '', $place['level'], '']));
        }
        if ($footnote !== null) {
            $writer->addRow(Row::fromValues([$footnote, 'As noted by the PSA Board, the City of Batac is treated as a component city for statistical purposes.']));
        }

        $writer->close();
    }

    /**
     * The National Summary as the PSA prints it: a header row, the PHILIPPINES totals, one coded row per region (its
     * first number that region's province count), and a footnote.
     *
     * @param  array<string, int>  $summary
     * @param  list<array{code: string, name: string, level: string}>  $places
     */
    private static function summaryTable(Writer $writer, array $summary, array $places): void
    {
        $writer->addRow(Row::fromValues(['', '', ' REPUBLIC OF THE PHILIPPINES']));
        $writer->addRow(Row::fromValues(['NATIONAL SUMMARY']));
        $writer->addRow(Row::fromValues(['As of 30 September 2026']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['CODE', '', 'REGION', 'PROV.', 'CITIES', 'MUN.', 'BGY.', "POPULATION\n(2024 POPCEN)"]));
        $writer->addRow(Row::fromValues(['', '', 'PHILIPPINES', ...array_map(
            static fn (string $words): string => (string) ($summary[$words] ?? 0),
            ['Provinces', 'Cities', 'Municipalities', 'Barangays'],
        ), '1000', 'a']));

        $regions = array_values(array_filter($places, static fn (array $place): bool => $place['level'] === 'Reg'));
        for ($i = 0; $i < ($summary['Regions'] ?? 0); $i++) {
            $region = $regions[$i] ?? ['code' => sprintf('%02d00000000', 20 + $i), 'name' => 'Region '.($i + 1)];
            $writer->addRow(Row::fromValues([$region['code'], '', $region['name'], '1', '1', '1', '1', '100']));
        }
        $writer->addRow(Row::fromValues(['a', 'Population counts for the regions do not add up to the national total.']));
    }

    /**
     * The layout first guessed from third-party descriptions — a level's words, then its total — which the PSA does not
     * use; the build must not read it as one.
     *
     * @param  array<string, int>  $summary
     */
    private static function summaryLines(Writer $writer, array $summary): void
    {
        $writer->addRow(Row::fromValues(['NATIONAL SUMMARY', '', 'As of 30 September 2026']));
        foreach ($summary as $words => $total) {
            $writer->addRow(Row::fromValues([$words, '', number_format($total)]));
        }
    }

    /**
     * Load the fixture into `psgc_places` as a deploy would: built, written to a data directory, synced.
     *
     * @param  list<array{code: string, name: string, level: string}>|null  $places
     */
    public static function load(string $publication = '2026Q3', ?array $places = null): string
    {
        $places ??= self::places();
        $directory = self::directory();
        PsgcDataFile::write($directory, PsgcTiers::build($places), [
            'publication' => $publication,
            'released_on' => '2026-10-12',
            'source' => 'fixture.xlsx',
        ], PsgcTiers::psaCounts($places));

        $manifest = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        if (Artisan::call('psgc:sync', ['--file' => $manifest]) !== 0) {
            throw new RuntimeException('psgc:sync failed: '.Artisan::output());
        }

        return $manifest;
    }

    /** A new, empty directory for a data file. */
    public static function directory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'psgc-'.bin2hex(random_bytes(6));
        if (! mkdir($directory) && ! is_dir($directory)) {
            throw new RuntimeException("Could not make {$directory}.");
        }

        return $directory;
    }
}
