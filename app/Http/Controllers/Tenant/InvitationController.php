<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
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
            'needsRegistration' => $user !== null && $user->email_verified_at === null,
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invite = $this->resolvePendingInvite($token);
        $user = $this->prepareAcceptingUser($request, $invite);

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
     * Establish the accepting user's identity. A never-registered placeholder sets a name + password
     * now (and accepts the platform ToS/Privacy); an already-registered user must be signed in as
     * themselves (the styled sign-in-then-accept hand-off is Increment C).
     */
    private function prepareAcceptingUser(Request $request, TenantUser $invite): User
    {
        $user = $this->resolveInvitedUser($invite);
        abort_if($user === null, 404);

        if ($user->email_verified_at !== null) {
            abort_unless(Auth::id() === $user->id, 403, 'Sign in as the invited account to accept.');

            return $user;
        }

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

        return $user;
    }
}
