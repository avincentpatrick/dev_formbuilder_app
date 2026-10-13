<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Ocr\PrintedFormMatcher;
use App\Services\Ocr\VisionDocumentParser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ocr\Support\PrintedPageTypesetter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M169 (`R-27049032`) — a point or a comma stays on the word it was written against.
|--------------------------------------------------------------------------
| Vision reads handwriting's `.` `,` `@` as words of their own and marks no break between such a word and the one
| before it. The reader joined every word with a space, so Round 1's sheet 01 read "DE GUZMAN JR ." and sheet 09
| "STA . MESA". Each case goes the whole way from what Vision returns: its JSON through the parser on a level page
| and a tilted one (the line builder's deskew builds new words), and a photo stored on its side (the quarter turn
| builds new words too) — so a break the parser reads and a later step drops is caught where it is dropped.
|
| ⚠️ Helpers are prefixed `ocrSpacing*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);

    $form = app(FormService::class)->create($this->tenant, $this->user, 'Household Visit');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'full_name', FieldType::ShortText, 1, ['label' => 'Full name']);
    addFormField($draft, $this->user, 'address', FieldType::LongText, 2, ['label' => 'Home address']);
    addFormField($draft, $this->user, 'email', FieldType::Email, 3, ['label' => 'Email address']);
    addFormField($draft, $this->user, 'remarks', FieldType::ShortText, 4, ['label' => 'Remarks']);

    $this->version = app(PublishService::class)->publish($form->refresh(), $this->user);
    $this->form = $form->refresh();
    $this->model = app(BlankFormPrintPresenter::class)->present($this->form, $this->version);
});

it('keeps a written point or comma on its word, as Vision spaced it', function (string $photo): void {
    $typesetter = PrintedPageTypesetter::fromModel($this->model, [
        'full_name' => 'DE GUZMAN JR.',
        'address' => "STA. MESA,\nMANILA",
        'email' => 'rey.deguzman@pitahc.gov.ph',
        'remarks' => 'NO KNOWN ALLERGY',
    ], ['punctuation_apart' => ['full_name', 'address', 'email']]);

    $page = match ($photo) {
        'level' => (new VisionDocumentParser)->pages($typesetter->visionImageAnswer())[0],
        'tilted' => (new VisionDocumentParser)->pages($typesetter->visionImageAnswer(0.04))[0],
        'stored on its side' => $typesetter->turned(1),
    };

    $fields = app(PrintedFormMatcher::class)->match($this->form, $this->version, [$page])['fields'];

    expect(array_map(static fn (array $f): array => [$f['state'], $f['value'], $f['text']], $fields))->toBe([
        'full_name' => ['read', 'DE GUZMAN JR.', 'DE GUZMAN JR.'],
        // The line between "MESA," and "MANILA" is still a space: that join is the reader's, not Vision's (R-d4653a0c).
        'address' => ['read', 'STA. MESA, MANILA', 'STA. MESA, MANILA'],
        'email' => ['read', 'rey.deguzman@pitahc.gov.ph', 'rey.deguzman@pitahc.gov.ph'],
        // Words written apart stay apart.
        'remarks' => ['read', 'NO KNOWN ALLERGY', 'NO KNOWN ALLERGY'],
    ]);
})->with(['level', 'tilted', 'stored on its side']);
