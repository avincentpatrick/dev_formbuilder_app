<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Services\Submissions\SubmissionAnswerEditService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The post-submission answer-edit request (Increment I9c). Authorization is the `can:update,submission` route
 * middleware; this request only asserts the coarse shape — `answers` is a key⇒value map. All real validation
 * (per-field coercion, required/relevance, constraints) runs in
 * {@see SubmissionAnswerEditService::edit()} through the same Stage-1/Stage-3 pair every other channel uses,
 * so it is deliberately NOT duplicated here. Same posture as {@see EncodeSubmissionRequest}.
 *
 * ⚠️ NO `client_submission_uuid`, and its absence is deliberate rather than an oversight. That field exists to
 * IDENTIFY a submission being created or resumed; this route already has the submission bound in the URL and
 * gated by the policy. Accepting one would add a second, caller-chosen identifier to a request that already
 * has an authorized one — precisely the two-independent-inputs shape that produced I9b's cross-form draft
 * hole.
 *
 * ⚠️ AND NO `status` / `remarks` / `validated_by`. An edit changes ANSWERS. The only lifecycle movement it
 * causes is the approved → under_review demotion, which is a CONSEQUENCE the service derives, never something
 * the client asks for — accepting a status here would hand any Form Editor a way to approve their own
 * submission without holding `submissions.review.*` at all.
 */
final class EditSubmissionAnswersRequest extends FormRequest
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
            // `present` rather than `required`: clearing every answer on a form is a legitimate correction,
            // and `required` rejects an empty array. Stage 3 still enforces the form's own required fields,
            // so an empty document is refused there — with per-field messages — rather than here with one
            // generic one.
            'answers' => ['present', 'array'],
            // The optimistic-concurrency token (the checksum the page was rendered from).
            //
            // ⚠️ `present` + `nullable`, NOT `required`. The KEY must be sent — a body without it is a page
            // that predates this field or a hand-rolled request, and both are exactly the callers whose
            // blind whole-document write would revert someone else's correction. But the VALUE may
            // legitimately be null: `answers_content_checksum` is nullable, so a submission written before
            // the pipeline stamped one has no baseline to send, and `required` would make those rows
            // permanently uneditable. Null then matches null in the service's comparison, which is the
            // correct reading — "the document had no checksum when I loaded it, and it still has none".
            'baseline' => ['present', 'nullable', 'string'],
        ];
    }

    public function baseline(): ?string
    {
        $baseline = $this->input('baseline');

        return is_string($baseline) ? $baseline : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $answers = $this->input('answers', []);

        return is_array($answers) ? $answers : [];
    }
}
