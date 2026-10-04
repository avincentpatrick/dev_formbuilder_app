<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Exceptions\Attachments\AttachmentException;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormVersionReferenceFile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M132 — a form's reference files (R-bf49e4c1, D61 = B): attaching, renaming and removing them on the draft.
|--------------------------------------------------------------------------
| Driven through the real subdomain pipeline. Every refusal runs its POSITIVE CONTROL first, so the refusal can
| only come from the boundary under test — a route that does not exist answers 404 too (M122).
|
| ⚠️ Files are REAL uploads made from bytes (`referenceFileReal()`), never `UploadedFile::fake()` for a type case:
| a fake reports its type from its NAME, so a renamed file would pass for what it claims to be (M129).
|
| ⚠️ Helpers are prefixed `referenceFile*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake(config('filesystems.default'));

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Clinic Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function referenceFileUrl(Form $form, ?string $file = null, string $host = 'acme'): string
{
    return "http://{$host}.meridian.test/forms/{$form->id}/reference-files".($file === null ? '' : "/{$file}");
}

/** A REAL uploaded file made from `$bytes`, so its type is sniffed from them (see the header). */
function referenceFileReal(string $name, string $bytes): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm132');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/** A minimal PDF; `$marker` makes its bytes, and so its digest, distinct. */
function referenceFilePdf(string $name = 'guide.pdf', string $marker = 'A'): UploadedFile
{
    return referenceFileReal($name, "%PDF-1.4\n% {$marker}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

function referenceFilePng(string $name = 'map.png'): UploadedFile
{
    return referenceFileReal($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

function referenceFileMember(Tenant $tenant, string $role): User
{
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, $role);

    return $user;
}

it('stores a PDF as the form’s own file and lists it on the draft under its own name', function (): void {
    $response = $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('Visit guide.pdf')], ['Accept' => 'application/json'])
        ->assertCreated();

    enterTenant($this->tenant->id, $this->admin->id);
    $attachment = Attachment::query()->sole();
    $row = FormVersionReferenceFile::query()->sole();

    // `checking`: the virus check is queued inside the write's transaction and runs only once it commits — inline
    // in the test queue, a worker's job in production — so the upload's own answer always predates it.
    expect($response->json('data'))->toBe([
        'id' => $attachment->id,
        'label' => 'Visit guide.pdf',
        'file_name' => 'Visit guide.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => $attachment->size_bytes,
        'scan' => 'checking',
        'url' => "/attachments/{$attachment->id}",
    ])
        ->and($attachment->kind)->toBe(AttachmentKind::FormReferenceFile)
        ->and($attachment->attachable_type)->toBe('form')
        ->and($attachment->attachable_id)->toBe($this->form->id)
        ->and($attachment->is_pii)->toBeFalse()
        ->and($attachment->checksum_sha256)->toHaveLength(64)
        ->and($attachment->path)->toStartWith("tenants/{$this->tenant->id}/form_reference_file/")
        ->and($row->form_version_id)->toBe($this->form->refresh()->draft_version_id)
        ->and($row->attachment_id)->toBe($attachment->id)
        ->and($row->position)->toBe(1);
    Storage::disk($attachment->disk)->assertExists($attachment->path);

    // What the panel polls says so once the check has run.
    $this->actingAs($this->admin)->getJson(referenceFileUrl($this->form))->assertJsonPath('data.0.scan', 'ready');
});

it('lists the draft’s files in the order they were attached, for the panel to poll', function (): void {
    $this->actingAs($this->admin)->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('first.pdf', 'one')], ['Accept' => 'application/json'])->assertCreated();
    $this->actingAs($this->admin)->post(referenceFileUrl($this->form), ['file' => referenceFilePng('second.png')], ['Accept' => 'application/json'])->assertCreated();

    $this->actingAs($this->admin)->getJson(referenceFileUrl($this->form))
        ->assertOk()
        ->assertJsonPath('data.0.label', 'first.pdf')
        ->assertJsonPath('data.1.label', 'second.png')
        ->assertJsonPath('data.1.mime_type', 'image/png')
        ->assertJsonCount(2, 'data');
});

it('refuses a file that is not a PDF, PNG, JPEG or WebP or is over 10 MB, after a PDF is accepted, and stores none', function (string $case): void {
    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf()], ['Accept' => 'application/json'])
        ->assertCreated();

    [$file, $message] = match ($case) {
        // The bytes decide, not the name: each of these says it is a PDF.
        'an SVG named .pdf' => [
            referenceFileReal('guide.pdf', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'A reference file must be a PDF, or a PNG, JPEG or WebP image.',
        ],
        'an HTML page named .pdf' => [
            referenceFileReal('guide.pdf', '<!doctype html><html><body><script>alert(1)</script></body></html>'),
            'A reference file must be a PDF, or a PNG, JPEG or WebP image.',
        ],
        default => [UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'), 'A reference file must be 10 MB or smaller.'],
    };

    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => $message]);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Attachment::query()->count())->toBe(1)
        ->and(FormVersionReferenceFile::query()->count())->toBe(1);
})->with(['an SVG named .pdf', 'an HTML page named .pdf', 'over 10 MB']);

it('refuses the same files in the write path itself, for any caller that does not come through the request', function (): void {
    $store = fn (UploadedFile $file) => fn () => app(AttachmentStorageService::class)
        ->storeFormReferenceFile($file, $this->tenant->id, $this->form->id, $this->admin->id);

    expect($store(referenceFileReal('guide.pdf', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')))
        ->toThrow(AttachmentException::class, 'are not accepted')
        ->and($store(UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')))
        ->toThrow(AttachmentException::class, 'exceeds');

    expect(Attachment::query()->count())->toBe(0);
});

it('stores identical bytes once per form: a second copy reuses the stored file, with no storage charged', function (): void {
    $first = $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('guide.pdf', 'same')], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data.id');

    // Removed from the draft while the published version still shows it — so the file is kept, not collected.
    enterTenant($this->tenant->id, $this->admin->id);
    addFormField($this->form->refresh()->draftVersion, $this->admin, 'full_name', FieldType::ShortText, 0);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $this->actingAs($this->admin)->deleteJson(referenceFileUrl($this->form, $first))->assertNoContent();

    // Storage is now full: a NEW file would be refused, so the re-attach succeeding proves nothing was charged.
    enterTenant($this->tenant->id, $this->admin->id);
    $plan = Plan::factory()->tier(PlanTier::Free)->withQuotas([UsageMetric::StorageBytes->value => 10])->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget();

    $again = $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('guide (copy).pdf', 'same')], ['Accept' => 'application/json'])
        ->assertCreated();

    enterTenant($this->tenant->id, $this->admin->id);
    expect($again->json('data.id'))->toBe($first)
        ->and($again->json('data.label'))->toBe('guide (copy).pdf')
        ->and(Attachment::query()->count())->toBe(1);

    // The control: different bytes are a new file, and the full storage refuses it.
    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('other.pdf', 'different')], ['Accept' => 'application/json'])
        ->assertStatus(402);
});

it('refuses the same file twice on one draft, in words the panel can show', function (): void {
    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('guide.pdf', 'same')], ['Accept' => 'application/json'])
        ->assertCreated();

    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('renamed.pdf', 'same')], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'This file is already attached to the form.']);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormVersionReferenceFile::query()->count())->toBe(1);
});

it('refuses an eleventh file on one version, after the tenth is accepted', function (): void {
    foreach (range(1, 10) as $n) {
        $this->actingAs($this->admin)
            ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf("guide-{$n}.pdf", "n{$n}")], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('guide-11.pdf', 'n11')], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'A form can show at most 10 reference files. Remove one to add another.']);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Attachment::query()->count())->toBe(10);
});

it('refuses a new file once the plan’s storage is used up, in words the panel can show', function (): void {
    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf()], ['Accept' => 'application/json'])
        ->assertCreated();

    enterTenant($this->tenant->id, $this->admin->id);
    $plan = Plan::factory()->tier(PlanTier::Free)->withQuotas([UsageMetric::StorageBytes->value => 10])->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget();

    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('second.pdf', 'B')], ['Accept' => 'application/json'])
        ->assertStatus(402)
        ->assertJsonPath('message', "You have reached your plan's storage limit. Free up space or upgrade your plan to upload more.");

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Attachment::query()->count())->toBe(1);
});

it('renames a file on the draft, and refuses a blank name, after a real name is accepted', function (): void {
    $id = $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf()], ['Accept' => 'application/json'])
        ->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson(referenceFileUrl($this->form, $id), ['label' => '  Visit guide (English)  '])
        ->assertOk()
        ->assertJsonPath('data.label', 'Visit guide (English)');

    $this->actingAs($this->admin)
        ->patchJson(referenceFileUrl($this->form, $id), ['label' => '   '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['label' => 'Give the file a name respondents will recognise.']);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormVersionReferenceFile::query()->sole()->label)->toBe('Visit guide (English)');
});

it('answers 404 to a rename or a removal of a file the draft does not show, after the same request works', function (string $verb): void {
    $id = $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf()], ['Accept' => 'application/json'])
        ->json('data.id');

    $send = fn (string $file) => $verb === 'rename'
        ? $this->actingAs($this->admin)->patchJson(referenceFileUrl($this->form, $file), ['label' => 'Guide'])
        : $this->actingAs($this->admin)->deleteJson(referenceFileUrl($this->form, $file));

    $send((string) Str::uuid())->assertNotFound();
    $send($id)->assertSuccessful();
})->with(['rename', 'removal']);

it('refuses every write from a member who may not edit the form, after its editor is admitted', function (): void {
    $id = $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf()], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data.id');

    $viewer = referenceFileMember($this->tenant, 'viewer');

    $this->actingAs($viewer)->post(referenceFileUrl($this->form), ['file' => referenceFilePdf('x.pdf', 'X')], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($viewer)->patchJson(referenceFileUrl($this->form, $id), ['label' => 'Mine'])->assertForbidden();
    $this->actingAs($viewer)->deleteJson(referenceFileUrl($this->form, $id))->assertForbidden();
    $this->actingAs($viewer)->getJson(referenceFileUrl($this->form))->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormVersionReferenceFile::query()->sole()->label)->toBe('guide.pdf');
});

it('answers 404 for another workspace’s form, after the same request works on its own', function (): void {
    $this->actingAs($this->admin)
        ->post(referenceFileUrl($this->form), ['file' => referenceFilePdf()], ['Accept' => 'application/json'])
        ->assertCreated();

    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $betaUser = User::factory()->create();
    enterTenant($beta->id, $betaUser->id);
    $betaForm = app(FormService::class)->create($beta, $betaUser, 'Their Form');
    enterTenant($this->tenant->id, $this->admin->id);

    $this->actingAs($this->admin)
        ->post(referenceFileUrl($betaForm), ['file' => referenceFilePdf('x.pdf', 'X')], ['Accept' => 'application/json'])
        ->assertNotFound();
});

it('gates every route on editing the form, and throttles the upload, because every file is stored and scanned', function (): void {
    // Declared middleware: no route here excludes any, and the resolved stack spells `can:` as a class name.
    $stack = static fn (string $name): array => Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

    expect($stack('forms.reference-files.store'))->toContain('can:update,form')
        ->and(in_array('throttle:30,1', $stack('forms.reference-files.store'), true))->toBeTrue()
        ->and($stack('forms.reference-files.index'))->toContain('can:update,form')
        ->and($stack('forms.reference-files.update'))->toContain('can:update,form')
        ->and($stack('forms.reference-files.destroy'))->toContain('can:update,form');
});

it('offers in the panel exactly the types and the size the server accepts', function (): void {
    $source = (string) file_get_contents(resource_path('js/components/forms/ReferenceFilesPanel.vue'));
    preg_match('/accept="([^"]+)"/', $source, $accept);

    expect(explode(',', $accept[1] ?? ''))->toBe(config('attachments.form_reference_file.accepted_types'))
        ->and($source)->toContain('up to '.(config('attachments.form_reference_file.max_bytes') / 1024 / 1024).' MB');
});
