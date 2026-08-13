<?php

declare(strict_types=1);

use App\Enums\SsoConnectionStatus;
use App\Enums\SsoProtocol;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's federated-identity trust anchor (Phase 4, P1a — ADR-0016). One row per tenant, holding the
 * IdP half of a SAML 2.0 Service Provider relationship. Strictly tenant-scoped, ordinary writes → the plain
 * strict RLS shape.
 *
 * ── WHY STRICT RLS IS SAFE ON A TABLE THE UNAUTHENTICATED ACS MUST READ ──────────────────────────────
 * The same argument `impersonation_tokens` records: the protocol routes run `EstablishTenantDatabaseContext`
 * BEFORE any query, so the GUC is set by the host that resolved the tenant, not by anything in the request
 * body. An attacker choosing the tenant chooses only which subdomain to visit — which is public.
 *
 * ── `idp_certificates` IS ENCRYPTED, AND THAT IS AN INTEGRITY CLAIM, NOT A CONFIDENTIALITY ONE ────────
 * A signing certificate is public by construction; an IdP publishes it in metadata anyone may fetch. It is
 * encrypted anyway because it is the TRUST ANCHOR: anything that can silently rewrite this column can make
 * this application accept assertions minted by a key of the attacker's choosing, which is a total
 * authentication bypass for that tenant. Laravel's `encrypted` cast is authenticated (AES-256-CBC + HMAC),
 * so a tampered value fails to decrypt loudly instead of validating against a substituted key. The column is
 * TEXT rather than varchar for the reason `webhook_endpoints.secret` records — the cast base64s a JSON
 * envelope, and this one wraps a multi-KB PEM list.
 *
 * `idp_certificates_fingerprint` sits beside it in PLAINTEXT, deliberately: an `encrypted` column cannot be
 * compared or displayed without decrypting, and three consumers need a stable identity for the trust anchor
 * — the audit row's old/new values, the settings screen's "current signing key", and the re-import
 * "did this actually change?" check. Same shape as `WebhookEndpoint::maskedSecret()`.
 *
 * `default_role_name` is a STRING WITH A CHECK, not an FK to `roles`. Spatie's `roles` are teams-scoped and
 * RLS'd, and the catalog rows carry `tenant_id IS NULL`; `TenantMembershipService` already resolves a role by
 * `where('name', …)->whereNull('tenant_id')` and takes a `string $roleName`. Storing the name keeps the
 * column and its only consumer the same type with no translation layer. `owner` is excluded — SSO must not
 * be able to mint an owner, and ownership transfer is a separate step-up-guarded action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('protocol', 20)->default(SsoProtocol::Saml2->value);
            $table->string('status', 20)->default(SsoConnectionStatus::Draft->value);

            // SAML entity ids and endpoints are URIs; the specification sets no practical length bound.
            $table->string('idp_entity_id', 1024);
            $table->string('idp_sso_url', 1024);          // HTTP-Redirect SingleSignOnService Location

            // Encrypted at rest (see the class docblock — an integrity claim). Holds a LIST: an IdP publishes
            // old and new certificates simultaneously during a key rollover, and refusing the successor is
            // how a rotation becomes an outage.
            $table->text('idp_certificates');
            $table->char('idp_certificates_fingerprint', 64);

            $table->char('idp_metadata_sha256', 64)->nullable();
            $table->timestampTz('idp_metadata_imported_at')->nullable();

            $table->string('name_id_format', 120)
                ->default(config('saml.default_name_id_format'));

            // {email, name, first_name, last_name} → the IdP's attribute names. Empty = fall back to the
            // NameID for email and derive nothing else.
            $table->jsonb('attribute_map')->default('{}');

            $table->boolean('jit_provisioning_enabled')->default(true);   // decision of record, 2026-08-13
            $table->string('default_role_name', 30)->default('viewer');

            $table->timestampTz('last_login_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // One connection per tenant in P1. A multi-IdP tenant is a real enterprise ask, but it changes
            // the LOGIN surface (the visitor must choose an IdP before any redirect), so it belongs with a
            // deliberate UX decision rather than arriving free with a nullable column.
            $table->unique('tenant_id', 'sso_connections_tenant_id_unique');

            // Composite-FK target for `sso_auth_requests` (ADR-0002 §D5, the `webhook_endpoints` precedent):
            // PostgreSQL runs referential actions BYPASSING RLS, so a single-column FK could let one tenant's
            // delete cascade into another tenant's row.
            $table->unique(['tenant_id', 'id'], 'sso_connections_tenant_id_id_unique');

            $table->index(['tenant_id', 'status'], 'sso_connections_tenant_status_idx');
        });

        // Pin all three vocabularies at the database, generated from their sources so they cannot drift.
        $quote = static fn (array $values): string => implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $values
        ));

        DB::statement(
            'ALTER TABLE sso_connections ADD CONSTRAINT sso_connections_protocol_check '
            .'CHECK (protocol IN ('.$quote(SsoProtocol::values()).'))'
        );
        DB::statement(
            'ALTER TABLE sso_connections ADD CONSTRAINT sso_connections_status_check '
            .'CHECK (status IN ('.$quote(SsoConnectionStatus::values()).'))'
        );

        // `owner` is deliberately not assignable by SSO — see the class docblock.
        $assignableRoles = array_values(array_filter(
            RolePermissionSeeder::ROLES,
            static fn (string $role): bool => $role !== 'owner'
        ));
        DB::statement(
            'ALTER TABLE sso_connections ADD CONSTRAINT sso_connections_default_role_check '
            .'CHECK (default_role_name IN ('.$quote($assignableRoles).'))'
        );

        withTenantIsolation('sso_connections'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_connections');
    }
};
