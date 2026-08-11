<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SearchEntity;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Search\SearchPresenter;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2d — A SEARCH RESULT MUST OPEN FOR THE READER IT WAS OFFERED TO.
|
| The third application of the `FormTabSetReachabilityTest` idiom, after the tab strip and the breadcrumb
| trail: take the URL the product actually emits and NAVIGATE it, as the narrowest role that receives it.
|
| ⚠️ THIS IS THE CASE THAT MAKES J2d's URL CHANGE PROVABLE, AND IT IS SHARP ONLY BECAUSE OF THE WIDENING.
| `FormSearchArm` pointed every result at `/forms/{id}/builder`, which is `can:update,form` → `canEdit()`.
| While the arm was gated on `viewAny,Form`, every reader it served could open the builder too — so a real
| GET could not have distinguished the right URL from the wrong one, and only a string assertion would have.
| Now that a Reviewer and a Viewer receive form results, they are readers who can open the HUB and not the
| builder: navigating what they were handed catches the old URL outright.
|
| ⚠️ ONE HTTP REQUEST PER CASE (`FormHubGateTest` — the tenant GUC is torn down on the way out), so every
| fixture write completes before the single `get()`.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    // Committed — the member case below renders `/members`. See `committedTenantIdentity()` in tests/Pest.php.
    $this->owner = committedTenantIdentity('Ada Lovelace');
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->form = app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The url the forms arm emits for `$user`, read off the real presenter. */
function firstFormResultUrl(User $user): ?string
{
    $props = app(SearchPresenter::class)->index($user, SearchTerms::parse('clinic'), null);

    foreach ($props['data'] as $group) {
        if ($group['entity'] === SearchEntity::Form->value && $group['items'] !== []) {
            return (string) $group['items'][0]['url'];
        }
    }

    return null;
}

it('hands a Viewer a form result they can actually open', function (): void {
    /*
     * ⚠️ THE VIEWER IS THE POINT. Before J2d this role received no form results at all, and the URL the arm
     * emitted — the builder — is one they are refused. Both halves are asserted by one navigation: if the
     * arm went back to `/forms/{id}/builder`, this 403s; if the widening were reverted, `expect(...)
     * ->not->toBeNull()` fails first and says which.
     */
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $url = firstFormResultUrl($viewer);

    expect($url)->not->toBeNull();

    $this->withoutVite()->actingAs($viewer)
        ->get('http://acme.meridian.test'.$url)
        ->assertSuccessful();
});

it('hands a Reviewer with a grant a form result they can actually open', function (): void {
    // The scoped arm of the same widening. A reviewer-capacity grant is what `scopeReadableBy()` accepts and
    // `scopeVisibleTo()` would refuse, so this also pins that the arm reads the right scope.
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($this->form, $reviewer, ResourceCapacity::Reviewer);

    $url = firstFormResultUrl($reviewer);

    expect($url)->not->toBeNull();

    $this->withoutVite()->actingAs($reviewer)
        ->get('http://acme.meridian.test'.$url)
        ->assertSuccessful();
});

it('points a form result at the hub rather than the builder', function (): void {
    /*
     * The string assertion, shipped ALONGSIDE the navigations above rather than instead of them, because
     * each buys something the other cannot.
     *
     * The navigations prove REACHABILITY — that whatever URL is emitted resolves for the reader it was
     * handed to. This proves IDENTITY: an Owner can open both the hub and the builder, so for that role a
     * revert to `/builder` would navigate perfectly well and prove nothing. Only the exact string catches it
     * on the role that most uses search.
     */
    expect(firstFormResultUrl($this->owner))->toBe('/forms/'.$this->form->id);
});

it('hands a member result the roster FILTERED to that member', function (): void {
    /*
     * `MemberSearchArm` pointed every hit at the bare `/members` — choosing "Ada Lovelace" and landing on an
     * unfiltered list of two hundred people answers a different question from the one asked.
     *
     * The navigation is what proves the parameter is REAL: `?q=` is read by `ReadsKeywordFilter` and matched
     * in PHP over the already-bounded roster, so a wrong param name would render the full list with a 200
     * and no test spelling the URL would notice.
     */
    // A second member, so "one row came back" is a measurement rather than a tautology.
    $stranger = committedTenantIdentity('Grace Hopper');
    makeActiveMember($stranger, 'viewer');

    $props = app(SearchPresenter::class)->index($this->owner, SearchTerms::parse($this->owner->name), null);

    $url = null;
    foreach ($props['data'] as $group) {
        if ($group['entity'] === SearchEntity::Member->value && $group['items'] !== []) {
            $url = (string) $group['items'][0]['url'];
        }
    }

    expect($url)->toBe('/members?q='.rawurlencode((string) $this->owner->email));

    $this->withoutVite()->actingAs($this->owner)
        ->get('http://acme.meridian.test'.$url)
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('members/Index', false)
            ->where('filters.applied.q', $this->owner->email)
            // ⚠️ THE COUNT ONLY MEANS SOMETHING BECAUSE A SECOND MEMBER EXISTS, and an earlier version of
            // this case asserted `has('members', 1)` against a roster that held exactly ONE — true by
            // construction, and green against a completely unfiltered list. The stranger seeded above is
            // what makes the narrowing observable; without them the only real assertions here are the URL
            // string and the echoed `q`.
            ->has('members', 1)
            ->where('members.0.email', $this->owner->email)
            ->etc());
});
