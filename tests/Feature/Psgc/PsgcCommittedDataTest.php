<?php

declare(strict_types=1);

use App\Services\Psgc\PsgcDataFile;
use App\Services\Psgc\PsgcTiers;

/*
|--------------------------------------------------------------------------
| M186 (`R-70c7673a`, `D131`) — the committed Philippine places hold together, with no database
|--------------------------------------------------------------------------
| Every deploy copies `database/data/psgc/` in (`psgc:sync`), and a file that fails its own checks leaves the site down —
| so the same checks run here, on every push: the CSV is the bytes `manifest.json` hashed (a line-ending conversion would
| break it), every place has its parent one level up, and the counts are the manifest's.
|
| SKIPPED, VISIBLY, until the first quarter is committed: `psgc:build` needs the PSA's workbook, which only a person can
| download (the site refuses scripts). The skip names what is owed rather than passing on nothing.
*/

beforeEach(function (): void {
    if (! is_file(database_path('data/psgc/manifest.json'))) {
        $this->markTestSkipped('No Philippine places are committed yet: run psgc:build on the PSA workbook (docs/deployment-infrastructure.md §3.2).');
    }
});

it('loads whole: the hash, every parent one level up, the counts', function (): void {
    $data = (new PsgcDataFile)->load();
    $counts = array_fill_keys(PsgcTiers::TIERS, 0);
    foreach ($data['rows'] as $row) {
        $counts[$row['level']]++;
    }

    expect($data['manifest']['counts'])->toBe($counts)
        ->and($data['manifest']['counts']['region'])->toBeGreaterThan(0)
        ->and($data['manifest']['counts']['barangay'])->toBeGreaterThan($data['manifest']['counts']['city']);
});

it('names its quarter, and credits the Philippine Statistics Authority', function (): void {
    $manifest = (new PsgcDataFile)->load()['manifest'];

    expect($manifest['publication'])->toMatch(PsgcDataFile::PUBLICATION)
        ->and($manifest['label'])->toBe(PsgcDataFile::label($manifest['publication']))
        ->and($manifest['credit'])->toBe(PsgcDataFile::credit($manifest['publication']));
});

it('is stored with LF line endings, so the box checks out the bytes the manifest hashed', function (): void {
    expect((string) file_get_contents(database_path('data/psgc/places.csv')))->not->toContain("\r");
});
