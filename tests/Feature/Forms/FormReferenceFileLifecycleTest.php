<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\FormVersionReferenceFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormReferenceFileService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\RestoreService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M132 — a form's reference files across its versions (R-bf49e4c1, D61 = B: frozen per published version).
|--------------------------------------------------------------------------
| Publishing freezes the draft's list into the version and carries it into the next draft; restoring replaces the
| draft's list with the old version's; removing a file keeps it while any version shows it. Driven through the
| services, because each case is about what the versions hold afterwards, not about a request.
|
| ⚠️ Helpers are prefixed `referenceLifecycle*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake(config('filesystems.default'));

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Clinic Intake');
    addFormField($this->form->draftVersion, $this->admin, 'full_name', FieldType::ShortText, 0);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A real PDF upload whose bytes, and so whose digest, `$marker` makes distinct. */
function referenceLifecyclePdf(string $name, string $marker): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm132');
    file_put_contents($path, "%PDF-1.4\n% {$marker}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

    return new UploadedFile($path, $name, null, null, true);
}

/** Attach a file to the form's draft and return its attachment id. */
function referenceLifecycleAttach(Form $form, User $actor, string $name, string $marker): string
{
    return (string) app(FormReferenceFileService::class)->attach($form->refresh(), referenceLifecyclePdf($name, $marker), $actor)['id'];
}

/** @return list<string> a version's files as `label=attachment id`, in display order */
function referenceLifecycleList(string $versionId): array
{
    return array_values(FormVersionReferenceFile::query()
        ->where('form_version_id', $versionId)
        ->orderBy('position')
        ->get()
        ->map(static fn (FormVersionReferenceFile $row): string => "{$row->label}={$row->attachment_id}")
        ->all());
}

it('freezes the draft’s files into the published version and carries the same files into the next draft', function (): void {
    $guide = referenceLifecycleAttach($this->form, $this->admin, 'guide.pdf', 'g');
    $consent = referenceLifecycleAttach($this->form, $this->admin, 'consent.pdf', 'c');
    $firstDraft = (string) $this->form->refresh()->draft_version_id;

    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $form = $this->form->refresh();

    expect($form->current_published_version_id)->toBe($firstDraft)
        ->and(referenceLifecycleList($firstDraft))->toBe(["guide.pdf={$guide}", "consent.pdf={$consent}"])
        // The new draft shows the same FILES — new rows, the same attachment ids — and no byte was copied.
        ->and(referenceLifecycleList((string) $form->draft_version_id))->toBe(["guide.pdf={$guide}", "consent.pdf={$consent}"])
        ->and(FormVersionReferenceFile::query()->count())->toBe(4)
        ->and(Attachment::query()->count())->toBe(2);
});

it('keeps a removed file while a published version shows it, and collects it once no version does', function (): void {
    $published = referenceLifecycleAttach($this->form, $this->admin, 'guide.pdf', 'g');
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $draftOnly = referenceLifecycleAttach($this->form, $this->admin, 'draft.pdf', 'd');

    $service = app(FormReferenceFileService::class);
    $service->detach($this->form->refresh(), $published);
    $service->detach($this->form->refresh(), $draftOnly);

    expect(Attachment::query()->whereKey($published)->exists())->toBeTrue()
        ->and(referenceLifecycleList((string) $this->form->refresh()->current_published_version_id))->toBe(["guide.pdf={$published}"])
        ->and(Attachment::query()->whereKey($draftOnly)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereKey($draftOnly)->first()?->trashed())->toBeTrue();
});

it('replaces the draft’s files with the restored version’s, and collects what only the old draft showed', function (): void {
    $guide = referenceLifecycleAttach($this->form, $this->admin, 'guide.pdf', 'g');
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $source = FormVersion::query()->whereKey($this->form->refresh()->current_published_version_id)->firstOrFail();

    $service = app(FormReferenceFileService::class);
    $service->detach($this->form->refresh(), $guide);
    $newer = referenceLifecycleAttach($this->form, $this->admin, 'newer.pdf', 'n');

    app(RestoreService::class)->restore($this->form->refresh(), $source, $this->admin);

    expect(referenceLifecycleList((string) $this->form->refresh()->draft_version_id))->toBe(["guide.pdf={$guide}"])
        ->and(Attachment::query()->whereKey($newer)->exists())->toBeFalse()
        ->and(Attachment::query()->whereKey($guide)->exists())->toBeTrue();
});

it('lets no path change a published version’s files: the database refuses each write, after a draft write lands', function (): void {
    referenceLifecycleAttach($this->form, $this->admin, 'guide.pdf', 'g');
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $form = $this->form->refresh();
    $frozen = FormVersionReferenceFile::query()->where('form_version_id', $form->current_published_version_id)->sole();
    $draftRow = FormVersionReferenceFile::query()->where('form_version_id', $form->draft_version_id)->sole();
    $other = Attachment::query()->sole();

    // The positive control: the same raw UPDATE on the draft's row lands.
    expect(FormVersionReferenceFile::query()->whereKey($draftRow->id)->update(['label' => 'renamed']))->toBe(1);

    // RLS filters the published row out of an UPDATE and a DELETE (zero rows), and refuses an INSERT outright.
    expect(FormVersionReferenceFile::query()->whereKey($frozen->id)->update(['label' => 'tampered']))->toBe(0)
        ->and(FormVersionReferenceFile::query()->whereKey($frozen->id)->delete())->toBe(0)
        ->and(fn () => DB::transaction(static fn () => FormVersionReferenceFile::create([
            'form_version_id' => $form->current_published_version_id,
            'attachment_id' => $other->id,
            'label' => 'smuggled',
            'position' => 9,
        ])))->toThrow(QueryException::class)
        ->and(FormVersionReferenceFile::query()->whereKey($frozen->id)->value('label'))->toBe('guide.pdf')
        ->and(FormVersionReferenceFile::query()->where('form_version_id', $form->current_published_version_id)->count())->toBe(1);
});

it('takes the form’s row lock before it writes a version’s files, so a publish cannot freeze a half-made change', function (): void {
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    referenceLifecycleAttach($this->form, $this->admin, 'guide.pdf', 'g');

    $lock = array_key_first(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'from "forms"') && str_contains($sql, 'for update')));
    $write = array_key_first(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'insert into "form_version_reference_files"')));

    expect($lock)->not->toBeNull()
        ->and($write)->not->toBeNull()
        ->and($lock < $write)->toBeTrue();
});

it('discards the draft’s files with the draft when the form is archived, and keeps the published version’s', function (): void {
    referenceLifecycleAttach($this->form, $this->admin, 'guide.pdf', 'g');
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $publishedId = (string) $this->form->refresh()->current_published_version_id;

    app(FormService::class)->archive($this->form->refresh(), $this->admin);

    expect(FormVersionReferenceFile::query()->count())->toBe(1)
        ->and(FormVersionReferenceFile::query()->sole()->form_version_id)->toBe($publishedId)
        ->and(FormVersion::query()->whereKey($publishedId)->value('status'))->toBe(FormVersionStatus::Published);
});
