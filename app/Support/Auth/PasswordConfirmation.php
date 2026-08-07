<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Http\Controllers\Admin\TenantAdminController;
use App\Http\Controllers\Tenant\PreferencesController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * "Has this session confirmed its password recently enough?" — Increment I8a (PRD Feature #14).
 *
 * This is the READ half of Laravel's step-up mechanism. {@see RequirePassword} owns the enforcement and
 * keeps its own private copy of the same arithmetic; this class exists so a SURFACE can ask the question
 * before it makes a request that would be refused, rather than discovering the refusal in a response body.
 *
 * ── Why a surface needs to ask at all ───────────────────────────────────────────────────────────────────
 * {@see RequirePassword::handle()} answers a stale request one of two ways, and the fork is on
 * `expectsJson()`: a browser navigation gets a 302 to `password.confirm`, but anything sent with
 * `Accept: application/json` gets a bare **423 with a JSON body**. Fortify's 2FA-management endpoints carry
 * `password.confirm` (config/fortify.php's `confirmPassword` feature), and the enrolment component reads two
 * of them as JSON sidecars — so on that surface the guard is invisible unless the page asks first.
 * {@see PreferencesController::show()} and
 * {@see TenantAdminController::mfaSetup()} both do, and both ship the answer to
 * `TwoFactorSetup.vue`.
 *
 * ⚠️ THE DEFAULT TIMEOUT HERE IS `auth.password_timeout`, NOT `auth.step_up_timeout`, AND THAT IS THE POINT.
 * Two windows are live in this application: Fortify's own routes use the framework default (3 hours), while
 * {@see RequireRecentPassword} narrows the high-blast-radius actions to 15 minutes.
 * A caller asking "would THIS route refuse me" must pass the window that route actually enforces, so the
 * parameter is explicit and the default is the one Fortify's routes use.
 */
final class PasswordConfirmation
{
    /**
     * Whether a route guarded by `password.confirm` would currently refuse this request.
     *
     * Mirrors {@see RequirePassword::shouldConfirmPassword()} exactly, including its treatment of a session
     * that has never confirmed (the missing key defaults to 0, so the elapsed time is the whole unix epoch
     * and the answer is "yes"). Deliberately duplicated rather than reached through reflection: it is two
     * lines, and a subclass hook would tie a read-only helper to a protected method's signature.
     *
     * @param  int|null  $timeoutSeconds  the window the calling route enforces; `auth.password_timeout` when omitted
     */
    public static function isStale(Request $request, ?int $timeoutSeconds = null): bool
    {
        $timeout = $timeoutSeconds ?? (int) config('auth.password_timeout', 10800);

        return self::secondsSinceConfirmation($request) > $timeout;
    }

    /**
     * Seconds since this session last confirmed its password.
     *
     * Returns a very large number rather than null when it never has, so every caller compares the same
     * way and none has to special-case "never" — the same shape the framework's own check relies on.
     */
    public static function secondsSinceConfirmation(Request $request): int
    {
        /** @var int $confirmedAt */
        $confirmedAt = $request->hasSession()
            ? (int) $request->session()->get('auth.password_confirmed_at', 0)
            : 0;

        return Date::now()->unix() - $confirmedAt;
    }
}
