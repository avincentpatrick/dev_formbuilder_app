<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormField;
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
| M111 / B0 — config keys survive a field save.
|--------------------------------------------------------------------------
| UpdateFieldRequest declares `config` as ['present','array'] ALONGSIDE nested `config.*` rules, and
| Illuminate\Validation\Factory sets $excludeUnvalidatedArrayKeys = true on every validator it makes.
| Validator::validated() therefore SKIPS the top-level key and rebuilds `config` from the enumerated
| nested paths only (Validator.php:659-664) — so every key no arm enumerates is dropped, and
| FormBuilderService::writeField() whole-column-replaces with the result. 200 OK, data gone.
|
| That contradicts this very request's own class docblock, which states the posture as "persist
| unvalidated config, validate at publish". The pruning is what makes the first half untrue.
|
| Only the seven non-empty configRules() arms are affected — media, geo, cascading, matrix,
| likert_matrix, hidden and choices. A type whose arm returns [] keeps its config whole, which is why
| this stayed invisible for as long as it did.
|
| ⛔ EVERY TEST HERE WAS WRITTEN AND WATCHED TO FAIL BEFORE THE REMEDY EXISTED, except the clearing
| case, which must be green throughout: it is the guard against "fix it with a merge" being read as
| merging the STORED row over the payload, which would make a removed key unremovable.
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

/** Distinct name from BuilderRoutesTest's helper, per this suite's own convention. */
function configRetentionTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);

    return $tenant;
}

/**
 * An admin on a fresh tenant with one draft form, ready to author fields.
 *
 * @return array{0: Tenant, 1: User, 2: Form}
 */
function configRetentionAuthor(): array
{
    $tenant = configRetentionTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Multilingual Intake');

    return [$tenant, $admin, $form];
}

/** Add a field of $type through the real builder route and return [id, key]. */
function configRetentionField(User $admin, Form $form, string $type): array
{
    $add = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => $type])
        ->assertOk();

    return [$add->json('id'), $add->json('key')];
}

/** @param  array<string, mixed>  $config */
function configRetentionPatch(User $admin, Form $form, string $fieldId, string $key, array $config)
{
    return test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => $key,
            'label' => 'Question',
            'is_required' => 'optional',
            'config' => $config,
            'validations' => [],
            'version' => null,
        ]);
}

