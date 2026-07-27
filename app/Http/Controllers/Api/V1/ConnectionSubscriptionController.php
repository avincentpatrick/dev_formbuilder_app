<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreConnectionSubscriptionRequest;
use App\Http\Requests\Api\V1\UpdateConnectionSubscriptionRequest;
use App\Http\Resources\Api\V1\ConnectionSubscriptionResource;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Connectors\ConnectionSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CRUD for the delivery rules on one connection, plus each rule's delivery log (ADR-0009, H15a) — the
 * {@see WebhookEndpointController} + {@see WebhookDeliveryController} shape, collapsed into one controller
 * because a subscription has no secret lifecycle to manage.
 *
 * Every route is nested under a bound `{connection}`, so authorization is decided on the grant that owns the
 * rule (`can:update,connection`) rather than on a second policy that could disagree with the first. A
 * subscription belonging to a different connection 404s: the nesting is part of the identity, not decoration.
 *
 * The delivery log reads the SHARED ledger (`webhook_deliveries`, generalized in H15a) through the same
 * {@see WebhookDeliveryResource} the webhook channel uses — one table, one log shape, one resource.
 */
final class ConnectionSubscriptionController extends Controller
{
    public function __construct(private readonly ConnectionSubscriptionService $service) {}

    /** List the delivery rules on one connection, newest first. */
    public function index(Connection $connection): JsonResponse
    {
        $subscriptions = $connection->subscriptions()->latest('created_at')->get();

        return response()->json(['data' => ConnectionSubscriptionResource::collection($subscriptions)]);
    }

    /** Create a delivery rule: which events, which form scope, and the provider destination. */
    public function store(StoreConnectionSubscriptionRequest $request, Connection $connection): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $subscription = $this->service->create($connection, $request->validated(), $user);

        return response()->json(['data' => new ConnectionSubscriptionResource($subscription)], 201);
    }

    /** One delivery rule. */
    public function show(Connection $connection, ConnectionSubscription $subscription): ConnectionSubscriptionResource
    {
        $this->assertOwnedBy($connection, $subscription);

        return new ConnectionSubscriptionResource($subscription);
    }

    /** Partially update a rule, including the pause/re-enable status toggle. */
    public function update(
        UpdateConnectionSubscriptionRequest $request,
        Connection $connection,
        ConnectionSubscription $subscription,
    ): ConnectionSubscriptionResource {
        $this->assertOwnedBy($connection, $subscription);

        return new ConnectionSubscriptionResource($this->service->update($subscription, $request->validated()));
    }

    /** Soft-delete a rule; its delivery history stays in the shared ledger. */
    public function destroy(Connection $connection, ConnectionSubscription $subscription): Response
    {
        $this->assertOwnedBy($connection, $subscription);

        $this->service->delete($subscription);

        return response()->noContent();
    }

    /** Cursor-paginated delivery log for one rule, newest first — the shared outbound ledger. */
    public function deliveries(Request $request, Connection $connection, ConnectionSubscription $subscription): JsonResponse
    {
        $this->assertOwnedBy($connection, $subscription);

        $limit = min(max($request->integer('limit', 50), 1), 100);

        $deliveries = WebhookDelivery::query()
            ->where('connection_subscription_id', $subscription->getKey())
            ->latest('created_at')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => WebhookDeliveryResource::collection($deliveries->getCollection()),
            'meta' => [
                'next_cursor' => $deliveries->nextCursor()?->encode(),
                'has_more' => $deliveries->hasMorePages(),
            ],
        ]);
    }

    /**
     * Both models are RLS-scoped to the tenant, so this guards the remaining case: a real subscription of
     * THIS tenant reached through the wrong connection. 404, not 403 — from the caller's position that pair
     * simply does not exist.
     */
    private function assertOwnedBy(Connection $connection, ConnectionSubscription $subscription): void
    {
        abort_unless($subscription->connection_id === $connection->getKey(), 404);
    }
}
