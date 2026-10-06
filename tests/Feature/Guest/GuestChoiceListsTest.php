<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
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
| M141 (`R-f69aab42`, `D95`) — a published form's CSV choice lists, served to a guest beside the schema.
|--------------------------------------------------------------------------
| The published cascade holds its whole list (every server-side reader needs it there); the schema a browser is sent
| leaves it out, and `public/choice-lists/{token}/{version}` serves it once per version, each choice a tuple.
|
| ⚠️ Helpers are prefixed `guestChoiceList*`: Pest loads every test file into one process.
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
    $this->form = guestChoiceListForm($this->tenant, $this->owner);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function guestChoiceListFile(string $name, string $content): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm141');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/** A published guest form with a two-level cascade from two CSV files, and a typed dropdown beside it. */
function guestChoiceListForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Household Visit');
    $lists = app(FormChoiceListService::class);
    $lists->upload($form->refresh(), guestChoiceListFile('regions.csv', "name,label\n01,Ilocos Region\n13,NCR\n"));
    $lists->upload($form->refresh(), guestChoiceListFile('provinces.csv', "name,label,region\n0128,Ilocos Norte,01\n1339,Manila,13\n"));
    addFormField($form->refresh()->draftVersion, $owner, 'address', FieldType::CascadingSelect, 1, ['config' => [
        'levels' => [['key' => 'region', 'label' => 'Region', 'list' => 'regions'], ['key' => 'province', 'label' => 'Province', 'list' => 'provinces']],
        'options' => [],
    ]]);
    addFormField($form->refresh()->draftVersion, $owner, 'visit', FieldType::Dropdown, 2, ['config' => ['options' => [
        ['value' => 'first', 'label' => 'First'], ['value' => 'follow_up', 'label' => 'Follow-up'],
    ]]]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update(['public_slug' => 'household-visit', 'allow_guest_submissions' => true]);

    return $form->refresh();
}

function guestChoiceListUrl(Form $form, ?string $version = null): string
{
    $version ??= (string) $form->current_published_version_id;

    return 'http://acme.meridian.test/api/v1/public/choice-lists/'.shareTokenFor($form).'/'.$version;
}

it('serves each CSV-backed cascade’s list, keyed by question, each choice a tuple, cacheable for the version', function (): void {
    $response = $this->getJson(guestChoiceListUrl($this->form))->assertOk();

    expect($response->json('data.version_id'))->toBe($this->form->current_published_version_id)
        ->and($response->json('data.lists.address.levels'))->toBe(['region', 'province'])
        ->and($response->json('data.lists.address.options'))->toEqualCanonicalizing([
            [0, '01', 'Ilocos Region', null],
            [0, '13', 'NCR', null],
            [1, '0128', 'Ilocos Norte', '01'],
            [1, '1339', 'Manila', '13'],
        ])
        // A typed list is not a CSV list, and stays in the schema.
        ->and($response->json('data.lists.visit'))->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400');
});

it('sends the schema without the list, while the published version keeps it for every server-side reader', function (): void {
    $schema = $this->getJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($this->form))->assertOk()->json('data.version.schema');
    $address = collect($schema['fields'])->firstWhere('key', 'address');
    $visit = collect($schema['fields'])->firstWhere('key', 'visit');

    enterTenant($this->tenant->id, $this->owner->id);
    $frozen = collect(FormVersion::query()->findOrFail($this->form->current_published_version_id)->schema_snapshot['fields'])->firstWhere('key', 'address');

    expect($address['config']['options'])->toBe([])
        ->and($address['config']['levels'][0]['list'])->toBe('regions')
        ->and($visit['config']['options'])->toHaveCount(2)
        ->and($frozen['config']['options'])->toHaveCount(4);
});

it('answers the same 404 for another version, and once the form stops taking guest answers', function (): void {
    $this->getJson(guestChoiceListUrl($this->form, '01900000-0000-7000-8000-000000000000'))
        ->assertNotFound()->assertJsonPath('error.code', 'choice_lists_not_found');

    $url = guestChoiceListUrl($this->form);
    enterTenant($this->tenant->id, $this->owner->id);
    $this->form->update(['allow_guest_submissions' => false]);

    $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'choice_lists_not_found');
});
