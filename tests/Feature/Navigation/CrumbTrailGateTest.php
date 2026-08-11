<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SubmissionStatus;
use App\Models\User;
use App\Support\Navigation\CrumbTrail;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2d — A CRUMB IS OFFERED ONLY TO A READER THE DESTINATION WILL ADMIT.
|
| This file states the two LIVE defects J2d fixes, and pins the guards that are structural rather than
| observable. Its companion, `CrumbTrailReachabilityTest`, proves the other direction — that a crumb the
| trail DOES offer resolves to a real page.
|
| ⚠️ ONE HTTP REQUEST PER TEST, AND EVERY FIXTURE WRITE BEFORE IT. `FormHubGateTest` records why: the request
| tears the tenant GUC down on the way out, so a write issued afterwards runs with no tenant context and RLS
| refuses it outright. The cases below that assert a real 403/404 therefore build everything first and end
| with their single `get()`.
|
| ⚠️ SYNTHETIC ACTORS ARE INLINED, not borrowed from `ResourceGrantServiceTest::actorWith()` — that is a
| file-local Pest function, so a single-file run of this test would die with "undefined function".
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

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Every crumb's href, with a missing key flattened to null so a case reads as the trail looks. */
function hrefs(array $trail): array
{
    return array_map(static fn (array $crumb): ?string => $crumb['href'] ?? null, $trail);
}

it('withholds the Forms crumb from a Viewer while keeping its label', function (): void {
    /*
     * J2c's defect, now asserted against the REAL gate instead of a mocked page prop. `/forms` is
     * `can:viewAny,Form` = `forms.create | forms.edit.any | forms.edit.own`, and a Viewer holds none of the
     * three — while reaching the hub, a form's responses, a submission and the encode screen. A hard
     * `href: '/forms'` hands exactly this reader a 403 with no way back.
     *
     * ⚠️ THE LABEL SURVIVES. This is the deliberate difference from `FormTabSet`, where a refused item is
     * absent: dropping the crumb would renumber the trail and change the page's apparent DEPTH per role.
     */
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $trail = CrumbTrail::forms($viewer)->form($this->form)->current('Responses');

    expect($trail[0])->toBe(['label' => 'Forms'])
        ->and($trail[1]['href'] ?? null)->toBe('/forms/'.$this->form->id);
});

it('links the Forms crumb for an Owner', function (): void {
    // The other half of the same predicate — without this case, `forms()` returning a bare label
    // unconditionally would pass every assertion above.
    $trail = CrumbTrail::forms($this->owner)->current('Anything');

    expect($trail[0])->toBe(['label' => 'Forms', 'href' => '/forms']);
});

