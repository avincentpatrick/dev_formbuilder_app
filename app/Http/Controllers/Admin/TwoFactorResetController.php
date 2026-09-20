<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Clear an account's two-factor enrolment from the platform console — Increment M107, answering `D37`.
 *
 * This is the half of `D37` the workspace surface cannot serve. `/members` only ever sees active members
 * of one workspace, so it cannot reach an identity that belongs to NO workspace — the shape
 * `E2eSeeder`'s own two-factor fixture has — nor rescue the sole Owner of a workspace, who has nobody
 * above them to ask.
 *
 * Sits inside `routes/admin.php`'s `['superadmin.mfa', 'step-up']` group, so the operator has proven a
 * recent password and is themselves enrolled before they can clear somebody else's enrolment. That
 * ordering is the point: an unenrolled operator is redirected to `admin.mfa.setup` by `EnsureSuperAdminMfa`
 * and never reaches this route at all.
 */
final class TwoFactorResetController extends Controller
{
    public function __construct(private readonly TwoFactorResetService $twoFactorResets) {}

    /**
     * @throws ValidationException when the target cannot be reset
     */
    public function store(Request $request): RedirectResponse
    {
        // ⚠️ A RAW UUID FROM THE BODY, NEVER A BOUND MODEL — the decision
        // {@see ImpersonationController::store()} records at length. Route-model binding resolves on the
        // app connection and the console runs with NO tenant context, so `usersVisibilitySql()` matches
        // nothing and every VALID id 404s. Measured, not assumed: an app-connection read of another user's
        // row with no tenant context returns zero rows.
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        /** @var User $operator */
        $operator = $request->user();

        // `pgsql_auth` is the connection that can see any identity — the same one the service writes on,
        // and the only one that resolves a target the console has no tenant context for.
        $target = User::on('pgsql_auth')->whereKey((string) $validated['user_id'])->first();

        if ($target === null) {
            throw ValidationException::withMessages(['user_id' => 'That account no longer exists.']);
        }

        try {
            $this->twoFactorResets->resetForOperator($target, $operator);
        } catch (RuntimeException $e) {
            // Surfaced on the field that caused it so the console renders it beside the row, matching the
            // impersonation picker. Unlike that one, the distinct messages are KEPT: every account here is
            // already visible on this page, so naming the reason discloses nothing the operator cannot see,
            // and "they were never enrolled" is the one refusal an operator must not mistake for success.
            throw ValidationException::withMessages(['user_id' => $e->getMessage()]);
        }

        return back()
            ->with('status', 'platform-two-factor-reset')
            ->with('toast', ['type' => 'success', 'message' => 'Two-step sign-in reset']);
    }
}
