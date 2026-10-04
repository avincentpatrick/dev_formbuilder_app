<?php

declare(strict_types=1);

use App\Models\FormFolder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Forms\FormListFacets;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The forms list's folder filter (M131, `R-9e634897`, `D78`).
|--------------------------------------------------------------------------
| ⛔ THE ISOLATION CASE IS THE ONE THAT MATTERS, and the row says so: a folder must never reveal a form the
| viewer cannot already see. The filter and its counts run in PHP over the rows `FormPresenter::list()`
| returned AFTER `visibleTo()`, exactly as the facet chips do — so an Editor's "Clinics (1)" counts their
| one form and not the Owner's two beside it. Folder NAMES are workspace-shared, so every folder is listed.
|
| The counts are faceted: each filter's counts apply the OTHER filter and never their own, so a chip or an
| option keeps showing its own total while it is the one selected.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->editor = User::factory()->create();
    makeActiveMember($this->editor, 'form_editor');

    $forms = app(FormService::class);
    $this->clinics = folderNamed('clinics');
    $this->archive = folderNamed('Archive');
    folderNamed('Empty');

    // Two of the Owner's forms in Clinics, one of the Editor's in Clinics, one of the Owner's unfiled.
    $forms->assignFolder($forms->create($this->tenant, $this->owner, 'Owner clinic A'), $this->clinics->id, $this->owner);
    $forms->assignFolder(publishedInboxForm($this->tenant, $this->owner, 'Owner clinic B'), $this->clinics->id, $this->owner);
    $forms->assignFolder($forms->create($this->tenant, $this->editor, 'Editor clinic'), $this->clinics->id, $this->owner);
    $forms->create($this->tenant, $this->owner, 'Owner loose');

    // The page props $user sees at /forms$query. A closure rather than a file-level function, because
    // `withoutVite()` is protected on the test case.
    $this->props = fn (User $user, string $query = ''): array => $this->actingAs($user)->withoutVite()
        ->get("http://acme.meridian.test/forms{$query}")
        ->viewData('page')['props'];
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function folderNamed(string $name): FormFolder
{
    $folder = new FormFolder;
    $folder->name = $name;
    $folder->save();

    return $folder;
}

/** @return array<string, int> folder name => count, in the order the page lists them */
function folderCounts(array $props): array
{
    return collect($props['folders']['options'])->mapWithKeys(fn (array $o): array => [$o['name'] => $o['count']])->all();
}

it('lists every folder alphabetically ignoring case, each with its count', function (): void {
    $props = ($this->props)($this->owner);

    expect(folderCounts($props))->toBe(['Archive' => 0, 'clinics' => 3, 'Empty' => 0])
        ->and($props['folders']['unfiled_count'])->toBe(1)
        ->and($props['filters']['applied']['folder'])->toBeNull()
        ->and(collect($props['forms'])->firstWhere('title', 'Owner loose')['folder_id'])->toBeNull()
        ->and(collect($props['forms'])->firstWhere('title', 'Editor clinic')['folder_id'])->toBe($this->clinics->id);
});

it('filters to one folder, and to Unfiled', function (): void {
    $this->actingAs($this->owner)->withoutVite()
        ->get("http://acme.meridian.test/forms?folder={$this->clinics->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Index', false)
            ->has('forms', 3)
            ->where('filters.applied.folder', $this->clinics->id)
            ->where('empty_reason', null));

    $unfiled = ($this->props)($this->owner, '?folder=none');
    expect(collect($unfiled['forms'])->pluck('title')->all())->toBe(['Owner loose'])
        ->and($unfiled['filters']['applied']['folder'])->toBe('none');
});

it('never counts or lists a form the viewer cannot already see', function (): void {
    $props = ($this->props)($this->editor);

    // The Editor holds a grant on one form only. The Owner's two clinic forms and loose form are invisible
    // to them on this page, so neither a folder count nor Unfiled may admit they exist.
    expect(folderCounts($props))->toBe(['Archive' => 0, 'clinics' => 1, 'Empty' => 0])
        ->and($props['folders']['unfiled_count'])->toBe(0);

    $filtered = ($this->props)($this->editor, "?folder={$this->clinics->id}");
    expect(collect($filtered['forms'])->pluck('title')->all())->toBe(['Editor clinic']);
});

it('says "no matches" when a folder filters to zero', function (): void {
    $this->actingAs($this->owner)->withoutVite()
        ->get("http://acme.meridian.test/forms?folder={$this->archive->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Index', false)
            ->has('forms', 0)
            ->where('empty_reason', 'no_matches'));
});

it('counts each filter with the other one applied, never its own', function (): void {
    // Folder counts under the Draft chip: one of the Owner's clinic forms is published, so Clinics drops
    // to 2 there while the Draft chip under the Clinics folder reads 2 and All reads 3.
    $draft = ($this->props)($this->owner, '?state='.FormListFacets::DRAFT);
    expect(folderCounts($draft))->toBe(['Archive' => 0, 'clinics' => 2, 'Empty' => 0])
        ->and($draft['folders']['unfiled_count'])->toBe(1);

    $inClinics = ($this->props)($this->owner, "?folder={$this->clinics->id}");
    $facets = collect($inClinics['filters']['facets'])->mapWithKeys(fn (array $f): array => [$f['value'] ?? 'all' => $f['count']])->all();
    expect($facets)->toMatchArray(['all' => 3, 'draft' => 2, 'live' => 1])
        // …and the folder's own count is not narrowed by itself.
        ->and(folderCounts($inClinics)['clinics'])->toBe(3);
});

it('ignores a folder it does not know rather than filtering to zero', function (string $query): void {
    $props = ($this->props)($this->owner, $query);

    expect($props['forms'])->toHaveCount(4)
        ->and($props['filters']['applied']['folder'])->toBeNull()
        ->and($props['empty_reason'])->toBeNull();
})->with(['?folder=00000000-0000-0000-0000-000000000000', '?folder=nonsense', '?folder[]=none']);

it('tells the page who may create and who may manage folders', function (): void {
    expect(($this->props)($this->owner)['folders']['can'])->toBe(['create' => true, 'manage' => true])
        ->and(($this->props)($this->editor)['folders']['can'])->toBe(['create' => true, 'manage' => false]);
});
