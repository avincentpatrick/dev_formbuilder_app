<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PsgcFixture;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M186 (`R-7a2f4a13`) — manual entry fetches a list-backed cascade's choices by version, never in its props
|--------------------------------------------------------------------------
| A barangay list is 42,000 rows. The encode page's `blocks` now carry a list-backed cascade's levels and no choices;
| the page fetches them once from `forms/{form}/versions/{version}/choice-lists` — the guest endpoint's shape, whole,
| cacheable for a day — by whoever can open the page through ANY of its doors (`SubmissionPolicy::readEncodeLists`),
| which `can:view,form` is not: a Reviewer holding an editor grant can encode and could not view the form.
|
| ⚠️ Helpers are prefixed `encodeLists*`: Pest loads every test file into one process.
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
    $this->form = encodeListsForm($this->tenant, $this->owner);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A published form with a cascade on the Philippine places and a typed one beside it. */
function encodeListsForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Barangay Visit');
    addFormField($form->refresh()->draftVersion, $owner, 'place', FieldType::CascadingSelect, 1, ['config' => [
        'levels' => [
            ['key' => 'region', 'label' => 'Region', 'list' => 'psgc:region'],
            ['key' => 'province', 'label' => 'Province or district', 'list' => 'psgc:province'],
        ],
        'options' => [],
    ]]);
    addFormField($form->refresh()->draftVersion, $owner, 'zone', FieldType::CascadingSelect, 2, ['config' => [
        'levels' => [['key' => 'zone', 'label' => 'Zone']],
        'options' => [['value' => 'north', 'label' => 'North', 'level' => 'zone', 'parent' => null]],
    ]]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

function encodeListsUrl(Form $form, ?string $version = null): string
{
    return 'http://acme.meridian.test/forms/'.$form->id.'/versions/'.($version ?? $form->current_published_version_id).'/choice-lists';
}

/** A member of this workspace with one role, and optionally an editor grant on the form. */
function encodeListsMember(string $role, ?Form $editorOf = null): User
{
    $user = User::factory()->create();
    makeActiveMember($user, $role);
    if ($editorOf !== null) {
        makeCollaborator($editorOf, $user, ResourceCapacity::Editor);
    }

    return $user;
}

it('sends the page a list-backed cascade’s levels and no choices, and where to fetch them', function (): void {
    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/forms/'.$this->form->id.'/submissions/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('choice_lists_url', encodeListsUrl($this->form))
            ->where('blocks.0.fields.0.key', 'place')
            ->where('blocks.0.fields.0.cascade.options', [])
            ->where('blocks.0.fields.0.cascade.levels.1.key', 'province')
            // A typed cascade keeps its choices in the page: only a list is fetched.
            ->where('blocks.0.fields.1.cascade.options.0.value', 'north'));
});

it('serves the version’s lists whole, in the guest endpoint’s shape, cacheable for a day', function (): void {
    $response = $this->actingAs($this->owner)->getJson(encodeListsUrl($this->form))->assertOk();

    expect($response->json('data.version_id'))->toBe($this->form->current_published_version_id)
        ->and($response->json('data.lists.place.levels'))->toBe(['region', 'province'])
        ->and($response->json('data.lists.place.options'))->toHaveCount(7)
        ->and($response->json('data.lists.place.options'))->toContain([1, '1380600000', 'City of Manila', '1300000000'])
        ->and($response->json('data.lists.zone'))->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400');
});

it('sends no fetch address for a version with no list-backed cascade', function (): void {
    $plain = app(FormService::class)->create($this->tenant, $this->owner, 'Plain');
    addFormField($plain->refresh()->draftVersion, $this->owner, 'name', FieldType::ShortText, 1);
    app(PublishService::class)->publish($plain->refresh(), $this->owner);

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/forms/'.$plain->id.'/submissions/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('choice_lists_url', null));
});

it('serves whoever can open manual entry on the form — a Reviewer with an editor grant too — and no one else', function (): void {
    $reviewerEditor = encodeListsMember('reviewer', $this->form);
    $reviewer = encodeListsMember('reviewer');
    $viewer = encodeListsMember('viewer');

    // The door this gate must match: the Reviewer with an editor grant can open the encode page…
    $this->actingAs($reviewerEditor)->get('http://acme.meridian.test/forms/'.$this->form->id.'/submissions/create')->assertOk();
    // …and is refused the form itself, which is why `can:view,form` would have been the wrong gate.
    $this->actingAs($reviewerEditor)->get('http://acme.meridian.test/forms/'.$this->form->id.'/versions/'.$this->form->current_published_version_id.'/choices')->assertForbidden();

    $this->actingAs($reviewerEditor)->getJson(encodeListsUrl($this->form))->assertOk();
    $this->actingAs($reviewer)->getJson(encodeListsUrl($this->form))->assertForbidden();
    $this->actingAs($viewer)->getJson(encodeListsUrl($this->form))->assertForbidden();
});

it('serves a superseded version — resume and edit render the one the response was given on — and never a draft or another form’s', function (): void {
    $first = (string) $this->form->current_published_version_id;
    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    $draft = (string) $this->form->refresh()->draft_version_id;

    $other = app(FormService::class)->create($this->tenant, $this->owner, 'Other');
    addFormField($other->refresh()->draftVersion, $this->owner, 'name', FieldType::ShortText, 1);
    app(PublishService::class)->publish($other->refresh(), $this->owner);
    $othersVersion = (string) $other->refresh()->current_published_version_id; // read now: a request leaves the tenant context

    expect(FormVersion::query()->findOrFail($first)->status->value)->toBe('superseded');

    $this->actingAs($this->owner)->getJson(encodeListsUrl($this->form, $first))->assertOk()->assertJsonPath('data.version_id', $first);
    $this->actingAs($this->owner)->getJson(encodeListsUrl($this->form, $draft))->assertNotFound();
    $this->actingAs($this->owner)->getJson(encodeListsUrl($this->form, $othersVersion))->assertNotFound();
});
