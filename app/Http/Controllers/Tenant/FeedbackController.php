<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FeedbackReport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * In-app feedback submission (PRD Feature #11). Thin: validates + writes one tenant-scoped row. tenant_id
 * is filled by BelongsToTenant from the active RLS context; user_id is the authenticated user; status +
 * submitted_at default at the DB. Authorized by the `can:feedback.submit` route gate (granted to every
 * role). Screenshot capture is deferred (the shared attachments table is Phase 1).
 */
final class FeedbackController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'route' => ['required', 'string', 'max:255'],
            'remarks' => ['required', 'string', 'max:5000'],
            'browser_info' => ['nullable', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        FeedbackReport::create([
            'user_id' => $user->id,
            'route' => $validated['route'],
            'remarks' => $validated['remarks'],
            'browser_info' => $validated['browser_info'] ?? [],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Thanks for the feedback']);
    }
}
