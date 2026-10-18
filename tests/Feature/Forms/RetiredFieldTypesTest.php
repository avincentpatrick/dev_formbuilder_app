<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Data\PlatformFieldLibraryItems;
use Database\Seeders\Data\PlatformTemplateBlueprints;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M177 — Yes/No and Duration leave the palette (`R-0b7db001`, `R-22011569`, `D140`, `D145`).
|--------------------------------------------------------------------------
| An author can no longer PICK either type — not from the palette, not as a conversion target — and every existing
| question keeps working: no data is touched. The builder's undo puts a deleted question back, and reverses a
| conversion, through the same two routes, so a request that says it is restoring (`restore: true`, which only the
| store's history steps send) still reaches a retired type. A Yes/No becomes a Single choice with the choices Yes and
| No (`D145`, an exception to `D64`).
|
| ⚠️ Helpers are prefixed `retiredType*`: Pest loads every test file into one process.
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

function retiredTypeUrl(string $formId, string $tail): string
{
    return "http://acme.meridian.test/forms/{$formId}/{$tail}";
}

function retiredTypeVersion(FormField $field, Tenant $tenant, User $user): string
{
    enterTenant($tenant->id, $user->id);

    return (string) FormBuilderService::rowVersion($field->refresh());
}

it('refuses an author adding a Yes/No or a Duration, saying what to use instead', function (string $type, string $words): void {
    $this->actingAs($this->admin)
        ->postJson(retiredTypeUrl($this->form->id, 'fields'), ['field_type' => $type])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['field_type' => $words]);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormField::query()->where('field_type', $type)->count())->toBe(0);
})->with([
    'yes/no' => ['yes_no', 'Use Single choice'],
    'duration' => ['duration', 'calculation'],
]);

it('lets the undo of a delete put a Yes/No back', function (): void {
    $this->actingAs($this->admin)
        ->postJson(retiredTypeUrl($this->form->id, 'fields'), ['field_type' => 'yes_no', 'restore' => true])
        ->assertOk()
        ->assertJsonPath('field_type', 'yes_no');
});

it('still adds every type that is not retired', function (): void {
    $this->actingAs($this->admin)
        ->postJson(retiredTypeUrl($this->form->id, 'fields'), ['field_type' => 'single_select'])
        ->assertOk()
        ->assertJsonPath('field_type', 'single_select');
});

it('refuses converting a question into a retired type, unless an undo is restoring it', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::Note, null);
    $url = retiredTypeUrl($this->form->id, "fields/{$field->id}/convert");

    $this->actingAs($this->admin)
        ->postJson($url, ['to' => 'yes_no', 'version' => retiredTypeVersion($field, $this->tenant, $this->admin)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to' => 'Use Single choice']);

    $this->actingAs($this->admin)
        ->postJson($url, ['to' => 'yes_no', 'version' => retiredTypeVersion($field, $this->tenant, $this->admin), 'restore' => true])
        ->assertOk()
        ->assertJsonPath('field_type', 'yes_no');
});

it('turns an existing Yes/No into a Single choice with the choices Yes and No, keeping its key (D145)', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::YesNo, null); // as a seeder or an older form made it
    $plans = $this->actingAs($this->admin)
        ->getJson(retiredTypeUrl($this->form->id, "fields/{$field->id}/conversions"))
        ->assertOk()
        ->json('plans');

    expect(array_column($plans, 'to'))->toBe(['single_select', 'note', 'hidden']);
    $plan = $plans[0];
    expect(array_column($plan['warnings'], 'code'))->toBe(['answers_become_choices']);

    $this->actingAs($this->admin)
        ->postJson(retiredTypeUrl($this->form->id, "fields/{$field->id}/convert"), [
            'to' => 'single_select',
            'version' => retiredTypeVersion($field, $this->tenant, $this->admin),
            'fingerprint' => $plan['fingerprint'],
        ])
        ->assertOk()
        ->assertJsonPath('field_type', 'single_select')
        ->assertJsonPath('key', $field->key);

    enterTenant($this->tenant->id, $this->admin->id);
    expect($field->refresh()->config['options'])->toBe([['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]);
});

it('plans a single choice back into a Yes/No only so the undo can reverse it', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::SingleSelect, null);

    $targets = array_column(
        $this->actingAs($this->admin)->getJson(retiredTypeUrl($this->form->id, "fields/{$field->id}/conversions"))->json('plans'),
        'to',
    );

    // Listed for the undo to find; the dialog leaves out every type the palette marks retired.
    expect($targets)->toContain('yes_no');
});

it('copies the catalog’s yes-or-no questions as Single choices with the choices Yes and No', function (): void {
    $types = [];
    foreach (PlatformTemplateBlueprints::all() as $template) {
        foreach ($template['blueprint']['fields'] as $field) {
            $types[] = $field['field_type'];
            if (in_array($field['key'], ['has_electricity', 'consent', 'received_items', 'followup_needed', 'recommend', 'it_account', 'building_access'], true)) {
                expect($field['field_type'])->toBe('single_select', $field['key'])
                    ->and($field['config']['options'])->toBe([['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']]);
            }
        }
    }
    foreach (PlatformFieldLibraryItems::all() as $item) {
        $types[] = $item['field_type'] instanceof FieldType ? $item['field_type']->value : $item['field_type'];
    }

    expect($types)->not->toContain('yes_no')
        ->and($types)->not->toContain('duration');
});
