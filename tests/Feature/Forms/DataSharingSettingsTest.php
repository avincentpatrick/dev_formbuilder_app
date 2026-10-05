<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\FieldType;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\FormSettingsPresenter;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M133 — the Data sharing setting (PATCH /forms/{form}/data-sharing), Connect project v1's SOURCE key.
|--------------------------------------------------------------------------
| The only writer of `forms.data_sharing_enabled` and `forms.data_sharing_field_keys`. What it must hold:
|   - switching on needs the author's acknowledgement (a shared answer is readable through a linking form's
|     public link);
|   - `field_keys` is null for every shareable question and never `[]` — refused by the request AND the CHECK;
|   - a key must name a shareable question of the CURRENT PUBLISHED version: never a personal or sensitive one
|     (`D86`), never one inside a repeat group, never a type that is not one short answer, never a draft-only one;
|   - only someone who may edit the form AND read its responses may change it (`D87`).
|
| ⚠️ Helpers are prefixed `dataSharing*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->form = dataSharingSourceForm($this->tenant, $this->admin);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A published facility register: two shareable questions, and one of each kind that must never be shared — a
 * personal one, a sensitive one, an email, a long text, a multi-select and a member of a repeat group.
 */
function dataSharingSourceForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Facility Register');
    $draft = $form->draftVersion;
    addFormField($draft, $owner, 'facility_name', FieldType::ShortText, 1, ['label' => 'Facility name']);
    addFormField($draft, $owner, 'district', FieldType::Dropdown, 2, ['label' => 'District', 'config' => ['options' => [
        ['value' => 'north', 'label' => 'North'], ['value' => 'south', 'label' => 'South'],
    ]]]);
    addFormField($draft, $owner, 'head_name', FieldType::ShortText, 3, ['is_pii' => true]);
    addFormField($draft, $owner, 'diagnosis', FieldType::ShortText, 4, ['is_sensitive' => true]);
    addFormField($draft, $owner, 'contact_email', FieldType::Email, 5);
    addFormField($draft, $owner, 'notes', FieldType::LongText, 6);
    addFormField($draft, $owner, 'services', FieldType::MultiSelect, 7, ['config' => ['options' => [['value' => 'x', 'label' => 'X']]]]);
    $section = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'staff', 'label' => 'Staff',
        'sequence' => 1, 'is_repeatable' => true, 'min_instances' => 0, 'max_instances' => 5,
    ]);
    addFormField($draft, $owner, 'staff_name', FieldType::ShortText, 8, ['form_section_id' => $section->id, 'section_sequence' => 0]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

function dataSharingUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/data-sharing";
}

function dataSharingReload(Form $form, User $as): Form
{
    enterTenant($form->tenant_id, $as->id);

    return Form::findOrFail($form->id);
}

it('starts every new form not sharing, in the model AND in the row', function (): void {
    // The `M120` trap, both halves: `FormService::create()` never names the key, so only the model's default
    // keeps a fresh instance from reading null while its row reads false.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Fresh');

    expect($form->data_sharing_enabled)->toBeFalse()
        ->and($form->data_sharing_field_keys)->toBeNull();

    expect(dataSharingReload($form, $this->admin)->data_sharing_enabled)->toBeFalse();
});

it('switches sharing on only with the acknowledgement, and records who changed what', function (): void {
    $this->actingAs($this->admin)->patch(dataSharingUrl($this->form), ['enabled' => true, 'field_keys' => null])
        ->assertSessionHasErrors('acknowledged');
    expect(dataSharingReload($this->form, $this->admin)->data_sharing_enabled)->toBeFalse();

    $this->actingAs($this->admin)
        ->patch(dataSharingUrl($this->form), ['enabled' => true, 'acknowledged' => true, 'field_keys' => null])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast.message', 'Other forms can now use answers from this form.');

    $form = dataSharingReload($this->form, $this->admin);
    expect($form->data_sharing_enabled)->toBeTrue()
        ->and($form->data_sharing_field_keys)->toBeNull();

    $audit = Audit::query()->where('auditable_type', 'form')->where('auditable_id', $this->form->id)
        ->where('event', AuditEvent::Updated->value)->latest('id')->firstOrFail();
    expect($audit->old_values)->toBe(['data_sharing_enabled' => false, 'data_sharing_field_keys' => null])
        ->and($audit->new_values)->toBe(['data_sharing_enabled' => true, 'data_sharing_field_keys' => null])
        ->and($audit->user_id)->toBe($this->admin->id);
});

it('switches sharing back off without asking again', function (): void {
    app(FormService::class)->setDataSharing($this->form, true, null, $this->admin);

    $this->actingAs($this->admin)->patch(dataSharingUrl($this->form), ['enabled' => false, 'field_keys' => null])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(dataSharingReload($this->form, $this->admin)->data_sharing_enabled)->toBeFalse();
});

it('stores a chosen list of shareable questions', function (): void {
    $this->actingAs($this->admin)
        ->patch(dataSharingUrl($this->form), ['enabled' => true, 'acknowledged' => true, 'field_keys' => ['district']])
        ->assertSessionHasNoErrors();

    expect(dataSharingReload($this->form, $this->admin)->data_sharing_field_keys)->toBe(['district']);
});

it('refuses an empty list at the request, so "none" can never be stored as if it meant "all"', function (): void {
    $this->actingAs($this->admin)
        ->patch(dataSharingUrl($this->form), ['enabled' => true, 'acknowledged' => true, 'field_keys' => []])
        ->assertSessionHasErrors(['field_keys' => 'Choose at least one question, or share them all.']);

    expect(dataSharingReload($this->form, $this->admin)->data_sharing_enabled)->toBeFalse();
});

it('refuses an empty list at the database too', function (): void {
    expect(fn () => DB::table('forms')->where('id', $this->form->id)->update(['data_sharing_field_keys' => '[]']))
        ->toThrow(QueryException::class, 'forms_data_sharing_field_keys_check');
});

it('refuses to share a question that is personal, sensitive, repeated, not one short answer, or not published', function (string $key): void {
    if ($key === 'draft_only') {
        addFormField($this->form->refresh()->draftVersion, $this->admin, 'draft_only', FieldType::ShortText, 9);
    }

    $this->actingAs($this->admin)
        ->patch(dataSharingUrl($this->form), ['enabled' => true, 'acknowledged' => true, 'field_keys' => ['facility_name', $key]])
        ->assertSessionHasErrors('field_keys');

    expect(dataSharingReload($this->form, $this->admin)->data_sharing_enabled)->toBeFalse();
})->with(['head_name', 'diagnosis', 'contact_email', 'notes', 'services', 'staff_name', 'draft_only', 'no_such_key']);

it('refuses to switch on a form with nothing published', function (): void {
    $draftOnly = app(FormService::class)->create($this->tenant, $this->admin, 'Unpublished');
    addFormField($draftOnly->draftVersion, $this->admin, 'facility_name', FieldType::ShortText, 1);

    $this->actingAs($this->admin)
        ->patch(dataSharingUrl($draftOnly), ['enabled' => true, 'acknowledged' => true, 'field_keys' => null])
        ->assertSessionHasErrors(['enabled' => 'Publish this form before sharing its answers.']);

    expect(dataSharingReload($draftOnly, $this->admin)->data_sharing_enabled)->toBeFalse();
});

it('refuses a member who cannot edit the form', function (): void {
    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)
        ->patch(dataSharingUrl($this->form), ['enabled' => true, 'acknowledged' => true, 'field_keys' => null])
        ->assertForbidden();

    expect(dataSharingReload($this->form, $this->admin)->data_sharing_enabled)->toBeFalse();
});

it('refuses someone who may edit the form but cannot read its responses (D87), and shows them no section', function (array $permissions): void {
    // Two shapes, because the route stacks two gates and each must refuse on its own: one member may read responses but cannot open the
    // form's overview; the other can, and holds every dashboard key, but not `submissions.view`.
    $editorOnly = memberHoldingOnly(...$permissions);

    $this->actingAs($editorOnly)
        ->patch(dataSharingUrl($this->form), ['enabled' => true, 'acknowledged' => true, 'field_keys' => null])
        ->assertForbidden();

    enterTenant($this->tenant->id, $editorOnly->id);
    expect(app(FormSettingsPresenter::class)->dataSharing(Form::findOrFail($this->form->id), $editorOnly))->toBeNull();
    expect(dataSharingReload($this->form, $this->admin)->data_sharing_enabled)->toBeFalse();
})->with([
    'the responses, but no overview' => [['forms.edit.any', 'submissions.view']],
    'the overview, but no responses' => [['forms.edit.any', 'dashboard.form.view', 'dashboard.org.view']],
]);

it('lists only the shareable questions of the published version, in the order the form asks them', function (): void {
    $section = app(FormSettingsPresenter::class)->dataSharing($this->form, $this->admin);

    expect($section)->not->toBeNull()
        ->and($section['enabled'])->toBeFalse()
        ->and($section['published'])->toBeTrue()
        ->and($section['questions'])->toBe([
            ['key' => 'facility_name', 'label' => 'Facility name'],
            ['key' => 'district', 'label' => 'District'],
        ])
        ->and($section['used_by'])->toBe([])
        ->and($section['used_by_others'])->toBe(0);
});

it('sends the section to the builder and the hub alike', function (): void {
    $this->withoutVite()->actingAs($this->admin)->get("http://acme.meridian.test/forms/{$this->form->id}/settings")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('forms/Settings', false)
            ->where('data_sharing.questions.0.key', 'facility_name')
            ->where('data_sharing.enabled', false));

    $this->withoutVite()->actingAs($this->admin)->get("http://acme.meridian.test/forms/{$this->form->id}/builder")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('forms/Builder', false)
            ->where('data_sharing.questions.1.key', 'district'));
});
