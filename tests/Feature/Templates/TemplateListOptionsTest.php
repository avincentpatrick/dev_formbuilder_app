<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\TemplateService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PsgcFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M186 (`R-d41a9632`) — a template never carries the choices a list wrote into a published version
|--------------------------------------------------------------------------
| A published list-backed cascade holds every choice in `config.options` (with the Philippine places, 43,000); a draft
| holds none and names its lists. A template saved from a published version — the API takes any version, an archived
| form falls back to its published one — and every form made from a template must get the draft's shape, not the
| version's, or the builder's page carries the whole list until the first publish.
|
| ⚠️ Helpers are prefixed `templateList*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'admin');

    PsgcFixture::load();
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Barangay Visit');
    addFormField($form->refresh()->draftVersion, $this->owner, 'place', FieldType::CascadingSelect, 1, ['config' => [
        'levels' => [
            ['key' => 'region', 'label' => 'Region', 'list' => 'psgc:region'],
            ['key' => 'province', 'label' => 'Province or district', 'list' => 'psgc:province'],
        ],
        'options' => [],
    ]]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $this->published = FormVersion::query()->findOrFail($form->refresh()->current_published_version_id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return array<string, mixed> the `place` question's config in a blueprint */
function templateListPlace(array $blueprint): array
{
    return (array) collect($blueprint['fields'])->firstWhere('key', 'place')['config'];
}

it('saves a template from a published version without the choices its lists wrote', function (): void {
    expect(FormField::query()->where('form_version_id', $this->published->id)->where('key', 'place')->firstOrFail()->config['options'])
        ->toHaveCount(7); // the version keeps them: 3 regions + 4 province-level places

    $template = app(TemplateService::class)->saveAsTemplate($this->published, $this->owner, ['name' => 'Barangay Visit']);
    $place = templateListPlace($template->schema_blueprint);

    expect($place['options'])->toBe([])
        ->and($place['levels'][1]['list'])->toBe('psgc:province');
});

it('makes a form from a template that already carries them with a draft that holds none, which publishes them again', function (): void {
    // A template saved before this fix: the published version's snapshot, choices and all.
    $template = FormTemplate::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Old template',
        'schema_blueprint' => $this->published->schema_snapshot,
        'source_form_version_id' => $this->published->id,
        'is_public' => false,
        'usage_count' => 0,
        'created_by' => $this->owner->id,
    ]);
    expect(templateListPlace($template->schema_blueprint)['options'])->toHaveCount(7);

    $form = app(TemplateService::class)->instantiate($template, $this->tenant, $this->owner);
    $draft = FormField::query()->where('form_version_id', $form->refresh()->draft_version_id)->where('key', 'place')->firstOrFail();

    expect($draft->config['options'])->toBe([]);

    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $republished = FormField::query()->where('form_version_id', $form->refresh()->current_published_version_id)->where('key', 'place')->firstOrFail();

    expect($republished->config['options'])->toHaveCount(7);
});
