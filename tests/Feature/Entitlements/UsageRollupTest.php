<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SubmissionStatus;
use App\Enums\UsageMetric;
use App\Jobs\Entitlements\ReconcileTenantUsageJob;
use App\Jobs\Maintenance\RollUpUsageCountersJob;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Entitlements\QuotaOverageNotification;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// The cross-tenant usage rollup (ADR-0008 §D8/§D9): a MaintenanceJob fans out one ReconcileTenantUsageJob
// per active tenant. Committing test — the fan-out enqueues on the `database` driver and is drained with
// workOneJob(), the only path RefreshDatabase's shared-PDO transaction lets a worker see (Pest.php).

beforeEach(function (): void {
    TenantContext::flush();
    Notification::fake();
    config()->set('queue.default', 'database'); // enqueue to `jobs`; drain with workOneJob('scheduled-maintenance')
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner'); // one active seat + a resolvable owner for the overage email
    $this->tenant->forceFill(['owner_user_id' => $this->owner->id])->save();
});

/**
 * Run the rollup end to end: the fan-out, then ONE CHILD PER ACTIVE TENANT.
 *
 * ⚠️ THIS WAS TWO HARD-CODED `workOneJob()` CALLS UNTIL M44, AND THAT IS WHY NOTHING HERE COULD SEE A WRONG
 * TENANT ID. With a second tenant the parent enqueues two children and the second was simply never worked, so
 * every downstream assertion read identically — a new green test that still cannot see the defect.
 *
 * ⚠️ `$activeTenants` IS ASSERTED, NEVER INFERRED — the {@see runRefreshSweep()} rationale (M6). Draining
 * "however many jobs happen to be queued" keeps passing when the sweep dispatches NONE; M44 measured a
 * deliberately dead `sweep()` against the webhook sibling of this suite and TWO of its three cases stayed
 * green. The bound is the asserted count, so this cannot hang CI either.
 *
 * ⚠️ AND `failed_jobs` IS CHECKED BECAUSE THE WORKER SWALLOWS EXCEPTIONS ({@see workOneJob()}'s docblock): a
 * child that died under the SECOND tenant would otherwise read as "the rollup did nothing".
 */
function h5bRunRollup(int $activeTenants = 1): void
{
    RollUpUsageCountersJob::dispatch();
    workOneJob('scheduled-maintenance'); // the sweep enqueues one child per active tenant

    expect(DB::table('jobs')->where('queue', 'scheduled-maintenance')->count())->toBe($activeTenants);

    for ($i = 0; $i < $activeTenants; $i++) {
        workOneJob('scheduled-maintenance'); // each child reconciles ITS OWN tenant
    }

    expect(DB::table('failed_jobs')->count())->toBe(0);
}

it('reconciles submissions_count from source and stamps limit_snapshot', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    foreach (range(1, 3) as $i) {
        seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => "P{$i}"]);
    }

    $plan = Plan::factory()->tier(PlanTier::Free)
        ->withQuotas([UsageMetric::SubmissionsCount->value => 2])
        ->create();
    Subscription::factory()->forPlan($plan)->create();

    h5bRunRollup();

    enterTenant($this->tenant->id, $this->owner->id); // §D4 cleared context after the worker; re-enter to read
    $row = DB::table('usage_counters')->where('metric', 'submissions_count')->first();

    expect($row)->not->toBeNull();
    expect((int) $row->value)->toBe(3)            // reconciled from the 3 seeded submissions, not the 0 metered
        ->and((int) $row->limit_snapshot)->toBe(2) // the plan quota that applied, stamped for history
        ->and($row->subscription_id)->not->toBeNull();
});

it('materializes the gauge levels (forms + seats) into the current period', function (): void {
    publishedInboxForm($this->tenant, $this->owner); // one form
    $plan = Plan::factory()->tier(PlanTier::Professional)->create();
    Subscription::factory()->forPlan($plan)->create();

    h5bRunRollup();

    enterTenant($this->tenant->id, $this->owner->id);
    expect((int) DB::table('usage_counters')->where('metric', 'forms_count')->value('value'))->toBe(1)
        ->and((int) DB::table('usage_counters')->where('metric', 'active_seats')->value('value'))->toBe(1);
});

it('sends the never-block overage upsell once when submissions cross the quota', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    foreach (range(1, 3) as $i) {
        seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => "P{$i}"]);
    }
    $plan = Plan::factory()->tier(PlanTier::Free)
        ->withQuotas([UsageMetric::SubmissionsCount->value => 2])
        ->create();
    Subscription::factory()->forPlan($plan)->create();

    h5bRunRollup();

    Notification::assertSentOnDemand(QuotaOverageNotification::class);
});

