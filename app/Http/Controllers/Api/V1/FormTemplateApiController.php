<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreFormTemplateRequest;
use App\Http\Resources\Api\V1\FormTemplateResource;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Form templates over /api/v1 (Increment G9a). `index` lists the gallery RLS returns to the token's tenant
 * (platform + own), "popular" first; `store` saves a named version as a tenant-owned private template. Read
 * gates on `ability:read:forms`; write on `ability:write:forms` PLUS an in-controller `can:view` on the source
 * form (the version is a body param, not a bound model, so the FormPolicy gate runs here rather than as `can:`
 * route middleware). RLS scopes every query to the token's tenant.
 */
final class FormTemplateApiController extends Controller
{
    public function __construct(private readonly TemplateService $templates) {}

    /**
     * Cursor-paginated template gallery (platform templates + the tenant's own), most-used first.
     *
     * `usage_count` leads the sort AND is encoded into the cursor, so a template instantiated mid-walk can
     * cross a page boundary and be seen twice or skipped. Accepted: this is a browse-and-pick gallery, not a
     * sync feed (contrast the sync endpoints, which order on immutable keys precisely because they must not
     * drop rows). A caller needing a stable walk should sort on `id`.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 25), 1), 100);

        $templates = FormTemplate::query()
            ->withoutBlueprint()
            ->orderByDesc('usage_count')
            ->orderByDesc('id')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => FormTemplateResource::collection($templates->getCollection()),
            'meta' => [
                'next_cursor' => $templates->nextCursor()?->encode(),
                'has_more' => $templates->hasMorePages(),
            ],
        ]);
    }

    /**
     * Save a form version as a tenant-owned private template. Authorizes `view` on the source form (a Form
     * Editor may only template a form they can edit), then snapshots the version into a new template row.
     */
    public function store(StoreFormTemplateRequest $request): FormTemplateResource
    {
        /** @var User $user */
        $user = $request->user();

        $version = FormVersion::query()->whereKey($request->validated('form_version_id'))->firstOrFail();

        Gate::forUser($user)->authorize('view', $version->form);

        $template = $this->templates->saveAsTemplate($version, $user, $request->validated());

        return FormTemplateResource::make($template);
    }
}
