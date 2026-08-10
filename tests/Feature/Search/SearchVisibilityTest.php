<?php

declare(strict_types=1);

use App\Enums\FormStatus;
use App\Enums\ResourceCapacity;
use App\Enums\SearchEntity;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Search\SearchPresenter;
use App\Services\Search\SearchService;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Global search's per-entity visibility (Increment J1b, PRD §3.7).
|
| ⚠️ THE FIXTURE IS THE TEST. Almost every mutation these cases claim to catch survives against a naive
| fixture, so two rows below are load-bearing and must not be "tidied away":
|
|   F_hidden  — a form in the SAME tenant that the editor holds no grant on. Without a same-tenant
|               invisible row, deleting the visibility predicate leaves everything green, because RLS
|               catches the cross-tenant case and "the policy worked" is then indistinguishable from
|               "transaction isolation worked". That confusion is a recorded lesson in this repo.
|   F_nomatch — a form the user CAN see that does NOT match the keyword. Without it, deleting the keyword
|               predicate entirely leaves every assertion passing.
|
| The keyword is "clinic", chosen so it matches three fixture forms and misses one.
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

    $this->reviewer = User::factory()->create();
    makeActiveMember($this->reviewer, 'reviewer');

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');

    $forms = app(FormService::class);

    // Created by the EDITOR, so FormService grants them Editor capacity on it.
    $this->visible = $forms->create($this->tenant, $this->editor, 'Clinic Intake');
    // Same tenant, no grant to the editor. The row that makes the visibility mutation fail.
    $this->hidden = $forms->create($this->tenant, $this->owner, 'Clinic Referral');
    // Visible to the owner and matching nothing. The row that makes the keyword mutation fail.
    $this->nomatch = $forms->create($this->tenant, $this->owner, 'Payroll Register');

    $this->archived = $forms->create($this->tenant, $this->owner, 'Clinic Archive');
    $this->archived->forceFill(['status' => FormStatus::Archived->value])->save();
});

/** @return list<string> the form titles global search shows $user for $keyword */
function searchedFormTitles(User $user, string $keyword = 'clinic'): array
{
    $props = app(SearchPresenter::class)->index($user, SearchTerms::parse($keyword), null);

    foreach ($props['data'] as $group) {
        if ($group['entity'] === SearchEntity::Form->value) {
            return array_map(static fn (array $row): string => (string) $row['title'], $group['items']);
        }
    }

    return [];
}

it('shows an owner every non-archived matching form', function (): void {
    expect(searchedFormTitles($this->owner))
        ->toContain('Clinic Intake')
        ->toContain('Clinic Referral');
});

it('does not return a visible form that does not match the keyword', function (): void {
    // Mutation: delete the KeywordFilter::apply() call in FormSearchArm::builder() and this reddens.
    // Without the `Payroll Register` fixture row there would be nothing for it to catch.
    expect(searchedFormTitles($this->owner))->not->toContain('Payroll Register');
});

it('does not let an editor find a SAME-TENANT form they hold no grant on', function (): void {
    // Mutation: replace `->visibleTo($user)` with a bare `Form::query()` and this reddens.
    // This is the case that proves the ORM predicate, not merely RLS — both forms are in one tenant.
    expect(searchedFormTitles($this->editor))
        ->toContain('Clinic Intake')
        ->not->toContain('Clinic Referral');
});

it('refuses the forms arm entirely to a reviewer and a viewer', function (): void {
    // Neither role holds ANY `forms.*` key, so `FormPolicy::viewAny` is false and the arm never runs.
    //
    // ⚠️ THE COUNT KEY MUST BE ABSENT, NOT ZERO. A `0` is a claim ("there are no forms"); an absent key is
    // the truth ("you cannot ask"). Mutation: return 0 for a refused arm instead of omitting it, and this
    // reddens — which is the count-leak guard.
    foreach ([$this->reviewer, $this->viewer] as $user) {
        $props = app(SearchPresenter::class)->index($user, SearchTerms::parse('clinic'), null);

        expect(array_column($props['data'], 'entity'))->not->toContain(SearchEntity::Form->value)
            ->and($props['counts'])->not->toHaveKey(SearchEntity::Form->value)
            ->and(array_column($props['filters']['entities'], 'value'))
            ->not->toContain(SearchEntity::Form->value);
    }
});

