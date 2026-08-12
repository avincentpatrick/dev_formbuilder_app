<?php

declare(strict_types=1);

use App\Support\Branding\BrandRampGenerator;

/**
 * The drift guard over {@see BrandRampGenerator}'s GROUNDS constant (JR1).
 *
 * ── WHY THIS DID NOT EXIST BEFORE, AND WHAT IT WOULD HAVE CAUGHT ──────────────────────────────────
 * The generator measures every tenant's derived ramp against six literal hexes, and four of them are
 * copies of neutral primitives. Nothing tied the copy to the source. JR1 moved the neutral ramp — which
 * is an ordinary, sanctioned thing for a re-skin to do — and the failure mode was silent: the engine
 * would have kept certifying "6.4:1 against your dark canvas" for a canvas the application had stopped
 * painting, and every tenant's stored `measurements` array would have been a confident number about a
 * colour that no longer existed anywhere in the product. No test would have failed. Nothing renders the
 * grounds, so no screenshot would have looked wrong.
 *
 * That is the SECOND instance of this exact shape in the branding stack — `BrandPalette::PRODUCT` was
 * the first, and its own docblock records that a second copy guarded only by a comment is how
 * `https://acme/invitations/` shipped in every invitation email for two increments. Finding the same
 * class twice is the argument for testing the class rather than the instance.
 *
 * ── HOW IT RESOLVES ───────────────────────────────────────────────────────────────────────────────
 * Light is read from `tokens/{semantic,primitive}.json`. Dark is read from `theme-overrides.css`,
 * because primitive.json holds the LIGHT column only — reading the dark surface out of primitive.json
 * would silently measure `#FFFFFF` and pass everything, which is the hazard, not the guard. The dark
 * semantic re-point is FOLLOWED rather than assumed: `--mds-color-bg-surface` is `neutral-0` in light
 * and re-pointed to `neutral-100` in dark, so the test parses that re-point instead of hard-coding it.
 *
 * When a value below fails, the design system moved and the generator did not. Update GROUNDS, bump
 * {@see BrandRampGenerator::VERSION}, and write the re-derivation migration. Do not relax the test.
 */
function designSystemDir(): string
{
    // Plain paths, not base_path(): this runs at Pest COLLECTION time, before the container exists —
    // the BrandRampParityTest / BrandPaletteTokenParityTest precedent in this same directory.
    return dirname(__DIR__, 3).'/packages/design-system';
}

/**
 * @return array<string, mixed>
 */
function designSystemJson(string $file): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (string) file_get_contents(designSystemDir().'/tokens/'.$file),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    return $decoded;
}

/**
 * The generator's GROUNDS, read by reflection.
 *
 * Deliberately not promoted to a public accessor: the grounds are an internal of the measurement, not
 * an API, and the BrandPaletteTokenParityTest precedent reads `ON_PRIMARY` the same way for the same
 * reason. A test is not a reason to widen a class's surface.
 *
 * @return array<string, array<string, string>>
 */
function generatorGrounds(): array
{
    /** @var array<string, array<string, string>> $grounds */
    $grounds = (new ReflectionClassConstant(BrandRampGenerator::class, 'GROUNDS'))->getValue();

    return $grounds;
}

/**
 * The explicit `:root[data-theme-mode='dark']` declaration block, as `property => value`.
 *
 * @return array<string, string>
 */
function darkThemeBlock(): array
{
    $css = (string) file_get_contents(designSystemDir().'/src/theme/theme-overrides.css');

    preg_match("/:root\\[data-theme-mode='dark'\\]\\s*\\{([^}]*)\\}/", $css, $match);
    expect($match)->not->toBeEmpty('no :root[data-theme-mode=\'dark\'] block found');

    // Strip the block's comments before splitting, so prose containing a colon is not read as a
    // declaration — the same normalisation theme-overrides.test.ts performs on the TypeScript side.
    $declarations = explode(';', (string) preg_replace('#/\*[\s\S]*?\*/#', '', $match[1]));

    $block = [];

    foreach ($declarations as $declaration) {
        if (! str_contains($declaration, ':')) {
            continue;
        }

        [$property, $value] = explode(':', $declaration, 2);
        $block[trim($property)] = trim($value);
    }

    return $block;
}

/** `{neutral.50}` -> `50`. The semantic layer aliases; this reads which STEP it aliases. */
function neutralStepFor(string $semanticPath): string
{
    /** @var array<string, mixed> $node */
    $node = designSystemJson('semantic.json');

    foreach (explode('.', $semanticPath) as $segment) {
        /** @var array<string, mixed> $node */
        $node = $node[$segment];
    }

    /** @var string $alias */
    $alias = $node['value'];

    expect($alias)->toMatch('/^\{neutral\.[a-z0-9]+\}$/');

    return explode('.', trim($alias, '{}'))[1];
}

it('pins the LIGHT grounds to the token sources that define them', function (string $key, string $step): void {
    /** @var array{value: string} $primitive */
    $primitive = designSystemJson('primitive.json')['neutral'][$step];

    expect(strtoupper(generatorGrounds()['light'][$key]))->toBe(strtoupper($primitive['value']));
})->with([
    // `surface` and `canvas` follow their semantic aliases; `ink` is neutral-900 by the rule stated in
    // the generator's own docblock ("`ink` is `neutral-900`"), which is why it is named directly.
    'surface' => ['surface', neutralStepFor('color.bg.surface')],
    'canvas' => ['canvas', neutralStepFor('color.bg.canvas')],
    'ink' => ['ink', '900'],
]);

it('pins the DARK grounds to the dark flip in theme-overrides.css', function (string $key, string $step): void {
    $block = darkThemeBlock();
    $property = '--mds-neutral-'.$step;

    // `toHaveKey` takes a VALUE as its second argument, not a failure message — passing one there
    // asserts the key holds that message, which is how the first version of this line failed.
    expect(array_key_exists($property, $block))
        ->toBeTrue($property.' is not re-pointed in the dark block');

    expect(strtoupper(generatorGrounds()['dark'][$key]))->toBe(strtoupper($block[$property]));
})->with(function (): array {
    $block = darkThemeBlock();

    // Follow the dark re-point rather than assuming it: `--mds-color-bg-surface` is neutral-0 in light
    // and neutral-100 in dark, and a future theme edit could move it again.
    $surfaceStep = preg_match('/var\(--mds-neutral-([a-z0-9]+)\)/', $block['--mds-color-bg-surface'] ?? '', $m) === 1
        ? $m[1]
        : neutralStepFor('color.bg.surface');

    return [
        'surface' => ['surface', $surfaceStep],
        // bg-canvas is NOT re-pointed in dark — it keeps its semantic step and rides the neutral flip.
        'canvas' => ['canvas', neutralStepFor('color.bg.canvas')],
        'ink' => ['ink', '900'],
    ];
});

it('measures against exactly three grounds per theme, and only the two themes that exist', function (): void {
    // Anti-vacuity: a renamed key would otherwise make both data providers assert over nothing.
    expect(array_keys(generatorGrounds()))->toBe(['light', 'dark']);

    foreach (generatorGrounds() as $theme => $grounds) {
        expect(array_keys($grounds))->toBe(['surface', 'canvas', 'ink'], $theme);
    }
});
