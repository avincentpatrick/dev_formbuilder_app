<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Services\Feedback\FeedbackConsolePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The platform support console for in-app feedback (Increment I7a) — PRD Feature #11's "dedicated internal
 * view … with a simple status lifecycle (New → Reviewed → Resolved)", and RBAC §9's `feedback_reports`
 * review queue. Central host only, behind `superadmin` + `superadmin.mfa`.
 *
 * ⚠️ **THE ROUTE PARAMETER IS A RAW UUID, NOT A BOUND MODEL, AND THAT IS LOAD-BEARING.** Route-model
 * binding would resolve `FeedbackReport` on the app connection, which on the central host carries NO
 * tenant context — so the strict RLS policy matches nothing and EVERY id 404s, including valid ones. The
 * lookup has to go through {@see SuperAdminService::findFeedback()}'s elevated read. Any future console
 * route over an RLS-protected table inherits this constraint; the `tenants` routes get away with binding
 * only because that table is central and RLS-exempt.
 */
final class FeedbackConsoleController extends Controller
{
    public function __construct(
        private readonly FeedbackConsolePresenter $presenter,
        private readonly SuperAdminService $superAdmin,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $tenantId = $request->query('tenant_id');
        $page = (int) $request->query('page', '1');

        return Inertia::render('admin/Feedback', $this->presenter->index([
            'status' => is_string($status) && $status !== '' ? $status : null,
            'tenant_id' => is_string($tenantId) && $tenantId !== '' ? $tenantId : null,
        ], max(1, $page)));
    }

    /** Move one report through the lifecycle. The legality of the verb is the service's guard, not this one's. */
    public function update(Request $request, string $feedback): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(FeedbackStatus::class)],
        ]);

        $report = $this->superAdmin->findFeedback($feedback);
        abort_if($report === null, 404);

        /** @var User $actor */
        $actor = $request->user();

        $this->superAdmin->transitionFeedback($report, FeedbackStatus::from((string) $validated['status']), $actor);

        return back()->with('toast', ['type' => 'success', 'message' => 'Feedback updated']);
    }

    /** Stream a report's screenshot — see {@see SuperAdminService::feedbackScreenshot()} for why no bypass. */
    public function screenshot(string $feedback): StreamedResponse
    {
        $report = $this->superAdmin->findFeedback($feedback);
        abort_if($report === null, 404);

        $attachment = $this->superAdmin->feedbackScreenshot($report);

        abort_if($attachment === null, 404);
        abort_unless($attachment->virus_scan_status->servable(), 409, 'This file is not yet available.');

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }
}
