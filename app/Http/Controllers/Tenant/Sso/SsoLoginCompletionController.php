<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Sso;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\GoogleCompleteController;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\Sso\SsoGate;
use App\Services\Sso\SsoLoginCompletionService;
use App\Support\Sso\SsoReturnTo;
use App\Support\Sso\SsoSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The same-site hop that actually signs a member in, and the only place the SAML login arm creates a session
 * (Phase 4, P1e — ADR-0016 §D27).
 *
 * ── ⚠️ WHY THIS ROUTE EXISTS — IT CLOSES A LIVE AUTHENTICATION DEFECT ───────────────────────────────
 * Until P1e the ACS called `Auth::login()` itself. A signed assertion proves the tenant's identity provider
 * authenticated somebody; `InResponseTo` proves this SP minted the flow being answered. Neither says anything
 * about **who is holding the browser**, and nothing else checked. So an attacker with an account at the
 * tenant's own directory could start a flow, authenticate as themselves, capture the identity provider's
 * auto-POST form without submitting it, and induce a victim's browser to submit it instead — after which the
 * victim's browser held a session belonging to the attacker, and everything the victim typed, uploaded or
 * configured landed in an account somebody else controlled. Reading provenance as origin, the error this
 * repository already records against ADR-0009 §D3.
 *
 * ── AND WHY THE FIX IS A WHOLE EXTRA REQUEST RATHER THAN A SESSION KEY AT THE ACS ───────────────────
 * Because the ACS cannot read a cookie. It is a genuinely cross-site POST and `config/session.php` sets
 * `same_site` to `lax`, so the browser sends nothing (§D23). {@see GoogleCompleteController} solved the
 * sibling problem with a key compared at the callback, and that works there because both of Google's
 * session-creating hops share a host with their mint. Here the comparison has to happen somewhere the cookie
 * IS sent — and `SameSite=Lax` DOES send cookies on a top-level GET navigation, which is exactly what the
 * ACS's redirect produces. This request, unlike the one before it, arrives on the browser that started.
 *
 * ── EVERY REFUSAL IS THE SAME 404 ───────────────────────────────────────────────────────────────────
 * §D4's posture, carried across from the ACS: unknown id, wrong intent, never verified, already redeemed,
 * past the completion window, a subject that no longer resolves, or a browser that never started this flow
 * are one indistinguishable response.
 *
 * ⚠️ NO `auth` MIDDLEWARE, AND NO `verified` EITHER — this route is how a person BECOMES authenticated, so
 * requiring a session here would be circular. It is the {@see GoogleCompleteController} posture exactly. Org
 * -level two-factor is not evaluated here either and must not be: it guards the authenticated group, so the
 * redirect below walks straight into it on the very next request, exactly as P1b's `/dashboard` did.
 *
 * ⚠️ AND NO PERSONAL 2FA CHALLENGE, WHICH DIVERGES FROM `GoogleSessionStarter` ON PURPOSE. ADR-0016 §D22
 * decided that for SAML the identity provider is the authentication authority and a workspace whose IdP
 * performs MFA turns the setting off; ADR-0019 §D11 decided the opposite for Google, because a consumer
 * account is chosen by the end user rather than configured by an administrator. Two doors, two answers, and
 * copying the wrong one here would silently change a decision of record.
 */
final class SsoLoginCompletionController extends Controller
{
    public function __construct(
        private readonly SsoGate $gate,
        private readonly SsoLoginCompletionService $completions,
    ) {}

