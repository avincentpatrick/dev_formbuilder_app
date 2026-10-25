<?php

declare(strict_types=1);

use App\Models\PsgcPlace;
use App\Services\Psgc\PsgcDataFile;
use App\Services\Psgc\PsgcTiers;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\PsgcFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M186 (`R-70c7673a`, `D131`) — `psgc:sync` copies the committed places in, on every deploy
|--------------------------------------------------------------------------
| Idempotent, forward only, and it never deletes: a place a later quarter drops keeps its old quarter, so the next publish
| leaves it out while every version that chose it still reads it. The data is checked whole — its hash and its counts —
| before a row is written.
|
| ⚠️ Helpers are prefixed `psgcSync*`: Pest loads every test file into one process.
*/

function psgcSyncData(string $publication, ?array $places = null): string
{
    $places ??= PsgcFixture::places();
    $directory = PsgcFixture::directory();
    PsgcDataFile::write($directory, PsgcTiers::build($places), [
        'publication' => $publication, 'released_on' => '2026-10-12', 'source' => 'fixture.xlsx',
    ], PsgcTiers::psaCounts($places));

    return $directory.DIRECTORY_SEPARATOR.'manifest.json';
}

it('copies every place in, and copies nothing the second time', function (): void {
    $manifest = psgcSyncData('2026Q3');

    $this->artisan('psgc:sync', ['--file' => $manifest])
        ->expectsOutputToContain('19 added, 0 updated, 0 unchanged; 19 in the table, none removed (3Q 2026)')
        ->assertExitCode(0);

    $before = PsgcPlace::query()->orderBy('id')->get(['id', 'updated_at'])->toArray();

    $this->travel(1)->hours();
    $this->artisan('psgc:sync', ['--file' => $manifest])
        ->expectsOutputToContain('0 added, 0 updated, 19 unchanged')
        ->assertExitCode(0);

    expect(PsgcPlace::query()->orderBy('id')->get(['id', 'updated_at'])->toArray())->toBe($before)
        ->and(PsgcPlace::query()->where('level', 'city')->where('code', '1380600000')->value('parent_code'))->toBe('1380600000')
        ->and(PsgcPlace::query()->where('level', 'region')->whereNotNull('parent_code')->count())->toBe(0);
});

it('moves a later quarter in, keeping every place it dropped under its old quarter', function (): void {
    $this->artisan('psgc:sync', ['--file' => psgcSyncData('2026Q3')])->assertExitCode(0);

    // 4Q 2026: Aglipay is renamed, Balong is recoded away.
    $places = array_values(array_filter(
        array_map(static fn (array $p): array => $p['code'] === '0102805001' ? [...$p, 'name' => 'Aglipay Norte'] : $p, PsgcFixture::places()),
        static fn (array $p): bool => $p['code'] !== '1999908001',
    ));

    $this->artisan('psgc:sync', ['--file' => psgcSyncData('2026Q4', $places)])
        ->expectsOutputToContain('0 added, 18 updated, 0 unchanged; 19 in the table, none removed (4Q 2026)')
        ->assertExitCode(0);

    expect(PsgcPlace::query()->where('code', '0102805001')->value('name'))->toBe('Aglipay Norte')
        ->and(PsgcPlace::query()->where('code', '1999908001')->value('publication'))->toBe('2026Q3')
        ->and(PsgcPlace::query()->where('publication', '2026Q4')->count())->toBe(18);
});

it('refuses a quarter older than the one loaded', function (): void {
    $this->artisan('psgc:sync', ['--file' => psgcSyncData('2026Q4')])->assertExitCode(0);

    $this->artisan('psgc:sync', ['--file' => psgcSyncData('2026Q3')])
        ->expectsOutputToContain('older than the 4Q 2026 already loaded')
        ->assertExitCode(1);

    expect(PsgcPlace::query()->distinct()->pluck('publication')->all())->toBe(['2026Q4']);
});

it('refuses, copying nothing, a places file changed after it was built', function (): void {
    $manifest = psgcSyncData('2026Q3');
    $csv = dirname($manifest).'/places.csv';
    file_put_contents($csv, str_replace('Aglipay', 'Aglipai', (string) file_get_contents($csv)));

    $this->artisan('psgc:sync', ['--file' => $manifest])
        ->expectsOutputToContain('SHA-256 is not the one manifest.json records')
        ->assertExitCode(1);

    expect(PsgcPlace::query()->count())->toBe(0);
});

it('refuses a manifest whose counts the file does not hold', function (): void {
    $manifest = psgcSyncData('2026Q3');
    $decoded = json_decode((string) file_get_contents($manifest), true);
    $decoded['counts']['barangay'] = 8;
    file_put_contents($manifest, json_encode($decoded));

    $this->artisan('psgc:sync', ['--file' => $manifest])
        ->expectsOutputToContain('manifest.json records')
        ->assertExitCode(1);
});

it('copies nothing, and succeeds, while no places are committed — but fails on a file it was told to read', function (): void {
    $committed = database_path('data/psgc/manifest.json');
    if (is_file($committed)) {
        $this->markTestSkipped('The Philippine places are committed; the committed-data test reads them.');
    }

    // Every deploy runs this; before the first quarter is committed it must not take the site down.
    $this->artisan('psgc:sync')->expectsOutputToContain('no Philippine places are committed yet')->assertExitCode(0);
    $this->artisan('psgc:sync', ['--file' => PsgcFixture::directory().'/manifest.json'])->assertExitCode(1);

    expect(PsgcPlace::query()->count())->toBe(0);
});

it('keeps the E2E environment’s places fixture the one PsgcFixture builds', function (): void {
    // `E2eSeeder` syncs `tests/fixtures/psgc/`; regenerate it from `Tests\Support\PsgcFixture` when that changes.
    $committed = (new PsgcDataFile(base_path('tests/fixtures/psgc/manifest.json')))->load();

    expect($committed['rows'])->toBe(PsgcTiers::build(PsgcFixture::places()))
        ->and($committed['manifest']['publication'])->toBe('2026Q3');
});

it('holds the table to its levels, codes and quarters in the database too', function (): void {
    // Each in its own savepoint: a refused insert aborts the transaction around it, and the next would fail for that.
    $insert = static fn (array $row): bool => DB::transaction(static fn (): bool => DB::table('psgc_places')->insert([
        'id' => Uuid::uuid7()->toString(), 'level' => 'region', 'code' => '0100000000', 'name' => 'X', 'parent_code' => null,
        'publication' => '2026Q3', 'created_at' => now(), 'updated_at' => now(), ...$row,
    ]));

    expect($insert([]))->toBeTrue();
    DB::table('psgc_places')->delete();

    expect(fn () => $insert(['level' => 'purok']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['code' => '100000000']))->toThrow(QueryException::class)
        ->and(fn () => $insert(['publication' => '3Q2026']))->toThrow(QueryException::class);
});
