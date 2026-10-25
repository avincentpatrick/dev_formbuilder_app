<?php

declare(strict_types=1);

use App\Console\Commands\BuildPsgcCommand;
use App\Services\Psgc\PsgcDataFile;
use Tests\Support\PsgcFixture;

/*
|--------------------------------------------------------------------------
| M186 (`R-70c7673a`, `D131`) — `psgc:build` turns the PSA's workbook into the committed places
|--------------------------------------------------------------------------
| Read from a workbook written in the PSA's shape (`PsgcFixture`, measured on the PSA's 2Q and 3Q 2026 files): the
| places on the sheet named PSGC from column B, a code beginning 0 stored as a number, a place with no level, a footnote
| under the places, the National Summary as a table. The four levels come out with every parent one level up, and the
| build never writes unless the sheet's counts equal the summary — exit 1 when they differ, exit 2 when nothing can be
| compared.
|
| ⚠️ Helpers are prefixed `psgcBuild*`: Pest loads every test file into one process.
*/

function psgcBuildRun(string $workbook, string $out, array $extra = []): int
{
    return test()->artisan('psgc:build', [
        'workbook' => $workbook,
        '--publication' => '2026Q3',
        '--released' => '2026-10-12',
        '--out' => $out,
        ...$extra,
    ])->run();
}

function psgcBuildWorkbook(mixed ...$arguments): string
{
    $path = PsgcFixture::directory().DIRECTORY_SEPARATOR.'PSGC-3Q-2026-Publication-Datafile.xlsx';
    PsgcFixture::workbook($path, ...$arguments);

    return $path;
}

/** @return array<string, array{level: string, code: string, name: string, parent_code: ?string}> keyed level|code */
function psgcBuildRows(string $out): array
{
    $rows = [];
    foreach ((new PsgcDataFile($out.DIRECTORY_SEPARATOR.'manifest.json'))->load()['rows'] as $row) {
        $rows[$row['level'].'|'.$row['code']] = $row;
    }

    return $rows;
}

it('builds the four levels, each place one level under its parent', function (): void {
    $out = PsgcFixture::directory();

    expect(psgcBuildRun(psgcBuildWorkbook(), $out))->toBe(0);

    $rows = psgcBuildRows($out);
    $manifest = json_decode((string) file_get_contents($out.'/manifest.json'), true);

    expect($manifest['publication'])->toBe('2026Q3')
        ->and($manifest['label'])->toBe('3Q 2026')
        ->and($manifest['credit'])->toBe('Philippine Standard Geographic Code (PSGC), 3Q 2026. Philippine Statistics Authority.')
        ->and($manifest['source'])->toBe('PSGC-3Q-2026-Publication-Datafile.xlsx')
        ->and($manifest['counts'])->toBe(['region' => 3, 'province' => 4, 'city' => 5, 'barangay' => 7])
        // A code stored as a number got its zero back.
        ->and($rows['region|0100000000']['name'])->toBe('Region I (Ilocos Region)')
        ->and($rows['city|0102801000']['parent_code'])->toBe('0102800000')
        ->and($rows['barangay|0102801001']['parent_code'])->toBe('0102801000');
});

it('makes a city outside any province its own one choice at the city level, and leaves an empty district out', function (): void {
    $out = PsgcFixture::directory();
    psgcBuildRun(psgcBuildWorkbook(), $out);
    $rows = psgcBuildRows($out);

    expect($rows['province|1380600000'])->toBe(['level' => 'province', 'code' => '1380600000', 'name' => 'City of Manila', 'parent_code' => '1300000000'])
        ->and($rows['city|1380600000'])->toBe(['level' => 'city', 'code' => '1380600000', 'name' => 'City of Manila', 'parent_code' => '1380600000'])
        ->and($rows['province|1381701000']['parent_code'])->toBe('1300000000')
        ->and($rows['city|1381701000']['parent_code'])->toBe('1381701000')
        // The district no city is coded under is not offered empty.
        ->and($rows)->not->toHaveKey('province|1380000000')
        // The special geographic area is a province-level place.
        ->and($rows['province|1999900000']['parent_code'])->toBe('1900000000')
        ->and($rows['city|1999908000']['parent_code'])->toBe('1999900000');
});

