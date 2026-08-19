<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Support\Auth\GoogleSignInOutcome;
use App\Support\Sso\SsoReturnTo;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

/**
 * The last four lines of a Google sign-in, in the one order that is correct (Increment J3c2).
 *
 * Extracted rather than duplicated because BOTH arms end here — the central callback signs in on the spot,
 * and the tenant completion hop signs in after redeeming its handoff — and every line below is a decision
 * that a second copy would eventually get wrong in only one of them.
 *
 * ── ⚠️ PERSONAL TWO-FACTOR STILL APPLIES, AND THIS DIVERGES FROM ADR-0016 §D32 ON PURPOSE ────────────
 * *User decision of record, 2026-08-14, argued in ADR-0019 §D11.* SAML decided the opposite in ADR-0016
 * §D32 — the identity provider is the authentication authority at that door — and that
 * reasoning does not transfer: **SAML is an enterprise trust anchor a workspace administrator chose and
 * configured; a Google account is a consumer credential the end user chose**, and this product has no way
 * to know whether it is protected by anything at all. Someone who deliberately enrolled a TOTP would
 * otherwise find it silently bypassed by a button on the same page as the password form.
 *
 * Mechanically this is Fortify's own shape, copied from
 * {@see RedirectIfTwoFactorAuthenticatable::twoFactorChallengeResponse()}: write
 * `login.id`, dispatch the challenge event, redirect to `two-factor.login`. That page was UNREACHABLE BY
 * ANYONE until J3c1 repaired it, which is why this increment had to follow that one.
 *
 * ⚠️ `hasEnabledTwoFactorAuthentication()` rather than the bare column, and the two agree here:
 * `config/fortify.php` passes `'confirm' => true`, so Fortify's predicate is
 * `two_factor_secret IS NOT NULL AND two_factor_confirmed_at IS NOT NULL`. Reading the flag through
 * Fortify is what keeps this correct if that feature flag ever changes, since the challenge page is
 * Fortify's too.
 *
 * ── ⚠️ AND `url.intended` IS HOW `return_to` SURVIVES THE CHALLENGE ─────────────────────────────────
 * Both `Fortify\Http\Responses\LoginResponse` and `TwoFactorLoginResponse` end in
 * `redirect()->intended(...)`, so parking the destination there is the ONLY handoff that reaches the far
 * side of the second factor. Without it a person with 2FA enabled loses whatever page they were trying to
 * reach, and the loss is invisible to anyone testing without an enrolled account.
 */
final class GoogleSessionStarter
{
    /**
     * @param  string  $destination  an already-validated PATH from {@see SsoReturnTo}
     */
    public function start(Request $request, GoogleSignInOutcome $outcome, string $destination): RedirectResponse
    {
        $user = $outcome->user;

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put('url.intended', $destination);
            $request->session()->put([
                'login.id' => $user->getKey(),
                // No `remember` on this door: there is no checkbox beside the button, and inventing a
                // durable session the person did not ask for is not a default worth taking.
                'login.remember' => false,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        // ⚠️ ORDER IS LOAD-BEARING, and it is the SsoAcsController/ImpersonationSessionController lesson
        // verbatim: `Auth::login()` calls `Session::migrate(true)`, which regenerates the session id.
        // Anything written to the session BEFORE it lands on the pre-login session — Laravel carries the
        // data across, so it appears to work and breaks silently the day that stops being true. Note the
        // 2FA arm above is the exception that proves it: it writes to the session and never logs in.
        Auth::login($user);

        // ⚠️ `Verified`, NOT `Registered`, AND ONLY FOR A NEW ACCOUNT. `SendWelcomeEmail` listens here.
        // `Registered` would additionally trigger the framework's own
        // `SendEmailVerificationNotification` — a verification email for an address Google has already
        // proved — and `JoinTenantOnRegistration`, which joins at `viewer` with no Suspended refusal and
        // no Invited-role arm, i.e. a second and weaker implementation of ADR-0016 §D20 running beside the
        // one this flow deliberately reuses. The row is already stamped `email_verified_at`, so this
        // announces a fact rather than establishing one.
        if ($outcome->created) {
            event(new Verified($user));
        }

        return redirect($destination);
    }
}
