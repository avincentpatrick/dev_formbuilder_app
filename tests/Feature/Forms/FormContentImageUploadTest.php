<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Enums\ScanStatus;
use App\Enums\UsageMetric;
use App\Exceptions\Attachments\AttachmentException;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M129 — an image for a note's content (R-f0c5b682, D58 = B): the upload, and staff reading it back.
|--------------------------------------------------------------------------
| Driven through the real subdomain pipeline. Every refusal runs its POSITIVE CONTROL first, so the refusal can
| only come from the boundary under test — a route that does not exist answers 404 too (M122).
|
| ⚠️ THE READ CASES MAKE ONE REQUEST EACH, from fixtures written before it. `AttachmentPolicyTest` records why: a
| second actor in one test resolves its permissions from the first request's state.
|
| ⚠️ Helpers are prefixed `contentImage*`: Pest loads every test file into one process.
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

function contentImageUrl(Form $form, string $host = 'acme'): string
{
    return "http://{$host}.meridian.test/forms/{$form->id}/content-images";
}

/** A real 1x1 PNG, so the type is SNIFFED as image/png rather than declared. */
function contentImagePng(string $name = 'map.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

/**
 * A REAL uploaded file made from `$bytes`, so its type is sniffed from them.
 *
 * ⛔ NOT `UploadedFile::fake()`. A fake reports its type from its NAME (`Testing\File::getMimeType()` is
 * `MimeType::from($this->name)`), so an SVG named `.png` sails through a fake-driven case and proves nothing about the
 * server — measured: the first version of the case below passed an SVG with a 201 for exactly that reason.
 */
function contentImageRealFile(string $name, string $bytes): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm129');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/** A form's image written straight to the table and the disk, for the cases that make exactly one request. */
function contentImageStored(Form $form, array $state = []): Attachment
{
    $attachment = Attachment::factory()->create([
        'attachable_type' => 'form',
        'attachable_id' => $form->id,
        'kind' => AttachmentKind::FormContentImage,
        'mime_type' => 'image/png',
        'original_filename' => 'map.png',
        'virus_scan_status' => ScanStatus::Clean,
        ...$state,
    ]);
    Storage::disk($attachment->disk)->put($attachment->path, 'PNGBYTES');

    return $attachment;
}

function contentImageMember(Tenant $tenant, string $role): User
{
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, $role);

    return $user;
}

it('stores an image as the form’s own, not personal data, and answers with what the editor shows', function (): void {
    $response = $this->actingAs($this->admin)
        ->post(contentImageUrl($this->form), ['file' => contentImagePng()], ['Accept' => 'application/json'])
        ->assertCreated();

    enterTenant($this->tenant->id, $this->admin->id);
    $attachment = Attachment::query()->sole();
    // `servable` is true here only because the test queue runs the virus check inline; the editor reads it.
    expect($response->json('data'))->toBe([
        'id' => $attachment->id,
        'url' => "/attachments/{$attachment->id}",
        'servable' => true,
        'width' => 1,
        'height' => 1,
    ])
        ->and($attachment->kind)->toBe(AttachmentKind::FormContentImage)
        ->and($attachment->attachable_type)->toBe('form')
        ->and($attachment->attachable_id)->toBe($this->form->id)
        ->and($attachment->is_pii)->toBeFalse()
        ->and($attachment->mime_type)->toBe('image/png')
        ->and($attachment->uploaded_by)->toBe($this->admin->id)
        ->and($attachment->path)->toStartWith("tenants/{$this->tenant->id}/form_content_image/");
    Storage::disk($attachment->disk)->assertExists($attachment->path);
});

