<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Services\Ocr\Bakeoff\OcrBakeoffAnswers;
use App\Services\Ocr\Bakeoff\OcrBakeoffQuestion;

/*
|--------------------------------------------------------------------------
| M136 — a correct answer typed into the answer sheet, turned into the value the reader stores, and compared.
|--------------------------------------------------------------------------
| A cell is either understood exactly or refused with a reason — never guessed at, because a mistyped correct answer
| would count against the reader. Excel hands dates and times over as objects, which is the exact case.
|
| ⚠️ Helpers are prefixed `ocrBakeoffAnswers*`: Pest loads every test file into one process.
*/

function ocrBakeoffAnswersQuestion(FieldType $type, array $options = [], array $config = []): OcrBakeoffQuestion
{
    return new OcrBakeoffQuestion('q', 'Question', $type, $config, $options);
}

/** @return mixed the stored-shape value, or the refusal's reason prefixed with `!` */
function ocrBakeoffAnswersRead(FieldType $type, mixed $raw, array $options = [], array $config = []): mixed
{
    $parsed = OcrBakeoffAnswers::expected(ocrBakeoffAnswersQuestion($type, $options, $config), $raw);

    return $parsed['ok'] ? $parsed['value'] : '!'.$parsed['reason'];
}

it('reads a date typed ISO, day first as the paper prints it, or handed over by Excel as a date', function (): void {
    expect(ocrBakeoffAnswersRead(FieldType::Date, '2026-10-08'))->toBe('2026-10-08')
        ->and(ocrBakeoffAnswersRead(FieldType::Date, '08/10/2026'))->toBe('2026-10-08')
        ->and(ocrBakeoffAnswersRead(FieldType::Date, '8-10-2026'))->toBe('2026-10-08')
        ->and(ocrBakeoffAnswersRead(FieldType::Date, new DateTimeImmutable('2026-10-08')))->toBe('2026-10-08')
        ->and(ocrBakeoffAnswersRead(FieldType::Date, '31/02/2026'))->toStartWith('!not a date')
        ->and(ocrBakeoffAnswersRead(FieldType::Date, 'Oct 8'))->toStartWith('!not a date');
});

it('reads times, a date and time, and a duration, including the shapes Excel turns them into', function (): void {
    expect(ocrBakeoffAnswersRead(FieldType::Time, '14:05'))->toBe('14:05')
        ->and(ocrBakeoffAnswersRead(FieldType::Time, '2:05 pm'))->toBe('14:05')
        ->and(ocrBakeoffAnswersRead(FieldType::Time, '12:30 AM'))->toBe('00:30')
        ->and(ocrBakeoffAnswersRead(FieldType::Time, new DateTimeImmutable('1899-12-30 14:05')))->toBe('14:05')
        ->and(ocrBakeoffAnswersRead(FieldType::Time, '25:00'))->toStartWith('!not a time')
        ->and(ocrBakeoffAnswersRead(FieldType::Datetime, '2026-10-08 14:05'))->toBe('2026-10-08T14:05')
        ->and(ocrBakeoffAnswersRead(FieldType::Datetime, '08/10/2026 2:05 pm'))->toBe('2026-10-08T14:05')
        ->and(ocrBakeoffAnswersRead(FieldType::Duration, '1:30'))->toBe(5400)
        ->and(ocrBakeoffAnswersRead(FieldType::Duration, new DateInterval('PT1H30M')))->toBe(5400)
        ->and(ocrBakeoffAnswersRead(FieldType::Duration, '90 min'))->toStartWith('!not a duration');
});

it('reads numbers as numbers, and refuses what is not one', function (): void {
    expect(ocrBakeoffAnswersRead(FieldType::Integer, '034'))->toBe('34')
        ->and(ocrBakeoffAnswersRead(FieldType::Integer, 34.0))->toBe('34')
        ->and(ocrBakeoffAnswersRead(FieldType::Integer, 'thirty'))->toBe('!not a whole number')
        ->and(ocrBakeoffAnswersRead(FieldType::Decimal, '3,50'))->toBe('3.5')
        ->and(ocrBakeoffAnswersRead(FieldType::Decimal, '-0.0'))->toBe('0');
});

it('reads yes and no in English and Filipino, and refuses anything else', function (): void {
    expect(ocrBakeoffAnswersRead(FieldType::YesNo, 'Yes'))->toBeTrue()
        ->and(ocrBakeoffAnswersRead(FieldType::YesNo, 'oo'))->toBeTrue()
        ->and(ocrBakeoffAnswersRead(FieldType::YesNo, 'Hindi'))->toBeFalse()
        ->and(ocrBakeoffAnswersRead(FieldType::YesNo, false))->toBeFalse()
        ->and(ocrBakeoffAnswersRead(FieldType::YesNo, 'maybe'))->toBe('!not yes or no');
});

