<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoAuthIntent;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\Auth\GoogleAuthRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        // ⚠️ HOUSEKEEPING MUST NEVER DECIDE WHETHER A SIGN-IN BEGINS. The trim is a DELETE added to the
        // authentication mint path, and the realistic way it fails is a deadlock: two concurrent mints for
        // one tenant issue DELETEs over overlapping row sets with no guaranteed lock-acquisition order.
        // Unguarded, that turns "the table is a little larger than it should be" into `GET /sso/saml/login`
        // answering 500 — an outage strictly worse than the unbounded growth this bound exists to stop.
        // {@see SsoAuthFailureRecorder::record()} swallows for the same reason one layer over.
        //
        // ⚠️ DELIBERATELY UNTESTED, AND THE REASON IS THE ONE P2a RECORDED FOR ITS OWN MISSING CASE.
        // `RefreshDatabase` wraps every test in a transaction, and a raised PostgreSQL error ABORTS that
        // transaction — so a case that forced this failure could not then observe a recovered request, and
        // the "fix" it invited would be pinning the harness's behaviour instead of the product's. In
        // production `mint()` runs in no transaction at all, which is what makes the recovery real.
        try {
            $this->trim($now);
        } catch (Throwable $error) {
            Log::warning('sso.authn_request.trim_failed', ['error' => $error->getMessage()]);
        }

        return $request;
    }

    /**
     * Drop dead rows past the retention window or beyond the row cap, for the current tenant (P1d).
     *
     * ⚠️ WHY THIS IS ON THE WRITE PATH AND NOT IN THE SCHEDULER. `config/saml.php` and this table's own
     * migration both described "a scheduled prune" and neither a command nor a schedule entry was ever
     * written — while `routes/console.php` records that nothing runs the scheduler on the production box, so
     * even a written one would have been a bound existing in this repository and not on the machine. The
     * endpoint that fills this table is unauthenticated and writes one row per hit, which is the same
     * argument {@see SsoAuthFailureRecorder::trim()} already makes for the table next door.
     *
     * ⚠️⚠️ THE LIVENESS PREDICATE IS THE OUTER `AND`, AND IT IS THE WHOLE DESIGN RATHER THAN A REFINEMENT.
     * A rank-only cap of this shape was ported onto a table of LIVE rows in J3c2 and produced unauthenticated
     * denial of *authentication* — anybody could push a member's pending row past the cap while they sat on
     * a consent screen. Only a SPENT row is eligible here, so no in-flight sign-in is evictable by anyone at
     * any volume — see {@see whereSpent()}, which is where "spent" is defined and where P1e had to correct
     * it, because a consumed row is in flight until its browser comes back.
     *
     * Deleting a spent row cannot re-enable a replay — {@see findLive()} requires the row to EXIST and be
     * unconsumed and unexpired, so an absent row is refused exactly as a consumed one is; what is lost is
     * resolution in the log, where a pruned replay reads as "never minted" rather than "already used".
     *
     * The cap is "not among the newest N", never "older than the Nth": a grinder writes many rows inside one
     * second and a timestamp comparison keeps every tied row, i.e. fails at exactly the volume it exists for.
     * `ORDER BY issued_at DESC, id DESC` makes the ordering total. RLS is the tenant scoping — there is no
     * `tenant_id` predicate because under strict isolation there cannot be a cross-tenant row to match.
     */
    private function trim(Carbon $now): void
    {
        $cap = (int) config('saml.authn_request_max_rows');
        $cutoff = $now->copy()->subDays((int) config('saml.authn_request_retention_days'));
        $deadline = $now->copy()->subSeconds(max(
            (int) config('saml.step_up_completion_ttl_seconds'),
            (int) config('saml.login_completion_ttl_seconds'),
        ));

        SsoAuthRequest::query()
            ->where(fn (Builder $dead) => $this->whereSpent($dead, $now, $deadline))
            ->where(function (Builder $beyond) use ($cutoff, $cap, $now, $deadline): void {
                $beyond->where('issued_at', '<', $cutoff)
                    ->orWhereNotIn('id', function (QueryBuilder $newest) use ($cap, $now, $deadline): void {
                        $newest->select('id')
                            ->from('sso_auth_requests')
                            // The cap counts SPENT rows only, so a burst of live ones cannot consume the
                            // allowance and make the retained history shorter than it says it is. ⚠️ It must
                            // use the SAME predicate as the outer WHERE, or the cap counts one population
                            // and the DELETE removes another.
                            ->where(fn (QueryBuilder $dead) => $this->whereSpent($dead, $now, $deadline))
                            ->orderByDesc('issued_at')
                            ->orderByDesc('id')
                            ->limit($cap);
                    });
            })
            ->delete();
    }

    /**
     * When a row has stopped being needed by anybody.
     *
     * ⚠️⚠️ "CONSUMED" STOPPED MEANING "FINISHED" IN P1e, AND THIS PREDICATE WAS ONE STATE BEHIND IT.
     * It used to read `consumed_at IS NOT NULL OR expires_at <= now`. But the ACS consumes a row and then
     * hands the browser back to a same-site hop — so between those two requests a row is CONSUMED AND STILL
     * IN FLIGHT, and this predicate called it dead. P1d's own docblock promised "no in-flight sign-in is
     * evictable by anyone at any volume", and that promise was false the moment P1c introduced the hop.
     *
     * It was reachable rather than theoretical. `issued_at` is the MINT, up to `authn_request_ttl_seconds`
     * (600) before the consume, so a member who spends three minutes at their identity provider owns a row
     * ranked three minutes down an `issued_at DESC` ordering at the instant it became eligible. Enough
     * newer spent rows — and `GET /sso/saml/login` is unauthenticated at 60/min per address — and the next
     * mint's trim deletes it. The victim is answered 404 after successfully authenticating, and the log says
     * "unknown request", pointing an operator at replay rather than at eviction.
     *
     * That is the identical misdiagnosis {@see GoogleAuthRequestService::trim()} records for J3c2, and the
     * corrected three-armed shape is that method's — ported FORWARD to the sibling table when it was written
     * and never back to the table it came from. Under P1e the same eviction lands on the LOGIN path, where
     * it stops being "a step-up must be retried" and becomes unauthenticated denial of authentication for a
     * whole workspace, so it is fixed here rather than inherited.
     *
     * Read it as: redeemed; or never reached a signed assertion and its request window has closed; or
     * reached one and its completion window has closed. **A row is no longer spent merely by being
     * consumed** — that fact says an assertion answered it, not that nobody needs it any more, which is
     * §D24's distinction applied to the housekeeping side.
     *
     * ⚠️ THE HONEST BOUND, STATED RATHER THAN DISCOVERED. Two costs. A consumed row whose fork then refused
     * — a subject mismatch, a suspended member, an exhausted seat — now survives to its own `expires_at`
     * instead of being immediately evictable, which is minutes against a seven-day retention window. And
     * the table's ceiling gains a third term: N spent rows, plus whatever the limiter admits inside one
     * request TTL, plus whatever the tenant's own identity provider signs inside one completion window.
     * The third term is bounded by the trust anchor and is ninety seconds wide.
     *
     * `$deadline` is the LATER of the two completion windows on purpose: a trim that read one arm's knob
     * would turn an operator raising the other's into exactly the eviction described above.
     *
     * @param  Builder<SsoAuthRequest>|QueryBuilder  $query
     */
    private function whereSpent(Builder|QueryBuilder $query, Carbon $now, Carbon $deadline): void
    {
        $query->whereNotNull('completed_at')
            ->orWhere(function (Builder|QueryBuilder $unanswered) use ($now): void {
                $unanswered->whereNull('verified_at')->where('expires_at', '<=', $now);
            })
            ->orWhere(function (Builder|QueryBuilder $abandoned) use ($deadline): void {
                $abandoned->whereNotNull('verified_at')->where('verified_at', '<', $deadline);
            });
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

    /**
     * The ACS's mark: a signed assertion answering this row validated, and here is who it named.
     *
     * ⚠️ ONE STATEMENT, AND IT CANNOT BE SPLIT. `sso_auth_requests_login_resolution_check` refuses a verified
     * login row that carries no subject, so writing `verified_at` first and `resolved_user_id` afterwards is
     * not merely racy — PostgreSQL rejects the first half outright. The constraint and this statement were
     * designed together, which is the `google_auth_requests_identity_check` posture: a half-written row fails
     * at the database rather than surfacing three layers away as a sign-in with nobody to sign in.
     *
     * A one-column-family UPDATE rather than a model save, for the reason {@see consume()} is one: neither
     * column is `$fillable`, and the only evidence that a signed assertion was ever presented against this
     * row must not be reachable by a `fill()` somewhere else.
     *
     * `$subject` is null on the step-up arm, where the subject was named at MINT and lives on `user_id` — the
     * fact this method records there is only "an assertion answered it". On the login arm the subject could
     * not have been known at mint, so it arrives here.
     */
    public function markVerified(SsoAuthRequest $request, ?User $subject = null, ?Carbon $now = null): void
    {
        $columns = ['verified_at' => $now ?? Carbon::now()];

        if ($subject !== null) {
            $columns['resolved_user_id'] = (string) $subject->getKey();
        }

        SsoAuthRequest::query()->whereKey($request->getKey())->update($columns);
    }
}
