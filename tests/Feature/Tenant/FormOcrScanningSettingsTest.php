<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Exceptions\Entitlements\ModuleDisabledException;
use App\Models\Form;
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
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M129 — the Scanning setting (PATCH /forms/{form}/ocr-scanning), the only writer of `forms.allow_ocr_single`.
|--------------------------------------------------------------------------
| The upload has read the column since `M128` with nothing able to set it, so the cases below go end to end: a
| scan refused before the switch, accepted after it. The route's gates are `can:update,form`, then the module
| BEFORE the plan, so a workspace that switched scanning off is told so rather than told to upgrade.
|
| ⚠️ Helpers are prefixed `ocrSetting*`: Pest loads every test file into one process.
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

    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Clinic Visit');
    addFormField($this->form->draftVersion, $this->admin, 'patient_name', FieldType::ShortText, 1);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $this->form->refresh();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function ocrSettingUrl(Form $form, string $tail = '/ocr-scanning'): string
{
    return "http://acme.meridian.test/forms/{$form->id}{$tail}";
}

function ocrSettingPng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('page.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

it('starts every new form refusing scans, in the model AND in the row', function (): void {
    // Both halves, through the sole real creation path — the `M120` trap: a `Form::create()` that omits the key
    // returns a model reading `null` while the row reads `false`, and the Scanning section publishes it as a
    // boolean.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Fresh');

    expect($form->allow_ocr_single)->toBeFalse();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->allow_ocr_single)->toBeFalse();
});

it('switches scanning on and back off, so the submitted value is read rather than assumed', function (): void {
    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => true])
        ->assertRedirect()
        ->assertSessionHas('toast.message', 'This form now accepts scans of its paper copies.');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($this->form->id)->allow_ocr_single)->toBeTrue();

    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => false])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($this->form->id)->allow_ocr_single)->toBeFalse();
});

it('is what lets a scan in: refused before the switch, accepted after it', function (): void {
    $this->withoutVite()->actingAs($this->admin)
        ->post(ocrSettingUrl($this->form, '/ocr/scans'), ['pages' => [ocrSettingPng()]], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ocr_scanning_off');

    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => true])->assertRedirect();

    $this->withoutVite()->actingAs($this->admin)
        ->post(ocrSettingUrl($this->form, '/ocr/scans'), ['pages' => [ocrSettingPng()]], ['Accept' => 'application/json'])
        ->assertStatus(202);
});

it('refuses a member without can:update,form, after an admin is admitted', function (): void {
    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => true])->assertRedirect();

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->withoutVite()->actingAs($viewer)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => false])->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($this->form->id)->allow_ocr_single)->toBeTrue();
});

it('requires the flag and refuses anything that is not a boolean', function (): void {
    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), [])
        ->assertSessionHasErrors('allow_ocr_single');
    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => 'maybe'])
        ->assertSessionHasErrors('allow_ocr_single');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($this->form->id)->allow_ocr_single)->toBeFalse();
});

it('says a workspace switched scanning off before it ever mentions the plan', function (): void {
    assignPlanTier(PlanTier::Starter);
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => false], $this->admin);

    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => true])
        ->assertRedirect()
        ->assertSessionHas('toast.message', ModuleDisabledException::forModule('ocr_single')->getMessage());

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($this->form->id)->allow_ocr_single)->toBeFalse();
});

it('refuses a workspace whose plan does not include scanning, and admits one whose plan does', function (): void {
    assignPlanTier(PlanTier::Starter);
    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => true])
        ->assertRedirect()
        ->assertSessionHas('toast.message', "Your plan doesn't include ocr_single. Upgrade your plan to use it.");

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($this->form->id)->allow_ocr_single)->toBeFalse();

    assignPlanTier(PlanTier::Professional);
    $this->withoutVite()->actingAs($this->admin)->patch(ocrSettingUrl($this->form), ['allow_ocr_single' => true])->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($this->form->id)->allow_ocr_single)->toBeTrue();
});
