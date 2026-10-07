<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OcrScanStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\OcrScan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Ocr\Support\ReadScanFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M129 — the review screen: a read scan becomes the encode page in scan mode, for the CURRENT version.
|--------------------------------------------------------------------------
| What a reviewer starts from is decided here: which answers carry, as what type, and what is said about the
| rest. The old-paper matrix is `D74` (answered in chat): an answer carries to the same key when the type is the
| same and the current question can hold the value; everything else is listed, never saved unseen.
|
| ⚠️ Helpers are prefixed `ocrReview*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    // These cases render whole pages, and CI builds no Vite manifest: without the suite opt-out every render is a
    // 500 there while it passes here, where the dev server or a local build supplies one (measured, M129).
    $this->withoutVite();
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
    Queue::fake();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->form = ocrReviewForm($this->tenant, $this->admin);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A published form with one question of every type the review has to treat differently. */
function ocrReviewForm(Tenant $tenant, User $user): Form
{
    $form = app(FormService::class)->create($tenant, $user, 'Clinic Visit');
    $draft = $form->draftVersion;
    addFormField($draft, $user, 'patient_name', FieldType::ShortText, 1);
    addFormField($draft, $user, 'age', FieldType::Integer, 2);
    addFormField($draft, $user, 'weight', FieldType::Decimal, 3);
    addFormField($draft, $user, 'sex', FieldType::SingleSelect, 4, ['config' => ['options' => [
        ['value' => 'female', 'label' => 'Female'], ['value' => 'male', 'label' => 'Male'],
    ]]]);
    addFormField($draft, $user, 'symptoms', FieldType::MultiSelect, 5, ['config' => ['options' => [
        ['value' => 'fever', 'label' => 'Fever'], ['value' => 'cough', 'label' => 'Cough'],
    ]]]);
    addFormField($draft, $user, 'consent', FieldType::YesNo, 6);
    addFormField($draft, $user, 'visit_date', FieldType::Date, 7);
    addFormField($draft, $user, 'notes', FieldType::LongText, 8);
    addFormField($draft, $user, 'time_spent', FieldType::Duration, 9);
    app(PublishService::class)->publish($form->refresh(), $user);

    $form = $form->refresh();
    $form->forceFill(['allow_ocr_single' => true])->save();

    return $form;
}

function ocrReviewUrl(Form $form, OcrScan|string $scan): string
{
    $id = $scan instanceof OcrScan ? $scan->id : $scan;

    return "http://acme.meridian.test/forms/{$form->id}/ocr/scans/{$id}/review";
}

/** @return array<string, array<string, mixed>> what a typical sheet of this form read as */
function ocrReviewSheet(): array
{
    return [
        'patient_name' => ReadScanFixture::read('short_text', 'Maria Santos', 'MARIA SANTOS', 96),
        'age' => ReadScanFixture::read('integer', '41', '41', 82, 'review'),
        'weight' => ReadScanFixture::read('decimal', '61.5', '61.5', 95),
        'sex' => ReadScanFixture::read('single_select', 'female', 'X Female', 97),
        'symptoms' => ReadScanFixture::read('multi_select', ['fever'], 'X Fever', 55, 'manual'),
        'consent' => ReadScanFixture::blank('yes_no'),
        'visit_date' => ReadScanFixture::read('date', '2026-10-01', '01 10 2026', 93),
        'notes' => ReadScanFixture::unreadable('long_text', 'scribble', 30),
        'time_spent' => ReadScanFixture::read('duration', 5400, '1 30', 95),
    ];
}

it('shows a scan still being read as the waiting page, with the route it polls and the way to key it instead', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, [], [
        'status' => OcrScanStatus::Queued, 'extraction' => null, 'read_at' => null, 'form_version_id' => null,
    ]);

    $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ocr/ScanStatus', false)
            ->where('scan.status', 'queued')
            ->where('scan.status_label', 'Waiting to be read')
            ->where('scan.poll_url', "/forms/{$this->form->id}/ocr/scans/{$scan->id}")
            ->where('encode_url', "/forms/{$this->form->id}/submissions/create")
            ->where('scans_url', "/forms/{$this->form->id}/ocr/scans"));
});

it('shows a scan that could not be read with the reason it ended on', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, [], [
        'status' => OcrScanStatus::Failed, 'extraction' => null, 'error_code' => 'unreadable_file',
        'error_message' => 'The reading service could not open this file.',
    ]);

    $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ocr/ScanStatus', false)
            ->where('scan.status', 'failed')
            ->where('scan.error_message', 'The reading service could not open this file.'));
});

it('renders a read scan as the encode page in scan mode, with no draft channel at all', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, ocrReviewSheet());

    $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('draft_url', null)
            ->where('draft', null)
            ->where('editing', null)
            ->where('scan.id', $scan->id)
            ->where('scan.submit_url', "/forms/{$this->form->id}/ocr/scans/{$scan->id}/confirm")
            ->where('scan.pages.0.number', 1)
            ->where('scan.pages.0.url', "/forms/{$this->form->id}/ocr/scans/{$scan->id}/pages/1")
            ->where('scan.pages.0.mime', 'image/png')
            ->where('scan.pages.0.servable', true)
            ->where('crumbs.3.label', 'Scanned forms')
            ->where('crumbs.3.href', "/forms/{$this->form->id}/ocr/scans")
            ->where('crumbs.4.label', 'Review a scan')
            ->where('cancel_url', "/forms/{$this->form->id}/ocr/scans"));
});

it('starts the reviewer from the answers the scan read, numbers as numbers', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, ocrReviewSheet());

    $answers = $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))
        ->assertOk()
        ->viewData('page')['props']['scan']['answers'];

    // ⚠️ `toBe`, never `toEqual`: the point is the TYPE. The reader returns '41', and the number control shows
    // only a number, so a string here is a box that looks empty while the map holds a value.
    expect($answers['age'])->toBe(41)
        ->and($answers['weight'])->toBe(61.5)
        ->and($answers['patient_name'])->toBe('Maria Santos')
        ->and($answers['sex'])->toBe('female')
        ->and($answers['visit_date'])->toBe('2026-10-01');
});

it('withholds what the scan was not sure of, and keeps the text it saw for the note', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, ocrReviewSheet());

    $props = $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))->viewData('page')['props']['scan'];

    expect(array_key_exists('symptoms', $props['answers']))->toBeFalse('a manual-tier answer must not be filled in')
        ->and(array_key_exists('notes', $props['answers']))->toBeFalse('an unreadable answer must not be filled in')
        ->and(array_key_exists('consent', $props['answers']))->toBeFalse('a blank answer must stay blank')
        ->and($props['fields']['symptoms']['tier'])->toBe('manual')
        ->and($props['fields']['symptoms']['text'])->toBe('X Fever')
        ->and($props['fields']['symptoms']['carried'])->toBeFalse()
        ->and($props['fields']['notes']['state'])->toBe('unreadable')
        ->and($props['fields']['consent']['state'])->toBe('blank')
        ->and($props['fields']['age']['tier'])->toBe('review')
        ->and($props['fields']['age']['carried'])->toBeTrue();
});

it('lists a duration rather than saving it, because no channel can show one', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, ocrReviewSheet());

    $props = $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))->viewData('page')['props']['scan'];
    $notice = collect($props['notices'])->firstWhere('code', 'not_carried');

    expect(array_key_exists('time_spent', $props['answers']))->toBeFalse()
        ->and($props['fields']['time_spent']['reason'])->toBe('not_answerable')
        ->and($notice)->not->toBeNull()
        ->and(array_column($notice['items'], 'label'))->toBe(['Time spent'])
        ->and($notice['items'][0]['text'])->toBe('1 30');
});

it('carries old paper onto the current version question by question, and lists every answer that cannot carry', function (): void {
    $printed = FormVersion::query()->whereKey($this->form->current_published_version_id)->firstOrFail();

    // Republish with four changes a sheet printed from the first version cannot follow.
    $draft = $this->form->refresh()->draftVersion;
    FormField::query()->where('form_version_id', $draft->id)->where('key', 'age')->update(['field_type' => FieldType::ShortText->value]);
    FormField::query()->where('form_version_id', $draft->id)->where('key', 'sex')->update(['config' => ['options' => [
        ['value' => 'man', 'label' => 'Man'], ['value' => 'woman', 'label' => 'Woman'],
    ]]]);
    FormField::query()->where('form_version_id', $draft->id)->where('key', 'notes')->delete();
    addFormField($draft, $this->admin, 'phone', FieldType::Phone, 10);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $this->form->refresh();

    expect($this->form->current_published_version_id)->not->toBe($printed->id);

    $sheet = ocrReviewSheet();
    $sheet['notes'] = ReadScanFixture::read('long_text', 'Came back after a week', 'Came back after a week', 91);
    $scan = ReadScanFixture::make($this->form, $this->admin, $sheet, printedFrom: $printed);

    $props = $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))
        ->assertOk()
        ->viewData('page')['props']['scan'];

    $notCarried = collect($props['notices'])->firstWhere('code', 'not_carried');
    $reasons = collect($notCarried['items'])->pluck('message', 'label')->all();

    expect($props['answers']['patient_name'])->toBe('Maria Santos')
        ->and(array_key_exists('age', $props['answers']))->toBeFalse('a question whose type changed must not take the old value')
        ->and(array_key_exists('sex', $props['answers']))->toBeFalse('a choice no longer offered must not be filled in')
        ->and($props['fields']['age']['reason'])->toBe('type_changed')
        ->and($props['fields']['sex']['reason'])->toBe('option_missing')
        ->and($props['fields']['phone']['state'])->toBe('not_on_paper')
        ->and(array_keys($reasons))->toEqualCanonicalizing(['Age', 'Sex', 'Notes', 'Time spent'])
        ->and($reasons['Notes'])->toBe('This question is not in the current version of the form.')
        ->and(collect($props['notices'])->pluck('code')->all())->toContain('old_paper')
        ->and($props['version']['number'])->toBe($printed->version_number)
        ->and($props['version']['current_number'])->toBeGreaterThan($printed->version_number);
});

it('says what the reading could not vouch for before the reviewer checks a single answer', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, ocrReviewSheet(), warnings: ['version_unconfirmed', 'layout_unconfirmed', 'pages_beyond_limit']);
    $notices = collect($this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))->viewData('page')['props']['scan']['notices']);
    $codes = $notices->pluck('code')->all();

    expect($codes)->toContain('version_unconfirmed')
        // M143: the layout mark (R-d6546409) — a warning with words, never a refusal, when it was merely not read.
        ->and(in_array('layout_unconfirmed', $codes, true))->toBeTrue()
        ->and($notices->firstWhere('code', 'layout_unconfirmed')['message'])->toContain('Layout 3 beside the version stamp')
        ->and(in_array('pages_beyond_limit', $codes, true))->toBeTrue()
        ->and(in_array('nothing_read', $codes, true))->toBeFalse();

    enterTenant($this->tenant->id, $this->admin->id);
    $empty = ReadScanFixture::make($this->form, $this->admin, ['patient_name' => ReadScanFixture::blank('short_text')]);
    $codes = collect($this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $empty))->viewData('page')['props']['scan']['notices'])->pluck('code')->all();

    expect(in_array('nothing_read', $codes, true))->toBeTrue();
});

it('sends a scan that was already saved to its response instead of reviewing it twice', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, ocrReviewSheet());
    $id = '0192e2e0-0000-7000-8000-0000000000aa';
    $scan->forceFill(['submission_id' => $id, 'confirmed_at' => now(), 'confirmed_by' => $this->admin->id])->save();

    $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))
        ->assertRedirect("/submissions/{$id}");
});

it('answers 404 for a scan asked for under another form, after the same scan reviews under its own', function (): void {
    $scan = ReadScanFixture::make($this->form, $this->admin, ocrReviewSheet());
    $this->actingAs($this->admin)->get(ocrReviewUrl($this->form, $scan))->assertOk();

    enterTenant($this->tenant->id, $this->admin->id);
    $other = ocrReviewForm($this->tenant, $this->admin);

    $this->actingAs($this->admin)->get(ocrReviewUrl($other, $scan))->assertNotFound();
});
