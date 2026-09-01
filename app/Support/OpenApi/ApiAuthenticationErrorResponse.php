<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\AuthenticationExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Type\Type;

/**
 * The 401 component, documenting the body `/api/v1` actually returns (M56).
 *
 * ⛔ `toResponse()` IS THE ONLY OVERRIDE, AND THAT IS THE DESIGN. `shouldHandle()` and `reference()`
 * are inherited verbatim from the vendor class:
 *
 *  · `shouldHandle()` — so the match predicate cannot drift from Scramble's. A re-implementation would
 *    be a second copy of a rule the package already owns.
 *  · `reference()` — so the published component KEY stays `AuthenticationException`. It returns the
 *    exception FQCN and `Components::uniqueSchemaName()` shortens it with `class_basename()`, which is
 *    what keeps every `$ref` in `openapi.json` byte-identical across this change. Overriding it would
 *    rename the generated type in every client already built against the contract, for no gain: the
 *    defect is the BODY.
 *
 * The description is deliberately the vendor's own string, so the diff on this component is the schema
 * and nothing else.
 *
 * @see ApiErrorEnvelope for the shape and why it is built in one place.
 */
final class ApiAuthenticationErrorResponse extends AuthenticationExceptionToResponseExtension
{
    /**
     * @return Response
     */
    public function toResponse(Type $type)
    {
        return Response::make(401)
            ->setDescription('Unauthenticated')
            ->setContent('application/json', Schema::fromType(
                // No `details`: the closure that renders this sends none, and a documented-but-absent
                // key is the same class of untruth this increment exists to remove.
                ApiErrorEnvelope::schema(
                    'Stable machine-readable code: `unauthenticated`.',
                    messageExample: 'Authentication is required.',
                )
            ));
    }
}
