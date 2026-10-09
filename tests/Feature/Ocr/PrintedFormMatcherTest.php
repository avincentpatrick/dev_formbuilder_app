<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Ocr\OcrPage;
use App\Services\Ocr\OcrText;
use App\Services\Ocr\OcrWord;
use App\Services\Ocr\PrintedFormMatcher;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
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
    // Layout 3 (D98): a phone is an open box, read as written. It sits BEFORE the notes so the footer still lands in
    // the last question's region, which the footer-sentence case below relies on.
    addFormField($draft, $this->user, 'mobile', FieldType::Phone, 8, ['label' => 'Mobile number']);
    $notes = addFormField($draft, $this->user, 'notes', FieldType::LongText, 9, ['label' => 'Clinical notes']);
    // Layout 3 (R-d696ba9e): 200 capitals is a five-line box, so a paragraph has somewhere to go. A validation is a
    // relation, not a column, so it is a row of its own (the serializer reads it into the snapshot).
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $notes->id,
        'rule_type' => 'max_length',
        'rule_value' => '200',
        'error_message' => 'Keep it short.',
        'sequence' => 0,
    ]);

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
        'mobile' => '0917 123 4567',
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
        // A phone in an open box (layout 3): what was written, spaces included — the pipeline's own rule accepts them.
        'mobile' => '0917 123 4567',
        'notes' => 'MILD FEVER FOR TWO DAYS',
    ]);

    foreach ($fields as $key => $field) {
        expect($field['state'])->toBe('read', $key)
            ->and($field['tier'])->toBe('auto', $key)
            ->and($field['anchored_by'])->toBe('key', $key)
            ->and($field['page'])->toBe(1, $key);
    }
});

/**
 * One typeset page cut into two at the top of the question keyed `$key`, the way the template breaks a long form: a question
 * never splits across pages (`.q { page-break-inside: avoid }`).
 *
 * @return array{0: OcrPage, 1: OcrPage}
 */
function ocrMatchSplit(OcrPage $page, string $key): array
{
    $tops = array_map(static fn (OcrWord $w): float => $w->y0, array_filter($page->words, static fn (OcrWord $w): bool => OcrText::key($w->text) === $key));
    $cut = min($tops) - 0.002;
    $above = array_values(array_filter($page->words, static fn (OcrWord $w): bool => $w->centerY() < $cut));
    $below = array_values(array_filter($page->words, static fn (OcrWord $w): bool => $w->centerY() >= $cut));

    return [new OcrPage($page->width, $page->height, $above), new OcrPage($page->width, $page->height, $below)];
}

it('reads a scan whose pages were uploaded out of order as if they were in order, and names each page by its upload place (R-4aaf3b6f)', function (): void {
    // M152, measured before this case: with each Round 1 form's two photos uploaded in reverse, the questions on its second
    // sheet were looked for only after the first sheet's, and all thirty came back "not found". The running head prints no page
    // number (§2.5), so the order is taken from what each page holds.
    [$first, $second] = ocrMatchSplit(PrintedPageTypesetter::fromModel($this->model, ocrMatchAnswers())->page(), 'sex');
    $values = static fn (array $fields): array => array_map(static fn (array $f): mixed => $f['value'], $fields);

    $inOrder = $this->matcher->match($this->form, $this->version, [$first, $second])['fields'];
    $swapped = $this->matcher->match($this->form, $this->version, [$second, $first])['fields'];
    $blankFirst = $this->matcher->match($this->form, $this->version, [new OcrPage(1.0, 1.0, []), $second, $first])['fields'];

    expect(array_unique(array_column($inOrder, 'state')))->toBe(['read'])
        ->and($values($swapped))->toBe($values($inOrder))
        ->and($values($blankFirst))->toBe($values($inOrder));

    // The page a note names is the one the review screen shows under that number: its place in the upload, counted from 1.
    expect([$inOrder['patient_name']['page'], $inOrder['consent']['page'], $inOrder['sex']['page'], $inOrder['notes']['page']])->toBe([1, 1, 2, 2])
        ->and([$swapped['patient_name']['page'], $swapped['consent']['page'], $swapped['sex']['page'], $swapped['notes']['page']])->toBe([2, 2, 1, 1])
        ->and([$blankFirst['patient_name']['page'], $blankFirst['sex']['page']])->toBe([3, 2]);
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

it('reads a date and a time through their printed separators, and a comb holding only its separators as blank (layout 4, M148)', function (): void {
    // Layout 4 prints `/` and `:` in a comb's gaps, as text the recognizer reads like the digits beside it. They are
    // the paper's, never the answer's: the date reads as its three groups, and an unanswered comb stays blank.
    $filled = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers());
    $empty = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['age' => '34']);

    expect($filled['visit_date'])->toMatchArray(['state' => 'read', 'value' => '2026-10-03', 'text' => '03 10 2026'])
        ->and($filled['visit_time'])->toMatchArray(['state' => 'read', 'value' => '14:05', 'text' => '14 05'])
        ->and($empty['visit_date']['state'])->toBe('blank')
        ->and($empty['visit_time']['state'])->toBe('blank');
});

