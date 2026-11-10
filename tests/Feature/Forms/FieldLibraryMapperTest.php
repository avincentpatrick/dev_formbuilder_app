<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\FieldLibrary;
use App\Models\FormFieldValidation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\SchemaBlueprintMaterializer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FieldLibrary mappers (Increment G9b): fromField (capture a draft field → library columns, dropping
| cross-field validations) and toBlueprintField (rebuild the serializer field shape from those columns),
| plus a full round-trip back into a draft via materializeField.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $form = makeForm($this->user);
    $this->draft = makeDraftVersion($form);
});

it('captures a draft field into library columns, dropping cross-field validations', function (): void {
    $age = addFormField($this->draft, $this->user, 'age', FieldType::Integer, 0);
    $score = addFormField($this->draft, $this->user, 'score', FieldType::ShortText, 1, [
        'label' => 'Score',
        'config' => ['min' => 0],
        'hint' => 'Out of 100',
    ]);

    // A self-contained rule (kept) and a cross-field comparison against `age` (dropped by fromField).
    FormFieldValidation::create([
        'form_version_id' => $this->draft->id, 'form_field_id' => $score->id,
        'rule_type' => 'min_length', 'rule_value' => '1', 'sequence' => 0,
    ]);
    FormFieldValidation::create([
        'form_version_id' => $this->draft->id, 'form_field_id' => $score->id,
        'related_form_field_id' => $age->id, 'rule_type' => 'greater_than_field', 'sequence' => 1,
    ]);

    $attrs = FieldLibrary::fromField($score->refresh(), ['category' => 'Scores']);

    expect($attrs['field_type'])->toBe('short_text')
        ->and($attrs['default_label'])->toBe('Score')
        ->and($attrs['default_hint'])->toBe('Out of 100')
        ->and($attrs['default_config'])->toBe(['min' => 0])
        ->and($attrs['category'])->toBe('Scores')
        ->and($attrs['name'])->toBe('Score'); // one-click: name defaults to the field label

    // Only the self-contained validation survives; the cross-field one is dropped.
    expect($attrs['default_validations'])->toHaveCount(1)
        ->and($attrs['default_validations'][0]['rule_type'])->toBe('min_length')
        ->and($attrs['default_validations'][0]['related_field_key'])->toBeNull();
});

it('drops a rule group whole when it loses a cross-field member, so the library copy is never stricter (M220, R-fcdb4594)', function (): void {
    $age = addFormField($this->draft, $this->user, 'age', FieldType::Integer, 0);
    $score = addFormField($this->draft, $this->user, 'score', FieldType::Integer, 1, ['label' => 'Score']);
    $rule = fn (array $attrs) => FormFieldValidation::create(['form_version_id' => $this->draft->id, 'form_field_id' => $score->id] + $attrs);

    // "The answer must pass ANY of these": at least 0, OR greater than `age`. In the library the comparison
    // cannot travel, and keeping its partner alone in the group would make the copy demand "at least 0".
    $anyOf = (string) Str::uuid();
    $rule(['rule_type' => 'min_value', 'rule_value' => '0', 'logic_group' => $anyOf, 'logic_operator' => 'or', 'sequence' => 0]);
    $rule(['rule_type' => 'greater_than_field', 'related_form_field_id' => $age->id, 'logic_group' => $anyOf, 'logic_operator' => 'or', 'sequence' => 1]);

    // A group with no cross-field member travels whole, its two rules still grouped together.
    $allOf = (string) Str::uuid();
    $rule(['rule_type' => 'min_value', 'rule_value' => '1', 'logic_group' => $allOf, 'logic_operator' => 'and', 'sequence' => 2]);
    $rule(['rule_type' => 'max_value', 'rule_value' => '100', 'logic_group' => $allOf, 'logic_operator' => 'and', 'sequence' => 3]);

    // An ungrouped self-contained rule travels; an ungrouped comparison is dropped, as before.
    $rule(['rule_type' => 'max_value', 'rule_value' => '1000', 'sequence' => 4]);
    $rule(['rule_type' => 'less_than_field', 'related_form_field_id' => $age->id, 'sequence' => 5]);

    $kept = FieldLibrary::fromField($score->refresh(), [])['default_validations'];

    expect(array_map(fn (array $v): string => $v['rule_type'].':'.$v['rule_value'], $kept))
        ->toBe(['min_value:1', 'max_value:100', 'max_value:1000']);

    // The surviving group is still one group, so it materialises as one again.
    expect($kept[0]['logic_group_ordinal'])->not->toBeNull()
        ->and($kept[1]['logic_group_ordinal'])->toBe($kept[0]['logic_group_ordinal'])
        ->and($kept[2]['logic_group_ordinal'])->toBeNull();
});

it('reconstructs the serializer field shape from stored columns via toBlueprintField', function (): void {
    $item = FieldLibrary::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Age', 'field_type' => 'integer',
        'default_label' => 'Age (years)', 'default_hint' => 'Whole years',
        'default_config' => ['min' => 0], 'default_validations' => [],
        'is_active' => true, 'usage_count' => 0,
    ]);

    $shape = $item->toBlueprintField();

    expect($shape['field_type'])->toBe('integer')
        ->and($shape['label'])->toBe('Age (years)')
        ->and($shape['hint'])->toBe('Whole years')
        ->and($shape['config'])->toBe(['min' => 0])
        ->and($shape['is_required'])->toBe(RequiredMode::Optional->value)
        ->and($shape['key'])->toBeNull()
        ->and($shape['section_key'])->toBeNull()
        ->and($shape['is_pii'])->toBeFalse()
        ->and($shape['validations'])->toBe([]);
});

it('round-trips a field through the library and back into a draft', function (): void {
    $original = addFormField($this->draft, $this->user, 'sex', FieldType::SingleSelect, 0, [
        'label' => 'Sex',
        'config' => ['options' => [['value' => 'f', 'label' => 'Female'], ['value' => 'm', 'label' => 'Male']]],
    ]);

    $item = FieldLibrary::create(array_merge(FieldLibrary::fromField($original->refresh(), []), [
        'tenant_id' => $this->tenant->id, 'is_active' => true, 'usage_count' => 0,
    ]));

    $inserted = app(SchemaBlueprintMaterializer::class)
        ->materializeField($this->draft, $item->toBlueprintField(), null, $this->user);

    expect($inserted->field_type)->toBe(FieldType::SingleSelect)
        ->and($inserted->label)->toBe('Sex')
        ->and($inserted->config)->toEqual(['options' => [['value' => 'f', 'label' => 'Female'], ['value' => 'm', 'label' => 'Male']]])
        ->and($inserted->key)->not->toBe('sex'); // fresh unique key (sex is taken by the original)
});
