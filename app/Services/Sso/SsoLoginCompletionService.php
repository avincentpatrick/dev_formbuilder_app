<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoAuthIntent;
use App\Models\SsoAuthRequest;
use Illuminate\Support\Carbon;

/**
 * Redeeming a verified login for the browser that started it (Phase 4, P1e — ADR-0016 §D27).
 *
 * {@see SsoStepUpService::redeem()} with a different guard set and a different third condition, and the
 * difference is the whole reason this is a separate class rather than a flag on that one. A step-up asks
 * "is the session in front of me the one this row NAMES", and it can, because that session is authenticated.
 * A login has no authenticated session to ask about — establishing one is what it is FOR — so it asks
 * "did this browser START this flow", and the answer lives in the session rather than on the row.
 *
 * ── ⚠️ WHAT THIS DELIBERATELY DOES *NOT* CHECK, AND WHY EACH ABSENCE IS SAFE ────────────────────────
 * `consumed_at` and `expires_at` are not consulted. By the time a row reaches here the ACS has already
 * consumed it, so a liveness predicate would refuse every legitimate redemption — {@see redeem()}'s bound is
 * `verified_at` plus the completion window instead, exactly as the step-up arm's is. `force_authn` is not
 * consulted either: it is a step-up's guarantee that the identity provider was told not to answer from cache,
 * and a plain login neither asks for it nor is weakened by its absence.
 *
 * ── THE ROW IS FOUND BY ITS `request_id`, NEVER BY BEING THE NEWEST ─────────────────────────────────
 * Two sign-ins begun inside one second are a tie and PostgreSQL breaks ties by physical order — the defect
 * P1b found in its own test helper. The id travels in the URL for that reason, and it is safe there for the
 * reason §D24 already gives: on its own it authorises nothing. The session must ALSO be one that minted it.
 */
final class SsoLoginCompletionService
{
    /**
     * Redeem a verified login, or refuse.
     *
     * Returns the row on success so the caller can read `resolved_user_id` and `return_to`; null on every
     * refusal, which the controller turns into the same 404 the rest of the flow answers with (§D4).
     *
     * ⚠️ THE REDEEM IS THE CONDITIONAL UPDATE AND ITS AFFECTED-ROW COUNT IS THE CHECK. The predicate repeats
     * what the guards above already asserted, and the repetition IS the mechanism: between the read and the
     * write another request may have redeemed the same row, and only the database can settle which one did.
     * `if ($row->completed_at === null) { ... }` is the shape this codebase has ruled out three times.
     *
     * ⚠️ AND THE CALLER MUST NOT SIGN ANYBODY IN BEFORE THIS RETURNS. Two concurrent GETs on one completion
     * URL — a double click, a link prefetch, a speculative navigation — both pass the guards; one loses the
     * UPDATE. If the login happened first, the loser would answer 404 from a request that had already
     * established a session, which is the affected-row count being consulted and then ignored.
     */
    public function redeem(string $requestId, ?Carbon $now = null): ?SsoAuthRequest
    {
        $now ??= Carbon::now();

        // RLS scopes this to the current tenant, so a handoff minted for one workspace and presented on
        // another's host matches nothing. There is deliberately no `tenant_id` predicate: writing one would
        // suggest the guarantee lived in PHP.
        $request = SsoAuthRequest::query()->where('request_id', $requestId)->first();

        if ($request === null
            || $request->intent !== SsoAuthIntent::Login
            || $request->verified_at === null
            // Unreachable while `sso_auth_requests_login_resolution_check` stands, and asserted anyway: this
            // is what the guard would have to be if that constraint were ever dropped, and a hop that signs
            // in `null` is worse than one that refuses.
            || $request->resolved_user_id === null
            || $request->completed_at !== null) {
            return null;
        }

        // The window between the ACS's redirect and the browser arriving is one network hop, so this is
        // deliberately far tighter than `authn_request_ttl_seconds`, which has to cover a human authenticating
        // at their identity provider. It bounds how long a `request_id` left in a browser's history, a
        // referrer header or a proxy log stays worth anything. Its own key rather than the step-up's: a knob
        // named for one arm silently governing the other is how an operator's change stops meaning what they
        // read it to mean.
        if ($request->verified_at->addSeconds((int) config('saml.login_completion_ttl_seconds'))->isBefore($now)) {
            return null;
        }

        $affected = SsoAuthRequest::query()
            ->where('request_id', $requestId)
            ->whereNotNull('verified_at')
            ->whereNotNull('resolved_user_id')
            ->whereNull('completed_at')
            ->update(['completed_at' => $now]);

        return $affected === 1 ? $request : null;
    }
}
