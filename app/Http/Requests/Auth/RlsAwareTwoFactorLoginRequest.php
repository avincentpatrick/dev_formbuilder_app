<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Auth\RlsAwareUserProvider;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Resolve the pending two-factor user through the provider that can actually see them (Increment J3c1).
 *
 * ── THE DEFECT THIS EXISTS TO CLOSE, MEASURED ON THE RUNNING APP ───────────────────────────────────────
 *   POST /login                → 302 → /two-factor-challenge   (correct: credentials pass)
 *   GET  /two-factor-challenge → 302 → /login                  (the bug)
 *
 * Anyone who actually enrolled in 2FA was locked out at their next sign-in, with NO self-service escape:
 * `two-factor.disable` sits behind `auth` + `password.confirm`, and a locked-out person can never complete
 * `auth`. Recovery required a DBA `UPDATE`.
 *
 * ── WHY THE VENDOR CANNOT SEE THE ROW, AND IT IS STRUCTURAL RATHER THAN A CONFIG MISTAKE ───────────────
 * {@see TwoFactorLoginRequest::hasChallengedUser()} resolves the pending user as:
 *
 *     $model = app(StatefulGuard::class)->getProvider()->getModel();
 *     … $model::find($this->session()->get('login.id'));
 *
 * `EloquentUserProvider::getModel()` returns a **class-string**, so `$model::find()` is a STATIC call on
 * App\Models\User. That bypasses {@see RlsAwareUserProvider::createModel()} — which that class's own
 * docblock calls "the single routing point" — not by configuration but by shape. The provider IS the
 * RLS-aware one (`config/auth.php` `'driver' => 'rls_aware'`); it is simply unreachable from that line.
 *
 * So the query runs on the DEFAULT connection as `meridian_app`, under the join-shape `users_users_visibility`
 * policy, which admits a row only to the acting user or an ACTIVE co-tenant. Mid-login no guard has
 * authenticated anybody, so `app.current_user_id` is unset, the row is invisible, `find()` returns null, and
 * the controller concludes there is no challenged user.
 *
 * ⚠️ {@see EstablishTenantDatabaseContext} IS NOT THE CAUSE AND MUST NOT BE "FIXED". For a guest it applies
 * `(null, null)` — the value the request already had — so it is not causal, and it is what PR #147 added to
 * repair six Fortify WRITE endpoints that were silently affecting zero rows. `config/fortify.php` says the
 * same thing from the other side. Removing it would not fix this and would re-break all six.
 *
 * ── WHY THE PROVIDER RATHER THAN A USER-CONTEXT WIDENING ───────────────────────────────────────────────
 * The obvious alternative — teach the middleware to set `app.current_user_id` from `session('login.id')`
 * when no guard has authenticated — was considered and refused. It redefines what that GUC MEANS: every RLS
 * policy in the schema is written against "the authenticated user", and `login.id` names somebody who has
 * passed factor ONE only. It also cannot be scoped to this page — Fortify registers its whole route set from
 * one config-level middleware array with no per-route hook, so the widening would land on `/login`,
 * `/register`, `/forgot-password` and `/reset-password` too. And it answers a READ problem by inventing a
 * second pre-auth read path, where {@see RlsAwareUserProvider} is the documented one and has been since B1.
 * Stated honestly: no reachable exploit existed under that option. The objection is the meaning change.
 *
 * ── WHAT IS DELIBERATELY NOT OVERRIDDEN ────────────────────────────────────────────────────────────────
 * `hasValidCode()` and `validRecoveryCode()` both call `challengedUser()`, so they inherit this fix and are
 * left alone on purpose: a second copy of the resolution would be a second thing to keep in step, and the
 * two are already the whole of the TOTP and recovery-code paths.
 *
 * ⚠️ The recovery-code path also WRITES — `$user->replaceRecoveryCode()` — and that write had the same RLS
 * problem on the other side. It is fixed on the model, not here; see {@see User::replaceRecoveryCode()}.
 *
 * ── THE UNKNOWN / SOFT-DELETED CONTRACT, WHICH IS VENDOR PARITY ────────────────────────────────────────
 * `parent::retrieveById()` builds its query from `newModelQuery()`, which applies the `SoftDeletingScope`
 * this model carries — so a trashed row resolves to null exactly as `$model::find()` did. GET then answers
 * `redirect()->route('login')` and POST throws the {@see FailedTwoFactorLoginResponse} bounce, both as
 * before. `login.id` is left in the session, as the vendor leaves it.
 *
 * A non-UUID `login.id` raises SQLSTATE 22P02, exactly as it does today, and is unreachable: the key is
 * written only by Fortify's own `RedirectIfTwoFactorAuthenticatable` from `$user->getKey()`, into an
 * encrypted, signed session. Stated rather than guarded — a guard here would be untested surface defending
 * an input that cannot arrive.
 */
final class RlsAwareTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    /**
     * Determine if there is a challenged user in the current session.
     *
     * @return bool
     */
    public function hasChallengedUser()
    {
        if ($this->challengedUser) {
            return true;
        }

        return $this->resolvePendingUser() !== null;
    }

    /**
     * Get the user that is attempting the two factor challenge.
     *
     * @return mixed
     */
    public function challengedUser()
    {
        if ($this->challengedUser) {
            return $this->challengedUser;
        }

        $user = $this->resolvePendingUser();

        if ($user === null) {
            throw new HttpResponseException(
                app(FailedTwoFactorLoginResponse::class)->toResponse($this)
            );
        }

        return $this->challengedUser = $user;
    }

    /**
     * The one resolution both public methods share.
     *
     * `retrieveById()` is the routing point the static `$model::find()` could not reach: it queries on
     * `pgsql_auth` (whose `users_auth_select` policy is permissive for the `meridian_auth` role, scoped to
     * the `users` table alone) and then RESETS the model to the default connection before returning it —
     * see {@see RlsAwareUserProvider::retrieveById()}. That reset is load-bearing: the object returned here
     * becomes `Auth::user()` for the rest of a successful POST, and `meridian_auth` holds grants on `users`
     * only, so an elevated model leaking into request code would fail on the first relation it touched.
     */
    private function resolvePendingUser(): ?Authenticatable
    {
        $id = $this->session()->get('login.id');

        if ($id === null) {
            return null;
        }

        return app(StatefulGuard::class)->getProvider()->retrieveById($id);
    }
}
