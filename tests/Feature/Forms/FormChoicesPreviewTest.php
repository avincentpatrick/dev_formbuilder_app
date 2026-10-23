<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\ChoicePreviewPresenter;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M184 (`R-d8c2ce84`) — GET /forms/{form}/choices and /forms/{form}/versions/{version}/choices.
|--------------------------------------------------------------------------
| What the builder's Preview, and an old version opened read-only, show of the choices a form takes from elsewhere. A
| draft's snapshot is empty, so its lists are built from its rows as publishing would build them; every list is cut to
| 200 choices — per level for a cascade, keeping children of the choices kept above — and says how many it holds.
|
| ⚠️ Helpers are prefixed `choicePreview*`: Pest loads every test file into one process.
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

    $this->source = app(FormService::class)->create($this->tenant, $this->admin, 'Facility Register');
    addFormField($this->source->draftVersion, $this->admin, 'facility_name', FieldType::ShortText, 1);
    app(PublishService::class)->publish($this->source->refresh(), $this->admin);
    $this->source = app(FormService::class)->setDataSharing($this->source->refresh(), true, null, $this->admin);
    seedInboxSubmission($this->source, null, SubmissionStatus::Submitted, ['facility_name' => 'San Jose RHU']);

    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Referral');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function choicePreviewList(Form $form, string $name, string $content): void
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm184');
    file_put_contents($path, $content);
    app(FormChoiceListService::class)->upload($form->refresh(), new UploadedFile($path, $name, null, null, true));
}

/** @param  array<string, mixed>  $config */
function choicePreviewField(object $test, string $key, FieldType $type, array $config, int $sequence): void
{
    addFormField($test->form->refresh()->draftVersion, $test->admin, $key, $type, $sequence, ['config' => $config]);
}

it('serves the draft\'s lists as publishing would write them, each with its size', function (): void {
    choicePreviewList($this->form, 'regions.csv', "name,label\n01,Ilocos Region\n13,NCR\n");
    choicePreviewList($this->form, 'provinces.csv', "name,label,region\n0128,Ilocos Norte,01\n1339,Manila,13\n");
    choicePreviewList($this->form, 'facilities.csv', "name,label\nRHU1,San Jose RHU\nBHS2,Poblacion BHS\n");
    choicePreviewField($this, 'address', FieldType::CascadingSelect, ['options' => [], 'levels' => [
        ['key' => 'region', 'label' => 'Region', 'list' => 'regions'], ['key' => 'province', 'label' => 'Province', 'list' => 'provinces'],
    ]], 1);
    choicePreviewField($this, 'facility', FieldType::Dropdown, ['options' => [], 'options_list' => 'facilities'], 2);
    choicePreviewField($this, 'visited', FieldType::ShortText, ['suggestions_source' => ['kind' => 'list', 'name' => 'facilities']], 3);
    choicePreviewField($this, 'referred_to', FieldType::SingleSelect, ['options' => [], 'options_source' => ['form_id' => $this->source->id, 'field_key' => 'facility_name']], 4);

    $data = $this->actingAs($this->admin)->getJson("http://acme.meridian.test/forms/{$this->form->id}/choices")->assertOk()->json('data');

    expect($data['cascades']['address'])->toBe([
        'levels' => ['region', 'province'],
        'options' => [[0, '01', 'Ilocos Region', null], [0, '13', 'NCR', null], [1, '0128', 'Ilocos Norte', '01'], [1, '1339', 'Manila', '13']],
        'total' => 4,
    ])
        ->and($data['flat']['facility'])->toBe(['options' => [['value' => 'RHU1', 'label' => 'San Jose RHU'], ['value' => 'BHS2', 'label' => 'Poblacion BHS']], 'total' => 2])
        ->and($data['suggestions']['visited'])->toBe(['suggestions' => ['San Jose RHU', 'Poblacion BHS'], 'total' => 2])
        ->and($data['linked']['referred_to']['options'])->toBe([['value' => 'San Jose RHU', 'label' => 'San Jose RHU']]);
});

