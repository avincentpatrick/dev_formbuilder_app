<?php

declare(strict_types=1);

use App\Enums\FieldAppearance;
use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Enums\ValueShape;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M170 — every setting the builder can send, for every question type, comes back on the builder page.
|--------------------------------------------------------------------------
| The user's report (2026-10-14): "For a Whole Number question, the settings on the Basic, Validation and Advanced
| tabs don't save. Please check every field type and every setting on every tab … that the server keeps the value,
| and that it's still there after a plain page refresh."
|
| Until M170 no test PATCHed a question and then loaded the builder page: the route tests read the stored row, and the
| one round trip (`FieldCreateRoundTripTest`) checked only the PATCH's own answer. A value the server stored and the
| page then failed to send — or one the request pruned without a word — passed all of them.
|
| ⛔ ONE PATCH PER TYPE CARRIES EVERY SETTING AT ONCE, BUILT FROM THE CREATE RESPONSE KEY FOR KEY the way the store's
| `fieldPayload()` builds it, and then the builder page is loaded and every setting read back from its `fields` prop.
| Each type's rules are every rule its value shape allows — read from `ValueShape::allows()`, not listed here — plus one
| expression row, and its config is a sample of every key its own tab writes.
|
| ⚠️ Helpers are prefixed `settingsRoundTrip*`: Pest loads every test file into one process.
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
function settingsRoundTripForm(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $form = app(FormService::class)->create($tenant, $admin, 'Every Setting');
    test()->withHeaders(builderHeaders($form));

    return [$admin, $form];
}

/** The builder store's `fieldPayload()`, transcribed: every key it sends, read from the row the server gave it. */
function settingsRoundTripPayload(array $row, string $label): array
{
    return [
        'key' => $row['key'], 'label' => $label, 'hint' => $row['hint'], 'placeholder' => $row['placeholder'],
        'is_required' => $row['is_required'], 'relevant_expression' => $row['relevant_expression'], 'appearance' => $row['appearance'],
        'config' => $row['config'], 'default_value' => $row['default_value'], 'default_value_is_expression' => $row['default_value_is_expression'],
        'is_pii' => $row['is_pii'], 'is_sensitive' => $row['is_sensitive'], 'is_queryable' => $row['is_queryable'],
        'indexed_data_type' => $row['indexed_data_type'], 'version' => $row['version'], 'validations' => $row['validations'],
    ];
}

/** A question as the builder's create route answers it. */
function settingsRoundTripCreate(User $admin, Form $form, string $type): array
{
    return test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => $type])
        ->assertOk()
        ->json();
}

/** The builder page's `fields` prop, keyed by id — what a plain refresh renders. */
function settingsRoundTripReload(User $admin, Form $form): array
{
    $page = test()->withoutVite()->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/builder")
        ->assertOk()
        ->viewData('page');

    // Through JSON, as the browser receives it: the presenter sends `config` as an object.
    return collect(json_decode((string) json_encode($page['props']['fields']), true))->keyBy('id')->all();
}

