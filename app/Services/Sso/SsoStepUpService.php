<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoAuthIntent;
use App\Http\Middleware\RequireRecentPassword;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Sso\SsoReturnTo;
use Illuminate\Support\Carbon;

/**
 * SSO step-up re-authentication — the protocol answer to {@see RequireRecentPassword} (P1c — ADR-0016 §D22).
 *
 * ── THE PROBLEM P1b CREATED BY SUCCEEDING ───────────────────────────────────────────────────────────
 * A JIT-provisioned member's `users.password` is `Hash::make(Str::random(64))`, discarded on the spot by the
 * only process that ever held it. `RequireRecentPassword` gates the SSO metadata import, role changes,
 * member removal and ownership transfer on `auth.password_confirmed_at`, whose only writer is Fortify's
 * confirm-password form. So the moment SSO started working, four of the product's highest-blast-radius
 * actions became unreachable for precisely the people SSO exists to serve.
 *
 * The answer is a fork on IDENTITY SOURCE, never an exemption: a session established through an identity
 * provider re-proves itself at that identity provider, and a session established with a password keeps the
 * password prompt. An exemption would have said "SSO users need no step-up", which is the opposite of what
 * the gate is for.
 *
 * ── THREE CONDITIONS, AND THEY LIVE IN TWO PLACES BECAUSE THE BROWSER MAKES THEM ────────────────────
 * {@see SsoAuthIntent}'s docblock requires all of: `intent === StepUp`, `ForceAuthn` on the request that
 * produced the assertion, and a `user_id` matching the CURRENTLY AUTHENTICATED user. The first two are
 * answered at the ACS by {@see matchSubject()}. The third cannot be — the ACS is a cross-site POST and
 * `SameSite=Lax` withholds the session cookie, so there is no authenticated user there to compare against.
 * It is answered by {@see redeem()} on the same-site hop that follows. The P1c migration's docblock carries
 * the full argument.
 *
 * Read the three together: the intent says what we asked for, `user_id` says who we asked about, and
 * `force_authn` says the IdP was told not to answer from cache. Any one alone is forgeable or stale.
 */
final class SsoStepUpService
{
    public function __construct(
        private readonly SsoAuthRequestService $requests,
        private readonly TenantMembershipService $memberships,
    ) {}

    /**
     * Mint the AuthnRequest for a step-up.
     *
     * `forceAuthn: true` is not a preference: without it a compliant IdP may satisfy the request from the
     * session the member already has there, and the assertion would then prove only that they signed in at
     * some point today. The whole question a step-up asks is "is the person who started this still at the
     * keyboard", and `ForceAuthn` is the only part of the protocol that asks it.
     *
     * `$intended` is whatever `Redirect::guest()` left in `url.intended` — an absolute URL of ours, reduced
     * by {@see SsoReturnTo::sanitise()} to a path so that the column cannot hold an off-site destination.
     * Null is fine and common: a PUT or DELETE has no full URL to remember, so the framework stores the
     * referrer or nothing, and the completion hop falls back to `/dashboard`.
     */
    public function start(
        SsoConnection $connection,
        User $user,
        ?string $intended,
        ?string $ip,
    ): SsoAuthRequest {
        return $this->requests->mint(
            connection: $connection,
            intent: SsoAuthIntent::StepUp,
            user: $user,
            forceAuthn: true,
            ip: $ip,
            returnTo: SsoReturnTo::sanitise($intended),
        );
    }

