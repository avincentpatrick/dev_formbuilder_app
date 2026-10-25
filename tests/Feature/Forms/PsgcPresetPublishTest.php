<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PsgcFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M186 (`R-70c7673a`, `D131`) — a cascade on the built-in Philippine places publishes from the platform's table
|--------------------------------------------------------------------------
| Levels named `psgc:region` … are list-backed like uploaded ones: the draft holds no options, publishing writes the
| newest quarter's into the version, the guest gets them beside the schema — and a later quarter changes the NEXT publish
| only. Refused in words: levels out of order, levels mixed with list files, places not loaded on this server.
|
| ⚠️ Helpers are prefixed `psgcPreset*`: Pest loads every test file into one process.
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
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return list<array{key: string, label: string, list: string}> */
function psgcPresetLevels(int $count = 4): array
{
    return array_slice([
        ['key' => 'region', 'label' => 'Region', 'list' => 'psgc:region'],
        ['key' => 'province', 'label' => 'Province or district', 'list' => 'psgc:province'],
        ['key' => 'city', 'label' => 'City or municipality', 'list' => 'psgc:city'],
        ['key' => 'barangay', 'label' => 'Barangay', 'list' => 'psgc:barangay'],
    ], 0, $count);
}

/** A draft form with one cascade on these levels. */
function psgcPresetForm(User $owner, array $levels): Form
{
    $form = app(FormService::class)->create(test()->tenant, $owner, 'Barangay Visit');
    addFormField($form->refresh()->draftVersion, $owner, 'place', FieldType::CascadingSelect, 1, ['config' => [
        'levels' => $levels,
        'options' => [],
        'skip_single_choice_levels' => true,
    ]]);

    return $form->refresh();
}

/** @return array<string, mixed> the published `place` question's config */
function psgcPresetPublished(Form $form): array
{
    $version = FormVersion::query()->findOrFail($form->refresh()->current_published_version_id);

    return (array) FormField::query()->where('form_version_id', $version->id)->where('key', 'place')->firstOrFail()->config;
}

it('writes the newest quarter into the published version, every place one level under its parent', function (): void {
    PsgcFixture::load();
    $form = psgcPresetForm($this->owner, psgcPresetLevels());

    app(PublishService::class)->publish($form, $this->owner);
    $options = collect(psgcPresetPublished($form)['options']);

    expect($options)->toHaveCount(19)
        ->and($options->where('level', 'region')->pluck('parent')->unique()->all())->toBe([null])
        ->and($options->firstWhere(fn (array $o): bool => $o['level'] === 'city' && $o['value'] === '1380600000'))
        // toEqual: `jsonb` keeps an object's keys in its own order.
        ->toEqual(['level' => 'city', 'value' => '1380600000', 'label' => 'City of Manila', 'parent' => '1380600000'])
        // Name order, numbers in natural order.
        ->and($options->where('level', 'barangay')->where('parent', '1380600000')->pluck('label')->values()->all())
        ->toBe(['Barangay 1', 'Barangay 2', 'Barangay 10']);
});

it('keeps the draft free of the places, and serves them to a guest beside the schema', function (): void {
    PsgcFixture::load();
    $form = psgcPresetForm($this->owner, psgcPresetLevels());
    app(PublishService::class)->publish($form, $this->owner);
    $form->refresh()->update(['public_slug' => 'barangay-visit', 'allow_guest_submissions' => true]);

    $draft = FormField::query()->where('form_version_id', $form->refresh()->draft_version_id)->where('key', 'place')->firstOrFail();
    $schema = $this->getJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form))->assertOk()->json('data.version.schema');
    $lists = $this->getJson('http://acme.meridian.test/api/v1/public/choice-lists/'.shareTokenFor($form).'/'.$form->current_published_version_id)
        ->assertOk()->json('data.lists.place');

    expect($draft->config['options'])->toBe([])
        ->and($draft->config['levels'][3]['list'])->toBe('psgc:barangay')
        ->and(collect($schema['fields'])->firstWhere('key', 'place')['config']['options'])->toBe([])
        ->and($lists['levels'])->toBe(['region', 'province', 'city', 'barangay'])
        ->and($lists['options'])->toHaveCount(19);
});

it('keeps a published form on its quarter; a later quarter changes the next publish only', function (): void {
    PsgcFixture::load('2026Q3');
    $form = psgcPresetForm($this->owner, psgcPresetLevels());
    app(PublishService::class)->publish($form, $this->owner);
    $first = $form->refresh()->current_published_version_id;

    $renamed = array_map(static fn (array $p): array => $p['code'] === '0102805001' ? [...$p, 'name' => 'Aglipay Norte'] : $p, PsgcFixture::places());
    PsgcFixture::load('2026Q4', $renamed);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $labelIn = static fn (string $version): ?string => collect(FormField::query()->where('form_version_id', $version)->where('key', 'place')->firstOrFail()->config['options'])
        ->firstWhere('value', '0102805001')['label'] ?? null;

    expect($labelIn($first))->toBe('Aglipay')
        ->and($labelIn((string) $form->refresh()->current_published_version_id))->toBe('Aglipay Norte');
});

it('publishes the top levels alone, and refuses a gap or a level out of order', function (): void {
    PsgcFixture::load();

    $two = psgcPresetForm($this->owner, psgcPresetLevels(2));
    app(PublishService::class)->publish($two, $this->owner);
    expect(collect(psgcPresetPublished($two)['options'])->pluck('level')->unique()->values()->all())->toBe(['region', 'province']);

    $gap = psgcPresetForm($this->owner, [psgcPresetLevels()[0], psgcPresetLevels()[2]]);
    expect(fn () => app(PublishService::class)->publish($gap, $this->owner))
        ->toThrow(PublishValidationException::class, 'keep that order from the top');
});

it('refuses levels that mix the places with a list file', function (): void {
    PsgcFixture::load();
    $levels = psgcPresetLevels(2);
    $levels[1]['list'] = 'provinces';

    expect(fn () => app(PublishService::class)->publish(psgcPresetForm($this->owner, $levels), $this->owner))
        ->toThrow(PublishValidationException::class, 'the Philippine places and a list file at once');
});

it('refuses, in words, while the places are not loaded on this server', function (): void {
    expect(fn () => app(PublishService::class)->publish(psgcPresetForm($this->owner, psgcPresetLevels()), $this->owner))
        ->toThrow(PublishValidationException::class, 'not loaded on this server yet');
});

it('saves a level bound to the places, and still refuses any other name with a colon', function (): void {
    $form = psgcPresetForm($this->owner, []);
    $field = FormField::query()->where('form_version_id', $form->draft_version_id)->where('key', 'place')->firstOrFail();
    $url = 'http://acme.meridian.test/forms/'.$form->id.'/fields/'.$field->id;
    $patch = static fn (array $levels): array => [
        'key' => 'place', 'label' => 'Place', 'is_required' => 'optional', 'version' => null, 'validations' => [],
        'config' => ['levels' => $levels, 'options' => [], 'skip_single_choice_levels' => true],
    ];

    $this->actingAs($this->owner)->withHeaders(builderHeaders($form))->patchJson($url, $patch(psgcPresetLevels()))->assertOk();
    $this->actingAs($this->owner)->withHeaders(builderHeaders($form))->patchJson($url, $patch([['key' => 'x', 'label' => 'X', 'list' => 'psgc:purok']]))
        ->assertUnprocessable()->assertJsonValidationErrors('config.levels.0.list');
});
