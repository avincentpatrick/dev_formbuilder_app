<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\HttpExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Every OTHER documented error status — the inline ones a bare `abort()` produces (M56).
 *
 * ⛔ THIS IS THE HALF THE BACKLOG ROW DID NOT NAME, AND IT IS A THIRD OF THE DEFECT. The row named the
 * four `components.responses` entries. Scramble ships FIVE exception extensions, and the fifth emits
 * its body INLINE with no component to notice — which is why three further responses had been wrong in
 * exactly the same way, unreferenced by any component and so invisible to a fix scoped to components:
 *
 *   · 422 POST   /domains/{domain}/primary                                    (`abort_unless(…, 422)`)
 *   · 422 DELETE /domains/{domain}                                            (`abort_unless(…, 422)`)
 *   · 409 POST   /webhooks/{endpoint}/deliveries/{delivery}/redeliver         (`abort_if(…, 409, '…')`)
 *
 * ⛔ THE STATUS TABLE IS NOT REIMPLEMENTED HERE, DELIBERATELY. `parent::toResponse()` owns the mapping
 * from an inferred exception type to a status code, description and message example — including the
 * `templateTypes` index magic its own comment apologises for. This override calls it, keeps everything
 * it decided, and replaces ONLY the schema. Copying that table would make this class a second
 * description of a rule the package owns, which is the shape of the defect being closed.
 *
 * ⚠️ AND `error.code` IS A DESCRIBED STRING RATHER THAN A PER-STATUS MATCH. It would be easy to mirror
 * `bootstrap/app.php`'s closure chain here — 419 to `csrf_token_mismatch`, 5xx to `server_error`, the
 * rest to `request_failed` — and that would put a SECOND copy of the render logic in the documentation
 * layer, drifting the moment a closure is added. The generic arm documents the envelope; the specific
 * arms name their codes because they have exactly one cause each.
 *
 * ⚠️ WHY THIS DOES NOT STEAL THE 403s AND 404s the specific arms own: selection is
 * `->reverse()->first()`, so precedence is registration order read backwards, and this class is
 * registered in the vendor's own position — before `ApiNotFoundErrorResponse`. See
 * `AppServiceProvider::boot()`, where the order is asserted rather than assumed.
 */
final class ApiHttpErrorResponse extends HttpExceptionToResponseExtension
{
    /**
     * @return Response|null
     */
    public function toResponse(Type $type)
    {
        $response = parent::toResponse($type);

        // The parent returns null when it cannot infer a status code from the type. Documenting a body
        // for a response we cannot give a status would be worse than documenting nothing.
        if (! $response instanceof Response) {
            return null;
        }

        return Response::make($response->code)
            ->setDescription($response->description)
            ->setContent('application/json', Schema::fromType(
                ApiErrorEnvelope::schema(
                    'Stable machine-readable code for this refusal. The `/api/v1` surface answers every error with this envelope — see `docs/api-specification.md` §2.3.',
                    messageExample: $this->messageExampleFrom($response),
                )
            ));
    }

    /**
     * Recover the example the parent hung on its `message` property, so an `abort($code, 'why')` keeps
     * its reason in the document — now under `error.message`, where the response actually carries it.
     *
     * Read back out of the parent's schema rather than re-derived from `$type->templateTypes`: the
     * index that holds it is a vendor internal, and one copy of that knowledge is enough.
     */
    private function messageExampleFrom(Response $response): ?string
    {
        $schema = $response->content['application/json'] ?? null;

        if (! $schema instanceof Schema || ! $schema->type instanceof OpenApiObjectType) {
            return null;
        }

        if (! $schema->type->hasProperty('message')) {
            return null;
        }

        $example = $schema->type->getProperty('message')->example;

        // Anything else is Scramble's MissingValue sentinel, meaning `abort()` carried no message.
        return is_string($example) ? $example : null;
    }
}
