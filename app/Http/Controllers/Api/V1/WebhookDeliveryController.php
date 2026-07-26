<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\WebhookEndpointStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEndpointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delivery-observability log + manual redeliver for one webhook endpoint (H13a/H13b;
 * webhook-integration-design.md §5). The log is cursor-paginated, newest first. The bound `{webhookEndpoint}`
 * is RLS-scoped to the tenant; every route carries `ability:manage:webhooks` + a policy `can:` gate +
 * `feature:webhooks`.
 */
final class WebhookDeliveryController extends Controller
{
    public function __construct(private readonly WebhookEndpointService $service) {}

    /** Cursor-paginated delivery-observability log for one webhook endpoint, newest first. */
    public function index(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 100);

        $deliveries = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $webhookEndpoint->getKey())
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
     * Re-enter the delivery pipeline for one delivery (re-runs SSRF; does not re-consume the monthly quota).
     * The delivery must belong to the bound endpoint, which must be active — a paused endpoint is re-enabled
     * (and its breaker reset) via a status update first.
     */
    public function redeliver(WebhookEndpoint $webhookEndpoint, WebhookDelivery $webhookDelivery): JsonResponse
    {
        abort_unless($webhookDelivery->webhook_endpoint_id === $webhookEndpoint->getKey(), 404);
        abort_if($webhookEndpoint->status !== WebhookEndpointStatus::Active, 409, 'Re-enable the endpoint before redelivering.');

        $this->service->redeliver($webhookDelivery);

        return response()->json(['data' => ['status' => 'queued']], 202);
    }
}
