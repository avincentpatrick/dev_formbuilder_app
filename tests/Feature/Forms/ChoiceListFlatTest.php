<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\FormException;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\ChoiceListMaterializer;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\RestoreService;
use App\Services\Validation\SemanticValidator;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M184 (`R-3e7c81bb`) — a Single choice, Multiple choice or Dropdown takes its choices from a list file.
|--------------------------------------------------------------------------
| `config.options_list` names one of the draft's lists; publishing writes the list's rows as the question's options, as
| it does a cascade's, so every reader of a published version — both engines, every export, the paper, the scan — sees
| plain choices. Unlike a cascade's, they stay in the schema a browser downloads, so one list is capped at 5,000 rows.
| The next draft names the list and holds none of its rows.
|
| ⚠️ Helpers are prefixed `flatList*`: Pest loads every test file into one process.
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
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Referral');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function flatListUpload(object $test, string $name, string $content): void
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm184');
    file_put_contents($path, $content);
    app(FormChoiceListService::class)->upload($test->form->refresh(), new UploadedFile($path, $name, null, null, true));
}

/** @param  array<string, mixed>  $config */
function flatListField(object $test, array $config, FieldType $type = FieldType::Dropdown): FormField
{
    return addFormField($test->form->refresh()->draftVersion, $test->admin, 'facility', $type, 1, ['config' => $config]);
}

function flatListPublishError(object $test): string
{
    try {
        app(PublishService::class)->publish($test->form->refresh(), $test->admin);
    } catch (PublishValidationException $e) {
        return $e->getMessage();
    }

    return 'published';
}

it('writes the list into the published question, and the next draft names the list and holds none of it', function (FieldType $type): void {
    flatListUpload($this, 'facilities.csv', "name,label,province\nRHU1,San Jose RHU,0128\nBHS2,Poblacion BHS,0128\n");
    flatListField($this, ['options' => [], 'options_list' => 'facilities'], $type);
    $draftId = (string) $this->form->refresh()->draft_version_id;

    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $form = $this->form->refresh();

    $published = FormField::query()->where('form_version_id', $draftId)->where('key', 'facility')->firstOrFail();
    $expected = [['value' => 'RHU1', 'label' => 'San Jose RHU'], ['value' => 'BHS2', 'label' => 'Poblacion BHS']];

    expect($published->config['options'])->toEqual($expected)
        ->and(collect(FormVersion::query()->findOrFail($draftId)->schema_snapshot['fields'])->firstWhere('key', 'facility')['config']['options'])->toEqual($expected);

    $next = FormField::query()->where('form_version_id', $form->draft_version_id)->where('key', 'facility')->firstOrFail();
    expect($next->config['options'])->toBe([])
        ->and($next->config['options_list'])->toBe('facilities');
})->with([FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown]);