it('refuses a day or a month longer than its boxes, rather than reading a separator as one more digit (M148)', function (): void {
    // The user's comment on layout 3 found the gaps printed as boxes, which invited a pen. A gap character goes to the
    // nearer group, and a `/` misread as `1` beside a month of `01` made `011` — November, at the recognizer's own
    // confidence — whenever the other separator was not read. No group may hold more characters than it has boxes.
    $misread = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['visit_date' => ['03', '01', '2026']], [
        'gap_reads' => ['visit_date' => [null, '1']],
    ]);
    $bothMisread = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['visit_date' => ['03', '01', '2026']], [
        'gap_reads' => ['visit_date' => ['1', '1']],
    ]);

    expect($misread['visit_date']['state'])->toBe('unreadable')
        ->and($misread['visit_date']['value'])->toBeNull()
        ->and($bothMisread['visit_date']['state'])->toBe('unreadable')
        ->and($bothMisread['visit_date']['value'])->toBeNull();
});

it('reads a datetime and a duration through their separators, and keeps the hyphen a cascade level was written with (M148)', function (): void {
    // A cascade's gap prints no separator: its levels are written words, and a hyphen in one is the answer.
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Referral');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'seen_at', FieldType::Datetime, 1, ['label' => 'Seen at']);
    addFormField($draft, $this->user, 'travel', FieldType::Duration, 2, ['label' => 'Travel time']);
    addFormField($draft, $this->user, 'address', FieldType::CascadingSelect, 3, ['label' => 'Address', 'config' => [
        'levels' => [['key' => 'region'], ['key' => 'city']],
        'options' => [
            ['value' => 'r7', 'label' => 'Central Visayas', 'level' => 'region', 'parent' => null],
            ['value' => 'lapu_lapu', 'label' => 'Lapu-Lapu', 'level' => 'city', 'parent' => 'r7'],
        ],
    ]]);
    $version = app(PublishService::class)->publish($form->refresh(), $this->user);
    $model = app(BlankFormPrintPresenter::class)->present($form->refresh(), $version);

    $fields = ocrMatchFields($this->matcher, $form, $version, $model, [
        'seen_at' => ['03', '10', '2026', '14', '05'],
        'travel' => ['2', '30'],
        'address' => ['R7', 'LAPU-LAPU'],
    ]);

    expect($fields['seen_at'])->toMatchArray(['state' => 'read', 'value' => '2026-10-03T14:05'])
        ->and($fields['travel'])->toMatchArray(['state' => 'read', 'value' => 9000])
        ->and($fields['address'])->toMatchArray(['state' => 'read', 'value' => ['r7', 'lapu_lapu']]);
});

it('reads a page photographed at a tilt exactly as a level one', function (): void {
    $level = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers());
    $tilted = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers(), [], 0.07);

    expect(array_column($tilted, 'value'))->toBe(array_column($level, 'value'));
});

it('reads a photo stored on its side, either way, or upside down exactly as an upright one (M149)', function (): void {
    // A phone keeps a portrait photo on its side and says so only in EXIF, which the recognizer's answer does not
    // carry: all thirty of the bake-off's pages arrived with their words at about -90 degrees, and every question on
    // them was "not found". The page's own word angles say how far it is turned.
    $upright = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers());
    $stamp = strtolower((string) $this->model['schema_stamp']);

    foreach ([1, 2, 3] as $quarters) {
        $page = PrintedPageTypesetter::fromModel($this->model, ocrMatchAnswers())->turned($quarters);
        $fields = $this->matcher->match($this->form, $this->version, [$page])['fields'];

        expect(array_column($fields, 'value'))->toBe(array_column($upright, 'value'), "turned {$quarters} quarter(s)")
            ->and(array_values(array_unique(array_column($fields, 'state'))))->toBe(['read'], "turned {$quarters} quarter(s)")
            ->and($this->matcher->layoutOf([$page], $stamp))->toBe(['layout' => BlankFormPrintPresenter::LAYOUT, 'evidence' => 'token']);
    }
});