    public function __invoke(Request $request, string $requestId): RedirectResponse
    {
        // ⚠️ THE GATE IS CONSULTED HERE TOO, AND THE STEP-UP HOP'S PRECEDENT IS DELIBERATELY NOT FOLLOWED.
        // `SsoStepUpCompletionController` asks no gate, on the reasoning that re-asking turns a race into a
        // lockout mid-authentication. That reasoning does not survive the difference between the two arms:
        // a step-up grants `auth.password_confirmed_at` to a session that already exists, whereas this hop
        // MINTS one. So the ninety seconds after an admin hits the status kill switch — the escape hatch
        // `/settings/sso` advertises as surviving a plan downgrade — would otherwise still be minting
        // sessions for a connection they had just disabled, and `stampLastLogin()` below would refresh
        // `last_login_at` on it, telling that admin sign-in was healthy on a connection they turned off.
        // The cost is one already-cached read and the answer is the same 404 as every other refusal (§D4).
        $this->gate->activeConnectionOrAbort();

        $authRequest = $this->completions->redeem($requestId);

        if ($authRequest === null) {
            return $this->refuse('login_not_redeemable', $requestId, $request);
        }

        // ⚠️ THE BROWSER THAT FINISHES MUST BE THE ONE THAT STARTED, AND THIS LINE IS THE WHOLE INCREMENT.
        //
        // Compared AFTER the redeem, deliberately — {@see GoogleCompleteController}'s ordering and its
        // reason: the row is burned either way, so an assertion tried in the wrong browser cannot then be
        // retried in the right one. It also means an attacker who induces a victim to submit their assertion
        // destroys their own sign-in rather than merely failing to steal the victim's session.
        //
        // ⚠️ A MEMBERSHIP TEST AGAINST THIS FLOW'S OWN ID, NEVER "the session holds something". The weaker
        // check is defeated by luring the victim through `/sso/saml/login` first so that their session holds
        // *a* pending flow, which is the refinement `GoogleRedirectController::FLOW_SID` was written to make.
        if (! SsoSession::hasPendingLogin($request->session(), $authRequest->request_id)) {
            return $this->refuse('flow_not_bound_to_browser', $requestId, $request);
        }

        // ⚠️ READ ON THE DEFAULT CONNECTION, AND THE RLS POLICY THERE IS A SECOND CONTROL RATHER THAN AN
        // OBSTACLE. `users_visibility` is `id = app.current_user_id OR EXISTS(an ACTIVE tenant_users row for
        // app.current_tenant_id)`. In the ordinary case nobody is signed in here, so the second arm is the
        // whole predicate: this row's subject is visible if and only if they are an ACTIVE MEMBER OF THIS
        // WORKSPACE, enforced by the database. ⚠️ Not an absolute: this route carries no `guest` either,
        // because `SsoLoginController` deliberately supports re-authenticating as somebody else on a shared
        // machine, and such a visitor DOES set `app.current_user_id` — the first arm can then reveal only
        // the visitor to themselves, which adds nothing a session they already hold did not.
        // A member suspended in the ninety seconds since the ACS is
        // refused here without this file containing a comparison, which is `GoogleCompleteController`'s
        // "the wrong-tenant refusal is RLS rather than a comparison" one door along.
        //
        // ⚠️ NOT `Auth::loginUsingId()`, WHICH WOULD HAVE BEEN THE OBVIOUS CHOICE AND IS WEAKER. That runs
        // through `RlsAwareUserProvider` on `pgsql_auth`, whose select policy is `USING (true)` — every
        // account in the deployment, with no membership predicate at all. It is the right connection for
        // restoring a session that already proved who it was, and the wrong one for deciding who may be
        // signed in. It is also invisible to this suite: `pgsql_auth` is a separate database session and
        // cannot see rows written inside `RefreshDatabase`'s transaction, so every just-in-time provisioned
        // subject would resolve to null in tests while working in production — a gate that certifies the
        // path it cannot see, which is the shape this suite's own header was written to warn about.
        $user = User::query()->whereKey($authRequest->resolved_user_id)->first();

        if ($user === null) {
            // ⚠️ THE BINDING IS SPENT ON THIS REFUSAL TOO, AND IT IS THE ONLY REFUSAL THAT NEEDS IT. The row
            // was burned by the redeem above, so the binding authorises nothing from here on — but leaving
            // it in the list would occupy one of five slots until four newer mints pushed it out, shrinking
            // the concurrency budget the list exists to provide. The other refusals need no such line: the
            // `flow_not_bound_to_browser` arm is reached precisely BECAUSE this session holds no binding for
            // the row, and the `redeem()` arm never learned which row it was.
            SsoSession::forgetPendingLogin($request->session(), $authRequest->request_id);

            return $this->refuse('subject_unresolvable', $requestId, $request);
        }

        // ⚠️ ORDER IS LOAD-BEARING: `Auth::login()` calls `Session::migrate(true)`, which regenerates the
        // session id. Everything written to the session must follow it.
        Auth::login($user);

        // ⚠️ BOTH WRITES ARE AFTER THE LOGIN, for the reason `SsoSession::markAuthenticated()` states:
        // Laravel carrying session data across that migration is a convenience rather than a contract. The
        // binding is spent here so a session cannot carry a stale one, and this is the ONE place the login
        // arm records its identity source.
        SsoSession::markAuthenticated($request->session());
        SsoSession::forgetPendingLogin($request->session(), $authRequest->request_id);

        $this->stampLastLogin($authRequest->sso_connection_id);

        // `SsoReturnTo::destination(null)` is `/dashboard`, which is where P1b's ACS sent every login
        // unconditionally — so a login row, whose `return_to` is null by §D21, lands exactly where it always
        // did. Re-validated on the way out even so: the column is 500 characters of stored text, and a
        // redirect target is not somewhere to trust a value because of where it was supposed to come from.
        return redirect(SsoReturnTo::destination($authRequest->return_to));
    }

