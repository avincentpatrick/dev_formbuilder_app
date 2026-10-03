<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OcrScanStatus;
use App\Enums\PlanTier;
use App\Exceptions\Entitlements\ModuleDisabledException;
use App\Exceptions\Ocr\OcrException;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Settings\TenantSettingRegistry;
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
| M129 — the scans page, and the "Scan paper forms" entry on a form's Responses tab.
|--------------------------------------------------------------------------
| Both answer the same question — may this reader scan this form? — so both are asked it as the scan routes
| ask it: `can:create` on a submission, then the module toggle before the plan. The entry additionally needs
| the form's own opt-in; the page renders without it, to say why uploading is off.
|
| ⚠️ Helpers are prefixed `ocrList*`: Pest loads every test file into one process.
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
    $this->admin = User::factory()->create(['name' => 'Ana Reyes']);
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->form = ocrListForm($this->tenant, $this->admin, 'Clinic Visit');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function ocrListForm(Tenant $tenant, User $user, string $title, ?FieldType $extra = null): Form
{
    $form = app(FormService::class)->create($tenant, $user, $title);
    addFormField($form->draftVersion, $user, 'patient_name', FieldType::ShortText, 1);
    if ($extra !== null) {
        addFormField($form->draftVersion, $user, 'extra', $extra, 2);
    }
    app(PublishService::class)->publish($form->refresh(), $user);

    $form = $form->refresh();
    $form->forceFill(['allow_ocr_single' => true])->save();

    return $form;
}

function ocrListUrl(Form $form, string $tail = '/ocr/scans'): string
{
    return "http://acme.meridian.test/forms/{$form->id}{$tail}";
}

it('lists this form\'s scans, newest first, each with where it leads', function (): void {
    $older = ReadScanFixture::make($this->form, $this->admin, ['patient_name' => ReadScanFixture::read('short_text', 'A', 'A', 96)], ['created_at' => now()->subHour()]);
    $saved = ReadScanFixture::make($this->form, $this->admin, ['patient_name' => ReadScanFixture::read('short_text', 'B', 'B', 96)], [
        'created_at' => now()->subMinutes(30), 'submission_id' => '0192e2e0-0000-7000-8000-0000000000bb', 'confirmed_at' => now(),
    ]);
    $failed = ReadScanFixture::make($this->form, $this->admin, [], [
        'status' => OcrScanStatus::Failed, 'extraction' => null, 'error_code' => 'unreadable_file', 'error_message' => 'Could not open it.',
    ]);
    $elsewhere = ReadScanFixture::make(ocrListForm($this->tenant, $this->admin, 'Another Form'), $this->admin, []);

    $this->actingAs($this->admin)->get(ocrListUrl($this->form))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ocr/Scans', false)
            ->where('accepts_scans', true)
            ->where('refusal', null)
            ->where('upload.url', "/forms/{$this->form->id}/ocr/scans")
            ->where('upload.max_pages', 5)
            ->has('scans', 3)
            ->where('scans.0.id', $failed->id)
            ->where('scans.0.status_label', 'Could not be read')
            ->where('scans.0.error_message', 'Could not open it.')
            ->where('scans.1.id', $saved->id)
            ->where('scans.1.status', 'saved')
            ->where('scans.1.review_url', null)
            ->where('scans.1.submission_url', '/submissions/0192e2e0-0000-7000-8000-0000000000bb')
            ->where('scans.2.id', $older->id)
            ->where('scans.2.status_label', 'Ready to review')
            ->where('scans.2.review_url', "/forms/{$this->form->id}/ocr/scans/{$older->id}/review")
            ->where('scans.2.uploaded_by', 'Ana Reyes')
            ->where('crumbs.3.label', 'Scanned forms'));

    expect($elsewhere->form_id)->not->toBe($this->form->id);
});

it('renders for a form that does not accept scans, and says so in the upload\'s own words', function (): void {
    $this->form->forceFill(['allow_ocr_single' => false])->save();

    $this->actingAs($this->admin)->get(ocrListUrl($this->form))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accepts_scans', false)
            ->where('refusal', OcrException::scanningOff()->getMessage()));
});

it('says a form paper cannot carry will not be read', function (): void {
    $grid = ocrListForm($this->tenant, $this->admin, 'With a location', FieldType::Geopoint);

    $this->actingAs($this->admin)->get(ocrListUrl($grid))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('refusal', OcrException::formNotEligible()->getMessage()));
});

it('refuses a member who may not enter responses, after an admin is admitted', function (): void {
    $this->actingAs($this->admin)->get(ocrListUrl($this->form))->assertOk();

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->get(ocrListUrl($this->form))->assertForbidden();
});

it('refuses a workspace that switched scanning off, and one whose plan does not include it', function (): void {
    $this->actingAs($this->admin)->get(ocrListUrl($this->form))->assertOk();

    // A page request is refused the way every plan gate refuses one on the web: back, with the reason as a
    // toast. (A JSON request gets the 402 — `OcrScanRoutesTest` asserts that on the upload.)
    enterTenant($this->tenant->id, $this->admin->id);
    assignPlanTier(PlanTier::Starter);
    $this->actingAs($this->admin)->get(ocrListUrl($this->form))
        ->assertRedirect()
        ->assertSessionHas('toast.message', "Your plan doesn't include ocr_single. Upgrade your plan to use it.");

    // The module is asked BEFORE the plan, so a workspace that switched scanning off is told that, not told
    // to upgrade — even on this Starter plan.
    enterTenant($this->tenant->id, $this->admin->id);
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => false], $this->admin);
    $this->actingAs($this->admin)->get(ocrListUrl($this->form))
        ->assertRedirect()
        ->assertSessionHas('toast.message', ModuleDisabledException::forModule('ocr_single')->getMessage());
});

it('offers "Scan paper forms" on the Responses tab only where the scans page admits the reader and the form accepts scans', function (): void {
    $this->actingAs($this->admin)->get(ocrListUrl($this->form, '/submissions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scan_url', "/forms/{$this->form->id}/ocr/scans"));

    enterTenant($this->tenant->id, $this->admin->id);
    $this->form->forceFill(['allow_ocr_single' => false])->save();
    $this->actingAs($this->admin)->get(ocrListUrl($this->form, '/submissions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scan_url', null));

    enterTenant($this->tenant->id, $this->admin->id);
    $this->form->forceFill(['allow_ocr_single' => true])->save();
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => false], $this->admin);
    $this->actingAs($this->admin)->get(ocrListUrl($this->form, '/submissions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scan_url', null));
});

it('does not offer scanning to a member who may read responses but not enter them', function (): void {
    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->get(ocrListUrl($this->form, '/submissions'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scan_url', null));
});