it('does not upsell when submissions are within quota', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'P1']);
    $plan = Plan::factory()->tier(PlanTier::Free)
        ->withQuotas([UsageMetric::SubmissionsCount->value => 10])
        ->create();
    Subscription::factory()->forPlan($plan)->create();

    h5bRunRollup();

    Notification::assertNothingSent();
});

// ── M44 — the fan-out's IDENTITY, which a one-tenant fixture structurally cannot assert ───────────────────
//
// The production loop is correct; what was missing was a fixture wide enough to notice if it stopped being.
// Both cases go red on a hoisted loop variable, and they fail differently on purpose: the first names the
// child class (which appeared under tests/ only inside comments before M44) and asserts the dispatched
// multiset; the second drives the real queue and asserts the per-tenant effect.

it('fans out one child per active tenant and holds no tenant context itself', function (): void {
    Bus::fake([ReconcileTenantUsageJob::class]);

    $other = inboxTenant('other');

    TenantContext::flush();
    (new RollUpUsageCountersJob)->handle();

    Bus::assertDispatchedTimes(ReconcileTenantUsageJob::class, 2);

    // ⚠️ Compared as a whole SET, never through Bus::assertDispatched($class, $closure): that is an
    // AT-LEAST-ONE-MATCH predicate, satisfied by the first of two identical jobs, so it cannot tell
    // "one child per tenant" from "the first tenant's id, twice" — which is exactly the defect.
    $expected = collect([$this->tenant, $other])
        ->map(fn (Tenant $tenant): string => (string) $tenant->getKey())
        ->sort()
        ->values()
        ->all();

    expect(Bus::dispatched(ReconcileTenantUsageJob::class)
        ->map(fn (ReconcileTenantUsageJob $job): string => $job->tenantId)
        ->sort()
        ->values()
        ->all())
        ->toBe($expected);

    // A sweep is cross-tenant by definition, so a context left behind here would be inherited by whatever
    // ran next on the worker. Never asserted in this file before M44.
    expect(TenantContext::currentTenantId())->toBeNull();
});

it('reconciles each active tenant\'s own submissions_count, not the first tenant\'s twice', function (): void {
    // ⚠️ DISTINCT MAGNITUDES, DELIBERATELY: 2 for acme and 1 for `other`. Equal counts would let an aliased
    // tenant id produce a right-looking pair by coincidence; unequal ones cannot.
    $acmeForm = publishedInboxForm($this->tenant, $this->owner);
    foreach (range(1, 2) as $i) {
        seedInboxSubmission($acmeForm, $this->owner, SubmissionStatus::Submitted, ['full_name' => "A{$i}"]);
    }

    // ⚠️ NO SUBSCRIPTION, NO PLAN, NO MEMBERSHIP FOR THE SECOND TENANT, AND THAT IS CHECKED RATHER THAN
    // ASSUMED. ReconcileTenantUsageJob writes submissions_count plus three gauges unconditionally;
    // `subscription_id` is nullable and an absent plan resolves to null (unlimited), so the overage guard
    // `$submissionsLimit !== null` cannot fire and beforeEach's Notification::fake() needs nothing said
    // about it. The row would lead you to build a second owner + plan here; none of it is required.
    $other = inboxTenant('other');
    $otherOwner = User::factory()->create();
    enterTenant($other->id, $otherOwner->id);
    $otherForm = publishedInboxForm($other, $otherOwner);
    seedInboxSubmission($otherForm, $otherOwner, SubmissionStatus::Submitted, ['full_name' => 'B1']);

    enterTenant($this->tenant->id, $this->owner->id);

    h5bRunRollup(activeTenants: 2);

    enterTenant($this->tenant->id, $this->owner->id); // §D4 cleared context after the worker; re-enter to read
    expect((int) DB::table('usage_counters')->where('metric', 'submissions_count')->value('value'))->toBe(2);

    // ⛔ THE ASSERTION A ONE-TENANT FIXTURE CANNOT MAKE. Hoist the loop variable and both children carry
    // acme's id: acme is reconciled twice to the same value — so the expectation above still passes — and
    // `other` gets NO counter row at all, which `value()` returns as null and `(int)` casts to 0.
    enterTenant($other->id, $otherOwner->id);
    expect((int) DB::table('usage_counters')->where('metric', 'submissions_count')->value('value'))->toBe(1);
});
