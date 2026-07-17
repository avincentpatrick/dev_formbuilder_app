<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Exceptions\Forms\FormException;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\SchemaBlueprintMaterializer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| SchemaBlueprintMaterializer (Increment G9a) — the inverse of SchemaSnapshotSerializer. Proves the
| section_key→id + related_field_key→id remap and, critically, the logic_group_ordinal→uuid REVERSE
| (validations sharing an ordinal rejoin the same fresh group; distinct ordinals get distinct groups).
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

it('materializes sections, fields, and validations with FK + logic-group remap', function (): void {
    $blueprint = [
        'sections' => [
            ['key' => 's1', 'label' => 'Details', 'sequence' => 1],
        ],
        'fields' => [
            [
                'key' => 'age', 'section_key' => 's1', 'field_type' => 'integer', 'label' => 'Age',
                'is_required' => 'optional', 'sequence' => 2, 'config' => [], 'validations' => [],
            ],
            [
                'key' => 'name', 'section_key' => 's1', 'field_type' => 'short_text', 'label' => 'Name',
                'is_required' => 'required', 'sequence' => 1, 'config' => [],
                'validations' => [
                    ['rule_type' => 'min_length', 'rule_value' => '3', 'logic_group_ordinal' => 0, 'logic_operator' => 'and', 'sequence' => 0],
                    ['rule_type' => 'max_length', 'rule_value' => '50', 'logic_group_ordinal' => 0, 'logic_operator' => 'and', 'sequence' => 1],
                    ['rule_type' => 'greater_than_field', 'related_field_key' => 'age', 'logic_group_ordinal' => 1, 'sequence' => 2],
                ],
            ],
        ],
    ];

    $result = app(SchemaBlueprintMaterializer::class)->materializeInto($this->draft, $blueprint, $this->user);

    expect($result->sectionCount)->toBe(1)
        ->and($result->fieldCount)->toBe(2)
        ->and($result->validationCount)->toBe(3);

    $name = FormField::query()->where('form_version_id', $this->draft->id)->where('key', 'name')->firstOrFail();
    $age = FormField::query()->where('form_version_id', $this->draft->id)->where('key', 'age')->firstOrFail();

    expect($name->field_type)->toBe(FieldType::ShortText)
        ->and($name->is_required)->toBe(RequiredMode::Required)
        ->and($name->form_section_id)->not->toBeNull()
        ->and($age->field_type)->toBe(FieldType::Integer);

    $vals = $name->validations()->orderBy('sequence')->get();
    expect($vals)->toHaveCount(3);

    // Ordinal 0 twice ⇒ the SAME fresh logic_group uuid; ordinal 1 ⇒ a distinct one (the reverse remap).
    expect($vals[0]->logic_group)->not->toBeNull()
        ->and($vals[1]->logic_group)->toBe($vals[0]->logic_group)
        ->and($vals[2]->logic_group)->not->toBe($vals[0]->logic_group);

    // related_field_key resolved to the sibling field's new id.
    expect($vals[2]->related_form_field_id)->toBe($age->id);
});

it('rejects a blueprint with an unknown field_type before writing any row', function (): void {
    $blueprint = [
        'sections' => [],
        'fields' => [['key' => 'q', 'section_key' => null, 'field_type' => 'not_a_type', 'label' => 'Q']],
    ];

    expect(fn () => app(SchemaBlueprintMaterializer::class)->materializeInto($this->draft, $blueprint, $this->user))
        ->toThrow(FormException::class);

    expect(FormField::query()->where('form_version_id', $this->draft->id)->count())->toBe(0);
});

it('materializes a single library field, minting a unique key on collision', function (): void {
    // An existing field occupies the natural key.
    addFormField($this->draft, $this->user, 'phone', FieldType::Phone, 0);

    $fieldBlueprint = [
        'key' => 'phone', 'field_type' => 'phone', 'label' => 'Phone', 'is_required' => 'optional', 'config' => [],
        'validations' => [],
    ];

    $created = app(SchemaBlueprintMaterializer::class)->materializeField($this->draft, $fieldBlueprint, null, $this->user);

    expect($created->key)->not->toBe('phone')            // minted around the collision
        ->and($created->field_type)->toBe(FieldType::Phone)
        ->and($created->form_version_id)->toBe($this->draft->id);
});