it('refuses a viewer at the ARM GATE, before the scope is ever consulted', function (): void {
    // Written this way after a mutation refused to redden and exposed the first draft as vacuous.
    //
    // The first version of this case claimed to prove that borrowing `AnalyticsFormSet`'s rule would show a
    // Viewer every form. It cannot: `FormPolicy::viewAny` is `forms.create || forms.edit.any ||
    // forms.edit.own`, a Viewer holds NONE of the three, so `allowed()` refuses the arm and the scope never
    // runs. Swapping the scope's predicate left all ten cases green.
    //
    // That is defence in depth working, and it is worth pinning as what it actually is — but it means this
    // case proves the GATE, not the scope. The two cases below prove the scope, on the roles that can
    // actually observe it.
    expect($this->viewer->can('dashboard.org.view'))->toBeTrue()
        ->and($this->viewer->can('forms.edit.any'))->toBeFalse()
        ->and($this->viewer->can('forms.edit.own'))->toBeFalse()
        ->and($this->viewer->can('forms.create'))->toBeFalse()
        ->and(searchedFormTitles($this->viewer))->toBe([]);
});

it('requires EDITOR capacity, so a bare reviewer grant surfaces nothing', function (): void {
    // ⚠️ THIS IS THE CASE THAT DISTINGUISHES THE POLICY RULE FROM THE ANALYTICS RULE, and the reason it has
    // to exist: `form_editor` holds no `dashboard.org.view`, so under `AnalyticsFormSet::visible()` it would
    // fall through to `grantedFormIdsQuery($user)` with a NULL capacity — i.e. ANY grant, including a bare
    // Reviewer one. Mutation: change `ResourceCapacity::Editor` to `null` in `Form::scopeVisibleTo()`, or
    // swap the whole predicate for the analytics rule, and this reddens. Nothing else does.
    makeCollaborator($this->hidden, $this->editor, ResourceCapacity::Reviewer);

    expect(searchedFormTitles($this->editor))->not->toContain('Clinic Referral');
});

it('excludes archived forms, matching what /forms shows', function (): void {
    expect(searchedFormTitles($this->owner))->not->toContain('Clinic Archive');
});

it('excludes soft-deleted forms for an ORG-WIDE reader too', function (): void {
    // The other half of the analytics rule: its org branch returns `Form::withTrashed()`. An owner takes
    // that branch under either rule, so this is where borrowing it would surface deleted rows. Asserted for
    // the owner as well as the editor precisely because the editor's path never reaches `withTrashed()`.
    $this->visible->delete();

    expect(searchedFormTitles($this->editor))->not->toContain('Clinic Intake')
        ->and(searchedFormTitles($this->owner))->not->toContain('Clinic Intake');
});

it('never returns another tenant’s form, in either direction', function (): void {
    // Both directions, because a one-way assertion cannot distinguish "the predicate works" from "the
    // fixture happened to be empty" — the lesson I11a recorded.
    $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'beta']);

    $betaOwner = User::factory()->create();
    enterTenant($other->id, $betaOwner->id);
    makeActiveMember($betaOwner, 'owner');
    app(FormService::class)->create($other, $betaOwner, 'Clinic Beta');

    expect(searchedFormTitles($betaOwner))->toBe(['Clinic Beta']);

    enterTenant($this->tenant->id, $this->owner->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(searchedFormTitles($this->owner))->not->toContain('Clinic Beta');
});

it('derives the count from the same builder as the rows', function (): void {
    // The count-leak guard. An editor sees one of the two matching forms, so a count taken from a
    // keyword-only query would read 2 while the rows read 1.
    $props = app(SearchPresenter::class)->index($this->editor, SearchTerms::parse('clinic'), null);

    expect($props['counts'][SearchEntity::Form->value])->toBe(1)
        ->and($props['data'][0]['items'])->toHaveCount(1);
});

it('caps a grouped arm and says so rather than truncating silently', function (): void {
    for ($i = 0; $i < SearchService::PER_ENTITY_PREVIEW + 2; $i++) {
        app(FormService::class)->create($this->tenant, $this->owner, "Clinic Extra {$i}");
    }

    $props = app(SearchPresenter::class)->index($this->owner, SearchTerms::parse('clinic'), null);
    $group = $props['data'][0];

    expect($group['items'])->toHaveCount(SearchService::PER_ENTITY_PREVIEW)
        ->and($group['has_more'])->toBeTrue();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});
