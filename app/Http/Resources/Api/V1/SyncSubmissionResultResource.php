<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\SyncResultStatus;
use App\Http\Controllers\Api\V1\SyncSubmissionController;
use Illuminate\Http\Request;

/**
 * One item's outcome in a batch offline replay (Increment G8b). Unlike the other resources this wraps a
 * plain result array (not an Eloquent model), built by {@see SyncSubmissionController}
 * per submission: `status` is one of `created` (fresh 201-equivalent) / `duplicate` (idempotent no-op) /
 * `invalid` (per-field 422) / `conflict` (version superseded) / `error` (unknown version); `submission` is the
 * persisted head on success, else null; `error` mirrors the /api/v1 `{code,message,details?}` envelope, else null.
 */
final class SyncSubmissionResultResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // ⛔ EVERY COMMENT IN THE RETURNED LITERAL IS PUBLISHED. Scramble lifts a leading comment on a
        // property into that property's `description` in `openapi.json` — a draft of this method put an
        // eight-line note about `when()` beside `details` and the whole thing, arrows and all, shipped
        // into the exported contract. Explanations belong here, above the literal; the array itself
        // carries only what an integrator should read.
        //
        // ⛔ `when()` ON `details`, NEVER `?? null`, AND THE DIFFERENCE IS THE WIRE FORMAT. `details` is
        // OMITTED rather than nulled when a refusal carries none — {@see \App\Support\OpenApi\ApiErrorEnvelope}
        // states that in terms for the envelope-level error, and this per-item one is the same shape a
        // layer down. A `?? null` would have published `details: null` on every refusal that has none and
        // then documented it as required. `ConditionallyLoadsAttributes::filter()` recurses into nested
        // arrays — read in the installed framework rather than assumed — so a MissingValue this deep is
        // still removed, and Scramble reads `when()` as an OPTIONAL property, which is the distinction
        // that was being lost.
        /** @var array<string, mixed> $result */
        $result = $this->resource;

        /** @var array<string, mixed>|null $submission */
        $submission = $result['submission'];
        /** @var array<string, mixed>|null $error */
        $error = $result['error'];

        return [
            'client_submission_uuid' => (string) $result['client_submission_uuid'],
            'status' => SyncResultStatus::fromWire((string) $result['status']),
            'submission' => $submission === null ? null : [
                'id' => (string) $submission['id'],
                'reference' => (string) $submission['reference'],
                'status' => (string) $submission['status'],
            ],
            'error' => $error === null ? null : [
                'code' => (string) $error['code'],
                'message' => (string) $error['message'],
                'details' => $this->when(
                    array_key_exists('details', $error),
                    fn (): mixed => $error['details'] ?? null,
                ),
            ],
        ];
    }
}
