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
 * geographic area with its municipality and barangay.
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
            ['code' => '1999900000', 'name' => 'Special Geographic Area', 'level' => 'SGU'],
            ['code' => '1999908000', 'name' => 'Tugunan', 'level' => 'Mun'],
            ['code' => '1999908001', 'name' => 'Balong', 'level' => 'Bgy'],
        ];
    }

    /** The PSA's summary for {@see places()}: what its National Summary sheet would print. */
    public const array SUMMARY = ['Regions' => 3, 'Provinces' => 1, 'Cities' => 2, 'Municipalities' => 3, 'Barangays' => 7];

    /**
     * Write the fixture as the PSA's workbook: a cover sheet, the places sheet with a title row above its header, and a
     * summary sheet — or the summary left out, or another sheet name, for the refusals.
     *
     * @param  list<array{code: string, name: string, level: string}>|null  $places
     * @param  array<string, int>|null  $summary  null leaves the summary sheet out
     */
    public static function workbook(string $path, ?array $places = null, ?array $summary = self::SUMMARY, string $sheet = 'PSGC'): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Read Me');
        $writer->addRow(Row::fromValues(['Philippine Standard Geographic Code (PSGC) — a test fixture']));

        $writer->addNewSheetAndMakeItCurrent()->setName($sheet);
        $writer->addRow(Row::fromValues(['PSGC as of 30 September 2026']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['10-digit PSGC', 'Name', 'Correspondence Code', 'Geographic Level', 'Old names']));
        foreach ($places ?? self::places() as $place) {
            // A code beginning 0 written as a number, as Excel stores one typed into a General cell.
            $code = str_starts_with($place['code'], '0') ? (int) $place['code'] : $place['code'];
            $writer->addRow(Row::fromValues([$code, $place['name'], '', $place['level'], '']));
        }

        if ($summary !== null) {
            $writer->addNewSheetAndMakeItCurrent()->setName('National Summary');
            $writer->addRow(Row::fromValues(['NATIONAL SUMMARY', '', 'As of 30 September 2026']));
            foreach ($summary as $words => $total) {
                $writer->addRow(Row::fromValues([$words, '', number_format($total)]));
            }
        }

        $writer->close();
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
