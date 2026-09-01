<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\AuthorizationExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\Type;

/**
 * The 403 component, documenting the body `/api/v1` actually returns (M56).
 *
 * ⛔ `toResponse()` is the only override — see `ApiAuthenticationErrorResponse` for why `shouldHandle()`
 * and `reference()` are inherited. This is the most-referenced component on the surface, which is why
 * the `$ref` names had to stay put.
 *
 * ⚠️ TWO CODES REACH THIS COMPONENT, WHICH IS WHY `code` IS A DESCRIBED STRING AND NOT A `const`. The
 * AccessDeniedHttpException closure splits on the previous exception: a missing Sanctum token ability
 * keeps its `MissingAbilityException` and answers `insufficient_ability` with the abilities it wanted,
 * while an ordinary policy refusal answers `forbidden` with no details. Pinning either one would make
 * the component wrong for the other half of its referrers — a subtler version of the defect this
 * increment closes.
 *
 * ⚠️ `module_disabled` IS A THIRD 403 CODE AND DELIBERATELY DOES NOT COME THROUGH HERE. It is attached
 * per-route and inline by `ModuleDisabledResponseExtension` (M54), because the gate lives on the route
 * rather than in the action; that extension already refuses to overwrite an existing 403, so the two
 * mechanisms cannot collide.
 */
final class ApiAuthorizationErrorResponse extends AuthorizationExceptionToResponseExtension
{
    /**
     * @return Response
     */
    public function toResponse(Type $type)
    {
        // Present ONLY on `insufficient_ability`, so optional — the ordinary `forbidden` refusal passes
        // no details at all and `ApiErrorResponse` omits the key rather than nulling it.
        $details = (new OpenApiTypes\ObjectType)
            ->addProperty(
                'missing',
                (new OpenApiTypes\ArrayType)
                    ->setItems(new OpenApiTypes\StringType)
                    ->setDescription('Sent with `insufficient_ability`: the token abilities the route required and this token does not hold.')
            );

        return Response::make(403)
            ->setDescription('Authorization error')
            ->setContent('application/json', Schema::fromType(
                ApiErrorEnvelope::schema(
                    'Stable machine-readable code: `forbidden` for a policy refusal, or `insufficient_ability` when the API token lacks a required ability.',
                    details: $details,
                    messageExample: 'You are not authorized to perform this action.',
                )
            ));
    }
}
