<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\ValidationExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\Type;

/**
 * The 422 component, documenting the body `/api/v1` actually returns (M56).
 *
 * ⛔ `toResponse()` is the only override — see `ApiAuthenticationErrorResponse` for why `shouldHandle()`
 * and `reference()` are inherited.
 *
 * ⚠️ THE FIELD MAP MOVED, AND THAT IS THE HALF AN INTEGRATOR ACTUALLY FEELS. The old component put it
 * at a top-level `errors`, mirroring Laravel's native validation shape; the surface has always sent it
 * at `error.details.fields` (`ApiErrorResponse::make(422, 'validation_failed', …, ['fields' => …])`).
 * So a client generated from the contract looked for `errors` and found nothing on every failed
 * validation — not merely a missing wrapper, a missing feature.
 *
 * ⚠️ `details` IS REQUIRED HERE AND OPTIONAL EVERYWHERE ELSE, because this is the one status whose
 * every referrer sends it: the closure passes `fields` unconditionally. `api-specification.md` §2.3
 * says the same thing in prose.
 *
 * ⚠️ OTHER 422 CODES EXIST AND NONE OF THEM REFERENCE THIS COMPONENT. `publish_invalid`,
 * `form_rule_violated`, `submission_invalid`, `expression_error` and the scope-node codes are thrown
 * from services, which Scramble does not trace, so they are absent from the document entirely — the
 * separate, still-open "undocumented status" row. This component describes what its 25 referrers
 * return, which is a validator failure and nothing else.
 */
final class ApiValidationErrorResponse extends ValidationExceptionToResponseExtension
{
    /**
     * @return Response
     */
    public function toResponse(Type $type)
    {
        $details = (new OpenApiTypes\ObjectType)
            ->addProperty(
                'fields',
                (new OpenApiTypes\ObjectType)
                    ->setDescription('A detailed description of each field that failed validation, keyed by field name.')
                    ->additionalProperties((new OpenApiTypes\ArrayType)->setItems(new OpenApiTypes\StringType))
            )
            ->setRequired(['fields']);

        return Response::make(422)
            ->setDescription('Validation error')
            ->setContent('application/json', Schema::fromType(
                ApiErrorEnvelope::schema(
                    'Stable machine-readable code: `validation_failed`.',
                    details: $details,
                    detailsRequired: true,
                    messageExample: 'The given data was invalid.',
                )
            ));
    }
}
