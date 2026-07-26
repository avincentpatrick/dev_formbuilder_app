<?php

declare(strict_types=1);

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Maintenance\SweepWebhookRetriesJob;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

function runWebhookRetrySweep(): void
{
    SweepWebhookRetriesJob::dispatch();
    workOneJob('scheduled-maintenance'); // parent → fans out the per-tenant child
    workOneJob('scheduled-maintenance'); // child → re-dispatches due deliveries
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
