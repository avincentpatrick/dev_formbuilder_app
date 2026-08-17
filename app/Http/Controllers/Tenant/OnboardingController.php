<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "Stop showing me the getting-started checklist" (Increment J5b) — one write, on the caller's own
 * membership row. Everything about WHAT the checklist says lives in `GettingStartedChecklist`; this is the
 * thin adapter.
 *
 * ⚠️ **THAT CLASS IS NAMED IN PROSE RATHER THAN WITH A DOC REFERENCE, AND SO IS EVERY OTHER CLASS BELOW
 * THIS CONTROLLER DOES NOT CALL.** A `{@see}` here made the import list carry a service this file never
 * instantiates — a dependency created for the sake of a comment, which is the same defect Pint's
 * `fully_qualified_strict_types` fixer produced twice elsewhere in J5. A thin adapter should not know the
 * name of the class that renders what it writes.
 *
 * ⚠️ **NO `can:` GATE, AND THAT IS AN ARGUMENT RATHER THAN AN OMISSION.** There is no instance to
 * authorize and no permission that could sensibly guard it: the row being written is the caller's own
 * membership, found BY the caller's own id, so the WHERE clause is the whole of the authorization — the
 * same construction and the same reasoning as {@see NotificationController::readAll()}. A policy method
 * returning true for every authenticated member would authorize nothing while looking like it did.
 *
 * ⚠️ **`firstOrFail()` IS LOAD-BEARING AND IS NOT DEFENSIVE TIDYING.** A tenant-scoped UPDATE that matches
 * nothing is this codebase's recorded silent-failure shape — four occurrences, every one of them a write
 * that reported success and changed no row, because RLS resolved the statement against a context that was
 * not set. This surface would fail in exactly that way and be *invisible*: the button would return 302, the
 * page would re-render, and the card would still be there, with nothing anywhere saying why. Reading the
 * row first turns that state into a loud 404 instead of a button that quietly does not work.
 *
 * The already-dismissed guard is {@see NotificationController::read()}'s, for a smaller version of its
 * reason: a second click must not move the timestamp, because the column answers *when did you dismiss
 * this* and a re-stamp makes that answer wrong.
 *
 * ⚠️ **AN INERTIA REDIRECT, NOT A JSON SIDECAR** — the opposite call from the notification bell one route
 * block up, and for the reason the notification-preferences route already records: this write has a
 * rendered page to go back to, and going back to it re-renders the dashboard with the card gone. The
 * disappearance is the confirmation, so there is deliberately no toast.
 */
final class OnboardingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = TenantUser::query()
            ->where('user_id', (string) $user->getKey())
            ->firstOrFail();

        if ($membership->onboarding_dismissed_at === null) {
            $membership->update(['onboarding_dismissed_at' => Carbon::now()]);
        }

        return back();
    }
}
