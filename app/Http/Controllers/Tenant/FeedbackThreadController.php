<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Feedback\FeedbackLifecycleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\FeedbackThreadRequest;
use App\Models\FeedbackComment;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Policies\FeedbackReportPolicy;
use App\Services\Feedback\FeedbackLifecycle;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The testers' door into a feedback report (M179, `R-129c44c3`, `D151`): **It works**, **Still a problem**, and
 * a comment. Thin, like its sibling {@see FeedbackController}: who may act is {@see FeedbackReportPolicy} (route
 * middleware), what a move does is {@see FeedbackLifecycle}.
 *
 * A move the lifecycle refuses — the report is not Resolved any more, someone already confirmed it — comes back
 * as an error on the page (`feedback`), never a 500: the tester raced another tester or the support team, and
 * the page's next load shows them why.
 */
final class FeedbackThreadController extends Controller
{
    public function __construct(private readonly FeedbackLifecycle $lifecycle) {}

    /** **It works** — a Resolved report becomes Confirmed working. */
    public function confirm(FeedbackThreadRequest $request, FeedbackReport $feedbackReport): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->lifecycle->confirm($feedbackReport, $this->member($request), $request->body()),
            'Thanks — marked as working.',
        );
    }

    /** **Still a problem** — back to Ongoing, with a comment (required) and an optional picture. */
    public function reopen(FeedbackThreadRequest $request, FeedbackReport $feedbackReport): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->lifecycle->reopen($feedbackReport, $this->member($request), $request->body(), $request->screenshot()),
            'Thanks — the team will look at it again.',
        );
    }

    /** A comment on any report the member may see, in any status. */
    public function comment(FeedbackThreadRequest $request, FeedbackReport $feedbackReport): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->lifecycle->comment($feedbackReport, $this->member($request), $request->body(), $request->screenshot()),
            'Comment added.',
        );
    }

    /**
     * A comment's picture — read under `feedback.view`, as every feedback screenshot is (ADR-0015 §D9). The
     * comment is bound scoped to its report, so a comment id from another report is a 404.
     */
    public function commentScreenshot(FeedbackReport $feedbackReport, FeedbackComment $comment): StreamedResponse
    {
        $attachment = $comment->screenshot;

        abort_if($attachment === null, 404);
        abort_unless($attachment->virus_scan_status->servable(), 409, 'This file is not yet available.');

        // `inline` and `nosniff`, for the reason FeedbackController::screenshot() gives: the type is raster-only
        // and re-sniffed when stored.
        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }

    private function member(FeedbackThreadRequest $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function attempt(Closure $move, string $done): RedirectResponse
    {
        try {
            $move();
        } catch (FeedbackLifecycleException $refused) {
            return back()->withErrors(['feedback' => $refused->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => $done]);
    }
}
