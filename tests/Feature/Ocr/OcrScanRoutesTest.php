<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\FieldType;
use App\Enums\OcrScanStatus;
use App\Enums\PlanTier;
use App\Jobs\ReadOcrScanJob;
use App\Jobs\ScanAttachmentJob;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\OcrScan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M128 — the single-form OCR door: upload a scan, read how its reading went.
|--------------------------------------------------------------------------
| Driven through the real subdomain pipeline: the manual-encoding `can:` gate, the module toggle, the plan,
| then the service's own refusals. Every 404 and 403 case runs its POSITIVE CONTROL first, so the refusal can
| only come from the boundary under test — a route that does not exist answers 404 too (M122).
|
| ⚠️ Helpers are prefixed `ocrRoute*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
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

    $this->form = ocrRoutePublishedForm($this->tenant, $this->admin, 'Patient Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A published, OCR-eligible form that accepts scans. */
function ocrRoutePublishedForm(Tenant $tenant, User $user, string $title, ?FieldType $extra = null): Form
{
    $form = app(FormService::class)->create($tenant, $user, $title);
    addFormField($form->draftVersion, $user, 'patient_name', FieldType::ShortText, 1);
    addFormField($form->draftVersion, $user, 'age', FieldType::Integer, 2);
    if ($extra !== null) {
        addFormField($form->draftVersion, $user, 'extra', $extra, 3);
    }
    app(PublishService::class)->publish($form->refresh(), $user);

    $form = $form->refresh();
    $form->forceFill(['allow_ocr_single' => true])->save();

    return $form;
}

function ocrRouteUrl(Form $form, string $tail = '', string $host = 'acme'): string
{
    return "http://{$host}.meridian.test/forms/{$form->id}/ocr/scans{$tail}";
}

/** A real 1x1 PNG, so the type is SNIFFED as image/png rather than declared. */
function ocrRoutePng(string $name = 'page.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

function ocrRoutePdf(string $name = 'scan.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
}

it('stores a scan, its pages as private source-scan attachments owned by the scan, and queues its reading', function (): void {
    $response = $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng('one.png'), ocrRoutePng('two.png')]], ['Accept' => 'application/json'])
        ->assertStatus(202);

    expect($response->json('data.status'))->toBe('queued')
        ->and($response->json('data.pages'))->toBe(2);

    enterTenant($this->tenant->id, $this->admin->id);
    $scan = OcrScan::query()->findOrFail($response->json('data.id'));
    $pages = Attachment::query()->whereIn('id', array_column($scan->pages, 'attachment_id'))->get();

    expect($scan->status)->toBe(OcrScanStatus::Queued)
        ->and($scan->uploaded_by)->toBe($this->admin->id)
        ->and($pages)->toHaveCount(2);

    foreach ($pages as $page) {
        expect($page->kind)->toBe(AttachmentKind::OcrSourceScan)
            ->and($page->attachable_type)->toBe('ocr_scan')
            ->and($page->attachable_id)->toBe($scan->id)
            ->and($page->is_pii)->toBeTrue()
            ->and($page->mime_type)->toBe('image/png')
            ->and($page->path)->toStartWith("tenants/{$this->tenant->id}/ocr_source_scan/");
        Storage::disk('local')->assertExists($page->path);
    }

    Queue::assertPushed(ReadOcrScanJob::class, fn (ReadOcrScanJob $job): bool => $job->scanId === $scan->id && $job->tenantId === $this->tenant->id);
    Queue::assertPushed(ScanAttachmentJob::class, 2);
});

it('answers 404 for another workspace\'s form, after the same request works on its own', function (): void {
    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertStatus(202);

    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $betaUser = User::factory()->create();
    enterTenant($beta->id, $betaUser->id);
    $betaForm = ocrRoutePublishedForm($beta, $betaUser, 'Their Form');
    enterTenant($this->tenant->id, $this->admin->id);

    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($betaForm), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertNotFound();
});

it('refuses a member who may not enter responses, after an admin is admitted', function (): void {
    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertStatus(202);

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('says scanning is switched off for the workspace before it ever mentions the plan', function (): void {
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => false], $this->admin);

    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(OcrScan::query()->count())->toBe(0);
});

it('refuses a workspace whose plan does not include scanning, and admits one whose plan does', function (): void {
    assignPlanTier(PlanTier::Starter);
    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertStatus(402);

    enterTenant($this->tenant->id, $this->admin->id);
    assignPlanTier(PlanTier::Professional);
    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertStatus(202);
});

it('refuses a form that has not been opened to scanning', function (): void {
    $this->form->forceFill(['allow_ocr_single' => false])->save();

    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ocr_scanning_off');
});

it('refuses a form whose published version paper cannot carry', function (): void {
    $grid = ocrRoutePublishedForm($this->tenant, $this->admin, 'With a location', FieldType::Geopoint);

    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($grid), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ocr_form_not_eligible');
});

it('refuses a PDF uploaded alongside other pages, and stores nothing', function (): void {
    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePdf(), ocrRoutePng()]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ocr_pdf_not_alone');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Attachment::query()->count())->toBe(0);
});

it('accepts a PDF on its own', function (): void {
    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePdf()]], ['Accept' => 'application/json'])
        ->assertStatus(202)
        ->assertJsonPath('data.pages', 1);
});

it('refuses more pages than the provider reads, and a file that is not an image or a PDF', function (): void {
    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => array_map(static fn (int $i): UploadedFile => ocrRoutePng("p{$i}.png"), range(1, 6))], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['pages']);

    $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [UploadedFile::fake()->createWithContent('notes.txt', 'just text')]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['pages.0']);
});

it('reports a scan under its own form, and 404s the same id asked for under another form', function (): void {
    $id = $this->actingAs($this->admin)
        ->post(ocrRouteUrl($this->form), ['pages' => [ocrRoutePng()]], ['Accept' => 'application/json'])
        ->json('data.id');

    $this->actingAs($this->admin)->getJson(ocrRouteUrl($this->form, "/{$id}"))
        ->assertOk()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.error', null);

    enterTenant($this->tenant->id, $this->admin->id);
    $other = ocrRoutePublishedForm($this->tenant, $this->admin, 'Another Form');

    $this->actingAs($this->admin)->getJson(ocrRouteUrl($other, "/{$id}"))->assertNotFound();
});

it('throttles the upload, because every page is a paid provider call', function (): void {
    expect(Route::getRoutes()->getByName('forms.ocr.scans.store')?->gatherMiddleware())->toContain('throttle:20,1');
});
