<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Concerns\ReadsGuestShareToken;
use App\Http\Middleware\EstablishGuestTenantContext;
use App\Models\Form;
use App\Models\FormVersion;
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
}