    /**
     * One 404 for every refusal, logged with a stable machine reason.
     *
     * ⚠️ LOGGED, NEVER RECORDED TO THE TENANT-FACING FAILURES PANEL. That store exists to explain why an
     * identity PROVIDER's answer was refused, and "your browser did not start this" is not something an admin
     * can act on — while an unauthenticated caller able to append to it would push against the bound
     * `SsoAuthFailureRecorder` was built to keep. Unlike the step-up hop's log line this one cannot name an
     * actor, because by construction there is nobody signed in here yet.
     *
     * **Accepted cost, stated rather than discovered:** a member who lost their session cookie between the
     * two hops sees a bare 404 with no way back, and has to start again. That is the answer
     * `SsoStepUpCompletionController` already gives; a friendlier bounce would need a failure contract on the
     * tenant sign-in page that does not exist yet (§D21).
     */
    private function refuse(string $reason, string $requestId, Request $request): RedirectResponse
    {
        Log::warning('sso.login.rejected', [
            'reason' => $reason,
            'request_id' => $requestId,
            'ip' => $request->ip(),
        ]);

        abort(404);
    }

    /**
     * Record that this trust relationship produced a sign-in — MOVED HERE FROM THE ACS BY P1e.
     *
     * `last_login_at` is what an admin reads to answer "is sign-in working", so it must be stamped where a
     * session actually exists. Left at the ACS it would be refreshed by every verified assertion whose browser
     * never came back, letting a broken completion hop look healthy for weeks — which is precisely the lie
     * §D23 refused to let a working step-up tell about a broken login path.
     *
     * ⚠️ A DIRECT ONE-COLUMN UPDATE, NEVER `$connection->save()`. With `APP_PREVIOUS_KEYS` set — i.e. during
     * any APP_KEY rotation window — `originalIsEquivalent()` short-circuits to false for every `encrypted:`
     * cast, so `idp_certificates` is permanently dirty and a model save would rewrite the tenant's trust
     * anchor on every single login. RLS scopes the update, which is why no gate is consulted to perform it.
     */
    private function stampLastLogin(string $connectionId): void
    {
        SsoConnection::query()->whereKey($connectionId)->update(['last_login_at' => now()]);
    }
}
