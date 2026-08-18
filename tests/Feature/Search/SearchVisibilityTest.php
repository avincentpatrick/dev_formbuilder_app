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

it('offers a viewer the forms arm, scoped, rather than refusing it', function (): void {
    /*
     * ⚠️ THIS CASE WAS INVERTED IN J2d, AND THE INVERSION IS THE POINT (user decision, 2026-08-11).
     *
     * It used to assert that a Reviewer and a Viewer got NO forms arm at all, because the arm gated on
     * `viewAny,Form` = `forms.create | forms.edit.any | forms.edit.own` and neither role holds any of the
     * three. That was correct for J1b and became a dead end once J2b opened the form HUB to all five roles:
     * a Viewer could open a form's overview from the inbox and could not find that form by name.
     *
     * The arm now gates on `dashboard.form.view` and scopes on `Form::scopeReadableBy()`. A Viewer holds
     * `dashboard.org.view`, so their set is every non-archived form — exactly the set `/forms/{form}` would
     * let them open. It discloses nothing new: the inbox has rendered `form_title` to both roles since F7.
     */
    expect(searchedFormTitles($this->viewer))
        ->toContain('Clinic Intake')
        ->toContain('Clinic Referral')
        ->not->toContain('Clinic Archive')
        ->not->toContain('Payroll Register');
});

it('scopes a reviewer to granted forms rather than handing them the tenant', function (): void {
    /*
     * The other half of the widening, and the half that would be a disclosure bug if it were wrong. A
     * Reviewer holds `dashboard.form.view` but NOT `dashboard.org.view`, so `scopeReadableBy()` falls to the
     * granted-forms subquery. Both directions in one case: the granted form appears, the same-tenant
     * ungranted one does not — a one-way assertion cannot tell "the predicate works" from "the fixture was
     * empty", the lesson this file already records.
     *
     * ⚠️ THE GRANT IS REVIEWER CAPACITY, which is the whole reason this is `readableBy` and not
     * `visibleTo`: the authoring scope demands EDITOR capacity, so it would return the empty set for the one
     * role the widening was for, while every owner-fixtured case stayed green.
     */
    makeCollaborator($this->visible, $this->reviewer, ResourceCapacity::Reviewer);

    expect(searchedFormTitles($this->reviewer))
        ->toContain('Clinic Intake')
        ->not->toContain('Clinic Referral');
});

it('omits the forms arm for a member without dashboard.form.view', function (): void {
    /*
     * ⚠️ THE COUNT KEY MUST BE ABSENT, NOT ZERO. A `0` is a claim ("there are no forms"); an absent key is
     * the truth ("you cannot ask"). Mutation: return 0 for a refused arm instead of omitting it and this
     * reddens — the count-leak guard, preserved verbatim from the case this replaced.
     *
     * ⚠️ THE ACTOR IS SYNTHETIC BECAUSE NO SHIPPED ROLE CAN REACH THIS STATE — all five hold
     * `dashboard.form.view`. `FormSearchArm::allowed()` is a fail-closed guard now rather than a live
     * refusal, and is labelled as one there; this case is what keeps it from being dead code.
     */
    $stranger = User::factory()->create();
    makeActiveMember($stranger, 'viewer');
    $stranger->syncRoles([]);
    $stranger->syncPermissions(['submissions.view', 'dashboard.org.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $props = app(SearchPresenter::class)->index($stranger, SearchTerms::parse('clinic'), null);

    expect(array_column($props['data'], 'entity'))->not->toContain(SearchEntity::Form->value)
        ->and($props['counts'])->not->toHaveKey(SearchEntity::Form->value)
        ->and(array_column($props['filters']['entities'], 'value'))
        ->not->toContain(SearchEntity::Form->value);
});

it('reaches a viewer through the SCOPE rather than through any forms.* key', function (): void {
    /*
     * The permission shape behind the case above, pinned separately so the reason a Viewer now sees forms is
     * unambiguous: it is `dashboard.org.view` inside `scopeReadableBy()`, NOT any `forms.*` key. If someone
     * later "fixes" the widening by granting Viewers `forms.edit.own`, this reddens and says so — which
     * matters, because that change would also hand them the builder.
     *
     * ⚠️ WHAT THIS CASE USED TO SAY WAS ITSELF A CORRECTION, AND THE HISTORY IS WORTH KEEPING. Its first
     * draft claimed to prove that borrowing `AnalyticsFormSet`'s rule would show a Viewer every form; it
     * could not, because the arm gate refused the Viewer before the scope ever ran, and swapping the scope's
     * predicate left all ten cases green. The gate no longer refuses them, so the scope is now genuinely
     * load-bearing for this role — which is what the two cases above exercise.
     */
    expect($this->viewer->can('dashboard.form.view'))->toBeTrue()
        ->and($this->viewer->can('dashboard.org.view'))->toBeTrue()
        ->and($this->viewer->can('forms.edit.any'))->toBeFalse()
        ->and($this->viewer->can('forms.edit.own'))->toBeFalse()
        ->and($this->viewer->can('forms.create'))->toBeFalse();
});

it('accepts ANY capacity, so a bare reviewer grant does surface its form', function (): void {
    /*
     * ⚠️ ALSO INVERTED IN J2d, AND FOR THE SAME REASON THE ARM GATE WAS. This case used to assert that a
     * reviewer-capacity grant surfaced nothing, because `Form::scopeVisibleTo()` demands EDITOR capacity —
     * correct for an AUTHORING scope and wrong for "which forms may I open". `scopeReadableBy()` passes no
     * capacity at all, deliberately, and its own docblock records why: an editor-capacity check refuses the
     * single role the widening exists for.
     *
     * ⚠️ THE ANALYTICS RULE IS STILL NOT THIS RULE, and the distinction this case used to carry has been
     * moved rather than dropped. `AnalyticsFormSet::visible()` keys on `dashboard.org.view` ALONE (no
     * `dashboard.form.view` conjunct) and returns `Form::withTrashed()`. The conjunct is pinned by "omits the
     * forms arm for a member without dashboard.form.view" above; the trashed half by "excludes soft-deleted
     * forms for an ORG-WIDE reader too" below. Swapping in the analytics predicate reddens both.
     */
    makeCollaborator($this->hidden, $this->editor, ResourceCapacity::Reviewer);

    expect(searchedFormTitles($this->editor))->toContain('Clinic Referral');
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
