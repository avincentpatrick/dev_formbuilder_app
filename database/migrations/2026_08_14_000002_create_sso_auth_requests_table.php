<?php

declare(strict_types=1);

use App\Enums\SsoAuthIntent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per AuthnRequest this SP has minted (Phase 4, P1a — ADR-0016). It is the SP's memory of "I asked
 * for this", and the ACS's `InResponseTo` check is a lookup against it.
 *
 * ── WHY THIS TABLE EXISTS AT ALL, WHEN THE CONNECTOR SEAM DELIBERATELY AVOIDED ONE ────────────────────
 * ADR-0009 §D3 rejects a server-side nonce store for connector OAuth and derives `state` statelessly under
 * an HMAC. The first draft of this row's plan proposed reusing that trick. **It is the wrong tool here, and
 * ADR-0009 itself says so**: §D3's stateless `state` is declared insufficient the moment "a callback gains a
 * side effect beyond writing the connection it names". A SAML ACS ESTABLISHES A SESSION — the largest side
 * effect in the application.
 *
 * The distinction is not stylistic. An HMAC proves WE MINTED a token. It cannot prove the token has not been
 * presented before, because verification is a pure function of the token — replaying it verifies exactly as
 * well the second time. Single-use IS replay protection, single-use requires remembering, and remembering
 * requires a store. The `consumed_at` UPDATE below is that memory, and its affected-row count is the atomic
 * check (see `SsoAcsController`).
 *
 * ── WHY ROWS ARE RETAINED RATHER THAN DELETED ON USE ─────────────────────────────────────────────────
 * `consumed_at` and `expires_at` are both kept so the ACS can distinguish "already used" from "expired" from
 * "never existed". Those are three different security events and only one of them — replay of a consumed
 * request — is an attack in progress. Deleting on consume collapses all three into "unknown request" and
 * throws away the one signal worth alerting on. A scheduled prune drops rows well past `expires_at`.
 *
 * `return_to` is stored HERE rather than echoed through SAML `RelayState`, and that is a security decision:
 * `RelayState` is attacker-controllable round-trip data, so redirecting to it is a textbook open redirect.
 * The SP chooses the destination when it mints the request and looks it up again on the way back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_auth_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Composite FK below, not foreignUuid()->constrained() — a single-column FK's referential action
            // bypasses RLS and could cross tenants (ADR-0002 §D5).
            $table->uuid('sso_connection_id');

            // '_' + 32 hex = 33 chars. `xs:ID` may not begin with a digit, and a bare hex string sometimes
            // does — a spec violation some IdPs reject outright and others echo back mangled.
            //
            // UNIQUE GLOBALLY, not per tenant: this value is matched from an UNAUTHENTICATED request body
            // before any tenant scoping can be trusted, so a collision across tenants would be a second row
            // a cross-tenant read could match. 128 bits of entropy makes that unreachable anyway; the
            // constraint is here so it is impossible rather than improbable.
            $table->char('request_id', 33)->unique();

            $table->string('intent', 20);
            // Step-up only: WHO we asked the IdP to re-authenticate. Null for a plain login, where there is
            // no authenticated user yet.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            // Chosen by the SP at mint time. NEVER populated from SAML RelayState — see the class docblock.
            $table->string('return_to', 500)->nullable();

            $table->boolean('force_authn')->default(false);
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();   // NULL = never used
            $table->string('ip_address', 45)->nullable();     // 45 = INET6_ADDRSTRLEN
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'sso_connection_id'], 'sso_auth_requests_connection_fk')
                ->references(['tenant_id', 'id'])->on('sso_connections')->cascadeOnDelete();

            // The prune scan, and the "is this request still live?" lookup.
            $table->index(['tenant_id', 'expires_at'], 'sso_auth_requests_tenant_expires_idx');
        });

        $intents = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            SsoAuthIntent::values()
        ));
        DB::statement(
            'ALTER TABLE sso_auth_requests ADD CONSTRAINT sso_auth_requests_intent_check '
            ."CHECK (intent IN ({$intents}))"
        );

        // A step-up request without a subject is meaningless, and a login request WITH one would let a
        // consumed login assertion masquerade as a step-up. Pin both directions at the database.
        DB::statement(
            'ALTER TABLE sso_auth_requests ADD CONSTRAINT sso_auth_requests_step_up_user_check '
            ."CHECK ((intent = '".SsoAuthIntent::StepUp->value."' AND user_id IS NOT NULL) "
            ."OR (intent = '".SsoAuthIntent::Login->value."' AND user_id IS NULL))"
        );

        withTenantIsolation('sso_auth_requests'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_auth_requests');
    }
};
