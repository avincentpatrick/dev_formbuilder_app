<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\GoogleAuthRequest;
use App\Models\Tenant;
use App\Services\Sso\SsoAuthFailureRecorder;
use App\Services\Sso\SsoAuthRequestService;
use App\Support\Auth\GoogleAuthState;
use App\Support\Auth\GoogleAuthStateService;
use App\Support\Auth\GoogleIdentity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The three writes of one Google sign-in (Increment J3c2, ADR-0017 §D7) — {@see SsoAuthRequestService}'s
 * sibling, and deliberately the same shapes.
 *
 *   · {@see mint()}   — tenant host, unauthenticated. A row with a `state_id` and nothing asserted.
 *   · {@see attach()} — central host, once Google's answer verifies. Stamps `consumed_at`, the identity
 *     and a handoff hash.
 *   · {@see redeem()} — tenant host again. Stamps `completed_at`; the session is created only after this.
 *
 * ── ⚠️ EVERY STATE TRANSITION IS A CONDITIONAL UPDATE WHOSE AFFECTED-ROW COUNT IS THE CHECK ──────────
 * `if ($row->consumed_at === null) { $row->consumed_at = now(); save(); }` is the shape this codebase has
 * now ruled out three times: two concurrent replays both read NULL and both proceed, and one Google
 * consent becomes two sessions. Each predicate below repeats what the caller has already established, and
 * **the repetition IS the mechanism** — between a read and a write another request may have won, and only
 * the database can settle which one did.
 *
 * ── ⚠️ WHY `mint()` BORROWS A TENANT CONTEXT AND THE OTHER TWO DO NOT ────────────────────────────────
 * `/auth/google/redirect` is served on the central host AND every tenant subdomain from ONE route, exactly
 * as Fortify serves `/login` (`config/fortify.php`'s `'domain' => null` + `RequirePlatformHost`), because
 * the central arm of this flow has to work too (ADR-0017 §D12). That route therefore carries no tenancy
 * middleware and there is no ambient GUC — and `google_auth_requests` is STRICT-RLS, so without one the
 * INSERT is REFUSED, not mis-scoped. So it borrows the context itself, inside `DB::transaction` because
 * {@see TenantContext::applyLocal()} is `SET LOCAL` and a silent no-op outside one, restoring in `finally`.
 * That is `TenantSettingRegistry::forTenant()`'s idiom, on the same class of context-free auth route.
 * `attach()` runs behind {@see \App\Http\Middleware\EstablishGoogleAuthContext} and `redeem()` behind the
 * full tenant pipeline, so both already have a real GUC and neither may borrow one.
 *
 * ── ⚠️ THE WRONG-TENANT REFUSAL IS RLS, NOT AN `if` ──────────────────────────────────────────────────
 * A handoff minted for workspace A and presented on workspace B's host matches nothing, because the
 * completion route established B's context before this class was reached. There is deliberately no
 * `tenant_id` predicate anywhere below: under strict isolation there cannot be a cross-tenant row to match,
 * and writing one would suggest the guarantee lived in PHP.
 */
final class GoogleAuthRequestService
{
    public function __construct(private readonly GoogleAuthStateService $states) {}

    /**
     * Open a sign-in and return the signed `state` that carries it across the host boundary.
     *
     * ⚠️ THE CENTRAL ARM GETS A STATE AND NO ROW, AND THAT IS NOT AN OPTIMISATION (ADR-0017 §D12). The
     * central callback and the session it creates share a host, so there is nothing for a handoff to carry
     * — and `TenantIsolation::nullableGlobalSql()` widens SELECT only, so a tenant-less row is literally
     * unwritable on the app connection. Its replay bound is Google's single-use `code` plus the state's own
     * expiry, which is ADR-0009 §D3's original argument, still sound for the leg it was written about.
     *
     * `$returnTo` is already {@see \App\Support\Sso\SsoReturnTo::sanitise()}d by the caller — a PATH, never
     * a URL. It is re-validated on the way out too.
     */
    public function mint(?Tenant $tenant, ?string $returnTo, ?string $ip): string
    {
        $stateId = GoogleAuthRequest::mintStateId();

        if ($tenant === null) {
            return $this->states->mint(null, $stateId);
        }

        $tenantId = (string) $tenant->getKey();
        $savedTenant = TenantContext::currentTenantId();
        $savedUser = TenantContext::currentUserId();

        DB::transaction(function () use ($tenantId, $stateId, $returnTo, $ip, $savedTenant, $savedUser): void {
            TenantContext::applyLocal($tenantId);

            try {
                $now = Carbon::now();

                // BelongsToTenant fills tenant_id from the context borrowed immediately above.
                GoogleAuthRequest::query()->create([
                    'state_id' => $stateId,
                    'return_to' => $returnTo,
                    'issued_at' => $now,
                    'expires_at' => $now->copy()->addSeconds((int) config('google-auth.state.ttl_seconds')),
                    'ip_address' => $ip,
                ]);

                $this->trim($now);
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });

        // Minted AFTER the row exists. A state naming a row that failed to insert would send the browser to
        // Google for a consent this flow could never redeem.
        return $this->states->mint($tenantId, $stateId);
    }

    /**
     * Burn the state and attach the identity Google vouched for. Returns false if someone else won.
     *
     * A `false` here must be treated exactly as an invalid callback — it means the state was already spent,
     * which is a replay, and ADR-0017 §D9 gives it the same indistinguishable bounce as everything else.
     *
     * ⚠️ ONE STATEMENT, NOT AN UPDATE FOLLOWED BY A SECOND ONE FOR THE IDENTITY. The migration's
     * `google_auth_requests_identity_check` refuses a consumed row whose identity columns are null, so
     * splitting this would be rejected by PostgreSQL rather than merely being racy — the constraint and the
     * statement were designed together.
     */
    public function attach(
        GoogleAuthState $state,
        GoogleIdentity $identity,
        string $handoffHash,
        ?Carbon $now = null,
    ): bool {
        $now ??= Carbon::now();

        $affected = GoogleAuthRequest::query()
            ->where('state_id', $state->stateId)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update([
                'consumed_at' => $now,
                'handoff_hash' => $handoffHash,
                'handoff_expires_at' => $now->copy()->addSeconds((int) config('google-auth.handoff.ttl_seconds')),
                'google_sub' => $identity->subject,
                'google_email' => $identity->email,
                'google_email_verified' => $identity->emailVerified,
                'google_name' => $identity->name,
            ]);

        return $affected === 1;
    }

    /**
     * Redeem the handoff exactly once, and return the row so the caller can read the identity and
     * `return_to` it carries. Null covers every refusal — unknown, wrong tenant, expired, already redeemed.
     *
     * ⚠️ LOOKED UP BY SHA-256, NEVER BY THE PLAINTEXT. The token travels in a URL path, so it lands in
     * browser history, any proxy log and any `Referer` — the `impersonation_tokens` precedent, for exactly
     * that reason. Nothing stores the plaintext, so nothing can leak it.
     *
     * ⚠️ THE ROW IS READ FIRST AND THE UPDATE STILL REPEATS EVERY CONDITION. The read exists only to hand
     * back the columns; it establishes nothing. Two tabs opening the same link in the same tick both reach
     * the UPDATE, and exactly one of them gets `1`.
     */
    public function redeem(string $plainHandoff, ?Carbon $now = null): ?GoogleAuthRequest
    {
        $now ??= Carbon::now();

        $hash = GoogleAuthRequest::hashHandoffToken($plainHandoff);

        $row = GoogleAuthRequest::query()->where('handoff_hash', $hash)->first();

        if ($row === null) {
            return null;
        }

        $affected = GoogleAuthRequest::query()
            ->where('handoff_hash', $hash)
            ->whereNotNull('consumed_at')
            ->whereNull('completed_at')
            ->where('handoff_expires_at', '>', $now)
            ->update(['completed_at' => $now]);

        return $affected === 1 ? $row : null;
    }

    /**
     * Bound the table, in the same call as the insert (ADR-0017 §D7) — {@see SsoAuthFailureRecorder::trim()}
     * ported, because it is the same problem: an UNAUTHENTICATED endpoint writing rows.
     *
     * ⚠️ NOT A SCHEDULED PRUNE, AND THAT IS MEASURED RATHER THAN PREFERRED. `routes/console.php` records
     * that nothing runs the scheduler on the production box, so a nightly job would be a bound that exists
     * in the repository and not on the machine.
     *
     * ⚠️ THE CAP IS "NOT AMONG THE NEWEST N", NEVER "OLDER THAN THE Nth". A grinder hitting the mint route
     * writes many rows inside one second, and a timestamp comparison keeps every tied row — i.e. it fails
     * at exactly the volume it exists for. `ORDER BY issued_at DESC, id DESC` makes the ordering total (the
     * id is a UUIDv7, so it is itself time-ordered), and the subquery then names exactly N rows.
     *
     * The row just inserted is the newest and is never its own casualty.
     */
    private function trim(Carbon $now): void
    {
        $cap = (int) config('google-auth.requests.max_rows_per_tenant');
        $cutoff = $now->copy()->subDays((int) config('google-auth.requests.retention_days'));

        GoogleAuthRequest::query()
            ->where(function (Builder $query) use ($cutoff, $cap): void {
                $query->where('issued_at', '<', $cutoff)
                    ->orWhereNotIn('id', function (QueryBuilder $newest) use ($cap): void {
                        $newest->select('id')
                            ->from('google_auth_requests')
                            ->orderByDesc('issued_at')
                            ->orderByDesc('id')
                            ->limit($cap);
                    });
            })
            ->delete();
    }
}