it('reads each answer under its own label when the key stamp is read as a line of its own above it (M149)', function (): void {
    // On about half the bake-off's photos the right-aligned key sat a few thousandths of the page above its label and
    // clustered alone. Anchored on it, a region began ABOVE the label — "2. Age 29", which no integer parses — and ran
    // on to the next label, keeping the next question's key under the name.
    $clean = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers());
    $raised = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers(), ['key_rise' => 0.6]);

    expect(array_map(static fn (array $f): mixed => $f['value'], $raised))->toBe(array_map(static fn (array $f): mixed => $f['value'], $clean));

    foreach ($raised as $key => $field) {
        expect($field['state'])->toBe('read', $key)
            ->and($field['anchored_by'])->toBe('key', $key);
    }
});

it('reads a tick the recognizer returns as a symbol as its option\'s mark, never as part of the label (M149)', function (): void {
    // "✓ Male" and "☑ Male" normalise to "male", so the longest-first label search took the tick into the label and
    // the walk back for marks found nothing: 37 of the bake-off's answered choices read blank. ☑ was the commonest
    // glyph Vision returned for a tick; a Greek chi and 区 came back too.
    foreach (['✓', '☑', '☒', '✗', '×', 'Χ', '区'] as $glyph) {
        $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, [
            'consent' => ['Yes'],
            'sex' => ['Male'],
            'symptoms' => ['Fever', 'Fatigue'],
        ], ['marks' => ['consent' => $glyph, 'sex' => $glyph, 'symptoms' => $glyph]]);

        expect($fields['consent'])->toMatchArray(['state' => 'read', 'value' => true], $glyph)
            ->and($fields['sex'])->toMatchArray(['state' => 'read', 'value' => 'm'], $glyph)
            ->and($fields['symptoms'])->toMatchArray(['state' => 'read', 'value' => ['fever', 'fatigue']], $glyph);
    }
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

it('credits a mark to the option it precedes on a shared line, never to the last label on it (M143, layout 2)', function (): void {
    // Layout 2 prints the options side by side. The old reader matched the TAIL of a line to one label and took
    // every mark to its left, so an X before "Female" was Male's — measured red with the layout-2 typesetter
    // before this change. Now each label is a span found in printed order, and a mark belongs to the label it
    // immediately precedes.
    $female = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['sex' => ['Female'], 'symptoms' => ['Cough']]);
    $male = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['sex' => ['Male'], 'symptoms' => ['Fever', 'Fatigue']]);

    expect($female['sex'])->toMatchArray(['state' => 'read', 'value' => 'f'])
        ->and($female['symptoms']['value'])->toBe(['cough'])
        ->and($male['sex'])->toMatchArray(['state' => 'read', 'value' => 'm'])
        ->and($male['symptoms']['value'])->toBe(['fever', 'fatigue']);
});

it('reads a mark run together with its label as that label\'s mark, on a shared line too', function (): void {
    // The pen came close to the text and the recognizer read "XFemale" as one word. "xfemale" is within the
    // plain tolerance of "female", so the run-together reading has to be tried first or the mark is lost.
    $female = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['sex' => ['Female']], ['run_together' => ['sex']]);
    $male = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['sex' => ['Male']], ['run_together' => ['sex']]);

    expect($female['sex'])->toMatchArray(['state' => 'read', 'value' => 'f'])
        ->and($male['sex'])->toMatchArray(['state' => 'read', 'value' => 'm']);
});

