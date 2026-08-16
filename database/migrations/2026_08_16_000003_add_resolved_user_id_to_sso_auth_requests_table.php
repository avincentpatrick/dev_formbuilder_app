<?php

declare(strict_types=1);

use App\Enums\SsoAuthIntent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sso_auth_requests.resolved_user_id` — Phase 4, P1e (ADR-0016 §D27–§D28).
 *
 * P1c gave the STEP-UP arm a same-site completion hop because `SameSite=Lax` withholds the session cookie from
 * the identity provider's cross-site POST. P1e gives the LOGIN arm the same hop, for the same cookie policy and
 * a different reason: the ACS cannot tell whether the browser posting an assertion is the browser that STARTED
 * the flow, so an attacker holding an account at the tenant's own directory could obtain a valid assertion and
 * induce a victim's browser to submit it. This column is what makes the hop possible.
 *
 * ── ⚠️ WHY A NEW COLUMN AND NOT `user_id`, WHICH IS SITTING RIGHT THERE ──────────────────────────────
 * Because `sso_auth_requests_step_up_user_check` forbids it, and that constraint is right. Its own comment in
 * the P1a migration says why: "a login request WITH one would let a consumed login assertion masquerade as a
 * step-up". Widening it to admit a post-validation subject on a login row re-opens exactly the door it was
 * written to close, and three separate docblocks — `SsoLoginController`, `SsoAuthRequestService::mint()` and
 * `SsoStepUpService::matchSubject()` — cite that constraint by name and assert what it forbids. All three stay
 * true here without being touched.
 *
 * The deeper reason is §D24's, applied to a subject instead of a timestamp. `user_id` is **who we asked the
 * identity provider about** — written before the redirect, by an authenticated session that already knew.
 * `resolved_user_id` is **who the assertion turned out to name** — written after validation, on behalf of a
 * caller who was anonymous when the row was minted. One is a question and the other is an answer, and
 * collapsing a question and an answer into one column is the mistake §D24 refused for `consumed_at`.
 *
 * ── WHY `verified_at` AND `completed_at` ARE REUSED WHEN THIS COLUMN IS NOT ─────────────────────────
 * The same test, answered the other way. §D24 forbids collapsing two DIFFERENT events into one column; it does
 * not forbid two intents sharing a fact they genuinely share. "A signed assertion answering this request
 * validated" and "the browser came back same-site and the flow finished, once" are the same two events at the
 * same two moments in the same order on both arms. A parallel `login_verified_at` pair would be one column
 * wearing two names, and it would fork `sso_auth_requests_completion_order_check`, the trim's liveness
 * predicate and every future reader's model of this table on `intent`, for no fact gained.
 *
 * ⚠️ Which means the P1c migration's "Null on every login row" stopped being true, and it is corrected in the
 * same commit that makes it false rather than left for the next reader to disbelieve.
 *
 * ── THE TWO CHECKS, AND WHY THE SECOND ONE IS THE INTERESTING ONE ───────────────────────────────────
 * The first says a resolved subject cannot exist on a step-up row, nor before the evidence that a signed
 * assertion was ever presented. The second is the `google_auth_requests_identity_check` posture: a VERIFIED
 * LOGIN ROW CARRIES ITS SUBJECT, so the completion hop can never find a row it is entitled to redeem and have
 * nobody to sign in. That is not defensive tidying — it is what makes "sign in whoever this row names" a
 * statement the database guarantees rather than one the service remembers to arrange. The constraint and the
 * single UPDATE that satisfies it were designed together, which is why `SsoAuthRequestService::markVerified()`
 * writes both columns in one statement and cannot be split.
 *
 * ── THE FOREIGN KEY IS SINGLE-COLUMN ON PURPOSE, AND THE TARGET IS NAMED ON PURPOSE ─────────────────
 * ADR-0002 §D5's composite-FK rule exists because a referential action bypasses row security and could cross
 * tenants. It does not reach `users`, which carries no `tenant_id` and therefore has no boundary to cross —
 * the same reason `sso_auth_requests.user_id` is single-column today and absent from
 * `ConstraintBoundaries::FOREIGN_KEY_EXCEPTIONS`. ⚠️ `constrained('users')` names its target EXPLICITLY
 * because `scripts/constraint-boundary-lint.php` resolves a bare `constrained()` by stemming the column name,
 * and `resolved_user_id` stems to a table nobody has ever created — which would leave the constraint
 * statically unreadable and move a gate number that is supposed to stay at zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_auth_requests', function (Blueprint $table): void {
            // WHO the assertion resolved to, written by the ACS in the same statement as `verified_at` and
            // never at mint, where an unauthenticated caller has no subject to name. Read exactly once, by the
            // same-site hop, which re-reads the subject on the DEFAULT connection and NEVER through
            // `Auth::loginUsingId()` — that resolves on `pgsql_auth`, whose select policy is `USING (true)`,
            // i.e. every account in the deployment with no membership predicate at all. See the controller.
            //
            // ⚠️ `ON DELETE CASCADE`, AND THAT IS A REAL CONSEQUENCE RATHER THAN A DEFAULT. Before P1e a login
            // row referenced no user at all, so deleting an account left the request ledger intact; now
            // deleting one prunes every login row that resolved to them. `ON DELETE SET NULL` — the obvious
            // alternative — is UNAVAILABLE by construction: `sso_auth_requests_login_resolution_check` refuses
            // a verified login row carrying no subject, so nulling the column would violate it. The ledger
            // property this table's retention policy describes ("distinguish expired from already used from
            // never existed") therefore does not survive an erasure for that subject's own rows.
            $table->foreignUuid('resolved_user_id')->nullable()->constrained('users')->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE sso_auth_requests ADD CONSTRAINT sso_auth_requests_login_subject_check '
            ."CHECK (resolved_user_id IS NULL OR (intent = '".SsoAuthIntent::Login->value."' AND verified_at IS NOT NULL))"
        );

        DB::statement(
            'ALTER TABLE sso_auth_requests ADD CONSTRAINT sso_auth_requests_login_resolution_check '
            ."CHECK (verified_at IS NULL OR intent <> '".SsoAuthIntent::Login->value."' OR resolved_user_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sso_auth_requests DROP CONSTRAINT IF EXISTS sso_auth_requests_login_resolution_check');
        DB::statement('ALTER TABLE sso_auth_requests DROP CONSTRAINT IF EXISTS sso_auth_requests_login_subject_check');

        Schema::table('sso_auth_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_user_id');
        });
    }
};
