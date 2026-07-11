<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Services\Submissions\SubmissionReviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The review-transition request (Increment F7). Authorization is the `can:review,submission` route
 * middleware; this validates the transition itself: which `action`, plus a required reason when returning.
 * The legality of the transition against the submission's current status is enforced in
 * {@see SubmissionReviewService}.
 */
final class ReviewSubmissionRequest extends FormRequest
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
            'action' => ['required', Rule::in(['under_review', 'approve', 'return', 'archive'])],
            'returned_reason' => ['required_if:action,return', 'nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function action(): string
    {
        return (string) $this->input('action');
    }

    public function reason(): string
    {
        return (string) $this->input('returned_reason', '');
    }

    public function remarks(): ?string
    {
        $remarks = $this->input('remarks');

        return is_string($remarks) && $remarks !== '' ? $remarks : null;
    }
}
