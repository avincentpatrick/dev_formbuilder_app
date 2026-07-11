<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Submissions\SubmissionReviewException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Submissions\ReviewSubmissionRequest;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Submissions\SubmissionReviewService;
use Illuminate\Http\RedirectResponse;

/**
 * Submission review transitions (Increment F7) — approve / return / mark-under-review / archive, driven from
 * the inbox detail view. Authorization is `can:review,submission` ({@see SubmissionPolicy::review()}).
 * The service enforces the legal source-state and surfaces an illegal transition as an error toast, mirroring
 * {@see FormPublishController}.
 */
final class SubmissionReviewController extends Controller
{
    public function update(ReviewSubmissionRequest $request, Submission $submission, SubmissionReviewService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action = $request->action();

        try {
            match ($action) {
                'under_review' => $service->markUnderReview($submission, $user, $request->remarks()),
                'approve' => $service->approve($submission, $user, $request->remarks()),
                'return' => $service->returnToRespondent($submission, $user, $request->reason(), $request->remarks()),
                default => $service->archive($submission, $user, $request->remarks()),
            };
        } catch (SubmissionReviewException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => $this->message($action)]);
    }

    private function message(string $action): string
    {
        return match ($action) {
            'under_review' => 'Submission moved to under review.',
            'approve' => 'Submission approved.',
            'return' => 'Submission returned to the respondent.',
            default => 'Submission archived.',
        };
    }
}
