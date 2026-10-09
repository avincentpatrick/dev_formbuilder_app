<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Ocr\OcrAnswerReader;
use App\Services\Ocr\PrintedFormMatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ocr\Support\PrintedPageTypesetter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M152 (`R-39a5388f`) — a tick's confidence is that a mark was seen, not what the glyph was.
|--------------------------------------------------------------------------
| The bake-off's Round 1 read 16 ticks right and withheld every one: Vision returns a hand-drawn tick as ☑, X, a Greek chi
| or 区 with a low confidence in the GLYPH, and the reader scored the glyph. A mark in the box before a label is evidence of a
| tick whatever it was read as, so it is shown for review — and never accepted on the mark alone, because the one wrong tick
| in Round 1 was a multi-select whose second mark Vision never returned at all (51.1% → 42.2% needing correction, 0 silent).
|
| ⚠️ Helpers are prefixed `ocrTick*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);

    $form = app(FormService::class)->create($this->tenant, $this->user, 'Tick Form');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'patient_name', FieldType::ShortText, 1, ['label' => 'Patient name']);
    addFormField($draft, $this->user, 'consent', FieldType::YesNo, 2, ['label' => 'Consent given']);
    addFormField($draft, $this->user, 'sex', FieldType::SingleSelect, 3, ['label' => 'Sex', 'config' => ['options' => [
        ['value' => 'f', 'label' => 'Female'], ['value' => 'm', 'label' => 'Male'],
    ]]]);
    addFormField($draft, $this->user, 'symptoms', FieldType::MultiSelect, 4, ['label' => 'Presenting symptoms', 'config' => ['options' => [
        ['value' => 'fever', 'label' => 'Fever'], ['value' => 'cough', 'label' => 'Cough'], ['value' => 'rash', 'label' => 'Rash'],
    ]]]);

    $this->version = app(PublishService::class)->publish($form->refresh(), $this->user);
    $this->form = $form->refresh();
    $this->model = app(BlankFormPrintPresenter::class)->present($this->form, $this->version);
});

/**
 * @param  array<string, mixed>  $options
 * @return array<string, array<string, mixed>>
 */
function ocrTickFields(object $test, array $options): array
{
    $page = PrintedPageTypesetter::fromModel($test->model, [
        'patient_name' => 'JUAN DELA CRUZ',
        'consent' => ['Yes'],
        'sex' => ['Male'],
        'symptoms' => ['Fever', 'Rash'],
    ], $options)->page();

    return app(PrintedFormMatcher::class)->match($test->form, $test->version, [$page])['fields'];
}

it('shows a tick read with a doubtful glyph for review instead of withholding it', function (): void {
    foreach (['X', '☑', 'Χ', '区'] as $glyph) {
        $fields = ocrTickFields($this, [
            'marks' => ['consent' => $glyph, 'sex' => $glyph, 'symptoms' => $glyph],
            'confidence' => ['consent' => 0.19, 'sex' => 0.34, 'symptoms' => 0.45],
        ]);

        expect($fields['consent'])->toMatchArray(['state' => 'read', 'value' => true, 'confidence' => OcrAnswerReader::MARK_SEEN, 'tier' => 'review'], $glyph)
            ->and($fields['sex'])->toMatchArray(['state' => 'read', 'value' => 'm', 'confidence' => OcrAnswerReader::MARK_SEEN, 'tier' => 'review'], $glyph)
            ->and($fields['symptoms'])->toMatchArray(['state' => 'read', 'value' => ['fever', 'rash'], 'confidence' => OcrAnswerReader::MARK_SEEN, 'tier' => 'review'], $glyph);
    }
});

it('keeps a tick read with a confident glyph at the glyph\'s confidence', function (): void {
    $fields = ocrTickFields($this, ['confidence' => ['sex' => 0.95, 'symptoms' => 0.83]]);

    expect($fields['sex'])->toMatchArray(['confidence' => 95, 'tier' => 'auto', 'value' => 'm'])
        ->and($fields['symptoms'])->toMatchArray(['confidence' => 83, 'tier' => 'review', 'value' => ['fever', 'rash']]);
});

it('raises only a tick: writing read with the same doubt is still withheld', function (): void {
    $fields = ocrTickFields($this, ['confidence' => ['patient_name' => 0.34]]);

    expect($fields['patient_name'])->toMatchArray(['confidence' => 34, 'tier' => 'manual', 'value' => null, 'text' => 'JUAN DELA CRUZ']);
});

it('keeps a seen tick inside the configured review band, so a tick is never accepted on the mark alone', function (): void {
    // At or above auto, Round 1's multi-select that lost its second mark would have been a silent error (the sweep shows one
    // at auto 85). Below review, every doubtful tick is withheld again — the defect this case closes.
    expect(OcrAnswerReader::MARK_SEEN)->toBeGreaterThanOrEqual((int) config('ocr.confidence.review'))
        ->and(OcrAnswerReader::MARK_SEEN)->toBeLessThan((int) config('ocr.confidence.auto'));
});
