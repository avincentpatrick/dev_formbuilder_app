<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\NotFoundExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Type\Type;

/**
 * The 404 component, documenting the body `/api/v1` actually returns (M56).
 *
 * ⛔ `toResponse()` is the only override — see `ApiAuthenticationErrorResponse` for why `shouldHandle()`
 * and `reference()` are inherited, and why keeping the `ModelNotFoundException` key matters.
 *
 * ⚠️ THE KEY NAMES AN ELOQUENT EXCEPTION AND THE ROUTES MOSTLY DO NOT THROW ONE. The inherited
 * predicate matches `RecordsNotFoundException` OR `NotFoundHttpException`, so a bare `abort(404)`
 * lands here too. Both render through the same closure and both answer `not_found`, so one body is
 * correct for every referrer — but the key is a Scramble artefact, not a statement about the cause.
 *
 * ⚠️ NOT EVERY 404 ON THIS SURFACE CARRIES THIS CODE: `unknown_connector_provider` and
 * `tenant_not_identified` are also 404s. Neither is inferable from a route or a controller return, so
 * neither appears in the document at all — that is the separate, still-open "undocumented status" row,
 * and widening `code` here to hint at codes no operation references would document a promise this
 * component cannot keep.
 */
final class ApiNotFoundErrorResponse extends NotFoundExceptionToResponseExtension
{
    /**
     * @return Response
     */
    public function toResponse(Type $type)
    {
        return Response::make(404)
            ->setDescription('Not found')
            ->setContent('application/json', Schema::fromType(
                ApiErrorEnvelope::schema(
                    'Stable machine-readable code: `not_found`.',
                    messageExample: 'The requested resource was not found.',
                )
            ));
    }
}