it('keeps option label_translations through a choice-field save', function (): void {
    [$tenant, $admin, $form] = configRetentionAuthor();
    [$fieldId, $key] = configRetentionField($admin, $form, 'single_select');

    // Exactly the shape XlsformImportParser::optionsFromList() writes (:343-344) and
    // XlsformExporter::pairs() re-exports (:555). The client sends it back verbatim, because
    // ChoicesEditor.vue spreads the whole option object.
    configRetentionPatch($admin, $form, $fieldId, $key, [
        'options' => [
            ['value' => 'yes', 'label' => 'Yes', 'label_translations' => ['fil' => 'Oo', 'es' => 'Si']],
            ['value' => 'no', 'label' => 'No', 'label_translations' => ['fil' => 'Hindi']],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('config.options.0.label_translations.fil', 'Oo')
        ->assertJsonPath('config.options.0.label_translations.es', 'Si')
        ->assertJsonPath('config.options.1.label_translations.fil', 'Hindi');

    // And in the column, not only in the response the presenter built. The tenant is re-entered
    // first: an HTTP request leaves the test's own connection without the GUCs the RLS policies read,
    // which is the pattern BuilderDraftGuardTest.php:198 already follows.
    enterTenant($tenant->id, $admin->id);
    // ⚠️ ASSERTED PER LOCALE, NOT AS AN ORDERED MAP. `config` is jsonb, and Postgres NORMALISES
    // object key order alphabetically on write — a toBe() here compares the database's ordering rather
    // than the application's, and fails on correct data (`es` sorts before `fil`). Measured, not
    // guessed: the first green run failed on exactly that and the values were all present.
    $stored = FormField::query()->findOrFail($fieldId);
    expect($stored->config['options'][0]['label_translations'])->toHaveCount(2)
        ->and($stored->config['options'][0]['label_translations']['fil'])->toBe('Oo')
        ->and($stored->config['options'][0]['label_translations']['es'])->toBe('Si')
        ->and($stored->config['options'][0]['value'])->toBe('yes');
});

it('keeps grid label_translations through a matrix-field save', function (): void {
    [, $admin, $form] = configRetentionAuthor();
    [$fieldId, $key] = configRetentionField($admin, $form, 'matrix');

    // The read side already exists for these — XlsformExporter::pairs() via :196-199/:224-226,
    // schema-mapping.ts:368 into buildMatrix(), BlankFormPrintPresenter.php:417-420 — so the author
    // side dropping them is a live read against a write that cannot land.
    configRetentionPatch($admin, $form, $fieldId, $key, [
        'rows' => [['value' => 'q1', 'label' => 'Clean water', 'label_translations' => ['fil' => 'Malinis na tubig']]],
        'columns' => [['value' => 'agree', 'label' => 'Agree', 'label_translations' => ['fil' => 'Sang-ayon']]],
        'cells' => [['value' => 'text', 'label' => 'Text', 'label_translations' => ['fil' => 'Teksto']]],
    ])
        ->assertOk()
        ->assertJsonPath('config.rows.0.label_translations.fil', 'Malinis na tubig')
        ->assertJsonPath('config.columns.0.label_translations.fil', 'Sang-ayon')
        ->assertJsonPath('config.cells.0.label_translations.fil', 'Teksto');
});

it('keeps a config key that no rule enumerates', function (): void {
    [$tenant, $admin, $form] = configRetentionAuthor();
    [$fieldId, $key] = configRetentionField($admin, $form, 'geopoint');

    // This is the CLASS, not the instance. B2a's per-type default validations, B5a's config transfer
    // and B11a's content blocks each write keys no arm lists; without this, every one is eaten on the
    // next save. `geopoint` is used because its arm is non-empty, so the pruning is active.
    configRetentionPatch($admin, $form, $fieldId, $key, [
        'default_zoom' => 11,
        'basemap_provider' => 'osm',
        'author_notes' => ['scope' => 'pilot', 'revision' => 3],
    ])
        ->assertOk()
        ->assertJsonPath('config.default_zoom', 11)
        ->assertJsonPath('config.basemap_provider', 'osm')
        ->assertJsonPath('config.author_notes.scope', 'pilot');

    enterTenant($tenant->id, $admin->id);
    $stored = FormField::query()->findOrFail($fieldId);
    expect($stored->config)->toHaveKey('basemap_provider')
        ->and($stored->config['author_notes']['revision'])->toBe(3);
});

it('still clears a config key the client removes', function (): void {
    [$tenant, $admin, $form] = configRetentionAuthor();
    [$fieldId, $key] = configRetentionField($admin, $form, 'single_select');

    configRetentionPatch($admin, $form, $fieldId, $key, [
        'options' => [['value' => 'yes', 'label' => 'Yes', 'label_translations' => ['fil' => 'Oo']]],
        'author_notes' => 'draft',
    ])->assertOk();

    // ⛔ THE GUARD. The merge base must be the REQUEST, never the stored row: the client sends the
    // whole config on every save (useBuilderStore.ts:883), so a merge of the row over the payload
    // would make deleting the last option, or any key, impossible. Green before the fix and after.
    configRetentionPatch($admin, $form, $fieldId, $key, ['options' => []])->assertOk();

    enterTenant($tenant->id, $admin->id);
    $stored = FormField::query()->findOrFail($fieldId);
    expect($stored->config)->not->toHaveKey('author_notes')
        ->and($stored->config['options'])->toBe([]);
});

it('validates the label_translations shape rather than merely preserving it', function (): void {
    [, $admin, $form] = configRetentionAuthor();
    [$fieldId, $key] = configRetentionField($admin, $form, 'single_select');

    // Preserving an un-enumerated key and VALIDATING a known one are different fixes. This asserts
    // the second half is live: without the new rules the scalar is simply persisted, because nothing
    // in the request has an opinion about it.
    configRetentionPatch($admin, $form, $fieldId, $key, [
        'options' => [['value' => 'yes', 'label' => 'Yes', 'label_translations' => 'not-a-map']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['config.options.0.label_translations']);

    // Mid-edit leniency is unchanged: absent is fine, empty is fine. Neither 422s the optimistic PATCH.
    configRetentionPatch($admin, $form, $fieldId, $key, [
        'options' => [['value' => 'yes', 'label' => 'Yes', 'label_translations' => []]],
    ])->assertOk();

    configRetentionPatch($admin, $form, $fieldId, $key, [
        'options' => [['value' => 'yes', 'label' => 'Yes']],
    ])->assertOk();
});