it('reads the layout off the running head, and tells old paper from a head that was not read (M143, R-d6546409)', function (): void {
    $pages = fn (array $options): array => [PrintedPageTypesetter::fromModel($this->model, [], $options)->page()];
    $stamp = strtolower((string) $this->model['schema_stamp']);

    expect($this->matcher->layoutOf($pages([]), $stamp))->toBe(['layout' => 4, 'evidence' => 'token'])
        // "Layout4" read as one word.
        ->and($this->matcher->layoutOf($pages(['run_together' => ['runhead']]), $stamp))->toBe(['layout' => 4, 'evidence' => 'token'])
        // Older paper, read for what it is; the job and the bake-off refuse it against the current number.
        ->and($this->matcher->layoutOf($pages(['layout' => 3]), $stamp))->toBe(['layout' => 3, 'evidence' => 'token'])
        ->and($this->matcher->layoutOf($pages(['layout' => 2]), $stamp))->toBe(['layout' => 2, 'evidence' => 'token'])
        ->and($this->matcher->layoutOf($pages(['layout' => 1]), $stamp))->toBe(['layout' => 1, 'evidence' => 'token'])
        // Layout-1 paper: the stamp's own line was legibly read, and no layout word is on it.
        ->and($this->matcher->layoutOf($pages(['layout' => null]), $stamp))->toBe(['layout' => null, 'evidence' => 'absent'])
        // A running head that was not read at all says nothing about the layout — never a refusal.
        ->and($this->matcher->layoutOf($pages(['layout' => null, 'stamp' => null]), null))->toBe(['layout' => null, 'evidence' => 'none'])
        ->and($this->matcher->layoutOf($pages(['layout' => 'Z']), $stamp))->toBe(['layout' => null, 'evidence' => 'garbled']);
});

it('knows all three footer sentences as printed text, so none is read as the last question\'s answer (R-6bbf9d73)', function (): void {
    // The fixture form has scanning switched off, so every page in this file carries the "switched off"
    // sentence inside `notes`' region — the happy path above already proves it stays out. The other two:
    $accepting = ocrMatchFields($this->matcher, $this->form, $this->version, [...$this->model, 'accepts_scans' => true], ocrMatchAnswers());
    $incompatible = ocrMatchFields($this->matcher, $this->form, $this->version, [...$this->model, 'ocr_compatible' => false], ocrMatchAnswers());

    expect($accepting['notes']['value'])->toBe('MILD FEVER FOR TWO DAYS')
        ->and($incompatible['notes']['value'])->toBe('MILD FEVER FOR TWO DAYS');
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

it('puts a value at exactly a threshold on the side the design names: 90 is auto, 70 is review', function (): void {
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ocrMatchAnswers(), ['confidence' => [
        'patient_name' => 0.90,
        'age' => 0.70,
        'notes' => 0.69,
    ]]);

    expect($fields['patient_name'])->toMatchArray(['confidence' => 90, 'tier' => 'auto'])
        ->and($fields['age'])->toMatchArray(['confidence' => 70, 'tier' => 'review', 'value' => '34'])
        ->and($fields['notes'])->toMatchArray(['confidence' => 69, 'tier' => 'manual', 'value' => null]);
});

it('applies thresholds handed to it instead of the configured ones, so zero withholds nothing (M136, the bake-off)', function (): void {
    $page = PrintedPageTypesetter::fromModel($this->model, ocrMatchAnswers(), ['confidence' => ['notes' => 0.40, 'age' => 0.92]])->page();

    $configured = $this->matcher->match($this->form, $this->version, [$page])['fields'];
    $zero = $this->matcher->match($this->form, $this->version, [$page], ['auto' => 0, 'review' => 0])['fields'];
    $strict = $this->matcher->match($this->form, $this->version, [$page], ['auto' => 95, 'review' => 91])['fields'];

    expect($configured['notes'])->toMatchArray(['confidence' => 40, 'tier' => 'manual', 'value' => null])
        ->and($zero['notes'])->toMatchArray(['confidence' => 40, 'tier' => 'auto', 'value' => 'MILD FEVER FOR TWO DAYS'])
        ->and($configured['age'])->toMatchArray(['tier' => 'auto', 'value' => '34'])
        ->and($strict['age'])->toMatchArray(['confidence' => 92, 'tier' => 'review', 'value' => '34'])
        ->and($strict['notes'])->toMatchArray(['tier' => 'manual', 'value' => null]);
});

it('reads a letter O in a number box as a zero, and flags it for review whatever its confidence', function (): void {
    // Since layout 3 the number is written in an open box; the same letter-for-digit fixes apply to the text.
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['age' => '3O']);

    expect($fields['age'])->toMatchArray(['state' => 'read', 'value' => '30', 'tier' => 'review', 'confidence' => 89]);
});

