<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sso_auth_requests.verified_at` and `.completed_at` — Phase 4, P1c (ADR-0016 §D22–§D24).
 *
 * Step-up re-authentication needs TWO facts the P1a table could not hold, and the reason it needs two is a
 * property of the browser rather than of SAML.
 *
 * ── ⚠️ WHY A STEP-UP CANNOT BE FINISHED AT THE ACS, WHICH IS THE WHOLE REASON THESE COLUMNS EXIST ─────
 * `config/session.php` sets `same_site` to `lax`. The ACS is a CROSS-SITE, TOP-LEVEL POST from an identity
 * provider, so the browser does not send the session cookie with it — `SsoAcsController`'s docblock has said
 * so since P1b, in the course of explaining why the endpoint is CSRF-exempt. For a LOGIN that is harmless:
 * `Auth::login()` creates the session on that very request. For a STEP-UP it is fatal, because a step-up is
 * defined against a session that already exists, and `auth.password_confirmed_at` has to be written INTO
 * that session. At the ACS there is nothing to write into and no `Auth::id()` to compare `user_id` against.
 *
 * So the flow gains one same-site hop. The ACS validates the assertion and stamps {@see verified_at}; it
 * then redirects the browser to a GET on the tenant's own host, and `SameSite=Lax` DOES send cookies on a
 * top-level GET navigation — so that request arrives on the ORIGINAL session, where the comparison and the
 * stamp both mean something. {@see completed_at} is what makes the hop single-use.
 *
 * ── TWO COLUMNS, NOT ONE, AND NOT A REUSE OF `consumed_at` ───────────────────────────────────────────
 * `consumed_at` answers "has this AuthnRequest been answered by an assertion", and it is stamped for logins
 * and step-ups alike. Overloading it would make "the assertion arrived" and "the browser came back and the
 * clock was stamped" indistinguishable — three distinct events collapsed into one column, which is the exact
 * mistake the table's own docblock rejects when it explains why rows are retained rather than deleted.
 *
 * `completed_at` is redeemed by a conditional UPDATE whose affected-row count is the check, the same shape
 * as `SsoAuthRequestService::consume()`. Neither column is `$fillable`, for the reason that one is not:
 * a read-then-write leaves a window in which two concurrent redemptions both see NULL and both proceed.
 *
 * ── THE CHECK CONSTRAINT ENCODES THE ORDER ──────────────────────────────────────────────────────────
 * A row cannot be completed without first having been verified. That is not a tidiness rule: the verify
 * stamp is the ONLY evidence that a signed assertion was ever presented, so a redemption that could precede
 * it would let anyone holding a `request_id` — a value that travels in a URL — stamp the step-up clock. The
 * application enforces it too; this is the layer that cannot be bypassed by a future second writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_auth_requests', function (Blueprint $table): void {
            // The ACS's mark: a signed assertion answering THIS request was validated, and its subject
            // resolved. ⚠️ "Null on every login row" was true when this was written and stopped being true in
            // P1e, which gave the login arm the same hop for the same cookie policy — corrected here rather
            // than left for the next reader to disbelieve.
            $table->timestampTz('verified_at')->nullable();

            // The completion hop's mark: the browser came back on the original session and the flow finished.
            // Single-use. On a step-up that means `auth.password_confirmed_at` was stamped; on a login (P1e)
            // it means `Auth::loginUsingId()` ran. Both are "the browser came back", which is why one column.
            $table->timestampTz('completed_at')->nullable();
        });

        DB::statement(
            'ALTER TABLE sso_auth_requests ADD CONSTRAINT sso_auth_requests_completion_order_check '
            .'CHECK (completed_at IS NULL OR verified_at IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sso_auth_requests DROP CONSTRAINT IF EXISTS sso_auth_requests_completion_order_check');

        Schema::table('sso_auth_requests', function (Blueprint $table): void {
            $table->dropColumn(['verified_at', 'completed_at']);
        });
    }
};
