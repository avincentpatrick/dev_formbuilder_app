<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
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
| Increment D4a — the interactive builder's HTTP mutation surface.
|--------------------------------------------------------------------------
| Proves the JSON section/field CRUD + reorder endpoints end-to-end through the real subdomain pipeline:
| the can:update,form gate, route-model binding under RLS context, the FormBuilderService writes, and the
| optimistic-concurrency 409 contract. (BuilderDraftGuardTest covers the published-version rejection.)
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

/** The demo tenant reachable at acme.meridian.test (distinct name from FormRoutesTest's helper). */
function builderTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);

    return $tenant;
}

it('forbids a Viewer from the builder and its mutations', function (): void {
    $this->withoutVite();
    $tenant = builderTenant();
    $owner = User::factory()->create();
    $viewer = User::factory()->create();

    enterTenant($tenant->id, $owner->id);
    $form = app(FormService::class)->create($tenant, $owner, 'Survey');

    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // no forms.* permissions

    $this->actingAs($viewer)->get("http://acme.meridian.test/forms/{$form->id}/builder")->assertForbidden();
    $this->actingAs($viewer)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'short_text'])
        ->assertForbidden();
});

it('lets an Admin add, configure, duplicate, reorder and delete through the builder', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Household Survey');

    // Add a field.
    $add = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'short_text'])
        ->assertOk()->assertJsonStructure(['id', 'key', 'field_type', 'version']);
    $fieldId = $add->json('id');
    $version = $add->json('version');

    // Configure it (label/key/required + one structured validation rule).
    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => 'age',
            'label' => 'Age',
            'is_required' => 'required',
            'config' => [],
            'validations' => [
                ['rule_type' => 'min_value', 'operator' => null, 'rule_value' => '0', 'expression' => null,
                    'error_message' => 'Must be positive', 'related_field_key' => null],
            ],
            'version' => $version,
        ])
        ->assertOk()->assertJsonPath('key', 'age')->assertJsonPath('is_required', 'required');

    // Duplicate it.
    $dup = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}/duplicate")
        ->assertOk();
    $dupId = $dup->json('id');

    // Add a section and move the original field into it via reorder.
    $section = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/sections")->assertOk();
    $sectionId = $section->json('id');

    $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/reorder", [
            'sections' => [['id' => $sectionId, 'sequence' => 0]],
            'fields' => [['id' => $fieldId, 'form_section_id' => $sectionId, 'sequence' => 0, 'section_sequence' => null]],
        ])
        ->assertOk();

    // Delete the duplicate.
    $this->actingAs($admin)
        ->deleteJson("http://acme.meridian.test/forms/{$form->id}/fields/{$dupId}")
        ->assertOk();

    enterTenant($tenant->id, $admin->id);
    $field = FormField::query()->whereKey($fieldId)->firstOrFail();
    expect($field->key)->toBe('age');
    expect($field->label)->toBe('Age');
    expect($field->form_section_id)->toBe($sectionId);
    expect(FormFieldValidation::query()->where('form_field_id', $fieldId)->count())->toBe(1);
    expect(FormField::query()->where('form_version_id', $form->draft_version_id)->count())->toBe(1); // dup gone
    expect(FormSection::query()->where('form_version_id', $form->draft_version_id)->count())->toBe(1);
});

it('rejects a stale field edit with 409 and returns the current row', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');

    $add = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'short_text'])
        ->assertOk();
    $fieldId = $add->json('id');
    $staleVersion = $add->json('version');

    // Simulate a concurrent edit: bump updated_at out from under the stale token.
    enterTenant($tenant->id, $admin->id);
    FormField::query()->whereKey($fieldId)->update(['updated_at' => now()->addMinute()]);

    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => 'age',
            'label' => 'Age',
            'is_required' => 'optional',
            'config' => [],
            'validations' => [],
            'version' => $staleVersion,
        ])
        ->assertStatus(409)
        ->assertJsonStructure(['message', 'current' => ['id', 'version']]);

    // The stale write did NOT land (in-flight input preserved client-side, nothing overwritten server-side).
    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->whereKey($fieldId)->value('key'))->not->toBe('age');
});

it('accepts an edit that carries no concurrency token (overwrite)', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');

    $add = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'short_text'])
        ->assertOk();
    $fieldId = $add->json('id');

    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => 'forced',
            'label' => 'Forced',
            'is_required' => 'optional',
            'config' => [],
            'validations' => [],
            'version' => null,
        ])
        ->assertOk()->assertJsonPath('key', 'forced');
});

it('validates the key format and per-version uniqueness', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');

    $a = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'short_text'])->assertOk();
    $b = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'short_text'])->assertOk();

    // Rename b to a's key → duplicate rejected (422).
    $this->actingAs($admin)->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$b->json('id')}", [
        'key' => $a->json('key'),
        'label' => 'Dup', 'is_required' => 'optional', 'config' => [], 'validations' => [], 'version' => null,
    ])->assertStatus(422);

    // A bad key format is rejected too.
    $this->actingAs($admin)->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$b->json('id')}", [
        'key' => 'Not A Key',
        'label' => 'Bad', 'is_required' => 'optional', 'config' => [], 'validations' => [], 'version' => null,
    ])->assertStatus(422);
});

// Increment G5b2b — the geospatial "Map" config editor persists its options through the same optimistic
// PATCH. The config is lenient (all optional, validated by UpdateFieldRequest::configRules()'s isGeo() arm)
// but out-of-range coordinates are rejected; these are the exact snake_case keys the GeoEditor writes and
// EncodeFormPresenter::geo()/buildGeo() read.
it('persists a geospatial map config and rejects an out-of-range coordinate', function (): void {
    $tenant = builderTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Field Survey');

    $add = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'geopoint'])
        ->assertOk();
    $fieldId = $add->json('id');
    $key = $add->json('key');

    // A full, valid geo config block persists verbatim.
    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => $key,
            'label' => 'Pin location',
            'is_required' => 'optional',
            'config' => [
                'capture_altitude' => true,
                'accuracy_threshold' => 20,
                'default_center' => ['lat' => 14.6, 'lon' => 121.0],
                'default_zoom' => 11,
            ],
            'validations' => [],
            'version' => null,
        ])
        ->assertOk()
        ->assertJsonPath('config.capture_altitude', true)
        ->assertJsonPath('config.default_center.lat', 14.6)
        ->assertJsonPath('config.default_zoom', 11);

    // An out-of-range latitude is rejected (the one place geo config shape is checked).
    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => $key,
            'label' => 'Pin location',
            'is_required' => 'optional',
            'config' => ['default_center' => ['lat' => 999, 'lon' => 0]],
            'validations' => [],
            'version' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['config.default_center.lat']);

    // Mid-edit leniency: an empty/partial geo config never 422s the optimistic PATCH.
    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => $key,
            'label' => 'Pin location',
            'is_required' => 'optional',
            'config' => [],
            'validations' => [],
            'version' => null,
        ])
        ->assertOk();
});