it('drops a box wall read as a bar from an open-box answer, so a number or a phone written against the edge still reads (layout 3, D98)', function (): void {
    // A comb always dropped the wall glyphs; an open box's single left wall is new ink beside a digit string.
    // Without the drop the number would be unreadable and the phone would be STORED with its bar.
    $fields = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, ['age' => '|34', 'mobile' => '|0917 123 4567|']);

    expect($fields['age'])->toMatchArray(['state' => 'read', 'value' => '34', 'tier' => 'auto', 'confidence' => 98])
        ->and($fields['mobile'])->toMatchArray(['state' => 'read', 'value' => '0917 123 4567']);
});

it('reads a paragraph written over several lines of the long-text box as one answer, the lines joined (layout 3, R-d696ba9e)', function (): void {
    // `notes` has a max_length of 200, so its box is five lines tall; the pen uses four, then — on a second
    // sheet — six, spilling below the box. The region runs to the next anchor (here the footer), so both read;
    // the lines are joined with spaces, and the breaks are not kept (a nit filed by M144).
    $notes = collect($this->model['blocks'][0]['fields'])->firstWhere('key', 'notes');
    $four = "MILD FEVER FOR TWO DAYS\nNO COUGH\nAPPETITE NORMAL\nADVISED REST AND FLUIDS";
    $six = $four."\nREVIEW IN THREE DAYS\nREFER IF FEVER PERSISTS";

    $inside = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, [...ocrMatchAnswers(), 'notes' => $four]);
    $spilled = ocrMatchFields($this->matcher, $this->form, $this->version, $this->model, [...ocrMatchAnswers(), 'notes' => $six]);

    expect($notes['lines'])->toBe(5)
        ->and($inside['notes'])->toMatchArray(['state' => 'read', 'value' => 'MILD FEVER FOR TWO DAYS NO COUGH APPETITE NORMAL ADVISED REST AND FLUIDS'])
        ->and($spilled['notes']['value'])->toBe('MILD FEVER FOR TWO DAYS NO COUGH APPETITE NORMAL ADVISED REST AND FLUIDS REVIEW IN THREE DAYS REFER IF FEVER PERSISTS')
        ->and($inside['mobile']['value'])->toBe('0917 123 4567');
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

it('reads what was written for a question that takes its choices from another form, as the answer (M133, D85)', function (): void {
    // A linked list is live, so the blank sheet prints no boxes for it — the template's empty branch draws a write-in
    // box — and what the enumerator writes IS the answer: `D85`, the text shown. Before M133 that writing came back
    // `unreadable`, because the choices reader found no option labels to look for.
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    enterTenant($this->tenant->id, $this->user->id);
    makeActiveMember($this->user, 'admin');

    $source = app(FormService::class)->create($this->tenant, $this->user, 'Facility Register');
    addFormField($source->draftVersion, $this->user, 'facility_name', FieldType::ShortText, 1);
    app(PublishService::class)->publish($source->refresh(), $this->user);
    app(FormService::class)->setDataSharing($source->refresh(), true, null, $this->user);

    $form = app(FormService::class)->create($this->tenant, $this->user, 'Referral');
    addFormField($form->draftVersion, $this->user, 'patient_name', FieldType::ShortText, 1, ['label' => 'Patient name']);
    addFormField($form->draftVersion, $this->user, 'facility', FieldType::Dropdown, 2, ['label' => 'Facility', 'config' => [
        'options' => [], 'options_source' => ['form_id' => $source->id, 'field_key' => 'facility_name'],
    ]]);
    $version = app(PublishService::class)->publish($form->refresh(), $this->user);
    $model = app(BlankFormPrintPresenter::class)->present($form->refresh(), $version);

    // The paper as printed: the linked question's area is a write-in box.
    $printed = $model;
    foreach ($printed['blocks'] as $b => $block) {
        foreach ($block['fields'] as $i => $row) {
            if ($row['key'] === 'facility') {
                expect($row['options'])->toBe([]);
                $printed['blocks'][$b]['fields'][$i] = [...$row, 'area' => 'ruled'];
            }
        }
    }

    $fields = ocrMatchFields($this->matcher, $form->refresh(), $version, $printed, ['patient_name' => 'ANA REYES', 'facility' => 'SAN JOSE RHU']);

    expect($fields['facility'])->toMatchArray(['state' => 'read', 'value' => 'SAN JOSE RHU']);
});
