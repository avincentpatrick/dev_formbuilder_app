<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Document the `409` a submission route can actually answer (M67).
 *
 * ⛔ WHY THE ROW'S OWN PRESCRIPTION COULD NOT BE FOLLOWED. The backlog row asked for *"a `@response`
 * annotation per cause"*. Scramble v0.13.30 has no such annotation — checked in the installed vendor,
 * not assumed. What it does have is a two-part seam, and both halves are needed:
 *
 *   1. `Infer\Handler\PhpDocHandler::leave()` collects every `@throws` tag on a class method into
 *      `$methodType->exceptions`, and `OperationExtensions\ResponseExtension` merges that list into the
 *      operation's responses. That is how a SERVICE-thrown refusal becomes visible at all: Scramble
 *      infers from the CONTROLLER, and `SubmissionPromoteController::store()` throws nothing itself.
 *   2. This class, which turns the resulting type into a response. Without it the type is inferred and
 *      then silently dropped, because no registered extension claims it.
 *
 * ⚠️ IT IS DELIBERATELY GENERAL RATHER THAN A PATCH ON THE PROMOTE ROUTE. Any submission action that
 * declares one of these two `@throws` documents its 409 without anyone remembering to — which is the
 * defect class the row was one instance of. The row named one route; the same pair of exceptions is
 * raised by the draft, submit and edit channels, whose controllers return their envelopes inline and so
 * already document theirs.
 *
 * ⚠️ NO `reference()` METHOD, AND THAT IS A DECISION. `TypeTransformer` calls `reference()` only when it
 * exists (`method_exists`), and a class that defines one registers a shared `components/responses` entry.
 * Emitting inline instead means this extension CANNOT alter any existing `$ref` — the property M56 had to
 * work to preserve across 113 of them, kept here for free by declining the feature.
 *
 * ⛔ A STATED LIMIT, WRITTEN HERE RATHER THAN DISCOVERED LATER: THIS EXTENSION IS KEYED ON THE EXCEPTION
 * CLASS AND CANNOT KNOW WHICH OF ITS CAUSES A GIVEN ROUTE RAISES. `SubmissionConflictException` carries
 * four; `promote()` raises exactly one of them (`draft_conflict`). Documenting the family's whole code set
 * per route would OVERSTATE what that route can return, so the description says plainly that an operation
 * raises a subset. Narrowing it per route would need a cause-level annotation Scramble has no seam for —
 * filed rather than faked.
 *
 * ⚠️ THE BODY IS THE `/api/v1` ENVELOPE, DESCRIBED ONCE. {@see ApiErrorEnvelope} owns that shape; five
 * other extensions read it from there for the reason its docblock gives. `details` is deliberately not
 * documented: `bootstrap/app.php` renders both of these causes through the two-argument
 * `ApiErrorResponse::make()`, which omits the key entirely rather than nulling it.
 */
final class SubmissionRefusalResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && (
                $type->isInstanceOf(SubmissionConflictException::class)
                || $type->isInstanceOf(SubmissionException::class)
            );
    }

    /**
     * @return Response|null
     */
    public function toResponse(Type $type)
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        // ⚠️ THE TWO CLASSES ARE NOT INTERCHANGEABLE AND THE `code` DESCRIPTION IS THE WHOLE VALUE OF
        // THIS RESPONSE. An integrator branches on `error.code`, so a 409 documented as "a string" tells
        // them the status exists and nothing they can act on. The codes below are read off the exception
        // factories rather than copied from a document — see the two classes' own docblocks, which argue
        // each split.
        $isConflict = $type->isInstanceOf(SubmissionConflictException::class);

        $codes = $isConflict
            ? 'One of this refusal family: `draft_conflict` (another device wrote this draft between the '
                .'read and this call — re-read the draft and keep the same `client_submission_uuid`), '
                .'`draft_already_finalized` (the row is no longer a draft and can never be saved as one '
                .'again), `submission_uuid_claimed` (the identifier is already spent in this workspace on '
                .'a row outside your scope — mint a fresh one), or `submission_conflict` (a copy with '
                .'materially different answers is already stored under this identifier). ⚠️ A given '
                .'operation raises a SUBSET of these — the set is a property of the refusal family, not of '
                .'any one route — so treat an unrecognised code as a refusal that must not be retried.'
            : 'Always `submission_version_superseded` — the form version this submission is pinned to is '
                .'no longer published, so finalizing it would commit against a schema the form has moved past.';

        $description = $isConflict
            ? 'The request conflicts with the stored state of this submission. The causes are separate refusals with separate remedies: branch on `error.code`, never on the message.'
            : 'The form version this submission is pinned to is no longer published.';

        return Response::make(409)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType(
                ApiErrorEnvelope::schema($codes)
            ));
    }
}
