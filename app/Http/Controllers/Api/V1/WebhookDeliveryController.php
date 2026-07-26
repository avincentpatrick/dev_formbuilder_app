<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only delivery-observability log for one webhook endpoint (H13a; webhook-integration-design.md §5).
 * Cursor-paginated, newest first. The bound `{webhookEndpoint}` is RLS-scoped to the tenant; the route
 * carries `ability:manage:webhooks` + a `can:view` policy gate + `feature:webhooks`. Manual redeliver is H13b.
 */
final class WebhookDeliveryController extends Controller
{
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
}
