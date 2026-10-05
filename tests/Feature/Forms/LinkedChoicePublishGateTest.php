<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\ResourceCapacity;
use App\Enums\TenantUserStatus;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\FormSettingsPresenter;
use App\Services\Forms\PublishService;
use App\Services\Forms\StructuralValidationGate;
use App\Support\Forms\FieldTypeConversion;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M133 — a choice question that takes its choices from another form: authoring and the publish gate.
|--------------------------------------------------------------------------
| The link is `config.options_source = {form_id, field_key}` on a `single_select` or `dropdown`. Publish refuses every
| link that cannot serve, each with its own code, and exempts a well-linked question from "no options defined".
| Key 2 is the destination's OWNER, never the person publishing.
|
| ⚠️ Helpers are prefixed `linkGate*`: Pest loads every test file into one process.
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

    $this->source = app(FormService::class)->create($this->tenant, $this->admin, 'Facility Register');
    addFormField($this->source->draftVersion, $this->admin, 'facility_name', FieldType::ShortText, 1);
    addFormField($this->source->draftVersion, $this->admin, 'head_name', FieldType::ShortText, 2, ['is_pii' => true]);
    app(PublishService::class)->publish($this->source->refresh(), $this->admin);
    $this->source = app(FormService::class)->setDataSharing($this->source->refresh(), true, null, $this->admin);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A draft destination owned by `$owner` with one linking question.
 *
 * @param  array<string, mixed>  $config
 */
function linkGateDestination(Tenant $tenant, User $owner, array $config, FieldType $type = FieldType::Dropdown): Form
{
    enterTenant($tenant->id, $owner->id);
    $form = app(FormService::class)->create($tenant, $owner, 'Referral');
    addFormField($form->draftVersion, $owner, 'facility', $type, 1, ['config' => $config]);

    return $form->refresh();
}

/** @return list<string> the codes the structural gate (and the linked gate inside it) refuses the draft with */
function linkGateCodes(Form $form): array
{
    return array_map(
        static fn (PublishValidationException $v): string => $v->violations()[0]['code'],
        (new StructuralValidationGate)->collect($form->draftVersion()->firstOrFail()),
    );
}

function linkGateTo(Form $source, string $key = 'facility_name'): array
{
    return ['options' => [], 'options_source' => ['form_id' => $source->id, 'field_key' => $key]];
}

it('publishes a question that takes its choices from a shared question, with no "no options" refusal', function (): void {
    $destination = linkGateDestination($this->tenant, $this->admin, linkGateTo($this->source));

    expect(linkGateCodes($destination))->toBe([]);

    $version = app(PublishService::class)->publish($destination, $this->admin);
    $snapshotField = collect($version->schema_snapshot['fields'])->firstWhere('key', 'facility');

    // The LINK is frozen into the version; the list never is.
    // toEqual, not toBe: jsonb orders an object's keys by length first, so the stored order is the database's.
    expect($snapshotField['config']['options_source'])->toEqual(['field_key' => 'facility_name', 'form_id' => $this->source->id])
        ->and($snapshotField['config']['options'])->toBe([]);
});

it('still refuses an ordinary choice question with no choices at all', function (): void {
    $destination = linkGateDestination($this->tenant, $this->admin, ['options' => []]);

    expect(linkGateCodes($destination))->toBe(['choice_options_invalid']);
});

it('refuses every link that cannot serve, each with its own code', function (Closure $config, string $code, string $type): void {
    $destination = linkGateDestination($this->tenant, $this->admin, $config($this->source), FieldType::from($type));

    expect(linkGateCodes($destination))->toBe([$code]);
    expect(fn () => app(PublishService::class)->publish($destination, $this->admin))
        ->toThrow(PublishValidationException::class);
})->with([
    'a multi-select' => [fn (Form $s) => linkGateTo($s), 'linked_choices_wrong_type', 'multi_select'],
    'no form picked' => [fn (Form $s) => ['options' => [], 'options_source' => ['form_id' => null, 'field_key' => null]], 'linked_choices_incomplete', 'dropdown'],
    'no question picked' => [fn (Form $s) => ['options' => [], 'options_source' => ['form_id' => $s->id, 'field_key' => null]], 'linked_choices_incomplete', 'single_select'],
    'typed choices beside it' => [fn (Form $s) => [...linkGateTo($s), 'options' => [['value' => 'a', 'label' => 'A']]], 'linked_choices_with_typed_options', 'dropdown'],
    'a form that does not exist' => [fn (Form $s) => ['options' => [], 'options_source' => ['form_id' => (string) Str::uuid7(), 'field_key' => 'facility_name']], 'linked_choices_source_missing', 'dropdown'],
    'a personal question' => [fn (Form $s) => linkGateTo($s, 'head_name'), 'linked_choices_not_shared', 'dropdown'],
    'a question that does not exist' => [fn (Form $s) => linkGateTo($s, 'no_such_key'), 'linked_choices_not_shared', 'dropdown'],
]);

it('refuses a link to the form itself', function (): void {
    $destination = linkGateDestination($this->tenant, $this->admin, ['options' => []]);
    FormField::query()->where('form_version_id', $destination->draft_version_id)
        ->update(['config' => json_encode(linkGateTo($destination))]);

    expect(linkGateCodes($destination))->toBe(['linked_choices_self']);
});