    /**
     * The ACS's step-up arm: the assertion answered a step-up, so it must name the subject we asked about.
     *
     * ⚠️ THIS NEVER PROVISIONS, AND THAT IS THE DIFFERENCE FROM {@see SsoUserProvisioner}. A step-up is
     * defined against an existing session, so "we have never seen this person" is a mismatch, not a new
     * joiner — creating an account here would let a step-up round trip mint members, which is a door nobody
     * asked for and which `jit_provisioning_enabled` would not be guarding.
     *
     * The comparison is against `sso_auth_requests.user_id` — the subject recorded before the redirect, by
     * the authenticated session that asked. Resolution goes through
     * {@see TenantMembershipService::resolveUserByEmail()} on `pgsql_auth` for the reason that method
     * documents: the assertion's email is the identity key, and exact equality on it is the only predicate
     * that connection may ever carry.
     *
     * @throws SsoAuthenticationException
     */
    public function matchSubject(SsoAuthRequest $request, SsoIdentity $identity): User
    {
        // Defence in depth against a row `SsoStepUpController` could not have written. The CHECK constraint
        // pins intent↔user_id; nothing at the database pins ForceAuthn, so this is where it is pinned.
        if (! $request->force_authn) {
            throw SsoAuthenticationException::stepUpNotForced($request->request_id);
        }

        $user = $this->memberships->resolveUserByEmail($identity->email);

        if ($user === null) {
            throw SsoAuthenticationException::stepUpUnknownSubject($identity->email);
        }

        if ((string) $user->getKey() !== (string) $request->user_id) {
            throw SsoAuthenticationException::stepUpSubjectMismatch($identity->email);
        }

        // A one-column UPDATE rather than a model save, for the same reason `consume()` is one: `verified_at`
        // is not `$fillable`, and the only evidence that a signed assertion was ever presented against this
        // row must not be writable by a `fill()` somewhere else.
        SsoAuthRequest::query()->whereKey($request->getKey())->update(['verified_at' => Carbon::now()]);

        return $user;
    }

    /**
     * The completion hop: redeem a verified step-up for the actually-authenticated session, or refuse.
     *
     * Returns the row on success so the caller can read `return_to`; null on every refusal, which the
     * controller turns into the same 404 the rest of the flow answers with.
     *
     * ── ⚠️ THE ROW IS FOUND BY ITS `request_id`, NEVER BY BEING THE NEWEST ──────────────────────────
     * Two step-ups begun inside one second are a tie, and PostgreSQL breaks ties by physical order — the
     * defect P1b found in its own test helper. The id travels in the URL for exactly this reason. It is
     * 128 bits, already single-use, and on its own it is not enough to stamp anything: the session must
     * ALSO be the one the row names, which is the condition this hop exists to evaluate.
     *
     * ── THE REDEEM IS THE CONDITIONAL UPDATE, AND ITS AFFECTED-ROW COUNT IS THE CHECK ───────────────
     * `if ($row->completed_at === null) { $row->completed_at = now(); save(); }` is the shape this codebase
     * has ruled out twice; two concurrent redemptions would both read NULL and both proceed. The predicate
     * repeats what the guards above already asserted, and the repetition IS the mechanism.
     */
    public function redeem(string $requestId, User $actor, ?Carbon $now = null): ?SsoAuthRequest
    {
        $now ??= Carbon::now();

        // RLS scopes this to the current tenant. `request_id` is globally unique, so there is no second row
        // this could match even before RLS narrows it.
        $request = SsoAuthRequest::query()->where('request_id', $requestId)->first();

        if ($request === null
            || $request->intent !== SsoAuthIntent::StepUp
            || ! $request->force_authn
            || $request->verified_at === null
            || $request->completed_at !== null) {
            return null;
        }

        // The window between the ACS's redirect and the browser arriving is one network hop, so this is
        // deliberately far tighter than `authn_request_ttl_seconds` (which has to cover a human typing a
        // password and completing MFA). It bounds how long a `request_id` left in a browser's history or a
        // referrer header stays worth anything.
        if ($request->verified_at->addSeconds((int) config('saml.step_up_completion_ttl_seconds'))->isBefore($now)) {
            return null;
        }

        // THE THIRD CONDITION, and the only place in the flow where it can be asked.
        if ((string) $request->user_id !== (string) $actor->getKey()) {
            return null;
        }

        $affected = SsoAuthRequest::query()
            ->where('request_id', $requestId)
            ->whereNotNull('verified_at')
            ->whereNull('completed_at')
            ->update(['completed_at' => $now]);

        return $affected === 1 ? $request : null;
    }
}
