<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;

/**
 * A new password ends every OTHER session of the account (M140, `R-d060bb77`).
 *
 * ── WHY THIS EXISTS: A RESET THAT LEAVES THE SQUATTER SIGNED IN RECLAIMS NOTHING ──────────────────────────
 * Someone who registers another person's address keeps the session that registration opened. When the real
 * owner reclaims the account by password reset and confirms the address, that session becomes theirs too —
 * and `PUT /user/profile-information` carries only `auth`, so it can move the account's email to an address
 * its holder controls and reset the password there. Before M140 nothing listened to `PasswordReset`, and
 * `AuthenticatesSessions` sat only in `bootstrap/app.php`'s priority list, mounted on no route.
 *
 * ── TWO EVENTS, AND WHAT EACH KEEPS ──────────────────────────────────────────────────────────────────────
 *  - **`PasswordReset`** (Fortify's `CompletePasswordReset`): every session goes. A reset is made signed OUT,
 *    so there is no session of the person's own to keep, and Fortify has already rotated `remember_token`, so
 *    no "remember me" cookie can sign another device back in.
 *  - **`PasswordUpdatedViaController`** (a change from the profile): every session except the one making the
 *    change. Fortify rotates nothing here, so the remember token is rotated too, or another device's "remember
 *    me" cookie would sign it straight back in. ⚠️ Cost, accepted: the device that made the change stays signed
 *    in for its session, but its own "remember me" must be ticked again at its next sign-in.
 *
 * ⚠️ ONLY THE DATABASE SESSION DRIVER CAN BE SEARCHED BY USER, AND IT IS THE ONE EVERY DEPLOYMENT USES
 * (`docs/deployment-infrastructure.md`). Under any other driver the session half does nothing, rather than
 * deleting from a table no session lives in; the remember-token rotation still runs.
 */
final class EndOtherSessionsOnPasswordChange
{
    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->endSessions($event->user, keep: null);
        }
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $request = request();
        $this->endSessions($event->user, keep: $request->hasSession() ? $request->session()->getId() : null);

        // ⛔ ON THE USER'S OWN CONNECTION, WHERE `UpdateUserPassword` HAS JUST SAVED THE SAME ROW — NOT through
        // `RlsAwareUserProvider::updateRememberToken()`, whose `pgsql_auth` is a separate SESSION. Measured: inside
        // any enclosing transaction (every test, and any future request that opens one) that write waits on this
        // request's own lock on the row, forever. This route sets the user GUC, which is what admits the save.
        $event->user->setRememberToken(Str::random(60));
        $event->user->save();
    }

    /** Delete the user's session rows, except `$keep`. */
    private function endSessions(User $user, ?string $keep): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $connection = config('session.connection');

        DB::connection(is_string($connection) ? $connection : null)
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($keep !== null, fn ($query) => $query->where('id', '!=', $keep))
            ->delete();
    }
}
