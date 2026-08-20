<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invitee side of the lifecycle (multi-tenancy-rbac-design.md §7). Served on the tenant subdomain
 * WITHOUT the `auth` middleware — the invitee is not yet a member — but WITH tenant context established,
 * which is what makes the strict-RLS `tenant_users` invite row visible (only within its own tenant) and
 * lets accept materialize the role. The token is matched by its hash; a token minted for another tenant
 * simply does not resolve here.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ NO `auth`, NO `verified`, AND ONE ARM THAT WRITES A CREDENTIAL — SO THE FORK BELOW IS THE WHOLE GATE.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * Increment M8: this file used to fork on `email_verified_at !== null` and read a NULL as *"this identity
 * does not exist yet"*. It does not mean that, and the difference was a live account takeover — an
 * enrolled-but-unverified member's password was silently overwritten by whoever held the emailed token,
 * and `Auth::login()` then minted them a session with no second factor. The predicate now lives in
 * {@see TenantMembershipService::identityIsEstablished()}, which carries the full reasoning, and BOTH doors
 * below ask it: {@see self::show()} decides what the page offers, {@see self::accept()} decides what the
 * server permits. **Fixing one without the other leaves the page inviting a credential the server then
 * refuses — they must move together.**
 */
final class InvitationController extends Controller
{
    use PasswordValidationRules;
    use ResolvesTenant;

    public function __construct(private readonly TenantMembershipService $memberships) {}

    public function show(string $token): Response
    {
        $invite = $this->resolvePendingInvite($token);
        $user = $this->resolveInvitedUser($invite);

        return Inertia::render('invitations/Show', [
            'tenantName' => $this->currentTenant()->name,
            'email' => $user?->email,
            // ⚠️ THE SAME PREDICATE `accept()` ENFORCES, ASKED HERE SO THE PAGE CANNOT PROMISE WHAT THE
            // SERVER WILL REFUSE. Before M8 both sites asked `email_verified_at === null` — consistently,
            // and consistently wrongly — so an enrolled member's invite page rendered a "Choose a password"
            // form for an account that already had one. False renders the accept-only page, which is
            // exactly what an already-verified invitee has always seen, so no `.vue` change was needed.
            'needsRegistration' => $user !== null && ! $this->memberships->identityIsEstablished($user, $invite),
            'token' => $token,
            // J3b: this page sets a password when `needsRegistration`, through the same
            // `Password::defaults()` every other surface validates against — so it gets the same
            // checklist. Standing Rule 2: a live checklist on Register but not here would be the drift
            // "one shared design system, no exceptions" exists to prevent.
            'passwordPolicy' => PasswordPolicy::requirements(),
        ]);
    }

