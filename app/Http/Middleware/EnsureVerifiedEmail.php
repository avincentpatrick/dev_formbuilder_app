<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Notifications\Auth\QueuedVerifyEmail;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Audit\ImpersonationContext;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Email-verification enforcement — Increment J3a.
 *
 * ── EVERYTHING EXCEPT THE GATE WAS ALREADY BUILT, WHICH IS WHY THIS IS SO SMALL ────────────────────────
 * `User implements MustVerifyEmail`, `config/fortify.php` enables `Features::emailVerification()`,
 * `Pages/auth/VerifyEmail.vue` renders the notice, and {@see QueuedVerifyEmail} is
 * already a branded, queued override sending a signed link. All of it has been shipping since Phase 0 and
 * **guarded nothing**: `verified` appeared on zero routes, so the product asked people to verify an address
 * and then never checked. J3a mounts the gate; it builds no new flow.
 *
 * ── WHY THIS CLASS EXISTS INSTEAD OF THE FRAMEWORK'S `EnsureEmailIsVerified` ───────────────────────────
 * Two things the stock class cannot express, and this middleware OVERRIDES the `verified` alias
 * (bootstrap/app.php) rather than inventing a second name — so a future `->middleware('verified')` anywhere
 * in the tree gets the right behaviour by default, which is the only reason to own an alias at all.
 *
 * **(1) An impersonated session is exempt.** {@see EnforceTenantTwoFactor} makes the identical argument for
 * its own gate and this is the same shape: the notice page's only action is *resend*, which would mail a
 * verification link **to the member** because a platform operator opened their account — and the operator
 * still could not click it. Bouncing them is not the safe failure it looks like; it makes support access
 * useless in exactly the workspaces most likely to need it. The authority being trusted is not the member's
 * mailbox but the console stack the grant came through (`superadmin` + `superadmin.mfa` + `step-up`).
 * Like there, this exemption is deliberately NOT structural: it depends on SESSION STATE rather than on
 * which URL was asked for, so no route list could express it.
 *
 * **(2) A JSON caller is answered in kind.** The stock class redirects everyone, so the notification bell —
 * which polls `GET /notifications` every sixty seconds — would receive a 302 into an HTML notice page and
 * parse markup as JSON. {@see EnforceTenantTwoFactor} tolerates exactly that and documents that
 * `notificationsClient` swallows the resulting `SyntaxError`; this gate simply does not create it.
 *
 * ⚠️ **On `/api/v1/*` — AND ONLY THERE — that becomes the documented `forbidden` envelope.**
 * `bootstrap/app.php`'s renderers are gated on `$request->is('api/v1/*')`, so a tenant sidecar such as
 * `/notifications` gets a 403 with the framework's default body while `POST /api/v1/auth/tokens` gets
 * `{"error":{"code":"forbidden"}}`. Both are correct and neither moves `openapi.json`; the distinction is
 * recorded because a first draft of this comment claimed the envelope applied everywhere, and
 * `EmailVerificationGateTest` asserts each surface against what it actually returns.
 *
 * ── ORDER ─────────────────────────────────────────────────────────────────────────────────────────────
 * It needs a resolved user and nothing else. `RlsAwareUserProvider::retrieveById()` returns the whole row,
 * `email_verified_at` included, at no extra query and under no RLS constraint — so unlike
 * {@see EnforceTenantTwoFactor} (a tenant-scoped settings read) this needs no RLS GUC and is the cheapest
 * failing check in the group.
 *
 * ⚠️ **`auth` RUNS FIRST BECAUSE OF THE PRIORITY LIST, NOT BECAUSE OF WHERE THIS IS WRITTEN.**
 * `AuthenticatesRequests` is in `bootstrap/app.php`'s `$middleware->priority()` and this class is not, so
 * `auth` is pulled ahead wherever either appears in a route group's array. Mutation-verified: moving
 * `'verified'` above `'auth'` in `routes/tenant.php` changes nothing and reddens no test. A first draft of
 * this docblock claimed the written position was what guaranteed the ordering. It is not.
 *
 * What the written position DOES control is the order against {@see EnforceTenantTwoFactor} and
 * {@see EnforceImpersonationTimeout}, which are also absent from the priority list and so run as written.
 * Before the 2FA gate is the one that matters: an unverified member under org-2FA enforcement proves the
 * mailbox before being asked to enrol a second factor against an address nobody has confirmed they own.
 *
 * ── WHERE IT IS NOT MOUNTED, AND WHY EACH IS A DECISION ───────────────────────────────────────────────
 *  · `GET /two-factor/required` — its own group, outside this one, for the reason above.
 *  · the invitation-accept group and the impersonation-arrival group — neither carries `auth`, so there is
 *    no user to check; and `InvitationController::registerInvitedPlaceholder()` stamps `email_verified_at`
 *    itself, because clicking a link in an invitation email already proves control of that mailbox.
 *    ⚠️ M8 narrowed WHO reaches that stamp: only an identity
 *    {@see TenantMembershipService::identityIsEstablished()} answers FALSE for. An
 *    account that already exists is now handed to the sign-in-then-accept hand-off instead, so the invite
 *    link no longer verifies a mailbox on behalf of somebody who never asked it to.
 *  · the guest/public form runtime — a respondent has no account. The null-user early return is belt to
 *    that braces.
 *  · **the central super-admin console — deliberately.** It is already behind `superadmin` (a privileged
 *    flag), `superadmin.mfa` (a confirmed second factor) and `step-up` (a password confirmation in the last
 *    15 minutes), which is a strictly stronger stack than a mailbox round trip. Operators are promoted by a
 *    privileged UPDATE and hold no membership, so there is no product flow that would ever verify them; a
 *    gate there would lock the console for a fixture reason and buy nothing. Recorded as a decision rather
 *    than left to look like an omission.
 */
final class EnsureVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // `instanceof MustVerifyEmail` rather than a null check alone: a guard whose provider model does not
        // implement the contract has no `hasVerifiedEmail()` to call, and a gate that fatals is worse than
        // one that passes through.
        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        // ⚠️ THE ONLY THING GUARDING THIS BRANCH IS ONE PEST CASE, AND THAT IS WORTH KNOWING BEFORE ANYONE
        // "SIMPLIFIES" IT. `impersonation.spec.ts` cannot catch a regression here: `E2eSeeder` stamps every
        // identity it creates as verified, so an impersonated member passes the check above and never
        // reaches this line. A first draft of J3a's commit message claimed the e2e spec would redden — it
        // would not. `EmailVerificationGateTest`'s "exempts an impersonated session" case is the guard.
        if (ImpersonationContext::operatorId() !== null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            throw new AccessDeniedHttpException('This account\'s email address is not verified.');
        }

        // `redirect()->guest()`, not `redirect()->to()` — `Redirector::guest()` STORES `url.intended`, so
        // verifying returns the person to the page they were actually trying to reach instead of dropping
        // them on the dashboard. Same property `Authenticate` relies on, and the same one
        // `FortifyServiceProvider::confirmPasswordView()` had to reconstruct by hand when it was missing.
        return redirect()->guest(route('verification.notice'));
    }
}