it('moves a sub-municipality’s barangays to its city, and offers no sub-municipality', function (): void {
    $out = PsgcFixture::directory();
    psgcBuildRun(psgcBuildWorkbook(), $out);
    $rows = psgcBuildRows($out);

    expect($rows['barangay|1380601001']['parent_code'])->toBe('1380600000')
        ->and($rows['barangay|1380601010']['parent_code'])->toBe('1380600000')
        ->and(array_filter(array_keys($rows), static fn (string $key): bool => str_ends_with($key, '|1380601000')))->toBe([]);
});

it('writes the same bytes from the same workbook, LF line endings, so a rebuild is no diff', function (): void {
    $first = PsgcFixture::directory();
    $second = PsgcFixture::directory();
    $workbook = psgcBuildWorkbook();
    psgcBuildRun($workbook, $first);
    psgcBuildRun($workbook, $second);

    $csv = (string) file_get_contents($first.'/places.csv');

    expect($csv)->toBe((string) file_get_contents($second.'/places.csv'))
        ->and($csv)->not->toContain("\r")
        ->and(strtok($csv, "\n"))->toBe('level,code,name,parent_code');
});

it('refuses, writing nothing, when a count differs from the summary sheet', function (): void {
    $out = PsgcFixture::directory();

    $this->artisan('psgc:build', [
        'workbook' => psgcBuildWorkbook(null, [...PsgcFixture::SUMMARY, 'Barangays' => 8]),
        '--publication' => '2026Q3', '--released' => '2026-10-12', '--out' => $out,
    ])
        ->expectsOutputToContain('the sheet holds 7 barangays, and the summary sheet says 8')
        ->assertExitCode(1);

    expect(is_file($out.'/manifest.json'))->toBeFalse();
});

it('refuses with a status of its own when the totals cannot be read, and builds once they are typed', function (): void {
    $out = PsgcFixture::directory();
    $workbook = psgcBuildWorkbook(null, null);

    expect(psgcBuildRun($workbook, $out))->toBe(BuildPsgcCommand::CANNOT_MEASURE)
        ->and(is_file($out.'/manifest.json'))->toBeFalse()
        ->and(psgcBuildRun($workbook, $out, ['--expect' => 'regions=3,provinces=1,cities=2,municipalities=3,barangays=7']))->toBe(0)
        ->and(is_file($out.'/manifest.json'))->toBeTrue();
});

it('refuses a typed total that disagrees with the summary sheet', function (): void {
    $out = PsgcFixture::directory();

    expect(psgcBuildRun(psgcBuildWorkbook(), $out, ['--expect' => 'barangays=9']))->toBe(1)
        ->and(is_file($out.'/manifest.json'))->toBeFalse();
});

it('refuses a level it does not know, and a workbook with no PSGC sheet, by name', function (): void {
    $out = PsgcFixture::directory();
    $places = [...PsgcFixture::places(), ['code' => '1999908002', 'name' => 'Somewhere', 'level' => 'Purok']];

    $this->artisan('psgc:build', [
        'workbook' => psgcBuildWorkbook($places, [...PsgcFixture::SUMMARY]),
        '--publication' => '2026Q3', '--released' => '2026-10-12', '--out' => $out,
    ])->expectsOutputToContain('has the level “Purok”')->assertExitCode(1);

    $this->artisan('psgc:build', [
        'workbook' => psgcBuildWorkbook(null, PsgcFixture::SUMMARY, 'Places'),
        '--publication' => '2026Q3', '--released' => '2026-10-12', '--out' => $out,
    ])->expectsOutputToContain('no sheet named PSGC')->assertExitCode(1);

    expect(is_file($out.'/manifest.json'))->toBeFalse();
});

