<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConnectionResource;
use App\Models\Connection;
use App\Policies\ConnectionPolicy;
use App\Services\Connectors\ConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Read + disconnect the tenant's native-connector OAuth grants (ADR-0009, H15a). Thin: the list is a
 * cursor-paginated RLS-scoped query, and disconnect delegates to {@see ConnectionService}.
 *
 * There is deliberately NO create/update here. A grant can only come into existence through the interactive
 * OAuth flow (a browser redirect to the provider's consent screen and back through the central-domain
 * callback), so a bearer-token API can start nothing and must never accept a token as input — accepting one
 * would be an unauthenticated path to writing a credential the platform then acts with.
 *
 * Every route carries `ability:manage:integrations` + a {@see ConnectionPolicy} `can:` gate +
 * `feature:native_connectors`. RLS scopes every query and the `{connection}` binding to the tenant.
 */
final class ConnectionController extends Controller
{
    public function __construct(private readonly ConnectionService $service) {}

    /** Cursor-paginated list of the tenant's connections, newest first. */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 100);

        $connections = Connection::query()
            ->latest('created_at')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => ConnectionResource::collection($connections->getCollection()),
            'meta' => [
                'next_cursor' => $connections->nextCursor()?->encode(),
                'has_more' => $connections->hasMorePages(),
            ],
        ]);
    }

    /** One connection: which workspace, which scopes, and whether the grant still works. */
    public function show(Connection $connection): ConnectionResource
    {
        return new ConnectionResource($connection);
    }

    /**
     * Disconnect: destroy the stored credential and pause the rules that used it. Local destruction is
     * unconditional — a provider-side revoke is attempted best-effort elsewhere and its outcome never gates
     * this, since an unreachable provider is exactly when a tenant most wants the token gone.
     */
    public function destroy(Connection $connection): Response
    {
        $this->service->disconnect($connection);

        return response()->noContent();
    }
}
