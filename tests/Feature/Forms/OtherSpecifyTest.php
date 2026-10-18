<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M177 — "Other, please specify" (`R-71509975`).
|--------------------------------------------------------------------------
| The user (2026-10-16): a choice question gets a setting that adds an "Other" choice and a "Please specify" question
| shown only when it is picked. One request turns the pair on, in one transaction — the choice added (or an existing
| `other` adopted, the library "Sex" item's case), a Short text made after it, shown only when `selected(${key}, 'other')`
| and required when shown, linked by `config.other_specify_key` — and one turns it off, removing both.
|
| ⚠️ Helpers are prefixed `otherSpecify*`: Pest loads every test file into one process.
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
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey')->refresh();
    $this->withHeaders(builderHeaders($this->form));
    $this->builder = app(FormBuilderService::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A choice question with the given choices, keyed `occupation`. */
function otherSpecifyQuestion(object $test, FieldType $type = FieldType::SingleSelect, array $options = [['value' => 'farmer', 'label' => 'Farmer']]): FormField
{
    $field = $test->builder->addField($test->form, $test->admin, $type, null);
    $field->forceFill(['key' => 'occupation', 'config' => ['options' => $options]])->save();

    return $field->refresh();
}

function otherSpecifyUrl(object $test, FormField $field): string
{
    return "http://acme.meridian.test/forms/{$test->form->id}/fields/{$field->id}/other-specify";
}

function otherSpecifyFresh(object $test, string $key): ?FormField
{
    enterTenant($test->tenant->id, $test->admin->id);

    return FormField::query()->where('form_version_id', $test->form->refresh()->draft_version_id)->where('key', $key)->first();
}

it('adds an Other choice and a Please specify question shown only when Other is picked, in one request', function (): void {
    $parent = otherSpecifyQuestion($this);

    $body = $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $parent))->assertOk()->json();

    expect($body['parent']['config']['options'])->toEqual([['value' => 'farmer', 'label' => 'Farmer'], ['value' => 'other', 'label' => 'Other']])
        ->and($body['parent']['config']['other_specify_key'])->toBe('occupation_other')
        ->and($body['child'])->toMatchArray([
            'key' => 'occupation_other',
            'field_type' => 'short_text',
            'label' => 'Please specify',
            'is_required' => 'required',
            'relevant_expression' => "selected(\${occupation}, 'other')",
            'form_section_id' => $parent->form_section_id,
        ])
        ->and($body['parent'])->toHaveKey('version'); // the parent's new token, so the next edit is not a conflict

    $child = otherSpecifyFresh($this, 'occupation_other');
    expect($child?->field_type)->toBe(FieldType::ShortText)
        ->and($child?->is_required)->toBe(RequiredMode::Required);
});

it('adopts a choice already valued other rather than adding a second one', function (): void {
    $parent = otherSpecifyQuestion($this, FieldType::SingleSelect, [['value' => 'female', 'label' => 'Female'], ['value' => 'other', 'label' => 'Something else']]);

    $options = $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $parent))->assertOk()->json('parent.config.options');

    expect($options)->toEqual([['value' => 'female', 'label' => 'Female'], ['value' => 'other', 'label' => 'Something else']]);
});

it('works on a multiple choice and a dropdown', function (FieldType $type): void {
    $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, otherSpecifyQuestion($this, $type)))
        ->assertOk()
        ->assertJsonPath('child.relevant_expression', "selected(\${occupation}, 'other')");
})->with([FieldType::MultiSelect, FieldType::Dropdown]);

it('refuses a question that has no typed choices to add Other to', function (FieldType $type, array $config): void {
    $field = $this->builder->addField($this->form, $this->admin, $type, null);
    $field->forceFill(['config' => $config])->save();

    $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $field))->assertStatus(422)->assertJsonStructure(['message']);
})->with([
    'short text' => [FieldType::ShortText, []],
    'likert scale' => [FieldType::LikertScale, ['options' => [['value' => '1', 'label' => 'Low']]]],
    'choices from another form' => [FieldType::SingleSelect, ['options' => [], 'options_source' => ['form_id' => '00000000-0000-0000-0000-000000000000', 'field_key' => 'x']]],
]);

it('refuses turning it on twice', function (): void {
    $parent = otherSpecifyQuestion($this);
    $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $parent))->assertOk();

    $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $parent))->assertStatus(422);
});

it('names the new question after the choice question, and around a key already taken', function (): void {
    $taken = $this->builder->addField($this->form, $this->admin, FieldType::ShortText, null);
    $taken->forceFill(['key' => 'occupation_other'])->save();
    $parent = otherSpecifyQuestion($this);

    $key = $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $parent))->assertOk()->json('child.key');

    expect($key)->not->toBe('occupation_other')->and($key)->toStartWith('occupation_other');
});

it('removes both the Other choice and the Please specify question when turned off', function (): void {
    $parent = otherSpecifyQuestion($this);
    $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $parent))->assertOk();

    $body = $this->actingAs($this->admin)->deleteJson(otherSpecifyUrl($this, $parent))->assertOk()->json();

    expect($body['parent']['config']['options'])->toEqual([['value' => 'farmer', 'label' => 'Farmer']])
        ->and($body['parent']['config'])->not->toHaveKey('other_specify_key')
        ->and(otherSpecifyFresh($this, 'occupation_other'))->toBeNull();
});

it('refuses turning off a question that has no Please specify question', function (): void {
    $this->actingAs($this->admin)->deleteJson(otherSpecifyUrl($this, otherSpecifyQuestion($this)))->assertStatus(422);
});

it('gives a duplicate its choices but not the link, so two questions never claim one Please specify', function (): void {
    $parent = otherSpecifyQuestion($this);
    $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $parent))->assertOk();

    $copy = $this->actingAs($this->admin)
        ->postJson("http://acme.meridian.test/forms/{$this->form->id}/fields/{$parent->id}/duplicate")
        ->assertOk()
        ->json();

    expect($copy['config'])->not->toHaveKey('other_specify_key')
        ->and(array_column($copy['config']['options'], 'value'))->toBe(['farmer', 'other']);
});

it('drops the link when the question becomes a type without the setting', function (): void {
    $parent = otherSpecifyQuestion($this);
    $this->actingAs($this->admin)->postJson(otherSpecifyUrl($this, $parent))->assertOk();
    enterTenant($this->tenant->id, $this->admin->id);
    $parent->refresh();

    $plans = $this->actingAs($this->admin)
        ->getJson("http://acme.meridian.test/forms/{$this->form->id}/fields/{$parent->id}/conversions")
        ->json('plans');
    $byTarget = array_column($plans, null, 'to');

    expect($byTarget['likert_scale']['config_dropped'])->toContain('other_specify_key')
        ->and($byTarget['multi_select']['config_dropped'])->not->toContain('other_specify_key');
});

it('tells the builder which types offer the switch, written out (M177)', function (): void {
    $payload = app(\App\Services\Forms\BuilderPresenter::class)->present($this->form->refresh());

    expect($payload['enums']['other_specify_types'])->toBe(['single_select', 'multi_select', 'dropdown']);
});
