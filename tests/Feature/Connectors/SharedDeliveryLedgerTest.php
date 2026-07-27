<?php

declare(strict_types=1);

use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookRetrySweeper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The shared outbound ledger (H15a): `webhook_deliveries` is owned by EITHER a webhook endpoint OR a
| connector subscription, never both and never neither. The DB CHECK is what makes "which job delivers this
| row" a total function rather than a guess, so it is worth pinning directly — and the retry sweep, which is
| the one place that has to branch on it, is worth pinning against both kinds at once.
*/

beforeEach(function (): void {
    TenantContext::flush();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    enterTenant($this->tenant->id);
});

/** Insert a ledger row through the query builder, bypassing the model, to reach the constraint directly. */
function insertLedgerRow(?string $endpointId, ?string $subscriptionId): void
{
    DB::table('webhook_deliveries')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => test()->tenant->id,
        'webhook_endpoint_id' => $endpointId,
        'connection_subscription_id' => $subscriptionId,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'submission.created',
        'payload' => json_encode(['data' => []]),
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('rejects a ledger row with no owner', function (): void {
    expect(fn () => insertLedgerRow(null, null))->toThrow(QueryException::class);
});

it('rejects a ledger row with two owners', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    $subscription = ConnectionSubscription::factory()->forConnection(Connection::factory()->create())->create();

    expect(fn () => insertLedgerRow($endpoint->id, $subscription->id))->toThrow(QueryException::class);
});

it('keeps idempotency per owner rather than across the whole table', function (): void {
    // The replacement uniques are PARTIAL for a reason: a plain unique over a nullable column would let
    // unlimited connector rows share an event_id under Postgres NULL semantics, silently ending idempotency
    // for half the ledger.
    $endpoint = WebhookEndpoint::factory()->create();
    $subscription = ConnectionSubscription::factory()->forConnection(Connection::factory()->create())->create();
    $eventId = (string) Str::uuid();

    WebhookDelivery::factory()->forEndpoint($endpoint)->create(['event_id' => $eventId]);
    WebhookDelivery::factory()->forSubscription($subscription)->create(['event_id' => $eventId]);

    // The same event may land once per owner...
    expect(WebhookDelivery::query()->where('event_id', $eventId)->count())->toBe(2);

    // ...but never twice for the same one.
    expect(fn () => WebhookDelivery::factory()->forSubscription($subscription)->create(['event_id' => $eventId]))
        ->toThrow(QueryException::class);
});

it('dispatches the matching delivery job for each kind of due row', function (): void {
    Bus::fake([DeliverWebhookJob::class, DeliverConnectorMessageJob::class]);

    $endpoint = WebhookEndpoint::factory()->create();
    $subscription = ConnectionSubscription::factory()->forConnection(Connection::factory()->create())->create();

    $webhookRow = WebhookDelivery::factory()->forEndpoint($endpoint)->dueForRetry()->create();
    $connectorRow = WebhookDelivery::factory()->forSubscription($subscription)->dueForRetry()->create();

    app(WebhookRetrySweeper::class)->sweep(Carbon::now());

    Bus::assertDispatched(
        DeliverWebhookJob::class,
        fn (DeliverWebhookJob $job): bool => $job->deliveryId === $webhookRow->id,
    );
    Bus::assertDispatched(
        DeliverConnectorMessageJob::class,
        fn (DeliverConnectorMessageJob $job): bool => $job->deliveryId === $connectorRow->id,
    );
    Bus::assertDispatchedTimes(DeliverWebhookJob::class, 1);
    Bus::assertDispatchedTimes(DeliverConnectorMessageJob::class, 1);
});

it('leaves the webhook channel behaviour unchanged', function (): void {
    // A webhook-owned row still round-trips exactly as it did before the ledger was generalized.
    $endpoint = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create();

    expect($delivery->fresh()->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($delivery->fresh()->connection_subscription_id)->toBeNull()
        ->and($delivery->endpoint->id)->toBe($endpoint->id)
        ->and($delivery->subscription)->toBeNull();
});

it('cascades a connector row away with its subscription', function (): void {
    $subscription = ConnectionSubscription::factory()->forConnection(Connection::factory()->create())->create();
    WebhookDelivery::factory()->forSubscription($subscription)->create();

    // A soft delete keeps the history (that is the disconnect path); a hard delete is a true teardown.
    $subscription->forceDelete();

    expect(WebhookDelivery::query()->where('connection_subscription_id', $subscription->id)->count())->toBe(0);
});
