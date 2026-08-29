<?php

declare(strict_types=1);

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Maintenance\SweepWebhookRetriesJob;
use App\Jobs\Webhooks\SweepTenantWebhookRetriesJob;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| SweepWebhookRetriesJob (H13a) — the cross-tenant fan-out re-dispatches a due `failed` delivery. Committing
| recipe: dispatch the parent, then workOneJob('scheduled-maintenance') TWICE (parent fan-out → per-tenant
| child); the child re-enqueues one DeliverWebhookJob per due row onto the `webhooks` queue.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    enterTenant($this->tenant->id);
    $this->endpoint = WebhookEndpoint::factory()->create();
});

/**
 * Run the sweep end to end: the fan-out, then ONE CHILD PER ACTIVE TENANT.
 *
 * ⚠️ THIS WAS TWO HARD-CODED `workOneJob()` CALLS UNTIL M44, AND THAT IS WHY NOTHING HERE COULD SEE A WRONG
 * TENANT ID. With a second tenant the parent enqueues two children and the second was simply never worked.
 *
 * ⛔ AND THE COUNT LINE BELOW CLOSES A SECOND, UNRELATED HOLE THAT M44 MEASURED RATHER THAN REASONED ABOUT.
 * Replacing this file's `sweep()` body with a comment — a sweep that dispatches NOTHING — left TWO of this
 * file's three cases GREEN, because both assert `webhookQueueDepth() === 0` and a dead sweep produces
 * exactly that. They were passing for a reason unrelated to their names. Asserting the fan-out's own count
 * reddens all three, so the negatives now prove the sweep ran and then declined, rather than never ran.
 *
 * The bound is the asserted count, so this cannot hang CI. `failed_jobs` is checked because the worker
 * SWALLOWS exceptions ({@see workOneJob()}'s docblock) — a child that died under the second tenant would
 * otherwise read as "the sweep did nothing".
 */
function runWebhookRetrySweep(int $activeTenants = 1): void
{
    SweepWebhookRetriesJob::dispatch();
    workOneJob('scheduled-maintenance'); // parent → fans out one child per active tenant

    expect(DB::table('jobs')->where('queue', 'scheduled-maintenance')->count())->toBe($activeTenants);

    for ($i = 0; $i < $activeTenants; $i++) {
        workOneJob('scheduled-maintenance'); // each child re-dispatches ITS OWN tenant's due deliveries
    }

    expect(DB::table('failed_jobs')->count())->toBe(0);

    enterTenant(test()->tenant->id);
}

function webhookQueueDepth(): int
{
    return (int) DB::table('jobs')->where('queue', 'webhooks')->count();
}

it('re-dispatches a due failed delivery', function (): void {
    WebhookDelivery::factory()->forEndpoint($this->endpoint)->dueForRetry()->create();

    runWebhookRetrySweep();

    expect(webhookQueueDepth())->toBe(1);
});

it('does not re-dispatch a delivery whose next_retry_at is still in the future', function (): void {
    WebhookDelivery::factory()->forEndpoint($this->endpoint)->create([
        'status' => WebhookDeliveryStatus::Failed,
        'attempt_count' => 1,
        'next_retry_at' => Carbon::now()->addHour(),
    ]);

    runWebhookRetrySweep();

    expect(webhookQueueDepth())->toBe(0);
});

it('does not re-dispatch a dead-lettered delivery', function (): void {
    WebhookDelivery::factory()->forEndpoint($this->endpoint)->create([
        'status' => WebhookDeliveryStatus::DeadLettered,
        'attempt_count' => 10,
        'next_retry_at' => null,
    ]);

    runWebhookRetrySweep();

    expect(webhookQueueDepth())->toBe(0);
});

// ── M44 — the fan-out's IDENTITY, which a one-tenant fixture structurally cannot assert ───────────────────
//
// The production loop is correct; what was missing was a fixture wide enough to notice if it stopped being.
// ⛔ THIS FILE IS THE ONE WHERE THE ROW'S PRESCRIBED REMEDY IS NOT ENOUGH, AND THE REASON IS WORTH READING
// BEFORE EDITING EITHER CASE. `WebhookRetrySweeper` writes NO rows — it selects due deliveries and dispatches
// one job each, leaving `status`, `next_retry_at` and `attempt_count` untouched. So under a hoisted loop
// variable acme is swept TWICE and re-dispatches its own due delivery both times, and the queue depth is 2 —
// numerically identical to two tenants each swept once. A count assertion is blind here even with a working
// drain loop. Only the IDENTITY of what was dispatched separates the two, which is what both cases assert.

it('fans out one child per active tenant and holds no tenant context itself', function (): void {
    Bus::fake([SweepTenantWebhookRetriesJob::class]);

    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);

    TenantContext::flush();
    (new SweepWebhookRetriesJob)->handle();

    Bus::assertDispatchedTimes(SweepTenantWebhookRetriesJob::class, 2);

    // ⚠️ Compared as a whole SET, never through Bus::assertDispatched($class, $closure): that is an
    // AT-LEAST-ONE-MATCH predicate, satisfied by the first of two identical jobs, so it cannot tell
    // "one child per tenant" from "the first tenant's id, twice" — which is exactly the defect.
    $expected = collect([$this->tenant, $other])
        ->map(fn (Tenant $tenant): string => (string) $tenant->getKey())
        ->sort()
        ->values()
        ->all();

    expect(Bus::dispatched(SweepTenantWebhookRetriesJob::class)
        ->map(fn (SweepTenantWebhookRetriesJob $job): string => $job->tenantId)
        ->sort()
        ->values()
        ->all())
        ->toBe($expected);

    // A sweep is cross-tenant by definition, so a context left behind here would be inherited by whatever
    // ran next on the worker. Never asserted in this file before M44.
    expect(TenantContext::currentTenantId())->toBeNull();
});

it('re-dispatches each active tenant\'s own due delivery, not the first tenant\'s twice', function (): void {
    $acmeDelivery = WebhookDelivery::factory()->forEndpoint($this->endpoint)->dueForRetry()->create();

    // A SECOND active tenant with its own endpoint and its own due delivery, built under its own RLS
    // context. Created inside this case rather than in beforeEach: widening the shared fixture would leave
    // the three existing cases draining only the first of two children and still passing.
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    enterTenant($other->id);
    $otherEndpoint = WebhookEndpoint::factory()->create();
    $otherDelivery = WebhookDelivery::factory()->forEndpoint($otherEndpoint)->dueForRetry()->create();

    enterTenant($this->tenant->id);

    runWebhookRetrySweep(activeTenants: 2);

    // Necessary but NOT sufficient — see this section's header. The hoist produces this same 2.
    expect(webhookQueueDepth())->toBe(2);

    // ⛔ THE ASSERTION THAT ACTUALLY SEPARATES THEM. Each due delivery must have been enqueued exactly ONCE.
    // Under the hoist, acme's id appears twice and `other`'s not at all, while the depth above still reads 2.
    // Payload containment rather than a Bus fake, so this stays a committing test all the way to the real
    // `webhooks` row — the DatabaseWorkerPipelineTest idiom.
    $payloads = DB::table('jobs')->where('queue', 'webhooks')->pluck('payload');

    expect($payloads->filter(fn (string $p): bool => str_contains($p, $acmeDelivery->id))->count())->toBe(1)
        ->and($payloads->filter(fn (string $p): bool => str_contains($p, $otherDelivery->id))->count())->toBe(1);
});
