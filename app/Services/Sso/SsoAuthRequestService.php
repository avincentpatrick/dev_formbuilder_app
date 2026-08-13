<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoAuthIntent;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The SP's memory of the AuthnRequests it has minted (Phase 4, P1b — ADR-0016 §D8).
 *
 * Three operations and the split between the last two is the whole design:
 *
 *   · {@see mint()}     — before the redirect.
 *   · {@see findLive()} — a READ, performed before the assertion is validated, so the expected
 *     `InResponseTo` can be handed to the validator.
 *   · {@see consume()}  — an atomic conditional UPDATE, performed only once validation has SUCCEEDED.
 *
 * ── ⚠️ WHY LOOK-UP AND CONSUME ARE TWO CALLS AND NOT ONE ─────────────────────────────────────────────
 * Consuming at look-up time would let any unauthenticated caller invalidate a legitimate pending login by
 * posting a body carrying its `InResponseTo` — a denial of service that costs the attacker one request and
 * costs the victim a restarted sign-in with no explanation. Reading first and consuming last means a
 * garbage POST changes nothing, while single-use still binds at the only moment it matters: the instant
 * before a session is created.
 *
 * The reverse order (consume first, then validate) is what the ADR's "affected-row count is the check"
 * rules out for a different reason, and both orders are safe from the RACE precisely because the UPDATE is
 * conditional. What is NOT safe is `if ($row->consumed_at === null) { $row->consumed_at = now(); save(); }`
 * — two concurrent replays both read NULL and both proceed. That shape must never appear here.
 *
 * ── EXPIRY IS EVALUATED WITHOUT CLOCK SKEW ──────────────────────────────────────────────────────────
 * {@see SsoAuthRequest::isLive()} says why, and this class agrees with it by construction: `expires_at` is
 * a value this host wrote and reads back, so there is no second clock to disagree with. The
 * `config('saml.clock_skew_seconds')` allowance belongs to timestamps the IDP wrote, and is applied by
 * {@see SsoAssertionValidator}.
 */
final class SsoAuthRequestService
{
    /**
     * Record an AuthnRequest about to be sent.
     *
     * `$user` is non-null only for a step-up (P1c): the `sso_auth_requests_step_up_user_check` CHECK pins
     * both directions at the database, so a login carrying a subject — which would let a consumed login
     * assertion masquerade as a re-authentication — is refused by PostgreSQL rather than by convention.
     */
    public function mint(
        SsoConnection $connection,
        SsoAuthIntent $intent = SsoAuthIntent::Login,
        ?User $user = null,
        bool $forceAuthn = false,
        ?string $ip = null,
        ?string $returnTo = null,
    ): SsoAuthRequest {
        $now = Carbon::now();

        $request = new SsoAuthRequest;
        $request->fill([
            // BelongsToTenant fills tenant_id from the ambient context; the composite FK then confines the
            // row to the same tenant as the connection it names.
            'sso_connection_id' => (string) $connection->getKey(),
            'request_id' => SsoAuthRequest::mintRequestId(),
            'intent' => $intent,
            'user_id' => $user?->getKey() === null ? null : (string) $user->getKey(),
            'return_to' => $returnTo,
            'force_authn' => $forceAuthn,
            'issued_at' => $now,
            'expires_at' => $now->copy()->addSeconds((int) config('saml.authn_request_ttl_seconds')),
            'ip_address' => $ip,
        ])->save();

        return $request;
    }

    /**
     * The still-consumable request an assertion claims to answer, or null.
     *
     * Null covers four different things on purpose — never minted, minted by another tenant, already
     * consumed, expired. The ACS collapses all four into the same 404 (§D4); the distinction survives in
     * the row itself, which is why {@see consume()} stamps rather than deletes.
     *
     * RLS is the tenant scoping. `request_id` is UNIQUE GLOBALLY rather than per tenant precisely because
     * this value is matched from an unauthenticated request body before any tenant claim can be trusted.
     */
    public function findLive(string $requestId, ?Carbon $now = null): ?SsoAuthRequest
    {
        return SsoAuthRequest::query()
            ->where('request_id', $requestId)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now ?? Carbon::now())
            ->first();
    }

    /**
     * Burn the request. The AFFECTED-ROW COUNT IS THE CHECK — never a read followed by a write.
     *
     * The predicate repeats `consumed_at IS NULL` and the expiry even though {@see findLive()} already
     * asserted both, and the repetition is the entire mechanism: between that read and this write another
     * request may have consumed the same row, and only the database can settle which one did. A `false`
     * return means someone else won, which the caller must treat exactly as an invalid assertion.
     */
    public function consume(SsoAuthRequest $request, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        $affected = SsoAuthRequest::query()
            ->where('request_id', $request->request_id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now]);

        return $affected === 1;
    }
}
