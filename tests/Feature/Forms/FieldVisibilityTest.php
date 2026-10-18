<?php

declare(strict_types=1);

use App\Enums\FieldType;
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
| M177 — Show to respondents is saved as `config.visibility` (`R-f44e9d00`).
|--------------------------------------------------------------------------
| `hidden` (Never) or `shown` (Always — what a calculation, Never by default, needs to show its value); absent keeps
| every type's own rule, so every existing question and published version reads as it did. Anything else is refused,
| in plain words, so a mistyped value never reaches the engines as a third state.
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
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function fieldVisibilitySave(object $test, mixed $visibility): Illuminate\Testing\TestResponse
{
    $field = app(FormBuilderService::class)->addField($test->form, $test->admin, FieldType::ShortText, null);

    return $test->actingAs($test->admin)->patchJson("http://acme.meridian.test/forms/{$test->form->id}/fields/{$field->id}", [
        'key' => $field->key,
        'label' => 'Reference',
        'is_required' => 'optional',
        'config' => ['visibility' => $visibility],
        'validations' => [],
        'version' => (string) FormBuilderService::rowVersion($field->refresh()),
    ]);
}

it('saves Never and Always', function (string $visibility): void {
    fieldVisibilitySave($this, $visibility)->assertOk()->assertJsonPath('config.visibility', $visibility);
})->with(['hidden', 'shown']);

it('refuses any other value, naming the setting as the screen does', function (): void {
    fieldVisibilitySave($this, 'sometimes')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['config.visibility' => 'Show to respondents']);
});
