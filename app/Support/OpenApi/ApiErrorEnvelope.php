<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;

/**
 * The ONE OpenAPI description of the `/api/v1` error envelope (M56).
 *
 * ⛔ WHY THIS CLASS EXISTS RATHER THAN FIVE COPIES OF THE SAME `ObjectType`. Five extensions document
 * this shape, and the defect M56 closes is precisely a SECOND description of an envelope drifting away
 * from the first: `openapi.json` published Laravel's `{ message }` for the whole life of the surface
 * while `ApiErrorResponse` returned `{ error: { code, message, details? } }`, and nothing compared
 * them. Building the schema in one place means the next status added cannot describe a different
 * envelope by accident.
 *
 * THE SHAPE IS `App\Support\Api\ApiErrorResponse::make()`, read from the code and not from a document:
 *
 *   { "error": { "code": <stable snake_case>, "message": <english>, "details": <optional object> } }
 *
 * ⚠️ `details` IS OMITTED, NOT NULLED, when a caller passes none — `ApiErrorResponse` only sets the key
 * when the argument is non-null. So a status that never carries details must not document the property
 * at all, and one that carries it only sometimes must not put it in `required`. That is why the caller
 * supplies the details type instead of this class inventing a generic one: on this surface the answer
 * differs per status (`fields` on a 422, `missing` on an ability 403, nothing on a 401 or 404), and a
 * blanket optional object would be a THIRD description of something the code already decides.
 */
final class ApiErrorEnvelope
{
    /**
     * @param  string  $codeDescription  what `error.code` can be for this status — the codes are the
     *                                   integration-consumer's branching key, so they are named rather
     *                                   than left as "a string".
     * @param  OpenApiTypes\ObjectType|null  $details  the `error.details` shape, or null when this status
     *                                                 never carries one.
     * @param  bool  $detailsRequired  true only when EVERY cause of this status sends details.
     * @param  string|null  $messageExample  a real message for this status, when one is known.
     */
    public static function schema(
        string $codeDescription,
        ?OpenApiTypes\ObjectType $details = null,
        bool $detailsRequired = false,
        ?string $messageExample = null,
    ): OpenApiTypes\ObjectType {
        $message = (new OpenApiTypes\StringType)
            ->setDescription('Human-readable English overview, for developer and support consumption. Never shown to a respondent — the public runtime renders its own localized copy keyed by `code`.');

        // An empty example is worse than none: it renders as `"example": ""` and reads like a contract
        // promise that the message can be blank. The generic HTTP arm hits this whenever `abort()` was
        // called without a message.
        if ($messageExample !== null && $messageExample !== '') {
            $message->example($messageExample);
        }

        $error = (new OpenApiTypes\ObjectType)
            ->addProperty(
                'code',
                (new OpenApiTypes\StringType)->setDescription($codeDescription)
            )
            ->addProperty('message', $message)
            ->setRequired(['code', 'message']);

        if ($details instanceof OpenApiTypes\ObjectType) {
            $error->addProperty('details', $details);

            if ($detailsRequired) {
                $error->addRequired(['details']);
            }
        }

        return (new OpenApiTypes\ObjectType)
            ->addProperty('error', $error)
            ->setRequired(['error']);
    }
}
