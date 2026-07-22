<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditResource;
use App\Models\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only access to the tenant's audit log over /api/v1 (H4, audit-compliance-logging-spec.md §3). Read
 * only by construction: the ledger is append-only (there is no write route, and the `append_only` RLS shape
 * would deny one anyway). RLS scopes every row to the calling tenant; the route additionally gates on
 * `ability:read:audit_log` (the token) + `can:viewAny,Audit` (the acting user's Owner/Admin permission).
 */
final class AuditApiController extends Controller
{
    /**
     * List audit rows newest-first, optionally filtered by target, event type, actor, or date range.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 100);

        $audits = Audit::query()
            ->when($request->filled('auditable_type'), fn ($q) => $q->where('auditable_type', $request->string('auditable_type')))
            ->when($request->filled('auditable_id'), fn ($q) => $q->where('auditable_id', $request->string('auditable_id')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')))
            ->orderByDesc('id') // uuidv7 ⇒ newest-first; a stable, unique cursor key
            ->cursorPaginate($limit);

        return response()->json([
            'data' => AuditResource::collection($audits->getCollection()),
            'meta' => [
                'next_cursor' => $audits->nextCursor()?->encode(),
                'has_more' => $audits->hasMorePages(),
            ],
        ]);
    }
}
