<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormPresenter;
use App\Services\Forms\FormService;
use App\Support\Forms\FormListFacets;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The forms list's card data (JR3) — the four ways this row ships broken.
|
|   1. **3N queries.** `FormHubPresenter::stats()` is three queries for ONE form. Reusing it per row on
|      a page with no pagination is the whole defect; the query count is asserted to be CONSTANT as the
|      number of forms grows, which is the only assertion that can tell a grouped query from a per-row
|      one. An absolute number would be a maintenance tax and would pass a 3N implementation on a
|      one-form fixture.
|   2. **The counts silently disagreeing with the hub.** The grouped query cannot call `countable()` —
|      one statement has to produce both a non-draft and a draft aggregate — so it writes the predicate
|      from `SubmissionStatus::Draft` instead. Parity against the hub is what makes that safe: widen
|      `countable()` and the hub moves, the list does not, and this goes red.
|   3. **Capacity picking up the reader's visibility, or `countable()`'s predicate.** Either one is a
|      billing bug. A form that is full is full for everyone (ADR-0011 §D2), and a screened-out
|      respondent must not burn a paid slot (I9a).
|   4. **`empty_reason` not seeing the new filter.** A facet that filters to zero must say "no matches",
|      not "create your first form" — verbatim the defect J1e existed to fix, one filter over.
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
});

/** Count the queries one `FormPresenter::list()` costs, with the grant/permission caches already warm. */
function listQueryCount(User $user): int
{
    // Warm first: the permission registrar and the grant resolver both memoize, and counting a cold run
    // would measure the cache miss rather than the page.
    app(FormPresenter::class)->list($user);

    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    app(FormPresenter::class)->list($user);
    DB::flushQueryLog();

    return $count;
}

it('keeps the query count CONSTANT as forms are added — the 3N guard', function (): void {
    $forms = app(FormService::class);

    $forms->create($this->tenant, $this->owner, 'One');
    $withOne = listQueryCount($this->owner);

    foreach (['Two', 'Three', 'Four', 'Five', 'Six'] as $title) {
        $forms->create($this->tenant, $this->owner, $title);
    }
    $withSix = listQueryCount($this->owner);

    // Six forms rather than two, so a 3N implementation cannot coincidentally match: it would add
    // fifteen queries here and zero is the only number that passes.
    expect($withSix)->toBe($withOne)
        ->and($withOne)->toBeGreaterThan(0); // anti-vacuity: DB::listen actually fired
});

it('reports the same responses/drafts/last_response_at the form hub does', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner, 'Parity');
    $version = $form->currentPublishedVersion;

    Submission::factory()->count(3)->forVersion($version)
        ->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()->subDay()]);
    Submission::factory()->count(2)->forVersion($version)->create(['status' => SubmissionStatus::Draft]);
    Submission::factory()->forVersion($version)
        ->create(['status' => SubmissionStatus::ScreenedOut, 'submitted_at' => now()]);

    $row = collect(app(FormPresenter::class)->list($this->owner))->firstWhere('id', $form->id);

    // The hub's own private method, reached through the page it renders, so this compares the two
    // SHIPPED answers rather than two copies of the same helper.
    $hub = $this->actingAs($this->owner)->withoutVite()
        ->get("http://acme.meridian.test/forms/{$form->id}")
        ->viewData('page')['props']['stats'];

    expect($row['stats'])->toBe($hub)
        // screened_out is countable — it was finalized, it just answered nothing. Pinned explicitly
        // because it is the one status whose membership differs between the two predicates below.
        ->and($row['stats']['responses'])->toBe(4)
        ->and($row['stats']['drafts'])->toBe(2);
});

it('counts capacity WITHOUT the reader visibility scope and WITHOUT screened-out rows', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner, 'Capped');
    $form->forceFill(['max_responses' => 10])->save();
    $version = $form->currentPublishedVersion;

    Submission::factory()->count(3)->forVersion($version)
        ->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);
    Submission::factory()->forVersion($version)->create(['status' => SubmissionStatus::Draft]);
    // Finalized but shown no questions — countable, and deliberately NOT capacity-consuming (I9a).
    Submission::factory()->forVersion($version)
        ->create(['status' => SubmissionStatus::ScreenedOut, 'submitted_at' => now()]);

    $row = collect(app(FormPresenter::class)->list($this->owner))->firstWhere('id', $form->id);

    expect($row['schedule']['max_responses'])->toBe(10)
        // 3 consumed, NOT 4 (screened_out) and NOT 5 (draft): 10 - 3.
        ->and($row['schedule']['remaining'])->toBe(7)
        ->and($row['stats']['responses'])->toBe(4);
});

