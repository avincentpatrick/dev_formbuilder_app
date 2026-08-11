<?php

declare(strict_types=1);

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment J2b — the form hub's PAYLOAD, as distinct from its gate.
|
| `FormHubGateTest` is the authorization record: who gets in, which tabs each role is offered, whether the
| share block is present. It deliberately asserts almost nothing about the numbers, and every judgement call
| the presenter's docblocks describe at length — the capacity predicate, the recency ordering, the visibility
| scoping — was therefore unpinned. This file is that half.
|
| ⚠️ THE TWO COUNTS ON THIS PAGE USE DIFFERENT PREDICATES ON PURPOSE, and a reviewer "tidying" them into one
| is the defect these cases exist to catch. `stats.responses` is `countable()` (the inbox's display default,
| so the hub and the list cannot disagree about whether a draft is a response) while the capacity headroom is
| `consumesCapacity()` (I9a — a screened-out respondent must not burn a paid slot). A `screened_out` row is
| inside the first and outside the second, which is the only fixture that can tell them apart.
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

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Payload Form');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function hubUrl(Form $form): string
{
    return 'http://acme.meridian.test/forms/'.$form->id;
}

/*
| ── The counts ─────────────────────────────────────────────────────────────────────────────────────────
*/

it('counts responses the way the inbox does, excluding drafts and including screened-out', function (): void {
    seedCountableAt($this->form, CarbonImmutable::now()->subDays(3));
    seedCountableAt($this->form, CarbonImmutable::now()->subDays(2), SubmissionStatus::Approved);
    seedCountableAt($this->form, CarbonImmutable::now()->subDay(), SubmissionStatus::ScreenedOut);
    seedCountableAt($this->form, CarbonImmutable::now(), SubmissionStatus::Draft);
    seedCountableAt($this->form, CarbonImmutable::now(), SubmissionStatus::Draft);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('forms/Show', false)
            // Three countable: submitted + approved + screened_out. `screened_out` IS countable — it is a
            // real row the tenant received and the inbox lists it, so dropping it here would make the tile
            // disagree with the list one click away (ADR-0011 §D2).
            ->where('stats.responses', 3)
            ->where('stats.drafts', 2)
            ->etc());
});

it('reports the last response time from the most recent COUNTABLE row', function (): void {
    $latest = CarbonImmutable::parse('2026-08-09 10:00:00');
    seedCountableAt($this->form, $latest->subDays(5));
    seedCountableAt($this->form, $latest);
    // A draft has no `submitted_at` at all, so it cannot move this — asserted rather than assumed, because
    // a `max()` over an unscoped query would return null-free garbage the moment drafts gained one.
    seedCountableAt($this->form, CarbonImmutable::now(), SubmissionStatus::Draft);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.last_response_at', fn (?string $v) => $v !== null && str_contains($v, '2026-08-09'))
            ->etc());
});

it('leaves the counts at zero and the last response null for a form nobody has answered', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.responses', 0)
            ->where('stats.drafts', 0)
            ->where('stats.last_response_at', null)
            ->has('recent', 0)
            ->etc());
});

/*
| ── The schedule block ─────────────────────────────────────────────────────────────────────────────────
*/

it('reports an uncapped open form as accepting, with no capacity numbers at all', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.acceptance', 'open')
            // Null rather than a number: an uncapped form pays for no capacity query, matching
            // PublicFormPresenter. A 0 here would be a claim that the form is full.
            ->where('schedule.max_responses', null)
            ->where('schedule.remaining', null)
            ->etc());
});

it('excludes a screened-out respondent from the capacity headroom', function (): void {
    /*
     * ⚠️ THIS IS THE CASE THAT TELLS THE TWO PREDICATES APART, and it is the whole reason this file exists.
     *
     * Four finalized rows, one of them `screened_out`. `countable()` admits all four; `consumesCapacity()`
     * admits three, because a screened-out respondent was shown no questions and charging them a paid slot
     * was the bug I9a fixed. So `stats.responses` is 4 while `remaining` is 10 − 3 = 7.
     *
     * MUTATION: swap `consumesCapacity()` for `countable()` in `FormHubPresenter::schedule()` and `remaining`
     * becomes 6 — this case reddens and nothing else does. Run before trusting it.
     */
    $this->form->forceFill(['max_responses' => 10])->save();

    seedCountableAt($this->form, CarbonImmutable::now()->subDays(3));
    seedCountableAt($this->form, CarbonImmutable::now()->subDays(2));
    seedCountableAt($this->form, CarbonImmutable::now()->subDay(), SubmissionStatus::Approved);
    seedCountableAt($this->form, CarbonImmutable::now(), SubmissionStatus::ScreenedOut);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.responses', 4)
            ->where('schedule.max_responses', 10)
            ->where('schedule.remaining', 7)
            ->where('schedule.acceptance', 'open')
            ->etc());
});