it('writes over typed choices a template or a restore carried beside the list name', function (): void {
    flatListUpload($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    flatListField($this, ['options' => [['value' => 'old', 'label' => 'Old']], 'options_list' => 'facilities']);

    expect(flatListPublishError($this))->toBe('published');

    $version = FormVersion::query()->findOrFail($this->form->refresh()->current_published_version_id);
    expect(collect($version->schema_snapshot['fields'])->firstWhere('key', 'facility')['config']['options'])
        ->toEqual([['value' => 'RHU1', 'label' => 'San Jose RHU']]);
});

it('keeps the options in the schema a browser downloads, and out of the cascade lists served beside it', function (): void {
    flatListUpload($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    flatListField($this, ['options' => [], 'options_list' => 'facilities']);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $version = FormVersion::query()->findOrFail($this->form->refresh()->current_published_version_id);

    $schemaField = collect($version->schemaWithoutListOptions()['fields'])->firstWhere('key', 'facility');

    expect($schemaField['config']['options'])->toEqual([['value' => 'RHU1', 'label' => 'San Jose RHU']])
        ->and(ChoiceListMaterializer::listsForBrowser($version))->toBe([]);
});

it('shows a keyer the list on the encode page', function (): void {
    flatListUpload($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    flatListField($this, ['options' => [], 'options_list' => 'facilities']);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);

    $this->withoutVite()->actingAs($this->admin)
        ->get("http://acme.meridian.test/forms/{$this->form->id}/submissions/create")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('blocks.0.fields.0.key', 'facility')
            ->where('blocks.0.fields.0.options', [['value' => 'RHU1', 'label' => 'San Jose RHU']]));
});

it('checks an answer against the list on the server, unlike a list taken from another form', function (): void {
    flatListUpload($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    flatListField($this, ['options' => [], 'options_list' => 'facilities']);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $version = FormVersion::query()->findOrFail($this->form->refresh()->current_published_version_id);
    $validator = app(SemanticValidator::class);

    expect($validator->validate($version, ['facility' => 'RHU1'])->passed())->toBeTrue()
        ->and($validator->validate($version, ['facility' => 'GONE'])->errorsFor('facility')[0]->rule ?? null)->toBe('choice_not_in_options');
});

it('refuses to publish a list it cannot use, each with its reason', function (Closure $setUp, string $reason): void {
    flatListUpload($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    $setUp($this);

    expect(flatListPublishError($this))->toContain($reason);
})->with([
    'no list chosen yet' => [fn (object $t) => flatListField($t, ['options' => [], 'options_list' => '']), 'no list file is chosen for its choices'],
    'a list the form does not hold' => [fn (object $t) => flatListField($t, ['options' => [], 'options_list' => 'clinics']), 'it takes its choices from “clinics”, which this form does not hold — upload clinics.csv or clinics.xlsx'],
    'a list and a link' => [fn (object $t) => flatListField($t, ['options' => [], 'options_list' => 'facilities', 'options_source' => ['form_id' => 'f', 'field_key' => 'k']]), 'it takes its choices from a list file and from another form — choose one'],
    'a Likert scale' => [fn (object $t) => flatListField($t, ['options' => [], 'options_list' => 'facilities'], FieldType::LikertScale), 'a Likert scale keeps its own points and cannot take them from a list file'],
]);

it('refuses a list longer than one question takes, and points at a cascade', function (): void {
    $rows = array_map(static fn (int $i): string => "c{$i},Choice {$i}", range(1, ChoiceListMaterializer::FLAT_LIST_MAX + 1));
    flatListUpload($this, 'barangays.csv', "name,label\n".implode("\n", $rows)."\n");
    flatListField($this, ['options' => [], 'options_list' => 'barangays']);

    expect(flatListPublishError($this))->toContain('“barangays” holds 5,001 choices, and one question takes at most 5,000 — use a cascading select for a longer list');
});

it('restores an old version\'s list-bound question into the draft without its rows', function (): void {
    flatListUpload($this, 'facilities.csv', "name,label\nRHU1,San Jose RHU\n");
    flatListField($this, ['options' => [], 'options_list' => 'facilities']);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $source = FormVersion::query()->findOrFail($this->form->refresh()->current_published_version_id);

    app(RestoreService::class)->restore($this->form->refresh(), $source, $this->admin);
    $restored = FormField::query()->where('form_version_id', $this->form->refresh()->draft_version_id)->where('key', 'facility')->firstOrFail();

    expect($restored->config['options'])->toBe([])
        ->and($restored->config['options_list'])->toBe('facilities');
});

it('saves a list name through the builder, a blank one while choosing, and refuses a name no file could have', function (): void {
    test()->withHeaders(builderHeaders($this->form));
    $add = $this->actingAs($this->admin)
        ->postJson("http://acme.meridian.test/forms/{$this->form->id}/fields", ['field_type' => 'dropdown'])->assertOk();
    $patch = fn (string $list) => $this->actingAs($this->admin)
        ->patchJson("http://acme.meridian.test/forms/{$this->form->id}/fields/{$add->json('id')}", [
            'key' => $add->json('key'), 'label' => 'Facility', 'is_required' => 'optional',
            'config' => ['options' => [], 'options_list' => $list], 'validations' => [], 'version' => null,
        ]);

    $patch('')->assertOk();
    $patch('facilities')->assertOk()->assertJsonPath('config.options_list', 'facilities');
    $patch('Facilities List!')->assertUnprocessable();
});

it('offers no "Other, please specify" on a question whose choices come from a list file', function (): void {
    $field = flatListField($this, ['options' => [], 'options_list' => 'facilities'], FieldType::SingleSelect);

    expect(fn () => app(FormBuilderService::class)->addOtherSpecify($this->form->refresh(), $this->admin, $field))
        ->toThrow(FormException::class);
});
