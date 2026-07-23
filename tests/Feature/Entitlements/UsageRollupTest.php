<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SubmissionStatus;
use App\Enums\UsageMetric;
use App\Jobs\Maintenance\RollUpUsageCountersJob;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Entitlements\QuotaOverageNotification;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/** Run the rollup end to end: sweep (fan-out) then the single acme child. */
function h5bRunRollup(): void
{
    RollUpUsageCountersJob::dispatch();
    workOneJob('scheduled-maintenance'); // the sweep enqueues one child (acme is the only active tenant)
    workOneJob('scheduled-maintenance'); // the child reconciles acme
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