it('reports a full form as capacity_reached with zero remaining', function (): void {
    $this->form->forceFill(['max_responses' => 2])->save();
    seedCountableAt($this->form, CarbonImmutable::now()->subDay());
    seedCountableAt($this->form, CarbonImmutable::now());

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.acceptance', 'capacity_reached')
            ->where('schedule.remaining', 0)
            ->etc());
});

it('reports a form whose window has closed as closed', function (): void {
    $this->form->forceFill([
        'closes_at' => CarbonImmutable::now()->subDay(),
        'timezone' => 'Asia/Manila',
    ])->save();

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.acceptance', 'closed')
            // The zone travels with the window: the page renders the instant in the FORM's zone, so a
            // payload without it would leave the reader guessing whose 5pm the deadline is.
            ->where('schedule.timezone', 'Asia/Manila')
            ->etc());
});

/*
| ── Recency and versions ───────────────────────────────────────────────────────────────────────────────
*/

it('caps the recent panel at five rows, newest first', function (): void {
    // Seven rows, each a second apart so their uuidv7 ids order the same way their timestamps do.
    foreach (range(1, 7) as $i) {
        seedCountableAt($this->form, CarbonImmutable::parse('2026-08-01 10:00:00')->addSeconds($i));
    }

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recent', 5)
            // The panel is a taste, not a list — the count above it stays honest about the other two.
            ->where('stats.responses', 7)
            ->etc());
});

it('orders the recent panel newest-first and never lists a draft', function (): void {
    $old = seedCountableAt($this->form, CarbonImmutable::parse('2026-08-01 10:00:00'));
    $new = seedCountableAt($this->form, CarbonImmutable::parse('2026-08-05 10:00:00'));
    seedCountableAt($this->form, CarbonImmutable::now(), SubmissionStatus::Draft);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recent', 2)
            ->where('recent.0.id', (string) $new->id)
            ->where('recent.1.id', (string) $old->id)
            ->etc());

    expect($old->id)->not->toBe($new->id);
});

it('carries a human channel label on every recent row rather than the raw enum', function (): void {
    seedCountableAt($this->form, CarbonImmutable::now(), SubmissionStatus::Submitted, SubmissionSource::Manual);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('recent.0.source_label', fn (string $v) => $v !== '' && $v !== 'manual')
            ->etc());
});

it('lists versions newest-first, with the live one and the open draft both present', function (): void {
    /*
     * TWO versions after a single publish, not one — `PublishService` clones the just-published structure
     * forward into a brand-new draft (v2) so builder editing continues without touching v1. Worth pinning
     * rather than glossing: the hub's version panel and its "v1 live / v2 draft" label are the only place a
     * reader sees that pairing, and the panel is ordered by `version_number` DESC, so the DRAFT sorts first
     * while `current_version` still names the published one. An implementation that ordered by
     * `published_at` would put a null-dated draft last (or first, by dialect) and look correct on any form
     * that has never been republished.
     */
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('versions', 2)
            ->where('versions.0.version_number', 2)
            ->where('versions.0.status', 'draft')
            ->where('versions.0.published_at', null)
            ->where('versions.1.version_number', 1)
            ->where('versions.1.status', 'published')
            ->where('form.current_version', 1)
            ->where('form.draft_version', 2)
            ->etc());
});

/*
| ── The `can` map, which drives four buttons the page renders and nothing else does ────────────────────
*/

it('hands an Owner every capability flag', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.edit', true)
            ->where('can.publish', true)
            ->where('can.encode', true)
            ->where('can.template', true)
            ->etc());
});

it('refuses a Viewer every capability flag while still serving the page', function (): void {
    /*
     * The widening's whole shape in one assertion: a Viewer READS the hub (that is `viewOverview`) and can do
     * nothing on it. `can.template` is the interesting one — it is `can:view,form`, which delegates to
     * canEdit and therefore refuses a Viewer, so the Print-blank button must not render. A page that gated
     * printing on `viewOverview` instead would offer a button that 403s.
     */
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->withoutVite()
        ->actingAs($viewer)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.edit', false)
            ->where('can.publish', false)
            ->where('can.template', false)
            ->missing('share')
            ->etc());
});

/*
| ── The scope node's NAME ──────────────────────────────────────────────────────────────────────────────
*/

it('sends no scope node name for a form that belongs to none', function (): void {
    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(hubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('form.scope_node_name', null)->etc());
});
