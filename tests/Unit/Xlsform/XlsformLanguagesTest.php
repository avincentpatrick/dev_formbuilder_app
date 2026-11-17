<?php

declare(strict_types=1);

use App\Services\Xlsform\XlsformLanguages;

/*
|--------------------------------------------------------------------------
| M244 (`R-49262cca`): an XLSForm's languages read as locale codes, and the default language's column chosen as the
| base text when there is no bare one — which is how Kobo exports.
|--------------------------------------------------------------------------
*/

it('reads a language as written into its code', function (string $written, ?string $code): void {
    expect(XlsformLanguages::code($written))->toBe($code);
})->with([
    'ODK name (code)' => ['English (en)', 'en'],
    'a regional code' => ['Filipino (fil_PH)', 'fil-PH'],
    'a name' => ['English', 'en'],
    'a native name' => ['Français', 'fr'],
    'a Philippine language' => ['Cebuano', 'ceb'],
    'a code' => ['fil', 'fil'],
    'an upper-case code' => ['EN', 'en'],
    'an ambiguous name' => ['Bisaya', null],
    'unknown' => ['Klingon', null],
    'blank' => ['  ', null],
    'too long for the column' => ['Name (abc-defghijk)', null],
]);

it('keeps a bare label column as the base and keys the others by code', function (): void {
    $warnings = [];
    $languages = new XlsformLanguages('English', ['type', 'name', 'label', 'label::Français (fr)']);

    [$base, $translations] = $languages->text(['label' => 'Name', 'label::Français (fr)' => 'Nom'], 'label', $warnings);

    expect($base)->toBe('Name')
        ->and($translations)->toBe(['fr' => 'Nom'])
        ->and($languages->locale($warnings))->toBe('en')
        ->and($warnings)->toBe([]);
});

it('takes the default language\'s column as the base when there is no bare one', function (): void {
    $warnings = [];
    $languages = new XlsformLanguages('English (en)', ['type', 'name', 'label::Filipino (fil)', 'label::English (en)']);

    [$base, $translations] = $languages->text(['label::Filipino (fil)' => 'Pangalan', 'label::English (en)' => 'Name'], 'label', $warnings);

    expect($base)->toBe('Name')->and($translations)->toBe(['fil' => 'Pangalan']);
});

it('matches the default by code when the columns spell it differently', function (): void {
    $warnings = [];
    $languages = new XlsformLanguages('en', ['label::English (en)', 'label::fil']);

    [$base, $translations] = $languages->text(['label::English (en)' => 'Name', 'label::fil' => 'Pangalan'], 'label', $warnings);

    expect($base)->toBe('Name')->and($translations)->toBe(['fil' => 'Pangalan']);
});

it('infers the default from the first language column when the settings name none', function (): void {
    $warnings = [];
    $languages = new XlsformLanguages(null, ['type', 'name', 'label::English (en)', 'label::Tagalog (tl)']);

    [$base] = $languages->text(['label::English (en)' => 'Name', 'label::Tagalog (tl)' => 'Pangalan'], 'label', $warnings);

    expect($base)->toBe('Name')->and($languages->locale($warnings))->toBe('en');
});

it('leaves the form\'s locale alone with a warning when it cannot name the default, and keeps an unknown language by name', function (): void {
    $warnings = [];
    $languages = new XlsformLanguages('Klingon', ['label', 'label::Klingon', 'label::Bisaya']);

    [, $translations] = $languages->text(['label' => 'Name', 'label::Bisaya' => 'Ngalan'], 'label', $warnings);
    [, $again] = $languages->text(['label' => 'Age', 'label::Bisaya' => 'Edad'], 'label', $warnings);

    expect($languages->locale($warnings))->toBeNull()
        ->and($translations)->toBe(['Bisaya' => 'Ngalan'])
        ->and($again)->toBe(['Bisaya' => 'Edad'])
        ->and($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('“Bisaya”')
        ->and($warnings[1])->toContain('“Klingon”');
});

it('keeps a form with bare columns and no setting on its own locale', function (): void {
    $warnings = [];

    expect((new XlsformLanguages(null, ['type', 'name', 'label']))->locale($warnings))->toBeNull()
        ->and($warnings)->toBe([]);
});
