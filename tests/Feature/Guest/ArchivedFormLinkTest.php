<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Forms\GuestReachability;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M146 (`R-f6567fc2`, `D99` A) — archiving closes the public link.
|--------------------------------------------------------------------------
| `FormService::archive()` discards the draft and leaves `allow_guest_submissions` and the published version as they
| were, and until M146 no guest route read the form's status: an archived form kept minting, rendering and accepting
| responses through `/f/{slug}` while the forms list hid it. Now every guest entry reads one predicate,
| `Form::allowsGuestAccess()`, and answers an archived form exactly as it answers one with guest access off — the web
| routes 404 (non-disclosure), the API routes 403 `guest_disabled`, the three reads their own enveloped 404 — so a
| queued offline response parks on its first replay (`replay.test.ts`) instead of landing on a form nobody can find.
|
| ⚠️ EVERY REFUSAL RUNS ITS POSITIVE CONTROL FIRST on the open form, then archives it, then asks again: a route that
| does not exist answers 404 too (M122), so a refusal alone proves nothing about the boundary under test.
|
| ⚠️ Helpers are prefixed `archivedLink*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake(config('filesystems.default'));

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function archivedLinkWeb(string $path): string
{
    return 'http://acme.meridian.test'.$path;
}

function archivedLinkApi(string $path): string
{
    return 'http://acme.meridian.test/api/v1/public'.$path;
}

function archivedLinkCsv(string $name, string $content): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm146');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * The open form, reaching every guest read: a required `full_name` and an `age`; a dropdown taking its choices from a
 * sharing register (the linked-choices read); a cascade from two CSV files (the choice-lists read); a note showing one
 * image (the content-image read); guest access and save-and-resume on.
 *
 * @return array{0: Form, 1: Attachment}
 */
function archivedLinkForm(Tenant $tenant, User $owner): array
{
    $register = app(FormService::class)->create($tenant, $owner, 'Facility Register');
    addFormField($register->draftVersion, $owner, 'facility_name', FieldType::ShortText, 1);
    app(PublishService::class)->publish($register->refresh(), $owner);
    $register = app(FormService::class)->setDataSharing($register->refresh(), true, null, $owner);

    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    $image = Attachment::factory()->create([
        'attachable_type' => 'form',
        'attachable_id' => $form->id,
        'kind' => AttachmentKind::FormContentImage,
        'mime_type' => 'image/png',
        'original_filename' => 'entrance.png',
        'virus_scan_status' => ScanStatus::Clean,
    ]);
    Storage::disk($image->disk)->put($image->path, 'PNGBYTES');

    $lists = app(FormChoiceListService::class);
    $lists->upload($form->refresh(), archivedLinkCsv('regions.csv', "name,label\n01,Ilocos Region\n13,NCR\n"));
    $lists->upload($form->refresh(), archivedLinkCsv('provinces.csv', "name,label,region\n0128,Ilocos Norte,01\n1339,Manila,13\n"));

    $draft = $form->refresh()->draftVersion;
    addFormField($draft, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($draft, $owner, 'age', FieldType::Integer, 1);
    addFormField($draft, $owner, 'facility', FieldType::Dropdown, 2, ['config' => [
        'options' => [], 'options_source' => ['form_id' => $register->id, 'field_key' => 'facility_name'],
    ]]);
    addFormField($draft, $owner, 'address', FieldType::CascadingSelect, 3, ['config' => [
        'levels' => [['key' => 'region', 'label' => 'Region', 'list' => 'regions'], ['key' => 'province', 'label' => 'Province', 'list' => 'provinces']],
        'options' => [],
    ]]);
    addFormField($draft, $owner, 'welcome', FieldType::Note, 4, [
        'label' => 'Welcome',
        'config' => ['content' => [
            ['type' => 'paragraph', 'spans' => [['text' => 'Find us here:']]],
            ['type' => 'image', 'attachment_id' => $image->id, 'alt' => 'The clinic entrance'],
        ]],
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update(['public_slug' => 'intake', 'allow_guest_submissions' => true, 'save_and_resume' => true]);

    return [$form->refresh(), $image];
}

/** Archive the form as its owner, re-entering the tenant first: an HTTP call leaves the test's connection without it. */
function archivedLinkArchive(Form $form, User $owner): Form
{
    enterTenant($form->tenant_id, $owner->id);
    app(FormService::class)->archive($form, $owner);

    return $form->refresh();
}

it('closes the public link once the form is archived — the mint, the page, the manifest and the predicate', function (): void {
    [$form] = archivedLinkForm($this->tenant, $this->owner);

    $this->getJson(archivedLinkWeb('/f/intake'))->assertOk()->assertJsonStructure(['shareToken']);
    $this->get(archivedLinkWeb('/f/intake/manifest.webmanifest'))->assertOk();
    expect(GuestReachability::reachable($form))->toBeTrue();

    $form = archivedLinkArchive($form, $this->owner);

    expect(GuestReachability::reachable($form))->toBeFalse();
    $this->getJson(archivedLinkWeb('/f/intake'))->assertNotFound();
    $this->withoutVite()->get(archivedLinkWeb('/f/intake'))->assertNotFound();
    $this->get(archivedLinkWeb('/f/intake/manifest.webmanifest'))->assertNotFound();
});

it('refuses the schema read and the submit on a token minted before the archive, and records no response', function (): void {
    [$form] = archivedLinkForm($this->tenant, $this->owner);
    $token = shareTokenFor($form);

    $this->getJson(archivedLinkApi("/f/{$token}"))->assertOk();
    $this->postJson(archivedLinkApi("/f/{$token}/submissions"), [
        'answers' => ['full_name' => 'Ada', 'age' => '36'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertCreated();

    $form = archivedLinkArchive($form, $this->owner);

    $this->getJson(archivedLinkApi("/f/{$token}"))->assertForbidden()->assertJsonPath('error.code', 'guest_disabled');
    $this->postJson(archivedLinkApi("/f/{$token}/submissions"), [
        'answers' => ['full_name' => 'Grace', 'age' => '40'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertForbidden()->assertJsonPath('error.code', 'guest_disabled');

    // The whole defect: a response landing on a form its author can no longer find.
    enterTenant($this->tenant->id, $this->owner->id);
    expect(Submission::query()->count())->toBe(1);
});

it('refuses an attachment upload and a draft save on a token minted before the archive', function (): void {
    [$form] = archivedLinkForm($this->tenant, $this->owner);
    $token = shareTokenFor($form);
    $upload = fn () => $this->post(archivedLinkApi("/f/{$token}/attachments"), [
        'file' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'), 'field_key' => 'full_name',
    ], ['Accept' => 'application/json']);

    // The open form gets past the guest guard to the field lookup (`full_name` takes no file) — any answer but
    // `guest_disabled` is the control; a media field is not needed to tell the two branches apart.
    expect($upload()->json('error.code'))->not->toBe('guest_disabled');
    $this->postJson(archivedLinkApi("/f/{$token}/draft"), [
        'answers' => ['age' => '30'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertCreated();

    $form = archivedLinkArchive($form, $this->owner);

    $upload()->assertForbidden()->assertJsonPath('error.code', 'guest_disabled');
    $this->postJson(archivedLinkApi("/f/{$token}/draft"), [
        'answers' => ['age' => '31'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertForbidden()->assertJsonPath('error.code', 'guest_disabled');
});

it('refuses to resume a saved draft, by the resume link and by the resume read', function (): void {
    [$form] = archivedLinkForm($this->tenant, $this->owner);
    $token = shareTokenFor($form);
    $resume = $this->postJson(archivedLinkApi("/f/{$token}/draft"), [
        'answers' => ['age' => '30'], 'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertCreated()->json('data.resume_token');

    $this->getJson(archivedLinkApi("/drafts/{$resume}"))->assertOk();
    $this->withoutVite()->get(archivedLinkWeb("/f/resume/{$resume}"))->assertOk();

    $form = archivedLinkArchive($form, $this->owner);

    $this->getJson(archivedLinkApi("/drafts/{$resume}"))->assertForbidden()->assertJsonPath('error.code', 'guest_disabled');
    $this->withoutVite()->get(archivedLinkWeb("/f/resume/{$resume}"))->assertNotFound();
});

it('refuses the three reads beside the schema — the note image, the linked choices and the CSV choice lists', function (): void {
    [$form, $image] = archivedLinkForm($this->tenant, $this->owner);
    $token = shareTokenFor($form);
    $version = (string) $form->current_published_version_id;

    $this->get(archivedLinkApi("/content-images/{$token}/{$image->id}"))->assertOk();
    $this->getJson(archivedLinkApi("/linked-choices/{$token}/{$version}"))->assertOk();
    $this->getJson(archivedLinkApi("/choice-lists/{$token}/{$version}"))->assertOk();

    $form = archivedLinkArchive($form, $this->owner);

    $this->getJson(archivedLinkApi("/content-images/{$token}/{$image->id}"))
        ->assertNotFound()->assertJsonPath('error.code', 'image_not_found');
    $this->getJson(archivedLinkApi("/linked-choices/{$token}/{$version}"))
        ->assertNotFound()->assertJsonPath('error.code', 'linked_choices_not_found');
    $this->getJson(archivedLinkApi("/choice-lists/{$token}/{$version}"))
        ->assertNotFound()->assertJsonPath('error.code', 'choice_lists_not_found');
});