it('refuses once the source stops sharing, or stops sharing that question, without any change to the link', function (): void {
    $destination = linkGateDestination($this->tenant, $this->admin, linkGateTo($this->source));
    expect(linkGateCodes($destination))->toBe([]);

    app(FormService::class)->setDataSharing($this->source, false, null, $this->admin);
    expect(linkGateCodes($destination))->toBe(['linked_choices_not_shared']);

    // Switched back on, but sharing only a question that is not the linked one.
    addFormField($this->source->refresh()->draftVersion, $this->admin, 'district', FieldType::ShortText, 3);
    app(PublishService::class)->publish($this->source->refresh(), $this->admin);
    app(FormService::class)->setDataSharing($this->source->refresh(), true, ['district'], $this->admin);
    expect(linkGateCodes($destination))->toBe(['linked_choices_not_shared']);
});

it('asks the destination OWNER, not the person publishing, whether the source responses may be read', function (): void {
    // A form editor owns the destination and holds no grant on the source, so they cannot read its responses.
    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    $destination = linkGateDestination($this->tenant, $editor, linkGateTo($this->source));

    // Even an admin publishing it is refused: the admin's own reach is not the owner's.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(linkGateCodes($destination))->toBe(['linked_choices_owner_cannot_read']);

    // A reviewer grant on the source gives the owner read access, and the same draft passes.
    makeCollaborator($this->source, $editor, ResourceCapacity::Reviewer);
    expect(linkGateCodes($destination))->toBe([]);
});

it('refuses once the owner is no longer an active member, though their role and grants remain', function (): void {
    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'admin');
    $destination = linkGateDestination($this->tenant, $editor, linkGateTo($this->source));

    enterTenant($this->tenant->id, $this->admin->id);
    expect(linkGateCodes($destination))->toBe([]);

    TenantUser::query()->where('user_id', $editor->id)->update(['status' => TenantUserStatus::Suspended]);
    expect(linkGateCodes($destination))->toBe(['linked_choices_owner_cannot_read']);
});

it('saves a link through the builder, and refuses a link carrying any other key', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Referral');
    $add = $this->actingAs($this->admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'dropdown'])->assertOk();
    $patch = fn (array $source) => $this->actingAs($this->admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$add->json('id')}", [
            'key' => $add->json('key'), 'label' => 'Facility', 'is_required' => 'optional',
            'config' => ['options' => [], 'options_source' => $source], 'validations' => [], 'version' => null,
        ]);

    // A half-picked link saves: the builder PATCHes mid-edit, and publish is where it must be whole (M116).
    $patch(['form_id' => $this->source->id, 'field_key' => null])->assertOk();
    $patch(['form_id' => $this->source->id, 'field_key' => 'facility_name'])
        ->assertOk()
        ->assertJsonPath('config.options_source.field_key', 'facility_name');

    $patch(['form_id' => $this->source->id, 'field_key' => 'facility_name', 'filename' => 'x.csv'])->assertUnprocessable();
    $patch(['form_id' => 'not-a-uuid', 'field_key' => 'facility_name'])->assertUnprocessable();
});

it('keeps a link across single choice and dropdown, and drops it onto a multi-select', function (): void {
    $destination = linkGateDestination($this->tenant, $this->admin, linkGateTo($this->source));
    $field = FormField::query()->where('form_version_id', $destination->draft_version_id)->firstOrFail();

    $toSingle = FieldTypeConversion::plan($field, [], FieldType::SingleSelect, [], false);
    expect($toSingle->config['options_source'] ?? null)->toBe(['form_id' => $this->source->id, 'field_key' => 'facility_name']);

    $toMulti = FieldTypeConversion::plan($field, [], FieldType::MultiSelect, [], false);
    expect(array_key_exists('options_source', $toMulti->config))->toBeFalse()
        ->and($toMulti->configDropped)->toContain('options_source');
});

it('offers the builder the sharing forms this author can read, with the questions they share', function (): void {
    $destination = linkGateDestination($this->tenant, $this->admin, ['options' => []]);

    $sources = app(BuilderPresenter::class)->present($destination, $this->admin)['linkable_sources'];

    expect($sources)->toBe([[
        'id' => $this->source->id,
        'title' => 'Facility Register',
        'questions' => [['key' => 'facility_name', 'label' => 'Facility name']],
    ]]);

    // An author who cannot read the source's responses is offered nothing from it, sharing or not.
    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    expect(app(BuilderPresenter::class)->present($destination, $editor)['linkable_sources'])->toBe([]);
    enterTenant($this->tenant->id, $this->admin->id);

    // Sharing off: nothing to offer.
    app(FormService::class)->setDataSharing($this->source, false, null, $this->admin);
    expect(app(BuilderPresenter::class)->present($destination, $this->admin)['linkable_sources'])->toBe([]);
});

it('lists a form that takes its choices from this one in the source Data sharing section', function (): void {
    $destination = linkGateDestination($this->tenant, $this->admin, linkGateTo($this->source));
    app(PublishService::class)->publish($destination, $this->admin);

    $section = app(FormSettingsPresenter::class)->dataSharing($this->source->refresh(), $this->admin);

    expect($section['used_by'])->toBe([['id' => $destination->id, 'title' => 'Referral']])
        ->and($section['used_by_others'])->toBe(0);
});
