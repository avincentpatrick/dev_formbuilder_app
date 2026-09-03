<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Resources\Api\V1\SyncSubmissionResultResource;

/**
 * One item's outcome in a batch offline replay (Increment G8b; `POST /sync/submissions`).
 *
 * ⛔ WHY THIS IS AN ENUM AND NOT FIVE STRING LITERALS IN A CONTROLLER (M69). The batch surface answers
 * HTTP 200 for every item and carries the real outcome in this field, so it — not the status code — is
 * what an integrator branches on. It was typed a bare `string` in `openapi.json` for the whole life of
 * the endpoint, which tells a client generator nothing at all about what to switch over.
 *
 * The fix has to be a real backed enum rather than an annotation, and that is measured rather than
 * assumed: a full `@return array{status: 'created'|'duplicate'|…}` shape on
 * {@see SyncSubmissionResultResource::toArray()} moved the exported document
 * by EXACTLY NOTHING. `dedoc/scramble` infers `toArray()` from the STATEMENTS it can trace, and a
 * docblock is not a statement.
 *
 * ⚠️ THE CASES ARE THE CONTROLLER'S EXISTING WIRE VALUES, UNCHANGED. This enum was introduced to
 * DESCRIBE the five strings `SyncSubmissionController` already returned, never to renegotiate them —
 * so no consumer moves, and the backing values are the contract.
 *
 * ⚠️ `Error` IS NOT "the server broke". It is the catch-all for a per-item refusal that is neither a
 * validation failure nor a version conflict — an unknown form version, a deleted form, an
 * unauthorized form, a closed one. Every one of them still arrives inside a 200.
 */
enum SyncResultStatus: string
{
    /** The item was persisted on this replay. */
    case Created = 'created';

    /** Idempotent no-op: this `client_submission_uuid` had already been accepted. */
    case Duplicate = 'duplicate';

    /** Per-field validation failure; `error.details.fields` carries the field map. */
    case Invalid = 'invalid';

    /** The version this item was encoded against has been superseded. */
    case Conflict = 'conflict';

    /** Any other per-item refusal — see `error.code` for which. */
    case Error = 'error';

    /**
     * The controller's stored wire value, back as the case.
     *
     * ⛔ THIS EXISTS FOR THE EXPORTED CONTRACT, AND `from()` WAS TRIED FIRST. `BackedEnum::from()` is
     * declared as returning `static`, which `dedoc/scramble` does not resolve here — the property came
     * out as a bare `type: string` with no `enum`, exactly as it had before the enum existed. An
     * EXPLICIT `self` return type is a fact the inference engine can trace, and the schema then
     * becomes a `$ref` to this enum's component. Measured both ways; do not "simplify" it back.
     */
    public static function fromWire(string $value): self
    {
        return self::from($value);
    }
}
