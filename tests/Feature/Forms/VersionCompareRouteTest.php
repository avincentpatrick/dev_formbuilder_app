<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\SchemaChangeClassifier;
use App\Services\Forms\VersionComparison;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PsgcFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M189 (`version-diff-ui`, `D169`) — the Compare page: any two versions of a form, opened from the hub's Versions panel.
|--------------------------------------------------------------------------
| `GET /forms/{form}/compare/{from}/{to}`, `can:view,form` — View's gate. Two version parameters cannot be scoped to the
| form by `scopeBindings` (Laravel would resolve `{to}` through `$from`), so the controller checks both itself, and a draft
| side is the form's own draft only.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Compare Form'); // v1 published, v2 the draft
    $this->v1 = FormVersion::query()->where('form_id', $this->form->id)->where('version_number', 1)->firstOrFail();
    $this->v2 = FormVersion::query()->where('form_id', $this->form->id)->where('version_number', 2)->firstOrFail();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function compareUrl(string $formId, string $fromId, string $toId): string
{
    return "http://acme.meridian.test/forms/{$formId}/compare/{$fromId}/{$toId}";
}

it('compares the live version with the draft, question by question, with the words to say it in', function (): void {
    FormField::query()->where('form_version_id', $this->v2->id)->where('key', 'color')->firstOrFail()->update(['is_required' => 'required']);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(compareUrl($this->form->id, $this->v1->id, $this->v2->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/VersionCompare', false)
            ->where('form.id', $this->form->id)
            ->where('from.id', $this->v1->id)
            ->where('from.name', 'Version 1 (live)')
            ->where('to.id', $this->v2->id)
            ->where('to.name', 'Draft')
            ->has('versions', 2)
            ->where('comparison.questions', fn ($questions): bool => collect($questions)->firstWhere('key', 'color')['breaking'] === 'required'
                && collect($questions)->where('status', 'changed')->count() === 1)
            ->has('words.enums.required_modes')
            ->has('words.type_labels.short_text')
            ->where('labels.to.color', 'Color')
            ->where('hub_href', "/forms/{$this->form->id}")
            ->etc());
});

it('marks the same questions as hard to compare that publishing records as breaking', function (): void {
    FormField::query()->where('form_version_id', $this->v2->id)->where('key', 'color')->firstOrFail()->update(['is_required' => 'required']);
    FormField::query()->where('form_version_id', $this->v2->id)->where('key', 'hobbies')->firstOrFail()->delete();

    $classification = app(SchemaChangeClassifier::class)->classify($this->v2, $this->v1);
    $expected = [...array_column($classification->breakingChanges, 'key'), ...$classification->removedFieldKeys];
    sort($expected);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(compareUrl($this->form->id, $this->v1->id, $this->v2->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison.questions', function ($questions) use ($expected): bool {
                $marked = collect($questions)->filter(fn (array $q): bool => in_array(VersionComparison::COMPARABILITY, $q['marks'], true))->pluck('key')->sort()->values()->all();

                return $marked === $expected && $expected !== [];
            })
            ->etc());
});

it('reads a list-backed question and the Philippine places as unchanged between the live version and its draft', function (): void {
    PsgcFixture::load();
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Referral');
    $path = (string) tempnam(sys_get_temp_dir(), 'm189');
    file_put_contents($path, "name,label\nRHU1,San Jose RHU\nBHS2,Poblacion BHS\n");
    app(FormChoiceListService::class)->upload($form->refresh(), new UploadedFile($path, 'facilities.csv', null, null, true));
    $draft = $form->refresh()->draftVersion;
    addFormField($draft, $this->owner, 'facility', FieldType::Dropdown, 1, ['config' => ['options' => [], 'options_list' => 'facilities']]);
    addFormField($draft, $this->owner, 'place', FieldType::CascadingSelect, 2, ['config' => [
        'levels' => [
            ['key' => 'region', 'label' => 'Region', 'list' => 'psgc:region'],
            ['key' => 'province', 'label' => 'Province or district', 'list' => 'psgc:province'],
        ],
        'options' => [],
        'skip_single_choice_levels' => true,
    ]]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $form = $form->refresh();

    $live = FormVersion::query()->findOrFail($form->current_published_version_id);
    expect(collect($live->schema_snapshot['fields'])->firstWhere('key', 'place')['config']['options'])->not->toBe([]);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(compareUrl($form->id, (string) $form->current_published_version_id, (string) $form->draft_version_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('comparison.questions', fn ($questions): bool => collect($questions)->pluck('status', 'key')->all() === ['facility' => 'same', 'place' => 'same'])
            ->etc());
});

it('compares any two published versions, either way round', function (): void {
    FormField::query()->where('form_version_id', $this->v2->id)->where('key', 'color')->firstOrFail()->update(['label' => 'Colour']);
    app(PublishService::class)->publish($this->form->refresh(), $this->owner); // v2 published, v3 the draft

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(compareUrl($this->form->id, $this->v2->id, $this->v1->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('from.name', 'Version 2 (live)')
            ->where('to.name', 'Version 1')
            ->has('versions', 3)
            ->where('comparison.questions', fn ($questions): bool => collect($questions)->firstWhere('key', 'color')['label'] === 'Color'
                && collect($questions)->firstWhere('key', 'color')['changes'][0]['before'] === 'Colour')
            ->etc());
});

it('refuses a version of another form, and an id that is not one', function (): void {
    $other = publishedInboxForm($this->tenant, $this->owner, 'Another Form');
    $foreign = (string) $other->refresh()->current_published_version_id;

    $this->withoutVite()->actingAs($this->owner)->get(compareUrl($this->form->id, $foreign, $this->v2->id))->assertNotFound();
    $this->withoutVite()->actingAs($this->owner)->get(compareUrl($this->form->id, $this->v1->id, $foreign))->assertNotFound();
    $this->withoutVite()->actingAs($this->owner)->get(compareUrl($this->form->id, 'v1', $this->v2->id))->assertNotFound();
});

it('refuses a reader without view on the form, as View does', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->withoutVite()
        ->actingAs($viewer)
        ->get(compareUrl($this->form->id, $this->v1->id, $this->v2->id))
        ->assertForbidden();
});