/** A sample of every key the type's own settings tab writes. */
function settingsRoundTripConfig(FieldType $type): array
{
    return match (true) {
        $type->isMedia() => ['accepted_types' => ['image/png'], 'max_file_size_bytes' => 1048576, 'max_count' => 2, 'min_count' => 1, 'capture_source' => 'camera'],
        $type->isGeo() => ['capture_altitude' => true, 'accuracy_threshold' => 15, 'default_center' => ['lat' => 14.6, 'lon' => 121.0], 'default_zoom' => 12],
        $type === FieldType::CascadingSelect => [
            'levels' => [['key' => 'region', 'label' => 'Region'], ['key' => 'province', 'label' => 'Province']],
            'options' => [
                ['value' => 'ncr', 'label' => 'NCR', 'level' => 'region', 'parent' => null],
                ['value' => 'mnl', 'label' => 'Manila', 'level' => 'province', 'parent' => 'ncr'],
            ],
        ],
        $type === FieldType::Matrix => [
            'rows' => [['value' => 'r1', 'label' => 'Morning']],
            'columns' => [['value' => 'c1', 'label' => 'Count']],
            'cells' => [['value' => 'x', 'label' => 'Tick']],
        ],
        $type === FieldType::LikertMatrix => [
            'rows' => [['value' => 'r1', 'label' => 'Staff were kind']],
            'columns' => [['value' => '1', 'label' => 'Disagree'], ['value' => '2', 'label' => 'Agree']],
        ],
        $type === FieldType::Hidden => ['prefill_source' => 'url', 'url_param' => 'ref'],
        $type->configEditor() === 'choices' => ['options' => [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']]],
        $type === FieldType::Note => ['content' => [['type' => 'heading', 'level' => 2, 'text' => 'Before you start']]],
        $type === FieldType::Calculated => ['calculated_formula' => '${anchor} * 2'],
        default => [],
    };
}

/**
 * Every rule the type's value shape allows, in a fixed order, plus one expression row. A rule that compares two
 * questions names `anchor`, a whole number added first.
 *
 * @return list<array<string, string|null>>
 */
function settingsRoundTripRules(FieldType $type): array
{
    $shape = ValueShape::for($type);
    $samples = [
        'min_value' => '1', 'max_value' => '10', 'min_length' => '2', 'max_length' => '40', 'pattern' => '^[A-Z]',
    ];
    $rows = [];

    foreach (ValidationRuleType::cases() as $rule) {
        if (! $shape->allows($rule)) {
            continue;
        }

        $operator = $rule->takesOperator() ? ($rule->operatorMayBeEmpty() ? null : 'eq') : null;
        $rows[] = [
            'rule_type' => $rule->value,
            'operator' => $operator,
            'rule_value' => $operator === 'eq' ? '3' : ($samples[$rule->value] ?? null),
            'expression' => null,
            'error_message' => "{$rule->label()} for {$type->value}.",
            'related_field_key' => $rule->takesRelatedField() ? 'anchor' : null,
        ];
    }

    if ($shape !== ValueShape::NoAnswer) {
        $rows[] = [
            'rule_type' => null, 'operator' => null, 'rule_value' => null,
            'expression' => '${anchor} > 0', 'error_message' => 'An expression rule.', 'related_field_key' => null,
        ];
    }

    return $rows;
}

it('keeps every Basics, Validation, Advanced and own-tab setting of a question, and sends it back on the builder page', function (FieldType $type): void {
    [$admin, $form] = settingsRoundTripForm();

    // The question the comparing rules and the expressions name.
    $anchor = settingsRoundTripCreate($admin, $form, 'integer');
    test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$anchor['id']}", [...settingsRoundTripPayload($anchor, 'Anchor'), 'key' => 'anchor'])
        ->assertOk();

    $row = settingsRoundTripCreate($admin, $form, $type->value);
    $layouts = FieldAppearance::for($type);
    $sent = [
        ...settingsRoundTripPayload($row, "Every setting of {$type->value}"),
        'key' => "every_{$type->value}",
        'hint' => 'The help text.',
        'placeholder' => 'The placeholder.',
        'is_required' => 'required',
        'relevant_expression' => '${anchor} > 1',
        'appearance' => $layouts === [] ? null : $layouts[0]->value,
        'config' => [...$row['config'], ...settingsRoundTripConfig($type)],
        'default_value' => '7',
        'default_value_is_expression' => false,
        'is_pii' => true,
        'is_sensitive' => true,
        'is_queryable' => true,
        'indexed_data_type' => 'text',
        'validations' => settingsRoundTripRules($type),
    ];

    test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$row['id']}", $sent)
        ->assertOk();

    $back = settingsRoundTripReload($admin, $form)[$row['id']];

    $lost = [];
    foreach (['key', 'label', 'hint', 'placeholder', 'is_required', 'relevant_expression', 'appearance', 'default_value',
        'default_value_is_expression', 'is_pii', 'is_sensitive', 'is_queryable', 'indexed_data_type'] as $setting) {
        if ($back[$setting] !== $sent[$setting]) {
            $lost[] = $setting.': sent '.json_encode($sent[$setting]).', back '.json_encode($back[$setting]);
        }
    }
    foreach (settingsRoundTripConfig($type) as $key => $value) {
        if (($back['config'][$key] ?? null) != $value) {
            $lost[] = "config.{$key}: sent ".json_encode($value).', back '.json_encode($back['config'][$key] ?? null);
        }
    }
    $rules = array_map(
        static fn (array $rule): array => array_intersect_key($rule, array_flip(['rule_type', 'operator', 'rule_value', 'expression', 'error_message', 'related_field_key'])),
        $back['validations'],
    );
    if ($rules !== $sent['validations']) {
        $lost[] = 'validations: sent '.json_encode($sent['validations']).', back '.json_encode($rules);
    }

    expect($lost)->toBe([]);
})->with(FieldType::cases());

it('keeps every setting of a section, and sends it back on the builder page', function (): void {
    [$admin, $form] = settingsRoundTripForm();

    $section = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/sections")
        ->assertOk()
        ->json();
    $sent = [
        'key' => 'household_members',
        'label' => 'Household members',
        'description' => 'One entry per person.',
        'is_repeatable' => true,
        'min_instances' => 1,
        'max_instances' => 12,
        'relevant_expression' => null,
        'version' => $section['version'],
    ];

    test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/sections/{$section['id']}", $sent)
        ->assertOk();

    $page = test()->withoutVite()->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/builder")
        ->assertOk()
        ->viewData('page');
    $back = collect(json_decode((string) json_encode($page['props']['sections']), true))->keyBy('id')[$section['id']];

    expect(array_intersect_key($back, $sent))->toEqual(array_diff_key($sent, ['version' => true]) + ['version' => $back['version']]);
});