it('refuses a file that is not a PNG, JPEG or WebP image or is over 2 MB, after a PNG is accepted, and stores none of them', function (string $case): void {
    $this->actingAs($this->admin)
        ->post(contentImageUrl($this->form), ['file' => contentImagePng()], ['Accept' => 'application/json'])
        ->assertCreated();

    [$file, $message] = match ($case) {
        // The bytes decide, not the name: both of these say they are PNGs. An SVG can carry script.
        'an SVG named .png' => [
            contentImageRealFile('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'An image must be a PNG, JPEG or WebP file. SVG and GIF are not accepted.',
        ],
        'a GIF named .png' => [
            contentImageRealFile('spinner.png', (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')),
            'An image must be a PNG, JPEG or WebP file. SVG and GIF are not accepted.',
        ],
        default => [contentImagePng('big.png')->size(2049), 'An image must be 2 MB or smaller.'],
    };

    $this->actingAs($this->admin)
        ->post(contentImageUrl($this->form), ['file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => $message]);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Attachment::query()->count())->toBe(1);
})->with(['an SVG named .png', 'a GIF named .png', 'over 2 MB']);

it('refuses the same files in the write path itself, for any caller that does not come through the request', function (): void {
    $store = fn (UploadedFile $file) => fn () => app(AttachmentStorageService::class)
        ->storeFormContentImage($file, $this->tenant->id, $this->form->id, $this->admin->id);

    expect($store(contentImageRealFile('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')))
        ->toThrow(AttachmentException::class, 'are not accepted')
        ->and($store(contentImagePng('big.png')->size(2049)))
        ->toThrow(AttachmentException::class, 'exceeds');

    expect(Attachment::query()->count())->toBe(0);
});

it('refuses an image once the plan’s storage is used up, in words the editor can show', function (): void {
    $this->actingAs($this->admin)
        ->post(contentImageUrl($this->form), ['file' => contentImagePng()], ['Accept' => 'application/json'])
        ->assertCreated();

    enterTenant($this->tenant->id, $this->admin->id);
    $plan = Plan::factory()->tier(PlanTier::Free)->withQuotas([UsageMetric::StorageBytes->value => 10])->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget();

    $this->actingAs($this->admin)
        ->post(contentImageUrl($this->form), ['file' => contentImagePng('second.png')], ['Accept' => 'application/json'])
        ->assertStatus(402)
        ->assertJsonPath('message', "You have reached your plan's storage limit. Free up space or upgrade your plan to upload more.");

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Attachment::query()->count())->toBe(1);
});

it('refuses an upload from a member who may not edit the form, after its editor is admitted', function (): void {
    $this->actingAs($this->admin)
        ->post(contentImageUrl($this->form), ['file' => contentImagePng()], ['Accept' => 'application/json'])
        ->assertCreated();

    $viewer = contentImageMember($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->post(contentImageUrl($this->form), ['file' => contentImagePng()], ['Accept' => 'application/json'])
        ->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Attachment::query()->count())->toBe(1);
});

it('answers 404 for another workspace’s form, after the same request works on its own', function (): void {
    $this->actingAs($this->admin)
        ->post(contentImageUrl($this->form), ['file' => contentImagePng()], ['Accept' => 'application/json'])
        ->assertCreated();

    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $betaUser = User::factory()->create();
    enterTenant($beta->id, $betaUser->id);
    $betaForm = app(FormService::class)->create($beta, $betaUser, 'Their Form');
    enterTenant($this->tenant->id, $this->admin->id);

    $this->actingAs($this->admin)
        ->post(contentImageUrl($betaForm), ['file' => contentImagePng()], ['Accept' => 'application/json'])
        ->assertNotFound();
});

it('gates the upload on editing the form, and throttles it, because every image is stored and scanned', function (): void {
    expect(Route::getRoutes()->getByName('forms.content-images.store')?->gatherMiddleware())
        ->toContain('can:update,form')
        ->toContain('throttle:30,1');
});

it('offers in the editor exactly the types and the size the server accepts', function (): void {
    $source = (string) file_get_contents(resource_path('js/components/builder/ContentBlocksEditor.vue'));
    preg_match('/accept="([^"]+)"/', $source, $accept);

    expect(explode(',', $accept[1] ?? ''))->toBe(config('attachments.form_content_image.accepted_types'))
        ->and($source)->toContain('up to '.(config('attachments.form_content_image.max_bytes') / 1024 / 1024).' MB');
});

/*
| Reading an image back: `GET /attachments/{attachment}` under `AttachmentPolicy`'s `FormContentImage` arm, which asks
| whether the reader may open the form (`FormPolicy::viewOverview`).
*/

it('serves a form’s image inline to whoever may open the form', function (string $role, bool $collaborates): void {
    $attachment = contentImageStored($this->form);
    $reader = $role === 'admin' ? $this->admin : contentImageMember($this->tenant, $role);
    if ($collaborates) {
        makeCollaborator($this->form, $reader, ResourceCapacity::Editor);
    }

    $response = $this->actingAs($reader)->get("http://acme.meridian.test/attachments/{$attachment->id}");

    $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->streamedContent())->toBe('PNGBYTES');
})->with([
    'its admin' => ['admin', false],
    'a viewer, who reads every form' => ['viewer', false],
    'a form editor who collaborates on it' => ['form_editor', true],
]);

it('refuses a form’s image to a form editor who does not collaborate on that form', function (): void {
    // The positive control is the dataset above: the same role, with the grant, is served.
    $attachment = contentImageStored($this->form);
    $editor = contentImageMember($this->tenant, 'form_editor');

    $this->actingAs($editor)->get("http://acme.meridian.test/attachments/{$attachment->id}")->assertForbidden();
});

it('withholds an image until its virus check has passed', function (): void {
    $attachment = contentImageStored($this->form, ['virus_scan_status' => ScanStatus::Pending]);

    // JSON, because an HTML 409 has no error view and falls through to the debug page, which took 30 seconds a case
    // to render here (measured: 40s HTML, under 1s JSON). The status is the same either way.
    $this->actingAs($this->admin)
        ->get("http://acme.meridian.test/attachments/{$attachment->id}", ['Accept' => 'application/json'])
        ->assertStatus(409);
});

it('fails closed for an image of this kind that is not held by a form', function (): void {
    $attachment = contentImageStored($this->form, ['attachable_type' => 'tenant', 'attachable_id' => $this->tenant->id]);

    $this->actingAs($this->admin)->get("http://acme.meridian.test/attachments/{$attachment->id}")->assertForbidden();
});
