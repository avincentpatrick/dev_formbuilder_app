<?php

declare(strict_types=1);

use App\Services\Forms\ChoiceSource;

/*
|--------------------------------------------------------------------------
| M184 (T3) — where a choice question's choices come from.
|--------------------------------------------------------------------------
| Typed here, a list file (`options_list`) or another form's answers (`options_source`). A key present and not null
| declares its source even while the author is still picking — the builder's `choiceSourceOf()` reads it the same way
| (`resources/js/components/builder/choice-source.test.ts` holds the same cases).
*/

it('reads typed choices when neither source key is declared', function (mixed $config): void {
    expect(ChoiceSource::of($config))->toBe(ChoiceSource::Typed)
        ->and(ChoiceSource::of($config)->isTyped())->toBeTrue();
})->with([
    'no config' => [null],
    'typed options' => [['options' => [['value' => 'a', 'label' => 'A']]]],
    'both keys null' => [['options' => [], 'options_source' => null, 'options_list' => null]],
]);

it('reads a list file once options_list is declared, chosen or not', function (): void {
    expect(ChoiceSource::of(['options' => [], 'options_list' => 'facilities']))->toBe(ChoiceSource::List)
        ->and(ChoiceSource::of(['options' => [], 'options_list' => '']))->toBe(ChoiceSource::List)
        ->and(ChoiceSource::of(['options_list' => 'facilities'])->isTyped())->toBeFalse();
});

it('reads another form once options_source is declared, chosen or not, and it wins over a list', function (): void {
    expect(ChoiceSource::of(['options_source' => ['form_id' => 'f', 'field_key' => 'k']]))->toBe(ChoiceSource::Form)
        ->and(ChoiceSource::of(['options_source' => ['form_id' => null, 'field_key' => null]]))->toBe(ChoiceSource::Form)
        ->and(ChoiceSource::of(['options_source' => ['form_id' => 'f', 'field_key' => 'k'], 'options_list' => 'x']))->toBe(ChoiceSource::Form);
});
