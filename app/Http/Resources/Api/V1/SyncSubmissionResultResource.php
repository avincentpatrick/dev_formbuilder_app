<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

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
        /** @var array<string, mixed> $result */
        $result = $this->resource;

        return [
            'client_submission_uuid' => $result['client_submission_uuid'],
            'status' => $result['status'],
            'submission' => $result['submission'],
            'error' => $result['error'],
        ];
    }
}
