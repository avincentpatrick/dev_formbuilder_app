<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWebhookEndpointRequest;
use App\Http\Requests\Api\V1\UpdateWebhookEndpointRequest;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEndpointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manage a tenant's webhook endpoints over /api/v1 (H13a). Authorization is the route's
 * `ability:manage:webhooks` + a WebhookEndpointPolicy `can:` gate + the `feature:webhooks` plan gate; RLS
 * scopes every query and binding to the token's tenant. Thin — orchestration lives in
 * {@see WebhookEndpointService}.
 */
final class WebhookEndpointController extends Controller
{
    public function __construct(private readonly WebhookEndpointService $service) {}

    /** Cursor-paginated list of the tenant's endpoints, newest first. */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 100);

        $endpoints = WebhookEndpoint::query()
            ->latest('created_at')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => WebhookEndpointResource::collection($endpoints->getCollection()),
            'meta' => [
                'next_cursor' => $endpoints->nextCursor()?->encode(),
                'has_more' => $endpoints->hasMorePages(),
            ],
        ]);
    }

    /** Create an endpoint. The plaintext signing secret is returned ONCE here, never again. */
    public function store(StoreWebhookEndpointRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $endpoint = $this->service->create($request->validated(), $user);

        return WebhookEndpointResource::make($endpoint)
            ->revealSecret()
            ->response()
            ->setStatusCode(201);
    }

    public function show(WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        return WebhookEndpointResource::make($webhookEndpoint);
    }

    public function update(UpdateWebhookEndpointRequest $request, WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        return WebhookEndpointResource::make(
            $this->service->update($webhookEndpoint, $request->validated()),
        );
    }

    public function destroy(WebhookEndpoint $webhookEndpoint): Response
    {
        $this->service->delete($webhookEndpoint);

        return response()->noContent();
    }
}
