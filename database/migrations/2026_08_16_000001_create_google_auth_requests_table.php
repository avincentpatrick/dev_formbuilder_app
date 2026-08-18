<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The in-flight state of a Google sign-in (Increment J3c2, ADR-0019 §D7).
 *
 * ── WHY A TABLE AT ALL, WHEN ADR-0009 §D3 SAYS A SIGNED HMAC IS ENOUGH ────────────────────────────────
 * Because §D3's own revisit trigger — "a flow where the callback has a side effect beyond writing the
 * connection it names" — has fired: a sign-in ESTABLISHES A SESSION, the largest side effect in the
 * application. An HMAC proves WE MINTED a token; it cannot prove the token has not been presented before.
 * Single-use IS replay protection, single-use requires remembering, and remembering requires a store.
 * `sso_auth_requests` made this exact argument for SAML and this table is its sibling.
 *
 * ⚠️ BUT ONLY ONE OF THE TWO LEGS NEEDS IT, WHICH IS WHY THIS TABLE IS NOT ALSO THE `state`. The outbound
 * leg's state stays a stateless signed token: replaying it only re-opens a consent screen, and the
 * authorization code behind it is single-use at Google. What is stored here is the HANDOFF — the hop that
 * carries a validated identity back to the tenant host, where the session is actually created.
 *
 * ⚠️ AND THE CENTRAL-HOST ARM HAS NO ROW HERE AT ALL (§D12). `tenant_id` is NOT NULL because
 * `TenantIsolation::nullableGlobalSql()` widens SELECT only — every write stays strict, so a tenant-less
 * row is literally unwritable on the app connection. The central arm needs no handoff anyway (callback and
 * session share a host), so this is a constraint agreeing with the design rather than limiting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_auth_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // 32 hex = 128 bits, the `SsoAuthRequest::mintRequestId()` budget without its leading '_'
            // (that character exists to satisfy SAML's `xs:ID`, which has no analogue in an OAuth state).
            //
            // UNIQUE GLOBALLY rather than per tenant, for the same reason the SAML table is: this value is
            // matched from an UNAUTHENTICATED callback before any tenant scoping can be trusted, so a
            // cross-tenant collision would be a second row the lookup could match.
            $table->char('state_id', 32)->unique();

            // A PATH, never a URL, sanitised by SHAPE on the way in and again on the way out — see
            // App\Support\Sso\SsoReturnTo and ADR-0016 §D25 for why a host comparison is the check that
            // keeps being got wrong.
            $table->string('return_to', 500)->nullable();

            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');           // the consent-screen window
            $table->timestampTz('consumed_at')->nullable(); // stamped by the CENTRAL callback

            // ⚠️ THE SHA-256 OF THE HANDOFF TOKEN, NEVER THE TOKEN. It travels in a URL, so it lands in
            // browser history, proxy logs and any Referer — the `impersonation_tokens` precedent, for
            // exactly the same reason. 64 hex chars.
            $table->char('handoff_hash', 64)->nullable()->unique();
            $table->timestampTz('handoff_expires_at')->nullable();

            // What Google asserted. Stored only POST-validation, which is what makes it safe to keep: an
            // address recorded from an unvalidated document would let anyone on the internet write chosen
            // text into a tenant's database (ADR-0016 §D19's disclosure rule, applied here).
            $table->string('google_sub', 255)->nullable();
            $table->string('google_email', 255)->nullable();
            $table->boolean('google_email_verified')->nullable();
            $table->string('google_name', 255)->nullable();

            // Stamped by the TENANT hop. Its presence is what makes the handoff single-use.
            $table->timestampTz('completed_at')->nullable();

            $table->string('ip_address', 45)->nullable(); // 45 = INET6_ADDRSTRLEN
            $table->timestampsTz();

            // The write-path trim scan and the "is this state still live?" lookup.
            $table->index(['tenant_id', 'expires_at'], 'google_auth_requests_tenant_expires_idx');
        });

        // ── THE ORDER OF THE FLOW, PINNED AT THE DATABASE RATHER THAN TRUSTED TO THE SERVICE ──────────
        // Each of these says "this stamp cannot exist before the one that justifies it". The handoff hash
        // is the only evidence that a validated identity was ever attached, so a row that could carry one
        // without having been consumed would let anyone holding a state id mint themselves a session.
        DB::statement(
            'ALTER TABLE google_auth_requests ADD CONSTRAINT google_auth_requests_handoff_order_check '
            .'CHECK (handoff_hash IS NULL OR consumed_at IS NOT NULL)'
        );

        DB::statement(
            'ALTER TABLE google_auth_requests ADD CONSTRAINT google_auth_requests_completion_order_check '
            .'CHECK (completed_at IS NULL OR handoff_hash IS NOT NULL)'
        );

        // A consumed row MUST carry the identity it was consumed for. Without this, a row could be marked
        // used while the columns the provisioner reads are still null, and the failure would surface as a
        // null-email lookup three layers away instead of here.
        DB::statement(
            'ALTER TABLE google_auth_requests ADD CONSTRAINT google_auth_requests_identity_check '
            .'CHECK (consumed_at IS NULL OR (google_sub IS NOT NULL AND google_email IS NOT NULL '
            .'AND google_email_verified IS NOT NULL))'
        );

        withTenantIsolation('google_auth_requests'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('google_auth_requests');
    }
};
