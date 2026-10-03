<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Ocr\PrintedFormMatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ocr\Support\PrintedPageTypesetter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M128 — matching a read page back onto the printed form it came from.
|--------------------------------------------------------------------------
| Every case lays a page out from the SAME render model the PDF is typeset from (`PrintedPageTypesetter`
| over `BlankFormPrintPresenter::present()`), so a case cannot pass by describing a layout the paper does not
| have. The form is really published, so the snapshot carries the key names the serializer actually writes.
|
| ⚠️ Helpers are prefixed `ocrMatch*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);

    $form = app(FormService::class)->create($this->tenant, $this->user, 'Patient Intake');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'patient_name', FieldType::ShortText, 1, ['label' => 'Patient name']);
    addFormField($draft, $this->user, 'age', FieldType::Integer, 2, ['label' => 'Age']);
    addFormField($draft, $this->user, 'visit_date', FieldType::Date, 3, ['label' => 'Visit date']);
    addFormField($draft, $this->user, 'visit_time', FieldType::Time, 4, ['label' => 'Time seen']);
    addFormField($draft, $this->user, 'consent', FieldType::YesNo, 5, ['label' => 'Consent given']);
    addFormField($draft, $this->user, 'sex', FieldType::SingleSelect, 6, ['label' => 'Sex', 'config' => ['options' => [
        ['value' => 'f', 'label' => 'Female'], ['value' => 'm', 'label' => 'Male'],
    ]]]);
    addFormField($draft, $this->user, 'symptoms', FieldType::MultiSelect, 7, ['label' => 'Presenting symptoms', 'config' => ['options' => [
        ['value' => 'fever', 'label' => 'Fever'], ['value' => 'cough', 'label' => 'Cough'], ['value' => 'fatigue', 'label' => 'Fatigue'],
    ]]]);
    addFormField($draft, $this->user, 'notes', FieldType::LongText, 8, ['label' => 'Clinical notes']);

    $this->version = app(PublishService::class)->publish($form->refresh(), $this->user);
    $this->form = $form->refresh();
    $this->model = app(BlankFormPrintPresenter::class)->present($this->form, $this->version);
    $this->matcher = app(PrintedFormMatcher::class);
});

/** @return array<string, string|list<string>> */
function ocrMatchAnswers(): array
{
    return [
        'patient_name' => 'JUAN DELA CRUZ',
        'age' => '34',
        'visit_date' => ['03', '10', '2026'],
        'visit_time' => ['14', '05'],
        'consent' => ['Yes'],
        'sex' => ['Female'],
        'symptoms' => ['Fever', 'Cough'],
        'notes' => 'MILD FEVER FOR TWO DAYS',
    ];
}

/**
 * @param  array<string, mixed>  $model
 * @param  array<string, string|list<string>>  $answers
 * @param  array<string, mixed>  $options
 * @return array<string, array<string, mixed>>
 */
function ocrMatchFields(PrintedFormMatcher $matcher, Form $form, FormVersion $version, array $model, array $answers, array $options = [], float $tilt = 0.0): array
{
    $page = PrintedPageTypesetter::fromModel($model, $answers, $options)->page($tilt);

    return $matcher->match($form, $version, [$page])['fields'];
}

it('reads every printed area of a cleanly filled page, each anchored on its key stamp', function (): void {
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers());

    expect(array_map(static fn (array $f): mixed => $f['value'], $fields))->toBe([
        'patient_name' => 'JUAN DELA CRUZ',
        'age' => '34',
        'visit_date' => '2026-10-03',
        'visit_time' => '14:05',
        'consent' => true,
        'sex' => 'f',
        'symptoms' => ['fever', 'cough'],
        'notes' => 'MILD FEVER FOR TWO DAYS',
    ]);

    foreach ($fields as $key => $field) {
        expect($field['state'])->toBe('read', $key)
            ->and($field['tier'])->toBe('auto', $key)
            ->and($field['anchored_by'])->toBe('key', $key)
            ->and($field['page'])->toBe(0, $key);
    }
});

it('splits a date by WHERE each digit sits under its caption, never by counting digits', function (): void {
    // One empty box in the day: seven digits for eight cells. Counting would shift every digit after the gap
    // into the wrong group; position keeps the month and the year where they were written.
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['visit_date' => ['3', '10', '2026']]);

    expect($fields['visit_date']['state'])->toBe('read')
        ->and($fields['visit_date']['value'])->toBe('2026-10-03');
});

it('falls back to the printed cell counts only when the captions were not read and every box is filled', function (): void {
    $full = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['visit_date' => ['03', '10', '2026']], ['omit_captions' => true]);
    $short = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['visit_date' => ['3', '10', '2026']], ['omit_captions' => true]);

    expect($full['visit_date']['value'])->toBe('2026-10-03')
        ->and($short['visit_date']['state'])->toBe('unreadable')
        ->and($short['visit_date']['value'])->toBeNull();
});

it('refuses a date that does not exist rather than rolling it over', function (): void {
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['visit_date' => ['31', '02', '2026']]);

    expect($fields['visit_date']['state'])->toBe('unreadable')
        ->and($fields['visit_date']['text'])->toBe('31 02 2026');
});

it('reads a page photographed at a tilt exactly as a level one', function (): void {
    $level = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers());
    $tilted = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers(), [], 0.07);

    expect(array_column($tilted, 'value'))->toBe(array_column($level, 'value'));
});

it('finds a question by its label when the small key stamp was not read, and reports one found by neither', function (): void {
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers(), [
        'omit_keys' => ['age', 'notes'],
        'omit_labels' => ['notes'],
    ]);

    expect($fields['age']['anchored_by'])->toBe('label')
        ->and($fields['age']['value'])->toBe('34')
        ->and($fields['notes']['state'])->toBe('not_found')
        ->and($fields['notes']['value'])->toBeNull();
});

it('reports an empty question as blank, not as a failure', function (): void {
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['age' => '34']);

    expect($fields['patient_name']['state'])->toBe('blank')
        ->and($fields['sex']['state'])->toBe('blank')
        ->and($fields['notes']['state'])->toBe('blank')
        ->and($fields['age']['state'])->toBe('read');
});

it('refuses two marked boxes on a one-answer question, and keeps both on a many-answer one', function (): void {
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, [
        'sex' => ['Female', 'Male'],
        'symptoms' => ['Fever', 'Cough', 'Fatigue'],
    ]);

    expect($fields['sex']['state'])->toBe('unreadable')
        ->and($fields['sex']['value'])->toBeNull()
        ->and($fields['symptoms']['value'])->toBe(['fever', 'cough', 'fatigue']);
});

it('turns confidence into tiers and withholds a value below the review threshold', function (): void {
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers(), ['confidence' => [
        'patient_name' => 0.95,
        'age' => 0.80,
        'notes' => 0.50,
    ]]);

    expect($fields['patient_name'])->toMatchArray(['tier' => 'auto', 'value' => 'JUAN DELA CRUZ', 'confidence' => 95])
        ->and($fields['age'])->toMatchArray(['tier' => 'review', 'value' => '34', 'confidence' => 80])
        ->and($fields['notes'])->toMatchArray(['tier' => 'manual', 'value' => null, 'text' => 'MILD FEVER FOR TWO DAYS']);
});

it('reads a letter O in a number box as a zero, and flags it for review whatever its confidence', function (): void {
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['age' => '3O']);

    expect($fields['age'])->toMatchArray(['state' => 'read', 'value' => '30', 'tier' => 'review', 'confidence' => 89]);
});

it('reads a yes/no written as a word, the shape of a sheet printed before this increment', function (): void {
    // Before M128 a yes/no printed a write-in box under the SAME checksum stamp, so old paper names this very
    // version. The page is set from the model as it was then — a ruled area, no options — and read against
    // the version as it is now.
    $old = $this->model;
    foreach ($old['blocks'] as $b => $block) {
        foreach ($block['fields'] as $f => $row) {
            if ($row['key'] === 'consent') {
                $old['blocks'][$b]['fields'][$f] = [...$row, 'area' => 'ruled', 'options' => []];
            }
        }
    }

    $yes = ocrMatchFields($this->matcher, $this->form, $this->version, $old, [...ocrMatchAnswers(), 'consent' => 'YES']);
    $no = ocrMatchFields($this->matcher, $this->form, $this->version, $old, [...ocrMatchAnswers(), 'consent' => 'NO']);

    expect($yes['consent'])->toMatchArray(['state' => 'read', 'value' => true])
        ->and($no['consent'])->toMatchArray(['state' => 'read', 'value' => false]);
});

it('reads the version off the page stamp, a superseded one included, and says when it could not', function (): void {
    $v1 = (new FormVersion)->forceFill(['version_number' => 1, 'checksum' => 'aaaa1111'.str_repeat('0', 56)]);
    $v2 = (new FormVersion)->forceFill(['version_number' => 2, 'checksum' => 'bbbb2222'.str_repeat('0', 56)]);
    $stamped = static fn (?string $stamp): array => [PrintedPageTypesetter::fromModel(test()->model, [], ['stamp' => $stamp])->page()];

    $exact = $this->matcher->resolveVersion($stamped('aaaa1111'), [$v2, $v1], $v2);
    $near = $this->matcher->resolveVersion($stamped('aaaa1117'), [$v2, $v1], $v2);
    $none = $this->matcher->resolveVersion($stamped(null), [$v2, $v1], $v2);

    expect($exact['version'])->toBe($v1)->and($exact['matched_by'])->toBe('stamp')
        ->and($near['version'])->toBe($v1)->and($near['matched_by'])->toBe('stamp_near')
        ->and($none['version'])->toBe($v2)->and($none['matched_by'])->toBe('unconfirmed');
});
