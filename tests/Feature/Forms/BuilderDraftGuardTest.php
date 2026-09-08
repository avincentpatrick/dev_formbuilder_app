<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
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

/*
|--------------------------------------------------------------------------
| Increment M88 — the same guard, re-decided from the database inside the write.
|--------------------------------------------------------------------------
| Every case above binds a form that was ALREADY published, which is the only state
| `assertDraftChild` can see: it compares `$form->draft_version_id` and `$child->form_version_id`,
| both read at route-model-binding time. The cases below bind a DRAFT and then let the version
| change underneath it — which is what a publish, a restore, an XLSForm import, an archive or
| another editor's delete does — and the pre-M88 service let all of them through.
|
| ⛔ AND "LET THROUGH" DID NOT MEAN A CRASH. The `draft_child` RLS policy made the UPDATE match zero
| rows, `save()` reported success anyway, and `updateField()` returned `$field->refresh()`: the
| builder got 200 OK carrying the PRE-EDIT values and the user watched the edit revert with no
| error. The assertions below are written in that shape — the throw AND the unchanged row — because
| the throw alone would also pass against a service that silently did nothing.
|
| These drive the SERVICE and not the HTTP surface, deliberately. Through a request, route-model
| binding re-loads both models on every call, so the stale snapshot this closes cannot be staged
| without a second connection; the service takes the snapshot as arguments, which is exactly the
| shape the defect has.
*/

/** A form with one draft field, plus the models a request would be holding. Returns [form, field]. */
function draftFormWithBoundField(Tenant $tenant, User $admin): array
{
    enterTenant($tenant->id, $admin->id);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    app(FormBuilderService::class)->addField($form->refresh(), $admin, FieldType::ShortText, null);

    $bound = Form::query()->whereKey($form->id)->firstOrFail();
    $field = FormField::query()->where('form_version_id', $bound->draft_version_id)->firstOrFail();

    return [$bound, $field];
}

/** @return array<string, mixed> */
function builderFieldPayload(string $key): array
{
    return [
        'key' => $key,
        'label' => 'Edited',
        'is_required' => 'optional',
        'config' => [],
        'validations' => [],
    ];
}

it('refuses a field edit whose form was published after the models were bound', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$boundForm, $boundField] = draftFormWithBoundField($tenant, $admin);
    $original = $boundField->key;

    // The concurrent commit, staged: publish through a SEPARATELY loaded model, so the bound one
    // keeps the draft_version_id it was loaded with — exactly the state the race produces.
    app(PublishService::class)->publish(Form::query()->whereKey($boundForm->id)->firstOrFail(), $admin);

    expect($boundForm->draft_version_id)->toBe($boundField->form_version_id); // the stale guard agrees

    expect(fn () => app(FormBuilderService::class)
        ->updateField($boundForm, $boundField, $admin, builderFieldPayload('edited'), null))
        ->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($boundField->id)->value('key'))->toBe($original);
});

it('refuses a section edit whose form was published after the models were bound', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();

    enterTenant($tenant->id, $admin->id);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    $section = app(FormBuilderService::class)->addSection($form->refresh());

    $boundForm = Form::query()->whereKey($form->id)->firstOrFail();
    $boundSection = FormSection::query()->whereKey($section->id)->firstOrFail();
    $original = $boundSection->label;

    app(PublishService::class)->publish(Form::query()->whereKey($form->id)->firstOrFail(), $admin);

    expect(fn () => app(FormBuilderService::class)->updateSection($boundForm, $boundSection, [
        'key' => 'edited', 'label' => 'Edited', 'description' => null,
    ], null))->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(FormSection::query()->whereKey($boundSection->id)->value('label'))->toBe($original);
});

it('refuses a field edit whose row another editor deleted after the models were bound', function (): void {
    // The publish case needs a version flip to be visible. This one does not move
    // `draft_version_id` at all, so re-reading the FORM alone cannot see it — which is why the
    // guard re-reads the CHILD as well. It is also the more reachable race of the two: two
    // collaborators in one builder, no publish involved.
    $tenant = builderTenant();
    $admin = User::factory()->create();

    [$boundForm, $boundField] = draftFormWithBoundField($tenant, $admin);

    app(FormBuilderService::class)->deleteField(
        Form::query()->whereKey($boundForm->id)->firstOrFail(),
        FormField::query()->whereKey($boundField->id)->firstOrFail()
    );

    expect($boundForm->draft_version_id)->toBe($boundField->form_version_id); // still agrees

    expect(fn () => app(FormBuilderService::class)
        ->updateField($boundForm, $boundField, $admin, builderFieldPayload('edited'), null))
        ->toThrow(FormException::class);

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($boundField->id)->exists())->toBeFalse();
});
