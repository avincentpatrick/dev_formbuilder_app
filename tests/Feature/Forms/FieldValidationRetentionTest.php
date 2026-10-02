<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M126 (`R-86a0426d`) — a builder save keeps each rule's translations and AND/OR grouping.
|--------------------------------------------------------------------------
| `error_message_translations`, `logic_group` and `logic_operator` are written by the XLSForm importer (translations)
| and the schema blueprint materializer (all three), and never reach the builder: `BuilderPresenter::field()` emits
| none of them and no row id, and `fieldPayload()` sends six keys per rule. `replaceValidations()` used to delete every
| rule row and re-insert what the builder sent — so ANY save of the field, a label edit included, deleted all three,
| with a 200. Rows are now kept in place when the rule is the same rule.
|
| ⛔ EVERY PATCH BELOW IS BUILT FROM THE BUILDER'S OWN VIEW OF THE FIELD, KEY FOR KEY, the way `fieldPayload()` builds
| it (the `FieldCreateRoundTripTest` pattern). A payload that carried the three columns would pass in both worlds.
|
| ⚠️ Helpers are prefixed `ruleRetention*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return array{0: User, 1: Form} */
function ruleRetentionForm(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    return [$admin, app(FormService::class)->create($tenant, $admin, 'Imported survey')];
}

function ruleRetentionAddField(User $admin, Form $form, string $type): FormField
{
    $id = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => $type])
        ->assertOk()
        ->json('id');

    // The request leaves the tenant context behind it, and every read below is under RLS.
    enterTenant($form->tenant_id, $admin->id);

    return FormField::query()->findOrFail($id);
}

/**
 * A rule row as the importer or the materializer writes it — with the three columns the builder never sees.
 *
 * @param  array<string, mixed>  $attributes
 */
function ruleRetentionRule(FormField $field, int $sequence, array $attributes): FormFieldValidation
{
    return FormFieldValidation::create([
        'form_version_id' => $field->form_version_id,
        'form_field_id' => $field->id,
        'sequence' => $sequence,
        ...$attributes,
    ]);
}

/**
 * The builder store's `fieldPayload()`, transcribed: every key it sends, read from the field as the builder holds it.
 *
 * @param  callable(list<array<string, mixed>>): list<array<string, mixed>>|null  $editRules
 * @return array<string, mixed>
 */
function ruleRetentionPayload(FormField $field, string $label, ?callable $editRules = null): array
{
    $row = app(BuilderPresenter::class)->field($field->fresh());

    $rules = array_map(fn (array $v): array => [
        'rule_type' => $v['rule_type'],
        'operator' => $v['operator'],
        'rule_value' => $v['rule_value'],
        'expression' => $v['expression'],
        'error_message' => $v['error_message'],
        'related_field_key' => $v['related_field_key'],
    ], $row['validations']);

    return [
        'key' => $row['key'],
        'label' => $label,
        'hint' => $row['hint'],
        'placeholder' => $row['placeholder'],
        'is_required' => $row['is_required'],
        'relevant_expression' => $row['relevant_expression'],
        'appearance' => $row['appearance'],
        'config' => $row['config'],
        'default_value' => $row['default_value'],
        'is_pii' => $row['is_pii'],
        'is_sensitive' => $row['is_sensitive'],
        'is_queryable' => $row['is_queryable'],
        'indexed_data_type' => $row['indexed_data_type'],
        'version' => $row['version'],
        'validations' => $editRules !== null ? $editRules($rules) : $rules,
    ];
}

function ruleRetentionPatch(User $admin, Form $form, FormField $field, array $payload): void
{
    test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$field->id}", $payload)
        ->assertOk();

    enterTenant($form->tenant_id, $admin->id);
}

/** @return list<FormFieldValidation> */
function ruleRetentionRows(FormField $field): array
{
    return array_values($field->validations()->orderBy('sequence')->get()->all());
}

it('keeps every rule row in place, with its translations and grouping, when only the label is edited', function (): void {
    [$admin, $form] = ruleRetentionForm();
    $field = ruleRetentionAddField($admin, $form, 'short_text');
    $group = (string) Str::uuid();
    $min = ruleRetentionRule($field, 0, [
        'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '3', 'error_message' => 'Too short',
        'error_message_translations' => ['es' => 'Demasiado corto'],
        'logic_group' => $group, 'logic_operator' => LogicOperator::Or,
    ]);
    $max = ruleRetentionRule($field, 1, [
        'rule_type' => ValidationRuleType::MaxLength, 'rule_value' => '10', 'error_message' => 'Too long',
        'error_message_translations' => ['es' => 'Demasiado largo'],
        'logic_group' => $group, 'logic_operator' => LogicOperator::Or,
    ]);
    $constraint = ruleRetentionRule($field, 2, [
        'expression' => ". != 'none'", 'error_message' => 'Not none',
        'error_message_translations' => ['fr' => 'Pas aucun'],
    ]);

    ruleRetentionPatch($admin, $form, $field, ruleRetentionPayload($field, 'Renamed'));

    $rows = ruleRetentionRows($field);

    expect(array_map(fn (FormFieldValidation $v): string => $v->id, $rows))->toBe([$min->id, $max->id, $constraint->id])
        ->and($rows[0]->error_message_translations)->toBe(['es' => 'Demasiado corto'])
        ->and($rows[0]->logic_group)->toBe($group)
        ->and($rows[0]->logic_operator)->toBe(LogicOperator::Or)
        ->and($rows[1]->error_message_translations)->toBe(['es' => 'Demasiado largo'])
        ->and($rows[1]->logic_group)->toBe($group)
        ->and($rows[2]->error_message_translations)->toBe(['fr' => 'Pas aucun']);
});

it('keeps a cross-field rule in place, because the compared question resolves to the same id', function (): void {
    [$admin, $form] = ruleRetentionForm();
    $consent = ruleRetentionAddField($admin, $form, 'yes_no');
    $details = ruleRetentionAddField($admin, $form, 'short_text');
    $rule = ruleRetentionRule($details, 0, [
        'rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'yes',
        'related_form_field_id' => $consent->id, 'error_message' => 'Tell us more',
        'error_message_translations' => ['es' => 'Cuéntenos más'],
    ]);

    ruleRetentionPatch($admin, $form, $details, ruleRetentionPayload($details, 'Details'));

    $rows = ruleRetentionRows($details);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($rule->id)
        ->and($rows[0]->related_form_field_id)->toBe($consent->id)
        ->and($rows[0]->error_message_translations)->toBe(['es' => 'Cuéntenos más']);
});

it('keeps the translations and grouping when only the message is edited, and writes the new message', function (): void {
    [$admin, $form] = ruleRetentionForm();
    $field = ruleRetentionAddField($admin, $form, 'short_text');
    $group = (string) Str::uuid();
    $rule = ruleRetentionRule($field, 0, [
        'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '3', 'error_message' => 'Too short',
        'error_message_translations' => ['es' => 'Demasiado corto'],
        'logic_group' => $group, 'logic_operator' => LogicOperator::And,
    ]);

    ruleRetentionPatch($admin, $form, $field, ruleRetentionPayload($field, 'Name', function (array $rules): array {
        $rules[0]['error_message'] = 'Please write at least three letters';

        return $rules;
    }));

    $rows = ruleRetentionRows($field);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($rule->id)
        ->and($rows[0]->error_message)->toBe('Please write at least three letters')
        ->and($rows[0]->error_message_translations)->toBe(['es' => 'Demasiado corto'])
        ->and($rows[0]->logic_group)->toBe($group)
        ->and($rows[0]->logic_operator)->toBe(LogicOperator::And);
});

it('makes a rule whose value changed a new rule, and leaves its untouched sibling exactly as it was', function (): void {
    [$admin, $form] = ruleRetentionForm();
    $field = ruleRetentionAddField($admin, $form, 'short_text');
    $min = ruleRetentionRule($field, 0, [
        'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '3', 'error_message' => 'Too short',
        'error_message_translations' => ['es' => 'Demasiado corto'],
    ]);
    $max = ruleRetentionRule($field, 1, [
        'rule_type' => ValidationRuleType::MaxLength, 'rule_value' => '10', 'error_message' => 'Too long',
        'error_message_translations' => ['es' => 'Demasiado largo'],
    ]);

    ruleRetentionPatch($admin, $form, $field, ruleRetentionPayload($field, 'Name', function (array $rules): array {
        $rules[0]['rule_value'] = '5';

        return $rules;
    }));

    $rows = ruleRetentionRows($field);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->id)->not->toBe($min->id)
        ->and($rows[0]->rule_value)->toBe('5')
        ->and($rows[0]->error_message_translations)->toBeNull()
        ->and($rows[1]->id)->toBe($max->id)
        ->and($rows[1]->error_message_translations)->toBe(['es' => 'Demasiado largo'])
        ->and(FormFieldValidation::query()->whereKey($min->id)->exists())->toBeFalse();
});

it('deletes a removed rule, and moves a reordered rule with its own translations', function (): void {
    [$admin, $form] = ruleRetentionForm();
    $field = ruleRetentionAddField($admin, $form, 'short_text');
    $min = ruleRetentionRule($field, 0, [
        'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '3', 'error_message' => 'Too short',
        'error_message_translations' => ['es' => 'Demasiado corto'],
    ]);
    $max = ruleRetentionRule($field, 1, [
        'rule_type' => ValidationRuleType::MaxLength, 'rule_value' => '10', 'error_message' => 'Too long',
        'error_message_translations' => ['es' => 'Demasiado largo'],
    ]);
    $pattern = ruleRetentionRule($field, 2, [
        'rule_type' => ValidationRuleType::Pattern, 'rule_value' => '^[a-z]+$', 'error_message' => 'Letters only',
        'error_message_translations' => ['es' => 'Solo letras'],
    ]);

    // Drop the first rule, then swap the other two.
    ruleRetentionPatch($admin, $form, $field, ruleRetentionPayload($field, 'Name', fn (array $rules): array => [$rules[2], $rules[1]]));

    $rows = ruleRetentionRows($field);

    expect(array_map(fn (FormFieldValidation $v): string => $v->id, $rows))->toBe([$pattern->id, $max->id])
        ->and(array_map(fn (FormFieldValidation $v): int => $v->sequence, $rows))->toBe([0, 1])
        ->and($rows[0]->error_message_translations)->toBe(['es' => 'Solo letras'])
        ->and($rows[1]->error_message_translations)->toBe(['es' => 'Demasiado largo'])
        ->and(FormFieldValidation::query()->whereKey($min->id)->exists())->toBeFalse();
});

it('matches two identical rules pairwise, in order, so removing the second keeps the first one', function (): void {
    [$admin, $form] = ruleRetentionForm();
    $field = ruleRetentionAddField($admin, $form, 'short_text');
    $first = ruleRetentionRule($field, 0, [
        'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '3', 'error_message' => 'Too short',
        'error_message_translations' => ['es' => 'Primero'],
    ]);
    ruleRetentionRule($field, 1, [
        'rule_type' => ValidationRuleType::MinLength, 'rule_value' => '3', 'error_message' => 'Too short',
        'error_message_translations' => ['es' => 'Segundo'],
    ]);

    ruleRetentionPatch($admin, $form, $field, ruleRetentionPayload($field, 'Name', fn (array $rules): array => [$rules[0]]));

    $rows = ruleRetentionRows($field);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($first->id)
        ->and($rows[0]->error_message_translations)->toBe(['es' => 'Primero']);
});

it('keeps a rule an importer stored with an empty value, which the request turns into null on the way back', function (): void {
    [$admin, $form] = ruleRetentionForm();
    $consent = ruleRetentionAddField($admin, $form, 'yes_no');
    $details = ruleRetentionAddField($admin, $form, 'short_text');
    $rule = ruleRetentionRule($details, 0, [
        'rule_type' => ValidationRuleType::RequiredWith, 'rule_value' => '', 'related_form_field_id' => $consent->id,
        'error_message' => 'Tell us more', 'error_message_translations' => ['es' => 'Cuéntenos más'],
    ]);

    // The builder sends back the '' it was given; `ConvertEmptyStringsToNull` hands the service a null.
    ruleRetentionPatch($admin, $form, $details, ruleRetentionPayload($details, 'Details'));

    $rows = ruleRetentionRows($details);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($rule->id)
        ->and($rows[0]->error_message_translations)->toBe(['es' => 'Cuéntenos más']);
});
