<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment D4a — the builder cannot mutate a PUBLISHED version through the HTTP surface.
|--------------------------------------------------------------------------
| Published versions are immutable (form-versioning-schema-migration.md §2). The draft_child RLS guard is
| the DB backstop; FormBuilderService::assertDraftChild is the clean service guard that turns a write
| against a published version into a 422 instead of a silent zero-row write. This proves both: the HTTP
| endpoint rejects it, AND a raw RLS-level UPDATE on a published child is a no-op. (builderTenant() +
| enterTenant()/makeActiveMember() are shared — defined in BuilderRoutesTest.php / tests/Pest.php.)
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

/** Create a form, add one field to its draft, publish it — returns [form, publishedField, draftField]. */
function publishedFormWithField(Tenant $tenant, User $admin): array
{
    enterTenant($tenant->id, $admin->id);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    app(FormBuilderService::class)->addField($form, $admin, FieldType::ShortText, null);
    app(PublishService::class)->publish($form->refresh(), $admin);

    $form->refresh();
    $publishedVersion = FormVersion::query()->where('form_id', $form->id)
        ->where('status', FormVersionStatus::Published)->firstOrFail();
    $publishedField = FormField::query()->where('form_version_id', $publishedVersion->id)->firstOrFail();
    $draftField = FormField::query()->where('form_version_id', $form->draft_version_id)->firstOrFail();

    return [$form, $publishedField, $draftField];
}

it('rejects editing a field that belongs to a published version (422)', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    [$form, $publishedField] = publishedFormWithField($tenant, $admin);

    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$publishedField->id}", [
            'key' => 'hacked', 'label' => 'Hacked', 'is_required' => 'optional',
            'config' => [], 'validations' => [], 'version' => null,
        ])
        ->assertStatus(422);

    // The published field is untouched.
    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($publishedField->id)->value('key'))->not->toBe('hacked');
});

it('rejects deleting a field that belongs to a published version (422)', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    [$form, $publishedField] = publishedFormWithField($tenant, $admin);

    $this->actingAs($admin)
        ->deleteJson("http://acme.meridian.test/forms/{$form->id}/fields/{$publishedField->id}")
        ->assertStatus(422);

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($publishedField->id)->exists())->toBeTrue();
});

it('still allows editing the current draft after publishing (positive control)', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    [$form, , $draftField] = publishedFormWithField($tenant, $admin);

    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$draftField->id}", [
            'key' => 'edited', 'label' => 'Edited', 'is_required' => 'optional',
            'config' => [], 'validations' => [], 'version' => null,
        ])
        ->assertOk();

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($draftField->id)->value('key'))->toBe('edited');
});

it('is backstopped by RLS — a raw UPDATE on a published child touches zero rows', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [, $publishedField] = publishedFormWithField($tenant, $admin);

    enterTenant($tenant->id, $admin->id);
    $affected = FormField::query()->whereKey($publishedField->id)->update(['label' => 'via-rls']);

    expect($affected)->toBe(0);
    expect(FormField::query()->whereKey($publishedField->id)->value('label'))->not->toBe('via-rls');
});
