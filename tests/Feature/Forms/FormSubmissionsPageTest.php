<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SubmissionStatus;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2c — the payload of GET /forms/{form}/submissions.
|
| The page is `submissions/Inbox` in both modes, so what this file pins is the DIFFERENCE the bound form
| makes, and each of the three differences is a defect the obvious implementation would have shipped:
|
|   · the route's form WINS over `?form_id=`, so the URL cannot narrow the list to a different form than
|     the one whose name is in the heading;
|   · `filters.forms` is ABSENT, not an empty array — a dropdown on a page that is already one form is
|     meaningless, and an empty array reads to the client as "you may select nothing";
|   · `empty_reason` is `no_rows`, NOT `no_matches` — the bound form is not a filter the reader chose, so
|     counting it would greet every brand-new form with "clear the filters" over a list with no filters.
|
| The third is the one no fixture would have caught: every test that seeds a submission passes either way.
|--------------------------------------------------------------------------
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

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Payload Responses');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function formSubmissionsPageUrl(string $formId): string
{
    return 'http://acme.meridian.test/forms/'.$formId.'/submissions';
}

it('renders the shared inbox component with the form and its tab strip', function (): void {
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsPageUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The SAME component as `/submissions`. A second page would be a second copy of the filter bar,
            // the URL builder and the empty-state branching — J1e's audit-export defect one surface over.
            ->component('submissions/Inbox', false)
            ->where('form.id', (string) $this->form->id)
            ->where('form.title', 'Payload Responses')
            ->where('tabs.0.key', 'overview')
            ->where('tabs.1.key', 'submissions')
            ->where('tabs.1.href', '/forms/'.$this->form->id.'/submissions')
            ->where('meta.total', 1)
            ->etc());
});

it('lists only this form’s responses, and carries form_id on every row', function (): void {
    // `form_id` on the ROW is what lets the GLOBAL inbox link a submission to its form. `detail()` has
    // carried it since F7; the list row did not, so the inbox printed a form's name on every row and linked
    // none of them.
    $other = publishedInboxForm($this->tenant, $this->owner, 'Some Other Form');
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());
    seedCountableAt($this->form, CarbonImmutable::now()->subDays(2));
    seedCountableAt($other, CarbonImmutable::now()->subDay());

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsPageUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 2)
            ->where('data.0.form_id', (string) $this->form->id)
            ->where('data.1.form_id', (string) $this->form->id)
            ->etc());
});

it('ignores a ?form_id that disagrees with the route', function (): void {
    // ⚠️ THE HEADING MUST NOT BE ABLE TO LIE. The h1, the breadcrumb and the tab strip all name the ROUTE's
    // form; if a query string could narrow the rows to a different one, the page would describe itself
    // incorrectly with no way for the reader to tell. The presenter takes the bound form unconditionally.
    $other = publishedInboxForm($this->tenant, $this->owner, 'Some Other Form');
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());
    seedCountableAt($other, CarbonImmutable::now()->subDay());

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsPageUrl((string) $this->form->id).'?form_id='.$other->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 1)
            ->where('data.0.form_id', (string) $this->form->id)
            ->where('filters.applied.form_id', (string) $this->form->id)
            ->etc());
});

it('omits the form dropdown entirely rather than sending an empty one', function (): void {
    // Absent, never empty — ADR-0011 §D9. `Inbox.vue` keys per-form mode off the PRESENCE of `form`, and an
    // empty `filters.forms` would still render a select with only "All forms" in it.
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsPageUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('filters.forms')
            ->has('filters.statuses')
            ->has('filters.sources')
            ->etc());
});

it('says NO RESPONSES YET on a form with none, not "no matching submissions"', function (): void {
    // ⚠️ THE MUTATION TARGET. Delete the `form_id` skip in `SubmissionInboxPresenter::hasAnyFilter()` and
    // this is the ONLY case in the suite that reddens — every other fixture seeds a submission, so
    // `empty_reason` is null and the two implementations are indistinguishable.
    //
    // The lie it prevents: a brand-new form is the single most likely form to have no responses, and its
    // author would have been told to "try a different keyword, or clear the filters to see everything" over
    // a list with no filters applied and no way to clear them.
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsPageUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 0)
            ->where('empty_reason', 'no_rows')
            ->etc());
});

it('says NO MATCHES when the reader actually narrowed something', function (): void {
    // The other half, so the case above cannot decay into "this page always reports no_rows". A keyword the
    // reader typed IS a filter, on the per-form page exactly as on the global one.
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsPageUrl((string) $this->form->id).'?q=zzzznotathing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 0)
            ->where('empty_reason', 'no_matches')
            ->etc());
});

it('keeps the inbox display default: drafts are hidden until asked for', function (): void {
    // The per-form page inherits `countable()` because it is the same presenter method. Worth pinning here
    // because "this form has 1 response" on the hub and a list showing 2 rows would be the ADR-0011 §D2
    // disagreement the shared predicate exists to prevent.
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());
    seedCountableAt($this->form, CarbonImmutable::now()->subDay(), SubmissionStatus::Draft);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsPageUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('meta.total', 1)->etc());

});

it('offers export against the bound form, with no form_id echoed as a chosen filter', function (): void {
    // ⚠️ AN EARLIER VERSION OF THIS CASE ASSERTED ONLY `can.export === true`, which is
    // `$user->can('submissions.export')` for an Owner and is byte-identical on `/submissions`. It named a
    // per-form behaviour and tested a permission — it would have passed against any implementation,
    // including one that never bound the form at all.
    //
    // What actually makes Export unconditional here is that the PAGE supplies the form: `filters.applied
    // .form_id` is the route's form, which is what the client's export href reads. Assert that pairing.
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formSubmissionsPageUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.export', true)
            ->where('filters.applied.form_id', (string) $this->form->id)
            ->where('form.id', (string) $this->form->id)
            ->etc());
});

it('marks a row openable only when the reader may open its form', function (): void {
    // ⚠️ THE ROW SET IS WIDER THAN FORM READABILITY, so "listed" does not imply "linkable" — found by the
    // J2c adversarial review, not by a gate. `Submission::scopeVisibleTo()` has a RESPONDENT arm
    // (`respondent_user_id = me`) that `FormPolicy::viewOverview()` has no counterpart for, so a keyer whose
    // grant is revoked keeps seeing rows they encoded while the form behind them 403s. Without
    // `can.open_form` the inbox rendered that as a live link.
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($this->form, $reviewer, ResourceCapacity::Reviewer);
    app(ResourceGrantResolver::class)->forget();
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());

    $this->withoutVite()
        ->actingAs($reviewer)
        ->get(formSubmissionsPageUrl((string) $this->form->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('data.0.can.open_form', true)->etc());
});
