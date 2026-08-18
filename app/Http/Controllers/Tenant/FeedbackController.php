<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\ReadsKeywordFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreFeedbackRequest;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Services\Feedback\FeedbackPresenter;
use App\Services\Feedback\FeedbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * In-app feedback (PRD Feature #11) — the tenant's own half. Thin: every write decision is in
 * {@see FeedbackService}, every prop decision in {@see FeedbackPresenter}.
 *
 * `store()` is authorized by `can:feedback.submit` (every role holds it); `index()` and `screenshot()` by
 * `can:feedback.view` (Owner + Admin), the permission the role catalog has carried since Phase 0 with
 * nothing behind it until now.
 */
final class FeedbackController extends Controller
{
    use ReadsKeywordFilter;

    public function __construct(private readonly FeedbackPresenter $presenter) {}

    /** The workspace's own reports — read-only; the status lifecycle belongs to the platform console. */
    public function index(Request $request): Response
    {
        $status = $request->query('status');

        return Inertia::render('feedback/Index', $this->presenter->index([
            'status' => is_string($status) && $status !== '' ? $status : null,
            'q' => $this->keyword($request),
        ]));
    }

    public function store(StoreFeedbackRequest $request, FeedbackService $feedback): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $feedback->submit($request->payload(), $request->screenshot(), $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Thanks for the feedback']);
    }

    /**
     * Stream a report's screenshot.
     *
     * **Deliberately NOT routed through `GET /attachments/{attachment}`.** That route's policy is literally
     * `$user->can('submissions.view')` ({@see AttachmentPolicy::view()}) — a permission about
     * respondent data, which happens to be held by the same people but says something entirely different.
     * Reusing it would mean a workspace that revoked `submissions.view` from an Admin silently lost their
     * feedback screenshots too. One extra six-line action is cheaper than that coupling.
     *
     * Route-model binding does the tenant scoping (RLS hides another workspace's report, so a foreign id
     * 404s at binding); the servable check mirrors `AttachmentController::show()` — an unscanned or
     * condemned object is never served.
     */
    public function screenshot(FeedbackReport $feedbackReport): StreamedResponse
    {
        $attachment = $feedbackReport->screenshot;

        abort_if($attachment === null, 404);
        abort_unless($attachment->virus_scan_status->servable(), 409, 'This file is not yet available.');

        // Unconditionally `inline`, unlike AttachmentController::show()'s branch: that route serves a
        // generic `file_upload` and must force a download for anything that could execute in the origin.
        // Here `config('attachments.feedback_screenshot.accepted_types')` is raster-only and re-sniffed at
        // store time, so there is no SVG/HTML case to defend against. `nosniff` stays regardless.
        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }
}
