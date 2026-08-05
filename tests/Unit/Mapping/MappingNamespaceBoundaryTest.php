<?php

declare(strict_types=1);

// The forcing device for H16a's central claim (the `PdfFieldRoleTest` / `BrandRampParityTest` source-parsing
// recipe). The H-map's H16a row promises a "drift-detection engine — SHARED w/ OCR linelist", and H19a depends
// on H16a for exactly that reason. A promise like that is worth nothing if the engine can quietly grow a
// dependency on the Sheets adapter between now and whenever the OCR samples arrive: H19a would then find the
// "shared" engine unreusable and write a second one, and nothing would have failed in between.
//
// So the sharing is enforced structurally rather than remembered. This test PARSES the namespace's own source,
// because a hand-maintained list of what the engine may import keeps passing after someone edits the files.

/**
 * A plain path, not `app_path()`: Unit tests do not boot the container (`tests/Pest.php` binds TestCase to
 * `Feature` only), so the framework helper is unavailable here — the `PdfFieldRoleTest` precedent.
 *
 * @return list<string>
 */
function mappingSourceFiles(): array
{
    $files = glob(dirname(__DIR__, 3).'/app/Support/Mapping/*.php');

    return $files === false ? [] : array_values($files);
}

/** @return list<string> the `use X;` targets in one file */
function mappingImportsIn(string $file): array
{
    $source = (string) file_get_contents($file);

    preg_match_all('/^use\s+([^\s;]+)\s*;/m', $source, $matches);

    return array_values($matches[1]);
}

it('has source files to check at all', function (): void {
    // Without this, every assertion below passes vacuously the day the directory is moved or renamed — the
    // "assert the fixture is non-empty before comparing anything derived from it" lesson H21c paid for.
    expect(mappingSourceFiles())->not->toBeEmpty()
        ->and(count(mappingSourceFiles()))->toBeGreaterThanOrEqual(5);
});

it('imports nothing from the connector, OCR, HTTP, database or framework layers', function (): void {
    // Each prefix is here for a specific reason, not as a blanket ban:
    //   Connectors  — the engine must not become Sheets-shaped; that is the entire point.
    //   Ocr         — the symmetric guard, so H19a cannot "share" it by making it OCR-shaped instead.
    //   Http/Client — a drift check is a comparison, never a fetch; the CALLER holds the wire.
    //   Database/Eloquent/Models — the mapping is stored in a jsonb column by its caller, so a model
    //                              dependency here would make the engine untestable without a database and
    //                              would bind it to `connection_subscriptions` specifically.
    $forbidden = [
        'App\\Support\\Connectors',
        'App\\Services\\Connectors',
        'App\\Support\\Ocr',
        'App\\Services\\Ocr',
        'App\\Models',
        'App\\Jobs',
        'Illuminate\\Database',
        'Illuminate\\Http',
        'Illuminate\\Support\\Facades',
    ];

    foreach (mappingSourceFiles() as $file) {
        foreach (mappingImportsIn($file) as $import) {
            foreach ($forbidden as $prefix) {
                expect(str_starts_with($import, $prefix))->toBeFalse(
                    basename($file).' imports '.$import.', which binds the shared mapping engine to one consumer.'
                );
            }
        }
    }
});

it('declares every class in the App\\Support\\Mapping namespace', function (): void {
    // A file that drifted into another namespace would escape the import guard above while still living in the
    // directory — the guard reads files, so the namespace has to be pinned separately.
    foreach (mappingSourceFiles() as $file) {
        expect((string) file_get_contents($file))->toContain('namespace App\Support\Mapping;');
    }
});

it('can be exercised with no Laravel application booted', function (): void {
    // The strongest statement of portability available here, and the one H19a actually cares about: the engine
    // is reachable through plain autoloading with no container, no config, no database and no tenant context.
    // If this ever needs `app()` or `config()` to run, it has stopped being shareable.
    $mapping = App\Support\Mapping\ColumnMapping::author(
        ['Name', 'Age'],
        ['Name' => 'full_name', 'Age' => 'age'],
    );

    $drift = (new App\Support\Mapping\MappingDriftDetector)->compare($mapping, ['Name', 'Age']);

    expect($drift->hasDrifted)->toBeFalse();
});
