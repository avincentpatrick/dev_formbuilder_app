<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\Submissions\SubmissionPipeline;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The offline batch-replay request (Increment G8b, docs/offline-first-sync-design.md §5). Authorization is
 * the route's `ability:write:submissions` middleware + RLS. Only the coarse shape is asserted here — each
 * item's per-field validation is the shared {@see SubmissionPipeline}, one item at
 * a time, so a partial failure never rolls back its siblings. `client_submission_uuid` is the idempotency key
 * (a duplicate replay is a 200 no-op); `submitted_at` is accepted but informational — the server timestamp is
 * authoritative (architecture §4.2). Bounded to 100 items per request.
 */
final class SyncSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submissions' => ['present', 'array', 'max:100'],
            'submissions.*.form_version_id' => ['required', 'uuid'],
            'submissions.*.client_submission_uuid' => ['required', 'uuid'],
            'submissions.*.answers' => ['present', 'array'],
            'submissions.*.locale' => ['nullable', 'string', 'max:10'],
            'submissions.*.device_id' => ['nullable', 'string', 'max:100'],
            'submissions.*.app_version' => ['nullable', 'string', 'max:20'],
            'submissions.*.submitted_at' => ['nullable', 'date'],
        ];
    }

    /**
     * The validated batch, re-indexed as a plain list.
     *
     * @return list<array<string, mixed>>
     */
    public function submissions(): array
    {
        $items = $this->input('submissions', []);

        return is_array($items) ? array_values($items) : [];
    }
}