it('excludes soft-deleted submissions from BOTH counts — the toBase() guard', function (): void {
    // The aggregates are read through `->toBase()`, which skips model hydration. It also LOOKS like it
    // would skip the SoftDeletes global scope, and does not: `Builder::toBase()` calls `applyScopes()`
    // first. That is a framework detail load-bearing enough to drive rather than assert in a comment —
    // if it ever stopped holding, a deleted response would keep counting and keep burning a paid slot,
    // with nothing anywhere to notice.
    $form = publishedInboxForm($this->tenant, $this->owner, 'Reaped');
    $form->forceFill(['max_responses' => 10])->save();
    $version = $form->currentPublishedVersion;

    Submission::factory()->count(2)->forVersion($version)
        ->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);
    $doomed = Submission::factory()->forVersion($version)
        ->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);

    $before = collect(app(FormPresenter::class)->list($this->owner))->firstWhere('id', $form->id);
    expect($before['stats']['responses'])->toBe(3)
        ->and($before['schedule']['remaining'])->toBe(7);

    $doomed->delete();

    $after = collect(app(FormPresenter::class)->list($this->owner))->firstWhere('id', $form->id);
    expect($after['stats']['responses'])->toBe(2)
        ->and($after['schedule']['remaining'])->toBe(8);
});

it('composes the grouped query with the GRANT arm of visibleTo, not just the org-wide short-circuit', function (): void {
    // ⚠️ EVERY OTHER CASE IN THIS FILE RUNS AS AN OWNER, AND AN OWNER NEVER EXERCISES THE INTERESTING
    // HALF. `Submission::scopeVisibleTo()` short-circuits to an unconstrained query for anyone holding
    // `dashboard.org.view`, so the composition this class's docblock spends a paragraph defending —
    // a `whereIn(subquery) orWhere(...)` closure nested inside a `whereIn` + four `selectRaw` bindings —
    // was compiled by no test at all. That is the one arrangement where a binding-order or
    // re-association bug would be silent, so it gets a reader who actually needs the predicate.
    // A form_editor, and the form is created BY them: `FormService::create` grants the creator Editor
    // capacity, which is what puts the form in their list at all. A reviewer cannot serve here — the
    // first draft used one and got a null row, because `Form::scopeVisibleTo()` asks for EDITOR capacity,
    // so a reviewer sees no forms and there is nothing to compute stats for.
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    $form = publishedInboxForm($this->tenant, $editor, 'Granted');
    $version = $form->currentPublishedVersion;

    Submission::factory()->count(2)->forVersion($version)
        ->create(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);
    Submission::factory()->forVersion($version)->create(['status' => SubmissionStatus::Draft]);

    // The submissions carry no `respondent_user_id`, so the scope's `orWhere` arm cannot match them —
    // the `whereIn(form_id, grantedFormIdsQuery)` subquery is the only thing that can, which is exactly
    // the composition being exercised.
    app(ResourceGrantResolver::class)->forget();

    $row = collect(app(FormPresenter::class)->list($editor))->firstWhere('id', $form->id);

    // The editor resolves the same numbers through the grant arm that an owner gets through the
    // short-circuit — which is the property, not merely "it did not crash".
    expect($row)->not->toBeNull()
        ->and($row['stats']['responses'])->toBe(2)
        ->and($row['stats']['drafts'])->toBe(1);

    // Anti-vacuity: the reader must NOT be holding the org-wide permission, or this test is just the
    // short-circuit again under a different role name.
    expect($editor->can('dashboard.org.view'))->toBeFalse();
});

it('leaves an uncapped form with no capacity count at all', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner, 'Uncapped');

    $row = collect(app(FormPresenter::class)->list($this->owner))->firstWhere('id', $form->id);

    expect($row['schedule']['max_responses'])->toBeNull()
        ->and($row['schedule']['remaining'])->toBeNull();
});

it('gives every form a stable identity index inside the six-hue scale', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Stable');

    $first = collect(app(FormPresenter::class)->list($this->owner))->firstWhere('id', $form->id)['identity'];
    $second = collect(app(FormPresenter::class)->list($this->owner))->firstWhere('id', $form->id)['identity'];

    expect($first)->toBe($second)
        ->and($first)->toBeGreaterThanOrEqual(1)
        ->and($first)->toBeLessThanOrEqual(6);
});

it('ships the description and the schedule columns the row never carried before', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Described', 'What it is for');

    $this->actingAs($this->owner)->withoutVite()->get('http://acme.meridian.test/forms')
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Index', false)
            ->where('forms.0.description', 'What it is for')
            ->has('forms.0.schedule.opens_at')
            ->has('forms.0.schedule.closes_at')
            ->has('forms.0.schedule.max_responses')
            ->has('forms.0.schedule.acceptance')
            ->has('forms.0.stats.responses')
            ->has('forms.0.identity'));
});

