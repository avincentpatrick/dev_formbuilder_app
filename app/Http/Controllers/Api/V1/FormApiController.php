<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\FormStatus;
use App\Enums\ResourceCapacity;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FormResource;
use App\Models\Form;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read access to the durable form record over /api/v1 (Increment E). Authorization is the route's
 * `ability:read:forms` + FormPolicy `can:` gates; RLS scopes every query to the token's tenant. Writes
 * (create/update/delete) and the other §7.1 resources extend this same pattern in Phase 1.
 */
final class FormApiController extends Controller
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * Cursor-paginated list of the tenant's non-archived forms (api-specification.md §2.2).
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 25), 1), 100);

        /** @var User $user */
        $user = $request->user();

        $forms = Form::query()
            // The API twin of the FormPresenter::list() scoping (Increment G10b). Fixing only the web
            // presenter would have moved the leak rather than closed it — this endpoint had the identical
            // "every non-archived tenant form to anyone holding read:forms" behaviour.
            ->when(
                ! $user->can('forms.edit.any'),
                fn ($q) => $q->whereIn('id', $this->grants->grantedFormIdsQuery($user, ResourceCapacity::Editor)),
            )
            ->where('status', '!=', FormStatus::Archived)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => FormResource::collection($forms->getCollection()),
            'meta' => [
                'next_cursor' => $forms->nextCursor()?->encode(),
                'has_more' => $forms->hasMorePages(),
            ],
        ]);
    }

    /**
     * Fetch a single form by id.
     */
    public function show(Form $form): FormResource
    {
        return FormResource::make($form);
    }
}