    /**
     * Accept the invitation, establishing WHO is accepting before anything is written.
     *
     * ── THE TWO ARMS, AND WHY THE ORDER MATTERS ─────────────────────────────────────────────────────────
     * An ESTABLISHED identity (a proved mailbox, a confirmed second factor, a linked Google account, or a
     * membership they actually joined somewhere) must be signed in AS THEMSELVES. That is not a formality:
     * signing in is what runs the password check and the two-factor challenge, and that is the entire
     * difference between this path and the password-reset path it was previously strictly weaker than.
     *
     * A NEVER-USED placeholder — the row {@see TenantMembershipService::invite()} creates for a stranger —
     * sets a name and password here and accepts the platform ToS/Privacy. Nothing is taken from anybody in
     * that arm: the account exists only because this workspace invited them, and its password is 48 random
     * bytes nobody has ever held.
     *
     * ⚠️ AN UNAUTHENTICATED VISITOR ON THE ESTABLISHED ARM IS HANDED OFF, NOT REFUSED. `abort(403)` alone
     * was a dead end for the legitimate invitee, so the destination is parked in the session and they are
     * sent to sign in — through the two-factor challenge if they are enrolled — landing back here able to
     * accept. That IS the sign-in-then-accept hand-off this file used to defer to "Increment C"; a styled
     * page explaining the hop is still worth building and is Lane A's, in
     * `resources/js/Pages/invitations/Show.vue`.
     *
     * A visitor authenticated as SOMEBODY ELSE is still refused outright — there is nothing to hand off to,
     * and Fortify's `GET /login` carries `guest:web`, so redirecting them would bounce straight to the
     * dashboard with a stale destination and no explanation of why.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invite = $this->resolvePendingInvite($token);

        $user = $this->resolveInvitedUser($invite);
        abort_if($user === null, 404);

        if ($this->memberships->identityIsEstablished($user, $invite)) {
            if (Auth::id() === null) {
                // ⛔ THE FRAMEWORK'S `guest()` REDIRECT IS DELIBERATELY NOT USED HERE, AND THE DIFFERENCE IS
                // A SECURITY ONE THIS REPOSITORY HAS ALREADY RULED ON ONCE. On a POST that helper parks
                // `url()->previous()` — the REFERER — as the post-login destination, and `UrlGenerator::to()`
                // hands an absolute URL straight back, so an attacker-chosen Referer becomes a post-login
                // open redirect. `FortifyServiceProvider`'s `confirmPasswordView()` declined exactly that
                // risk in exactly those words. It would also simply be WRONG here: with no Referer the
                // fallback is the truthy `/`, so the invitee would be delivered to the tenant root after
                // signing in and nothing anywhere would report it.
                //
                // The destination is server-derived instead — this invitation, on this host, named by the
                // route that serves it. `Fortify\Http\Responses\{LoginResponse,TwoFactorLoginResponse}` both
                // end in `redirect()->intended(...)`, so this session key is the one hand-off that survives
                // the second-factor hop — the argument {@see \App\Services\Auth\GoogleSessionStarter} makes
                // for its own `return_to`. GET and POST share this URI, so `invitations.show` is exact.
                $request->session()->put('url.intended', route('invitations.show', ['token' => $token]));

                return redirect()->route('login');
            }

            abort_unless(Auth::id() === $user->id, 403, 'Sign in as the invited account to accept.');
        } else {
            $this->registerInvitedPlaceholder($request, $user);
        }

        $this->memberships->accept($invite, $user);
        Auth::login($user);

        return redirect()->intended('/dashboard');
    }

    public function decline(string $token): RedirectResponse
    {
        $this->memberships->decline($this->resolvePendingInvite($token));

        return redirect('/')->with('status', 'invitation-declined');
    }

    /** Resolve the invite row by hashed token within the current tenant (strict RLS scopes it), or 404. */
    private function resolvePendingInvite(string $token): TenantUser
    {
        $invite = TenantUser::query()->where('invite_token', hash('sha256', $token))->first();
        abort_if($invite === null, 404);

        return $invite;
    }

    private function resolveInvitedUser(TenantUser $invite): ?User
    {
        // Cross-RLS lookup: the invitee is not yet a visible member (pgsql_auth is the pre-auth path).
        $user = User::on('pgsql_auth')->find($invite->user_id);
        $user?->setConnection((string) config('database.default'));

        return $user;
    }

    /**
     * Turn a never-used placeholder into a real account: their chosen name and password, the address
     * treated as verified (holding the token proved the mailbox), and the platform consents.
     *
     * ⛔ ONLY EVER CALLED FOR AN IDENTITY {@see TenantMembershipService::identityIsEstablished()} HAS
     * ANSWERED FALSE FOR. This method force-fills a password over whatever is in the column, so reaching it
     * with an established identity IS the M8 defect. Do not add a caller without asking that predicate
     * first, and do not move the question inside here — {@see self::show()} needs the same answer to decide
     * whether to render the form at all.
     */
    private function registerInvitedPlaceholder(Request $request, User $user): void
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            // Single-field on the minimal accept page, so `'confirmed'` cannot be inherited — which is the
            // WHOLE of this surface's divergence, and J3a moved it from an inline copy of the rules into a
            // named method on the shared trait. Everything else (min length, the four character classes, the
            // breached-password check) now arrives here by construction rather than by being remembered.
            'password' => $this->passwordRulesUnconfirmed(),
        ]);

        $user->forceFill([
            'name' => $validated['name'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
            'tos_accepted_at' => now(),
            'privacy_policy_accepted_at' => now(),
        ])->save();
    }
}
