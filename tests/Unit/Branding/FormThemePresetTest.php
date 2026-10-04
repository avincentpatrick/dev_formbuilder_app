<?php

declare(strict_types=1);

use App\Enums\FormFontPair;
use App\Enums\FormRadiusScale;
use App\Enums\FormThemePreset;
use App\Support\Branding\BrandRamp;
use App\Support\Branding\BrandRampGenerator;

/*
|--------------------------------------------------------------------------
| Form theme presets (M131, `R-6017d6d8`, `D65` = A) — the gates `D65` asked for.
|--------------------------------------------------------------------------
| `D65` = A confines a preset: the brand ramp's six colour roles, plus a named font pair and a radius scale,
| "each added as its own documented property with its own contrast or legibility check". These are those
| checks. ⛔ The one `D65` called the gate worth writing is TOTALITY ACROSS MODES — a preset legible in light
| and unreadable in dark is the failure it buys protection against — so every pairing is measured in BOTH
| themes, on the pinned literals that actually render, with a count so an empty loop cannot pass.
*/

it('pins each preset to exactly what the engine generates from its seed', function (FormThemePreset $preset): void {
    // ADR-0014 §D8: a ramp is stored, never re-derived on read. The literals are that store; this is what
    // turns an engine change into a red test and a reviewed re-pin instead of a silent repaint.
    $generated = (new BrandRampGenerator)->generate($preset->seed());

    expect($preset->tokens())->toBe($generated->tokens);
})->with(FormThemePreset::cases());

it('passes all seventeen pairings in light AND dark, measured on the literals that render', function (FormThemePreset $preset): void {
    $measurements = BrandRampGenerator::measureTokens($preset->tokens());

    expect($measurements)->toHaveCount(count(BrandRampGenerator::pairings()))
        ->and(array_values(array_unique(array_column($measurements, 'theme'))))->toEqualCanonicalizing(BrandRamp::THEMES);

    foreach ($measurements as $m) {
        expect($m['ratio'])->toBeGreaterThanOrEqual($m['min'], "{$preset->value} / {$m['theme']} / {$m['pairing']}");
    }
})->with(FormThemePreset::cases());

it('carries every colour role in both themes', function (FormThemePreset $preset): void {
    foreach (BrandRamp::THEMES as $theme) {
        expect(array_keys($preset->tokens()[$theme]))->toBe(BrandRamp::ROLES);
    }
})->with(FormThemePreset::cases());

it('sets nothing beyond the documented properties', function (FormThemePreset $preset): void {
    expect(array_diff(array_keys($preset->lines()), FormThemePreset::DOCUMENTED_PROPERTIES))->toBe([]);
})->with(FormThemePreset::cases());

it('uses only system font stacks, each ending in a generic family, and never touches the dyslexia alias', function (FormFontPair $pair): void {
    // Only the product's own pair may declare nothing — an empty loop below must never be how a pair passes.
    expect($pair->declarations() === [])->toBe($pair === FormFontPair::System);

    foreach ($pair->declarations() as $property => $stack) {
        // The partial emits these UNESCAPED inside <style>: nothing that could close a declaration, a rule or
        // the element may ever appear in one.
        expect($stack)->toMatch('/^[A-Za-z0-9 ",-]+$/', "{$pair->value}: a character outside the whitelist")
            ->and($property)->not->toBe('--mds-font-family-body')
            ->and($property)->not->toBe('--mds-font-family-mono')
            ->and(stripos($stack, 'OpenDyslexic'))->toBeFalse();

        $families = array_map('trim', explode(',', $stack));
        expect(end($families))->toBeIn(['sans-serif', 'serif'], "{$pair->value}: no generic fallback");

        // Body text stays sans-serif; only headings may take a serif.
        if ($property === '--mds-font-family-body-default') {
            expect(end($families))->toBe('sans-serif');
        }
    }
})->with(FormFontPair::cases());

it('moves only the three upper radius tiers, keeping controls tighter than cards', function (FormRadiusScale $scale): void {
    $d = $scale->declarations();

    expect(array_keys($d))->not->toContain('--mds-radius-sm')
        ->and(array_keys($d))->not->toContain('--mds-radius-none')
        ->and(array_keys($d))->not->toContain('--mds-radius-full');

    if ($d === []) {
        return;
    }

    expect(array_keys($d))->toBe(['--mds-radius-md', '--mds-radius-lg', '--mds-radius-xl']);

    [$md, $lg, $xl] = array_map(static fn (string $v): int => (int) rtrim($v, 'px'), array_values($d));

    // `sm` is 6px and must stay below every control (§2.6: a checkbox must not round toward the radio).
    expect($md)->toBeGreaterThan(6)
        ->and($lg)->toBeGreaterThan($md)
        ->and($xl)->toBeGreaterThanOrEqual($lg)
        ->and($xl - $md)->toBeGreaterThanOrEqual(4);
})->with(FormRadiusScale::cases());

it('reads a stored theme back, and an unknown one as the workspace brand', function (): void {
    expect(FormThemePreset::fromTheme(['preset' => 'graphite']))->toBe(FormThemePreset::Graphite)
        ->and(FormThemePreset::fromTheme(['preset' => 'removed-preset']))->toBeNull()
        ->and(FormThemePreset::fromTheme(['preset' => 7]))->toBeNull()
        ->and(FormThemePreset::fromTheme(null))->toBeNull();
});

it('transmits one catalogue entry per preset, carrying what the client renders', function (): void {
    $catalogue = FormThemePreset::catalogue();

    expect(array_column($catalogue, 'value'))->toBe(array_map(static fn (FormThemePreset $p): string => $p->value, FormThemePreset::cases()))
        ->and($catalogue[0])->toHaveKeys(['label', 'description', 'font', 'radius', 'tokens', 'lines']);
});
