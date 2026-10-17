<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M176 (`R-5554c526`) — a refused rule says what to do, in plain words.
|--------------------------------------------------------------------------
| The testers' comment (2026-10-11, comment 2): "Instructions repeat" — "A validation rule must be either a
| structured rule or an expression — not both, not neither." The builder prefixes each message with the rule's
| number on screen ("Rule 2: …"), so the message names no rule and splits the XOR into its two real cases.
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

/** @return array{0: User, 1: Form, 2: array<string, mixed>} */
function ruleWordsField(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    test()->withHeaders(builderHeaders($form));
    $field = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'integer'])
        ->assertOk()->json();

    return [$admin, $form, $field];
}

function ruleWordsSave(User $admin, Form $form, array $field, array $rule): TestResponse
{
    return test()->actingAs($admin)->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$field['id']}", [
        'key' => $field['key'], 'label' => $field['label'], 'is_required' => 'optional', 'config' => [],
        'validations' => [
            ['rule_type' => 'min_value', 'operator' => null, 'rule_value' => '0', 'expression' => null, 'error_message' => null, 'related_field_key' => null],
            ['operator' => null, 'rule_value' => null, 'error_message' => null, 'related_field_key' => null, ...$rule],
        ],
        'version' => $field['version'],
    ]);
}

it('says an empty rule is empty, and what to do about it', function (): void {
    [$admin, $form, $field] = ruleWordsField();

    ruleWordsSave($admin, $form, $field, ['rule_type' => '', 'expression' => null])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['validations.1' => ['Empty. Choose what it checks, or remove it.']]);
});

it('says a rule with both a check and an expression should keep one', function (): void {
    [$admin, $form, $field] = ruleWordsField();

    ruleWordsSave($admin, $form, $field, ['rule_type' => 'max_value', 'rule_value' => '9', 'expression' => '. < 10'])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['validations.1' => ['Has both a check and an expression. Keep one of them.']]);
});