it('refuses both form crumbs to a respondent who can open the submission but not the form', function (): void {
    /*
     * ══════════════════════════════════════════════════════════════════════════════════════════════════
     * ⚠️ DEFECT 1, AND IT IS LIVE ON `submissions/Show.vue` TODAY.
     * ══════════════════════════════════════════════════════════════════════════════════════════════════
     * `SubmissionPolicy::view()` admits three ways: org-wide visibility, collaboration on the form, OR
     * **being the respondent**. `FormPolicy::viewOverview()` has no counterpart for that third arm. So this
     * member opens `/submissions/{id}` and both middle crumbs — hard-coded there before J2d — are 403s.
     *
     * The state is reached by two live product paths, not by contrivance: a keyer's grant is revoked, or
     * their form is re-scoped. Built here as "encoded it, holds no grant", which is that state without
     * simulating the revocation: `SubmissionController` stamps the encoder as `respondent_user_id`.
     *
     * The trailing request PROVES the refusal rather than assuming it — `FormHubGateTest`'s idiom. Without
     * it this case would pass just as happily against a `viewOverview` that had quietly widened.
     */
    $keyer = User::factory()->create();
    makeActiveMember($keyer, 'form_editor');
    $submission = seedInboxSubmission($this->form, $keyer, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $trail = CrumbTrail::forms($keyer)
        ->form($submission->form)
        ->formSubmissions($submission->form)
        ->current('Response');

    expect(hrefs($trail))->toBe(['/forms', null, null, null]);

    $this->withoutVite()->actingAs($keyer)
        ->get('http://acme.meridian.test/forms/'.$this->form->id)
        ->assertForbidden();
});

it('refuses both form crumbs when the form is soft-deleted, and labels it as the inbox does', function (): void {
    /*
     * ══════════════════════════════════════════════════════════════════════════════════════════════════
     * ⚠️ DEFECT 2, ALSO LIVE, AND IT AFFECTS EVERY ROLE INCLUDING AN OWNER.
     * ══════════════════════════════════════════════════════════════════════════════════════════════════
     * `/forms/{form}` uses default route-model binding, which excludes trashed rows, while
     * `SubmissionInboxPresenter::formTitle()` renders a soft-deleted form's title as `—`. So the page
     * printed **an em dash as a live hyperlink to a 404**, twice — the literal defect that presenter's own
     * comment names, surviving one surface over on the page it links to.
     *
     * The label agreeing with `formTitle()` is the point: a reader who saw an em dash in the inbox must see
     * an em dash here, not a title implying a page that no longer exists.
     */
    $submission = seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    $this->form->delete();
    $submission->refresh()->load('form');

    $trail = CrumbTrail::forms($this->owner)
        ->form($submission->form)
        ->formSubmissions($submission->form)
        ->current('Response');

    expect($trail[1])->toBe(['label' => '—'])
        ->and($trail[2])->toBe(['label' => 'Responses']);

    $this->withoutVite()->actingAs($this->owner)
        ->get('http://acme.meridian.test/forms/'.$this->form->id)
        ->assertNotFound();
});

it('links the form crumb for a Reviewer holding a reviewer-capacity grant', function (): void {
    /*
     * The positive control for the respondent case above, and it pins `holdsAny()` rather than
     * `holds(Editor)` — the trap `viewOverview` and `scopeReadableBy` both record. A Reviewer's grant is
     * REVIEWER capacity, so an editor-capacity check would refuse the one role most likely to be reading a
     * submission, while every Owner-fixtured test stayed green.
     *
     * `/forms` stays unlinked: a Reviewer holds no `forms.*` key. That combination — a linked hub above an
     * unlinked root — is the trail behaving correctly, and is impossible to express with a hard-coded href.
     */
    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($this->form, $reviewer, ResourceCapacity::Reviewer);

    $trail = CrumbTrail::forms($reviewer)
        ->form($this->form)
        ->formSubmissions($this->form)
        ->current('Response');

    expect(hrefs($trail))->toBe([
        null,
        '/forms/'.$this->form->id,
        '/forms/'.$this->form->id.'/submissions',
        null,
    ]);
});

it('withholds the Responses crumb from a member who may open the form but not read submissions', function (): void {
    /*
     * ⚠️ A FAIL-CLOSED GUARD, LABELLED AS ONE. `formSubmissions()`'s second conjunct is unobservable across
     * every shipped role — all five hold `submissions.view` — so deleting it reddens nothing but this
     * synthetic case. It is here because the ROUTE carries two gates, and a trail that mirrors only one of
     * them is right by luck, which is the standing `FormTabSet` records for the identical conjunct on the
     * identical destination.
     */
    $stranger = User::factory()->create();
    makeActiveMember($stranger, 'viewer');
    $stranger->syncRoles([]);
    $stranger->syncPermissions(['dashboard.form.view', 'dashboard.org.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $trail = CrumbTrail::forms($stranger)
        ->form($this->form)
        ->formSubmissions($this->form)
        ->current('Response');

    expect($trail[1]['href'] ?? null)->toBe('/forms/'.$this->form->id)
        ->and($trail[2])->toBe(['label' => 'Responses']);
});

it('withholds the submission crumb from a member who may edit answers but not read the row', function (): void {
    /*
     * ⚠️ THE OTHER FAIL-CLOSED GUARD, and the reason this class lives in `Navigation` rather than `Forms`:
     * a form-only helper would have left the one crumb whose gate has the respondent arm unguarded.
     *
     * Reachable only synthetically. `SubmissionPolicy::update()` does NOT in fact require `submissions.view`
     * — see the docblock correction noted in this increment — so a member holding `submissions.edit.any`
     * without it passes the edit route's gate and fails `view`. Every shipped role holds both.
     */
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    $submission = seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $editor->syncRoles([]);
    $editor->syncPermissions(['submissions.edit.any', 'dashboard.form.view', 'dashboard.org.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $trail = CrumbTrail::forms($editor)
        ->form($this->form)
        ->formSubmissions($this->form)
        ->submission($submission)
        ->current('Edit answers');

    expect($trail[3])->toBe(['label' => 'Response']);
});

it('links the submission crumb for a reader who can open it', function (): void {
    // The positive control, without which the guard above would pass against a `submission()` that never
    // emitted an href at all.
    $submission = seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $trail = CrumbTrail::forms($this->owner)->submission($submission)->current('Edit answers');

    expect($trail[1])->toBe(['label' => 'Response', 'href' => '/submissions/'.$submission->id]);
});

it('never emits an href on the crumb that closes the trail', function (): void {
    /*
     * ⚠️ INVISIBLE EVERYWHERE EXCEPT HERE. `MdsBreadcrumb` renders its last item as text whatever it
     * carries, so an href leaking onto the tail changes nothing in the browser, in Vitest or in axe — it
     * would surface only as a dead payload key that a later author reads as meaningful. `current()` returns
     * the array rather than `self` precisely so the rule is unwriteable, and this case is what proves the
     * return type is doing that job.
     */
    $trail = CrumbTrail::forms($this->owner)->form($this->form)->current('Builder');

    expect(end($trail))->toBe(['label' => 'Builder'])
        ->and(array_key_exists('href', end($trail)))->toBeFalse();
});