it('reads the National Summary as the PSA’s table: a region row’s first number is not the number of regions', function (): void {
    $out = PsgcFixture::directory();

    // The first coded region row prints that region's province count (1) before anything else; the regions are the
    // coded rows (3), and the totals are the PHILIPPINES row's. The provinces' summary sheet is not read.
    $this->artisan('psgc:build', [
        'workbook' => psgcBuildWorkbook(), '--publication' => '2026Q3', '--released' => '2026-10-12', '--out' => $out,
    ])
        ->expectsOutputToContain('regions        3  = the summary sheet')
        ->expectsOutputToContain('barangays      7  = the summary sheet')
        ->assertExitCode(0);
});

it('cannot measure a summary sheet in a layout it does not know, and says so with a status of its own', function (): void {
    $out = PsgcFixture::directory();

    expect(psgcBuildRun(psgcBuildWorkbook(summaryLayout: 'lines'), $out))->toBe(BuildPsgcCommand::CANNOT_MEASURE)
        ->and(is_file($out.'/manifest.json'))->toBeFalse();
});

it('puts a place with no level on the province level when its code is a province’s, and refuses one anywhere else', function (): void {
    $out = PsgcFixture::directory();
    // `D166` B: the PSA's "City of Isabela (Not a Province)" is offered as it is written, above the City of Isabela.
    $isabela = [
        ['code' => '0900000000', 'name' => 'Region IX (Zamboanga Peninsula)', 'level' => 'Reg'],
        ['code' => '0990100000', 'name' => 'City of Isabela (Not a Province)', 'level' => ''],
        ['code' => '0990101000', 'name' => 'City of Isabela', 'level' => 'City'],
        ['code' => '0990101001', 'name' => 'Aguada', 'level' => 'Bgy'],
    ];
    $summary = ['Regions' => 4, 'Provinces' => 1, 'Cities' => 3, 'Municipalities' => 3, 'Barangays' => 8];

    expect(psgcBuildRun(psgcBuildWorkbook([...PsgcFixture::places(), ...$isabela], $summary), $out))->toBe(0);
    $rows = psgcBuildRows($out);
    expect($rows['province|0990100000'])->toBe(['level' => 'province', 'code' => '0990100000', 'name' => 'City of Isabela (Not a Province)', 'parent_code' => '0900000000'])
        ->and($rows['city|0990101000']['parent_code'])->toBe('0990100000');

    $stray = [...PsgcFixture::places(), ['code' => '1999908002', 'name' => 'Nowhere', 'level' => '']];
    $this->artisan('psgc:build', [
        'workbook' => psgcBuildWorkbook($stray),
        '--publication' => '2026Q3', '--released' => '2026-10-12', '--out' => PsgcFixture::directory(),
    ])->expectsOutputToContain('Place 1999908002 (Nowhere) has no level')->assertExitCode(1);
});

it('skips a footnote under the places, and takes its mark off the name it marks — only a mark a footnote declares', function (): void {
    $out = PsgcFixture::directory();

    $this->artisan('psgc:build', [
        'workbook' => psgcBuildWorkbook(), '--publication' => '2026Q3', '--released' => '2026-10-12', '--out' => $out,
    ])
        ->expectsOutputToContain('Not a place, skipped: a/ As noted by the PSA Board')
        ->expectsOutputToContain('Footnote mark a/ taken off 0102805000: “City of Batac”')
        ->assertExitCode(0);
    expect(psgcBuildRows($out)['city|0102805000']['name'])->toBe('City of Batac');

    // With no footnote declaring it, a name ending like a mark is the place's name, kept as written.
    $marked = array_map(static fn (array $p): array => $p['code'] === PsgcFixture::MARKED ? [...$p, 'name' => 'City of Batac b/'] : $p, PsgcFixture::places());
    $kept = PsgcFixture::directory();
    expect(psgcBuildRun(psgcBuildWorkbook($marked, footnote: null), $kept))->toBe(0)
        ->and(psgcBuildRows($kept)['city|0102805000']['name'])->toBe('City of Batac b/');
});

it('refuses a quarter or a release day it cannot read', function (): void {
    $workbook = psgcBuildWorkbook();
    $out = PsgcFixture::directory();

    expect(psgcBuildRun($workbook, $out, ['--publication' => 'Q3 2026']))->toBe(1)
        ->and(psgcBuildRun($workbook, $out, ['--released' => '12 Oct 2026']))->toBe(1);
});