it('shows the first 200 of a long list, and keeps a cascade\'s second level under the choices kept above it', function (): void {
    $rows = array_map(static fn (int $i): string => sprintf('r%03d,Region %d', $i, $i), range(1, 250));
    choicePreviewList($this->form, 'regions.csv', "name,label\n".implode("\n", $rows)."\n");
    choicePreviewList($this->form, 'towns.csv', "name,label,region\nt1,Town under the first,r001\nt2,Town under the last,r250\n");
    choicePreviewField($this, 'region_only', FieldType::Dropdown, ['options' => [], 'options_list' => 'regions'], 1);
    choicePreviewField($this, 'address', FieldType::CascadingSelect, ['options' => [], 'levels' => [
        ['key' => 'region', 'label' => 'Region', 'list' => 'regions'], ['key' => 'town', 'label' => 'Town', 'list' => 'towns'],
    ]], 2);

    $data = $this->actingAs($this->admin)->getJson("http://acme.meridian.test/forms/{$this->form->id}/choices")->assertOk()->json('data');
    $levelOne = array_values(array_filter($data['cascades']['address']['options'], static fn (array $o): bool => $o[0] === 1));

    expect($data['flat']['region_only']['options'])->toHaveCount(ChoicePreviewPresenter::SHOWN)
        ->and($data['flat']['region_only']['total'])->toBe(250)
        ->and($data['cascades']['address']['total'])->toBe(252)
        ->and(array_column($levelOne, 1))->toBe(['t1']);
});

it('serves a published version\'s cascade lists and links, for its read-only view', function (): void {
    choicePreviewList($this->form, 'regions.csv', "name,label\n01,Ilocos Region\n");
    choicePreviewField($this, 'address', FieldType::CascadingSelect, ['options' => [], 'levels' => [['key' => 'region', 'label' => 'Region', 'list' => 'regions']]], 1);
    choicePreviewField($this, 'referred_to', FieldType::Dropdown, ['options' => [], 'options_source' => ['form_id' => $this->source->id, 'field_key' => 'facility_name']], 2);
    $version = app(PublishService::class)->publish($this->form->refresh(), $this->admin);

    $data = $this->actingAs($this->admin)->getJson("http://acme.meridian.test/forms/{$this->form->id}/versions/{$version->id}/choices")->assertOk()->json('data');

    expect($data['cascades']['address']['options'])->toBe([[0, '01', 'Ilocos Region', null]])
        ->and($data['linked']['referred_to']['options'])->toBe([['value' => 'San Jose RHU', 'label' => 'San Jose RHU']]);
});

it('refuses a draft on the version route, and another form\'s version', function (): void {
    $this->actingAs($this->admin)->getJson("http://acme.meridian.test/forms/{$this->form->id}/versions/{$this->form->draft_version_id}/choices")->assertNotFound();
    $this->actingAs($this->admin)->getJson("http://acme.meridian.test/forms/{$this->form->id}/versions/{$this->source->current_published_version_id}/choices")->assertNotFound();
});

it('forbids a Viewer on both, the builder\'s and the version view\'s gate', function (): void {
    $version = $this->source->current_published_version_id;
    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->getJson("http://acme.meridian.test/forms/{$this->form->id}/choices")->assertForbidden();
    $this->actingAs($viewer)->getJson("http://acme.meridian.test/forms/{$this->source->id}/versions/{$version}/choices")->assertForbidden();
});

it('does not leak another tenant\'s form', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'beta']);
    $stranger = User::factory()->create();
    enterTenant($other->id, $stranger->id);
    makeActiveMember($stranger, 'admin');

    $this->actingAs($stranger)->getJson("http://beta.meridian.test/forms/{$this->form->id}/choices")->assertNotFound();
});
