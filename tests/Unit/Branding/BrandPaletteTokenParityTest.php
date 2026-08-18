<?php

declare(strict_types=1);

use App\Support\Branding\BrandPalette;
use App\Support\Branding\BrandRamp;
use App\Support\Branding\BrandRampGenerator;

/**
 * The drift guard over {@see BrandPalette::PRODUCT} (H23a4).
 *
 * `BrandPalette::PRODUCT` is a SECOND COPY of six design-system values, and it exists because mail and PDF
 * cannot resolve `var(--mds-…)` (ADR-0014 §D8). A second copy guarded only by a comment is exactly how
 * `https://acme/invitations/` shipped in every invitation email for two increments, so the copy is guarded
 * by this file instead: it parses the design system's own token sources and re-resolves each alias.
 *
 * **It reads `packages/design-system/tokens/*.json`, NOT `packages/design-system/dist/tokens.css`**, and
 * that is not a style preference — `/packages/*​/dist` is gitignored (`.gitignore:21`), so `dist/tokens.css`
 * exists locally from a dev build and is ABSENT in CI, where no asset build runs before the Pest job. A
 * test reading it would pass here and fail there with a misleading "file not found" — the same shape as the
 * `withoutVite()` trap H23a2 paid for.
 *
 * When a value below fails, the design system moved and mail/PDF did not. Update the constant; do not
 * relax the test.
 */

/**
 * @return array{primitive: array<string, array<string, array{value: string}>>, semantic: array<string, mixed>}
 */
function designSystemTokens(): array
{
    // Plain paths, not base_path(): this runs at Pest COLLECTION time, before the container exists —
    // the BrandRampParityTest precedent in this same directory.
    $dir = dirname(__DIR__, 3).'/packages/design-system/tokens';

    return [
        'primitive' => json_decode((string) file_get_contents($dir.'/primitive.json'), true, 512, JSON_THROW_ON_ERROR),
        'semantic' => json_decode((string) file_get_contents($dir.'/semantic.json'), true, 512, JSON_THROW_ON_ERROR),
    ];
}

/**
 * Resolve a Style Dictionary alias like `{primary.600}` down to its literal hex.
 */
function resolveToken(string $alias): string
{
    $tokens = designSystemTokens();

    expect($alias)->toMatch('/^\{[a-z0-9-]+\.[a-z0-9-]+\}$/i');

    [$family, $step] = explode('.', trim($alias, '{}'));

    /** @var array{value: string} $entry */
    $entry = $tokens['primitive'][$family][$step];

    return $entry['value'];
}

/**
 * The six semantic names H23a3 emits as custom properties in `app.blade.php`, mapped to the
 * {@see BrandPalette} key that carries the same role into mail and PDF.
 *
 * @return array<string, list<string>>
 */
function brandPaletteTokenMap(): array
{
    return [
        'bg' => ['color', 'action', 'primary', 'bg'],
        'bg_hover' => ['color', 'action', 'primary', 'bg-hover'],
        'bg_active' => ['color', 'action', 'primary', 'bg-active'],
        'fg' => ['color', 'action', 'primary', 'fg'],
        'tint' => ['color', 'action', 'primary', 'tint'],
        'ring' => ['color', 'focus', 'ring'],
    ];
}

it('pins every product-default hex to the design system token that defines it', function (string $key, array $path): void {
    $node = designSystemTokens()['semantic'];

    foreach ($path as $segment) {
        $node = $node[$segment];
    }

    /** @var array{value: string} $node */
    expect(BrandPalette::PRODUCT[$key])->toBe(resolveToken($node['value']));
})->with(array_map(
    static fn (string $key, array $path): array => [$key, $path],
    array_keys(brandPaletteTokenMap()),
    array_values(brandPaletteTokenMap()),
));

it('carries exactly the six roles the brand ramp generates, and no more', function (): void {
    // Same keys, same order as BrandRamp::ROLES — so a role added to the engine surfaces here as a
    // missing mail/PDF colour rather than as silence.
    expect(array_keys(BrandPalette::PRODUCT))->toBe(BrandRamp::ROLES);
});

it('uses the generator\'s own on-primary ground for text on a brand fill', function (): void {
    // Not a cosmetic duplicate: every bg/bg_hover/bg_active pairing in the stored ramp was measured
    // against THIS value at >= 4.5:1. A template that reached for `fg` instead would be putting the fill
    // colour on the fill, since the light ramp aliases fg to bg.
    //
    // Read by reflection because the generator keeps ON_PRIMARY private — it is an internal of the
    // measurement, not an API. Asserting the literal '#FFFFFF' on both sides would be two copies agreeing
    // with each other rather than with the engine, which is the failure mode this whole file exists to
    // prevent.
    $onPrimary = (new ReflectionClassConstant(BrandRampGenerator::class, 'ON_PRIMARY'))->getValue();

    expect(BrandPalette::ON_BRAND)->toBe($onPrimary);
});