it('defaults to the card grid and accepts only `table` as the alternative', function (): void {
    app(FormService::class)->create($this->tenant, $this->owner, 'Any');

    $view = fn (string $query): string => $this->actingAs($this->owner)->withoutVite()
        ->get("http://acme.meridian.test/forms{$query}")->viewData('page')['props']['view'];

    expect($view(''))->toBe('grid')
        ->and($view('?view=table'))->toBe('table')
        ->and($view('?view=grid'))->toBe('grid')
        // Anything else falls back rather than rendering a third thing or 500ing. `?view[]=x` is the
        // case that matters: the same array-injection shape `ReadsKeywordFilter` guards `?q` against.
        ->and($view('?view=carousel'))->toBe('grid')
        ->and($view('?view[]=table'))->toBe('grid');
});

it('counts the facets over the search result, not the tenant', function (): void {
    $forms = app(FormService::class);
    publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $forms->create($this->tenant, $this->owner, 'Clinic Draft');
    publishedInboxForm($this->tenant, $this->owner, 'Household Survey');

    $facets = fn (string $query): array => collect(
        $this->actingAs($this->owner)->withoutVite()->get("http://acme.meridian.test/forms{$query}")->viewData('page')['props']['filters']['facets']
    )->keyBy(fn (array $f): string => $f['value'] ?? 'all')->map(fn (array $f): int => $f['count'])->all();

    expect($facets(''))->toMatchArray(['all' => 3, 'live' => 2, 'draft' => 1])
        // Narrowed by the keyword: "Household" is gone from every chip, including All.
        ->and($facets('?q=clinic'))->toMatchArray(['all' => 2, 'live' => 1, 'draft' => 1]);
});

it('says "no matches" — never "no rows" — when a FACET filters to zero', function (): void {
    // The J1e defect, one filter over: a tenant with forms clicking a chip with none must not be told
    // it has never created a form. This is the assertion the widened `ListEmptyReason::for()` exists for.
    publishedInboxForm($this->tenant, $this->owner, 'Only Published');

    $this->actingAs($this->owner)->withoutVite()->get('http://acme.meridian.test/forms?state='.FormListFacets::DRAFT)
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Index', false)
            ->has('forms', 0)
            ->where('filters.applied.state', FormListFacets::DRAFT)
            ->where('empty_reason', 'no_matches'));
});

it('still says "no rows" when the tenant genuinely has none', function (): void {
    $this->actingAs($this->owner)->withoutVite()->get('http://acme.meridian.test/forms')
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Index', false)
            ->where('empty_reason', 'no_rows')
            ->where('filters.applied.state', null));
});

it('ignores an unrecognised facet rather than filtering to zero', function (): void {
    publishedInboxForm($this->tenant, $this->owner, 'Present');

    $this->actingAs($this->owner)->withoutVite()->get('http://acme.meridian.test/forms?state=nonsense')
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Index', false)
            ->has('forms', 1)
            // …and because the facet did not apply, the empty-reason input must not claim a filter ran.
            ->where('filters.applied.state', null)
            ->where('empty_reason', null));
});

it('excludes a closed form from Live and a far-off close from Closing soon', function (): void {
    $open = publishedInboxForm($this->tenant, $this->owner, 'Open');
    $soon = publishedInboxForm($this->tenant, $this->owner, 'Closing Soon');
    $soon->forceFill(['closes_at' => now()->addDays(2)])->save();
    $later = publishedInboxForm($this->tenant, $this->owner, 'Closing Later');
    $later->forceFill(['closes_at' => now()->addDays(30)])->save();
    $shut = publishedInboxForm($this->tenant, $this->owner, 'Shut');
    $shut->forceFill(['closes_at' => now()->subDay()])->save();

    // ⚠️ EVERY read below goes through HTTP, deliberately, and mixing in a direct
    // `FormPresenter::list()` call is what the first draft of this test did wrong. An HTTP request
    // ENDS the tenant context on the way out, so a presenter call afterwards runs unscoped and RLS
    // hands back an empty set — which reads as "the closed form disappeared from the list", a
    // convincing product bug that is entirely an artefact of the test. Measured, not guessed: the same
    // call returns the form before the request and nothing after it.
    $titles = function (string $query): array {
        $props = $this->actingAs($this->owner)->withoutVite()
            ->get("http://acme.meridian.test/forms{$query}")->viewData('page')['props'];

        return collect($props['forms'])->pluck('title')->sort()->values()->all();
    };

    expect($titles('?state='.FormListFacets::LIVE))->toBe(['Closing Later', 'Closing Soon', 'Open'])
        ->and($titles('?state='.FormListFacets::CLOSING_SOON))->toBe(['Closing Soon'])
        // The closed form is in neither chip and is still on the unfiltered page: "closed" is a
        // schedule state, not archival, and the list has always shown it.
        ->and($titles(''))->toBe(['Closing Later', 'Closing Soon', 'Open', 'Shut'])
        ->and($open->id)->not->toBeNull();
});
