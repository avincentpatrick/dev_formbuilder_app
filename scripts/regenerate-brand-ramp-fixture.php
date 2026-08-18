<?php

declare(strict_types=1);

/*
 * Regenerate tests/fixtures/brand-ramp.json from the PHP engine (ADR-0014, H23a1).
 *
 * ── WHY THIS EXISTS, AND WHY RUNNING IT IS ALMOST ALWAYS WRONG ────────────────────────────────────
 * The fixture is the record of what the SHIPPED engine produces, asserted hex-for-hex by both
 * tests/Unit/Branding/BrandRampParityTest.php and packages/design-system/src/theme/__tests__/
 * brand-ramp.test.ts. When a vector fails, regenerating is almost always the wrong fix: a diff means
 * either a deliberate engine change or a bug, and running this script turns the second into the first
 * without anyone noticing.
 *
 * It is legitimate in exactly one situation — a deliberate {@see BrandRampGenerator::VERSION} bump
 * accompanied by a re-derivation migration for stored ramps. JR1 (2026-08-12) is the first: the Vivid
 * re-skin moved the neutral ramp, and the engine's four non-white grounds ARE neutral primitives.
 *
 * It exists at all because the alternative is hand-editing 12 vectors × 12 hexes × 17 measurements,
 * which is both untrustworthy and exactly how a red build gets made green by hand — the failure mode
 * the TypeScript half of the suite re-measures from the stored hexes specifically to catch.
 *
 * ── THE CORPUS LIVES HERE, NOT IN THE JSON ────────────────────────────────────────────────────────
 * `name` and `note` are inputs to this script rather than values read back out of the fixture, so a
 * regeneration cannot silently drop a vector or inherit a stale rationale from the file it overwrites.
 * The fixture is pure output.
 *
 * Usage: php scripts/regenerate-brand-ramp-fixture.php
 */

use App\Support\Branding\BrandRampGenerator;

require __DIR__.'/../vendor/autoload.php';

/**
 * The twelve vectors, each chosen for a numerical property rather than for looking nice.
 *
 * @var list<array{name: string, note: string, input: string}>
 */
$corpus = [
    [
        'name' => 'vivid_product_primary',
        'note' => "The product's own primary. A ramp generated from the design system's own hue must land in the design system's own register. JR1 re-pointed this vector from Blueprint #1C4B72 to Vivid #0E6FE8 for that reason — the vector's job is to be the product's hue, not to be a particular hex.",
        'input' => '#0E6FE8',
    ],
    [
        'name' => 'teal_ratified_accent',
        'note' => 'THE ANCHOR VECTOR. Fed the ratified Teal accent, the engine must re-derive `--mds-accent-teal-600` byte-identically. Its TINT diverged from `--mds-accent-teal-50` in JR1 and that divergence is asserted deliberately — see BrandRampParityTest for why the tint half of the claim was never as robust as the fill half.',
        'input' => '#1B5E5E',
    ],
    [
        'name' => 'achromatic_black',
        'note' => 'C = 0, so `atan2(0, 0)` decides the hue. Both languages define that as 0, which is the only reason the two engines agree here at all.',
        'input' => '#000000',
    ],
    [
        'name' => 'achromatic_white',
        'note' => 'Must produce the SAME ramp as black and mid-grey: lightness is discarded and re-derived, so all three carry identical information.',
        'input' => '#FFFFFF',
    ],
    [
        'name' => 'achromatic_mid_grey',
        'note' => 'The third of the achromatic trio. If any of these three ever diverges, the engine has started reading the input lightness.',
        'input' => '#808080',
    ],
    [
        'name' => 'saturated_yellow_snap',
        'note' => 'THE SNAP VECTOR. C = 0.1629 is reduced to the 0.11 cap, and no lightness at that chroma carries white text at its own lightness — the engine drives it dark and the UI discloses the snap.',
        'input' => '#FFE14D',
    ],
    [
        'name' => 'extreme_chroma_purple',
        'note' => 'C = 0.2660, more than double the cap and far outside sRGB at most lightnesses — exercises the gamut bisection at full stretch.',
        'input' => '#7B2FF7',
    ],
    [
        'name' => 'realistic_brand_red',
        'note' => 'A plausible real brand colour (the "pomegranate" of several palettes), C = 0.1740.',
        'input' => '#C0392B',
    ],
    [
        'name' => 'sea_green_mid_chroma',
        'note' => 'A green hue, to keep the corpus from being blue/red-heavy — greens are where a luminance-based search behaves least like intuition.',
        'input' => '#2E8B57',
    ],
    [
        'name' => 'near_black_slight_hue',
        'note' => 'Near-black WITH a hue, unlike #000000: exercises the sRGB->linear branch below the 0.04045 threshold.',
        'input' => '#0A0B0C',
    ],
    [
        'name' => 'near_white_slight_hue',
        'note' => 'Near-white with a hue. The mirror of the vector above, at the other end of the transfer function.',
        'input' => '#FDFEFF',
    ],
    [
        'name' => 'pure_red_max_chroma',
        'note' => 'Maximum-chroma primary red — the corner of the sRGB gamut, and a hue that sits just where the bisection has least room.',
        'input' => '#FF0000',
    ],
];

$generator = new BrandRampGenerator;

$vectors = array_map(static function (array $vector) use ($generator): array {
    // ⚠️ `toArray()`, NOT the raw `$ramp->measurements` property — and the first version of this script
    // used the property. `toArray()` rounds every ratio to the two decimals §4.1 prints; the property
    // carries the full float. The fixture is the record of what gets STORED, so it must be the rounded
    // payload — and the TypeScript parity suite rounds before comparing, so a full-precision fixture
    // fails all twelve "measures X identically to PHP" cases while every token still matches. That is a
    // confusing failure to read: the engines agree completely and the test says they do not.
    $payload = $generator->generate($vector['input'])->toArray();

    // Key order matches the committed fixture so a regeneration diffs as VALUES changed, never as a
    // reshuffle — a diff nobody can read is a diff nobody reviews.
    return [
        'name' => $vector['name'],
        'note' => $vector['note'],
        'input' => $payload['input'],
        'engine_version' => $payload['engine_version'],
        'tokens' => $payload['tokens'],
        'measurements' => $payload['measurements'],
    ];
}, $corpus);

$path = __DIR__.'/../tests/fixtures/brand-ramp.json';

file_put_contents(
    $path,
    json_encode($vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
);

printf(
    "Wrote %d vectors at engine version %d to %s\n",
    count($vectors),
    BrandRampGenerator::VERSION,
    'tests/fixtures/brand-ramp.json',
);
