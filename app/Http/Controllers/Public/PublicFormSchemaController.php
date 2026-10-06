<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Http\Middleware\EstablishGuestTenantContext;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Forms\ChoiceListMaterializer;
use App\Services\Forms\LinkedChoiceService;
use App\Services\Submissions\PublicFormPresenter;
use App\Support\Api\ApiErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the pinned published schema a guest renders (Increment F5). Tenant context is already set from
 * the verified share token by {@see EstablishGuestTenantContext}, so the form/version
 * are resolved from the token payload under RLS. The `allow_guest_submissions` re-check here is the coarse
 * revocation lever a stateless token otherwise lacks — flipping the flag off invalidates every outstanding
 * link immediately, before expiry. The pinned version is returned even once superseded (the `formVersionGuard`
 * SELECT policy is status-agnostic), so a guest who loaded the form keeps a consistent schema to answer.
 */
final class PublicFormSchemaController extends Controller
{
    use ReadsGuestShareToken;

    /**
     * Fetch the pinned published form schema for a guest share token.
     *
     * @unauthenticated
     */
    public function show(Request $request, PublicFormPresenter $presenter): JsonResponse
    {
        $token = $this->shareToken($request);

        $form = Form::query()->whereKey($token->formId)->firstOrFail();

        if (! $form->allow_guest_submissions) {
            return ApiErrorResponse::make(403, 'guest_disabled', 'Guest submissions are disabled for this form.');
        }

        $version = FormVersion::query()->whereKey($token->formVersionId)->firstOrFail();

        return response()->json(['data' => $presenter->present($form, $version)]);
    }

    /**
     * Fetch the choices this shared form takes from another form's answers.
     *
     * One list per question that takes its choices from another form, keyed by that question's key. A list the
     * other form no longer shares comes back empty with `available: false`. `stamp` changes whenever the list
     * does. `version` must be the share token's own form version; any other request answers the same 404.
     *
     * @unauthenticated
     */
    public function linkedChoices(Request $request, string $shareToken, string $version, LinkedChoiceService $links): JsonResponse
    {
        $token = $this->shareToken($request);
        $form = Form::query()->whereKey($token->formId)->first();

        if ($form === null || ! $form->allow_guest_submissions || $version !== $token->formVersionId) {
            return ApiErrorResponse::make(404, 'linked_choices_not_found', 'These choices are not available.');
        }

        $pinned = FormVersion::query()->whereKey($token->formVersionId)->first();
        if ($pinned === null) {
            return ApiErrorResponse::make(404, 'linked_choices_not_found', 'These choices are not available.');
        }

        return response()
            ->json(['data' => ['version_id' => $pinned->id, 'lists' => $links->listsFor($form, $pinned)]])
            ->header('Cache-Control', 'private, no-cache');
    }

    /**
     * Fetch the choice lists this shared form's cascading questions take from uploaded CSV files.
     *
     * One entry per cascading question whose levels take their choices from CSV files, keyed by the question's key:
     * its level keys in order, and each choice as `[level index, value, label, parent]` with the parent null at the
     * first level. The lists are frozen with the form version, so a response never changes for a given `version`, which
     * must be the share token's own; any other request answers the same 404.
     *
     * @unauthenticated
     */
    public function choiceLists(Request $request, string $shareToken, string $version): JsonResponse
    {
        $token = $this->shareToken($request);
        $form = Form::query()->whereKey($token->formId)->first();

        if ($form === null || ! $form->allow_guest_submissions || $version !== $token->formVersionId) {
            return ApiErrorResponse::make(404, 'choice_lists_not_found', 'These choices are not available.');
        }

        $pinned = FormVersion::query()->whereKey($token->formVersionId)->first();
        if ($pinned === null) {
            return ApiErrorResponse::make(404, 'choice_lists_not_found', 'These choices are not available.');
        }

        return response()
            ->json(['data' => ['version_id' => $pinned->id, 'lists' => ChoiceListMaterializer::listsForBrowser($pinned)]])
            ->header('Cache-Control', 'private, max-age=86400');
    }
}
