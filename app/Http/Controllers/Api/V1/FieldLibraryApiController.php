<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreFieldLibraryRequest;
use App\Http\Resources\Api\V1\FieldLibraryResource;
use App\Models\FieldLibrary;
use App\Models\User;
use App\Services\Forms\BlueprintValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Question library over /api/v1 (Increment G9b). `index` lists the items RLS returns to the token's tenant
 * (platform + own, active only), "popular" first; `store` authors a tenant-owned reusable question from
 * explicit attributes. Read gates on `ability:read:forms`, write on `ability:write:forms` — a library item is
 * form-authoring metadata, not a bound model, so there is no `can:` route gate (mirroring form-templates).
 * RLS scopes every query to the token's tenant. `default_config` / `default_validations` are stored verbatim;
 * their shape is re-validated when the item is inserted into a draft ({@see FieldLibrary::toBlueprintField}
 * → {@see BlueprintValidator::validateField}).
 */
final class FieldLibraryApiController extends Controller
{
    /**
     * Cursor-paginated question library (platform items + the tenant's own, active only), most-used first.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 25), 1), 100);

        // Same mutable-sort-column caveat as the template gallery: `usage_count` leads the sort and is encoded
        // into the cursor, so an item inserted mid-walk can cross a page boundary. Accepted for a browse-and-pick
        // list (contrast the sync feed, which orders on immutable keys).
        $items = FieldLibrary::query()
            ->where('is_active', true)
            ->orderByDesc('usage_count')
            ->orderByDesc('id')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => FieldLibraryResource::collection($items->getCollection()),
            'meta' => [
                'next_cursor' => $items->nextCursor()?->encode(),
                'has_more' => $items->hasMorePages(),
            ],
        ]);
    }

    /**
     * Author a tenant-owned reusable question from explicit attributes. The model omits BelongsToTenant, so
     * `tenant_id` is set explicitly from the token's tenant context; the NOT-NULL jsonb columns default to
     * `{}` / `[]` when omitted (never null).
     */
    public function store(StoreFieldLibraryRequest $request): FieldLibraryResource
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        $item = FieldLibrary::create([
            'tenant_id' => TenantContext::currentTenantId(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? null,
            'field_type' => $validated['field_type'],
            'default_label' => $validated['default_label'],
            'default_hint' => $validated['default_hint'] ?? null,
            'default_config' => $validated['default_config'] ?? [],
            'default_validations' => $validated['default_validations'] ?? [],
            'created_by' => $user->id,
            'is_active' => true,
            'usage_count' => 0,
        ]);

        return FieldLibraryResource::make($item);
    }
}
