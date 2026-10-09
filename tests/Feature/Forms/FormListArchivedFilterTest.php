<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormFolder;
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
| The forms list's Archived filter (M154, `R-44b17445`, `D103` A).
|--------------------------------------------------------------------------
| The user archived a form on staging and asked "how can i retrieve the responses from that archived form?". The
| list hid every archived form and offered no way to see one. `D103` A: a read-only Archived chip; still no
| un-archive (`D101`).
|
| ⛔ THE CASE THAT MATTERS MOST IS THE FIRST. The chips and folders are counted in PHP over the presenter's rows,
| and "All" used to be `count($rows)` with `apply(null)` returning every row — so letting archived rows into the
| presenter without changing the default arm would have put them back into All, its count and every folder.
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

    $forms = app(FormService::class);
    $clinics = new FormFolder;
    $clinics->name = 'Clinics';
    $clinics->save();
    $this->clinics = $clinics;

    // Two active forms and two archived ones: one archived after publishing (its responses are what the user
    // wants back), one archived as a draft, which has no version left at all once archiving drops its draft.
    $this->live = $forms->assignFolder(publishedInboxForm($this->tenant, $this->owner, 'Clinic Live'), $clinics->id, $this->owner);
    $this->draft = $forms->create($this->tenant, $this->owner, 'Clinic Draft');
    $this->retired = $forms->archive(
        $forms->assignFolder(publishedInboxForm($this->tenant, $this->owner, 'Clinic Retired'), $clinics->id, $this->owner),
        $this->owner,
    );
    $this->abandoned = $forms->archive($forms->create($this->tenant, $this->owner, 'Old Draft'), $this->owner);

    $this->props = fn (string $query = ''): array => $this->actingAs($this->owner)->withoutVite()
        ->get("http://acme.meridian.test/forms{$query}")
        ->assertOk()
        ->viewData('page')['props'];
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return list<string> the listed titles, sorted — the order is recency's, which these cases do not test */
function archivedListTitles(array $props): array
{
    $titles = array_map(static fn (array $row): string => (string) $row['title'], $props['forms']);
    sort($titles);

    return $titles;
}

/** @return array<string, int> chip label => count */
function archivedListChips(array $props): array
{
    return collect($props['filters']['facets'])->mapWithKeys(fn (array $f): array => [$f['label'] => $f['count']])->all();
}

/** @return array<string, mixed> one listed row's `can` map */
function archivedListCan(array $props, Form $form): array
{
    return collect($props['forms'])->firstWhere('id', $form->id)['can'];
}

it('keeps archived forms out of All, its count and every folder count', function (): void {
    $props = ($this->props)();

    expect(archivedListTitles($props))->toBe(['Clinic Draft', 'Clinic Live'])
        ->and(archivedListChips($props))->toMatchArray(['All' => 2, 'Archived' => 2])
        ->and(collect($props['folders']['options'])->firstWhere('name', 'Clinics')['count'])->toBe(1)
        ->and($props['folders']['unfiled_count'])->toBe(1);

    // The keyword still ANDs with the default arm: the archived "Clinic Retired" matches and stays hidden,
    // while the Archived chip counts it among the forms the search found.
    $searched = ($this->props)('?q=clinic');
    expect(archivedListTitles($searched))->toBe(['Clinic Draft', 'Clinic Live'])
        ->and(archivedListChips($searched))->toMatchArray(['All' => 2, 'Archived' => 1]);
});

it('lists only archived forms under the Archived chip, with the search and the folder still applying', function (): void {
    $props = ($this->props)('?state=archived');

    expect(archivedListTitles($props))->toBe(['Clinic Retired', 'Old Draft'])
        ->and($props['filters']['applied']['state'])->toBe('archived')
        ->and($props['empty_reason'])->toBeNull()
        // Folder counts follow the chip: under Archived they count archived forms.
        ->and(collect($props['folders']['options'])->firstWhere('name', 'Clinics')['count'])->toBe(1)
        ->and($props['folders']['unfiled_count'])->toBe(1);

    expect(archivedListTitles(($this->props)("?state=archived&folder={$this->clinics->id}")))->toBe(['Clinic Retired'])
        ->and(archivedListTitles(($this->props)('?state=archived&q=old')))->toBe(['Old Draft']);
});

it('offers an archived form read-only: its responses and history, nothing that changes it', function (): void {
    $props = ($this->props)('?state=archived');

    expect(archivedListCan($props, $this->retired))->toBe([
        'edit' => false,
        'publish' => false,
        'delete' => false,
        'encode' => false,
        'template' => true,
        'analytics' => true,
    ])
        // Archived as a draft, it has no version left to save as a template — the route would 404.
        ->and(archivedListCan($props, $this->abandoned)['template'])->toBeFalse();

    // The non-vacuity partner: the same viewer may do all of it to a live form.
    expect(archivedListCan(($this->props)(), $this->live))->toMatchArray([
        'edit' => true,
        'publish' => true,
        'delete' => true,
        'encode' => true,
        'template' => true,
    ]);
});

it('says no forms match, rather than offering a first form, when every form is archived', function (): void {
    $forms = app(FormService::class);
    $forms->archive($this->live, $this->owner);
    $forms->archive($this->draft, $this->owner);

    $props = ($this->props)();

    expect($props['forms'])->toBe([])
        ->and($props['empty_reason'])->toBe('no_matches')
        ->and(archivedListChips($props))->toMatchArray(['All' => 0, 'Archived' => 4]);
});