it('reads a choice by its label or its value, several as a set, and a level path under each parent', function (): void {
    $sex = [['value' => 'f', 'label' => 'Female'], ['value' => 'm', 'label' => 'Male']];
    $symptoms = [['value' => 'fever', 'label' => 'Fever'], ['value' => 'cough', 'label' => 'Cough'], ['value' => 'fatigue', 'label' => 'Fatigue']];
    $places = ['levels' => [['key' => 'region'], ['key' => 'city']], 'options' => [
        ['value' => 'ncr', 'label' => 'NCR', 'level' => 'region'],
        ['value' => 'r4a', 'label' => 'Calabarzon', 'level' => 'region'],
        ['value' => 'mnl', 'label' => 'Manila', 'level' => 'city', 'parent' => 'ncr'],
        ['value' => 'bat', 'label' => 'Batangas City', 'level' => 'city', 'parent' => 'r4a'],
    ]];

    expect(ocrBakeoffAnswersRead(FieldType::SingleSelect, 'female', $sex))->toBe('f')
        ->and(ocrBakeoffAnswersRead(FieldType::SingleSelect, 'm', $sex))->toBe('m')
        ->and(ocrBakeoffAnswersRead(FieldType::SingleSelect, 'Other', $sex))->toBe('!not one of: Female / Male')
        // a choice list from another form prints a write-in box: the answer is the text written
        ->and(ocrBakeoffAnswersRead(FieldType::Dropdown, '  Barangay   Uno '))->toBe('Barangay Uno')
        ->and(ocrBakeoffAnswersRead(FieldType::MultiSelect, 'Cough; Fever', $symptoms))->toBe(['cough', 'fever'])
        ->and(ocrBakeoffAnswersRead(FieldType::MultiSelect, 'cough,fever', $symptoms))->toBe(['cough', 'fever'])
        ->and(ocrBakeoffAnswersRead(FieldType::MultiSelect, 'Cough; Rash', $symptoms))->toStartWith('!"Rash" is not one of')
        ->and(ocrBakeoffAnswersRead(FieldType::CascadingSelect, 'NCR > Manila', [], $places))->toBe(['ncr', 'mnl'])
        ->and(ocrBakeoffAnswersRead(FieldType::CascadingSelect, 'Calabarzon', [], $places))->toBe(['r4a'])
        ->and(ocrBakeoffAnswersRead(FieldType::CascadingSelect, 'NCR > Batangas City', [], $places))->toBe('!"Batangas City" is not a choice at level city under ncr');
});

it('reads a blank cell as a blank answer, and refuses a text answer Excel turned into a date', function (): void {
    expect(ocrBakeoffAnswersRead(FieldType::ShortText, ''))->toBeNull()
        ->and(ocrBakeoffAnswersRead(FieldType::Date, null))->toBeNull()
        ->and(ocrBakeoffAnswersRead(FieldType::ShortText, new DateTimeImmutable('2026-10-08')))->toStartWith('!the spreadsheet turned this text into a date');
});

it('compares with the stated leniency and no more', function (): void {
    expect(OcrBakeoffAnswers::same(FieldType::ShortText, 'Juan dela  Cruz', 'JUAN DELA CRUZ'))->toBeTrue()
        ->and(OcrBakeoffAnswers::same(FieldType::ShortText, 'Juan', 'JUAM'))->toBeFalse()
        ->and(OcrBakeoffAnswers::same(FieldType::Phone, '9171234567', '0917 123 4567'))->toBeTrue()
        ->and(OcrBakeoffAnswers::same(FieldType::Phone, '09171234567', '09171234568'))->toBeFalse()
        ->and(OcrBakeoffAnswers::same(FieldType::Email, 'ana@x.ph', 'ANA @X.PH'))->toBeTrue()
        ->and(OcrBakeoffAnswers::same(FieldType::Integer, '34', '034'))->toBeTrue()
        ->and(OcrBakeoffAnswers::same(FieldType::MultiSelect, ['fever', 'cough'], ['cough', 'fever']))->toBeTrue()
        ->and(OcrBakeoffAnswers::same(FieldType::MultiSelect, ['fever'], ['cough', 'fever']))->toBeFalse()
        ->and(OcrBakeoffAnswers::same(FieldType::SingleSelect, 'f', 'F'))->toBeFalse()
        ->and(OcrBakeoffAnswers::same(FieldType::YesNo, false, null))->toBeFalse()
        ->and(OcrBakeoffAnswers::same(FieldType::ShortText, null, ''))->toBeTrue();
});

it('recognises a date that is right with its day and month swapped, and nothing else', function (): void {
    expect(OcrBakeoffAnswers::daySwapped(FieldType::Date, '2026-08-10', '2026-10-08'))->toBeTrue()
        ->and(OcrBakeoffAnswers::daySwapped(FieldType::Datetime, '2026-08-10T14:05', '2026-10-08T14:05'))->toBeTrue()
        ->and(OcrBakeoffAnswers::daySwapped(FieldType::Date, '2026-08-10', '2026-08-11'))->toBeFalse()
        ->and(OcrBakeoffAnswers::daySwapped(FieldType::Date, '2026-10-13', '2026-13-10'))->toBeFalse()
        ->and(OcrBakeoffAnswers::daySwapped(FieldType::ShortText, '2026-08-10', '2026-10-08'))->toBeFalse();
});
