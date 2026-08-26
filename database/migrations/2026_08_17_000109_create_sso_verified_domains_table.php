<?php

declare(strict_types=1);

use App\Enums\DomainVerificationFailure;
use App\Services\Sso\SsoDomainService;
use App\Services\Sso\SsoUserProvisioner;
use App\Support\Tenancy\DnsTxtResolver;
use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The email domains a workspace has PROVEN it controls (M18 — ADR-0016 §D34).
 *
 * ── THE FACT THIS TABLE EXISTS TO HOLD, AND WHY NOTHING ELSE HELD IT ────────────────────────────────
 * A SAML connection is a trust anchor a workspace installs for itself. It vouches for "somebody
 * authenticated at the identity provider I configured" and for nothing whatsoever about WHICH ADDRESSES
 * that provider is entitled to speak for. Until this table there was no place in the schema for the second
 * fact, so `SsoUserProvisioner` had to take the assertion's word for it: a grep of `app/`, `database/` and
 * `config/` for `verified_domain|domain_verified|assertEmailDomain|domainOwn` returned **zero hits**.
 *
 * Two controls stood in for the missing fact and both are membership-layer refusals — M1's
 * `existingAccountNotMember()` and M9's `establishedIdentityNotJoined()`. Each closed a real takeover; neither
 * could establish that `acme.test`'s connection is entitled to speak for `acme.test` at all, which is why
 * `docs/security-threat-model.md` residual 32 records the gap as the ROOT of both rather than as their
 * sibling. {@see SsoUserProvisioner} is the single consumer — see its docblock for where the check sits in
 * the sequence and why the order is load-bearing.
 *
 * ── SCOPED TO THE TENANT, NOT TO THE CONNECTION, AND THAT IS A DECISION ─────────────────────────────
 * There is no foreign key to `sso_connections` here. A WORKSPACE controls a domain; an identity provider does
 * not, and binding the proof to the connection row would destroy it the moment an admin re-imported metadata
 * (`SsoConnectionService::delete()` drops the row and `sso_connections_tenant_id_unique` means the replacement
 * is a new one). Control of a DNS zone does not lapse because somebody rotated a SAML certificate.
 *
 * It also keeps this table out of `ConstraintBoundaryDriftTest`'s composite-FK census, which is a consequence
 * rather than the reason.
 *
 * ── ⚠️ `(tenant_id, domain)` IS UNIQUE; `domain` ALONE IS NOT — AND THE PRODUCT CONSEQUENCE IS REAL ──
 * ADR-0002 §D5 forbids a unique key that spans tenant boundaries, so a global unique on `domain` was never
 * available. That is the right answer on the merits anyway: **two workspaces may each verify the same email
 * domain**, each publishing its own token. One controller legitimately runs two workspaces, and a global
 * unique would have let whichever claimed first deny the other — turning a control designed to stop squatting
 * into a squatting primitive. `domains.domain` IS globally unique, and correctly so, because that table
 * answers "which tenant does this HOST resolve to" and two answers there would be a routing ambiguity. This
 * table answers a question that tolerates two truthful answers.
 *
 * ── STATE IS TWO NULLABLE TIMESTAMPS, THE `domains` SHAPE, MINUS THE HALF THAT DOES NOT APPLY ───────
 *   pending   `verified_at IS NULL`   → the row exists and grants NOTHING. Present so the token has somewhere
 *                                       to live between minting and publication.
 *   verified  `verified_at` set       → assertions naming this domain may reach {@see SsoUserProvisioner}.
 *
 * There is no `activated_at` and no `is_primary`. Custom domains have those because activation puts
 * respondents on an origin that needs a certificate installed by hand (ADR-0012 §D6); nothing is served from
 * an email domain, so the operator gate that exists there has nothing to gate here and the tenant may finish
 * the flow alone.
 *
 * ⚠️ NO PERIODIC RE-VERIFICATION, AND IT IS FILED RATHER THAN FORGOTTEN. `CustomDomainService::sweep()`
 * re-reads a verified host on a cadence as its dangling-DNS control; `verification_checked_at` is here so the
 * column exists when that lands, but no job writes it after the first success. Two reasons, both recorded in
 * `docs/feature-backlog.md`: `routes/console.php` states that nothing runs the scheduler on the production
 * box, so a sweep would be a control that exists in the repository and not on the machine; and how long a
 * proof of control should outlive the proving is a product call, not a defect.
 *
 * ── THE TOKEN IS NOT A SECRET, AND THAT IS THE SAME DECISION `domains` ALREADY TOOK ─────────────────
 * 256-bit CSPRNG, hex-encoded, stored in plaintext and deliberately NOT in `AuditRedactor::SECRETS`: it is
 * published in public DNS at a name only the zone's controller can write, so knowing it helps nobody who does
 * not already control that zone. It is random rather than an HMAC of (tenant, domain) for the reason
 * `CustomDomainService::claim()` records — a derived token would be unrotatable per claim and would make an
 * `APP_KEY` compromise a domain-claim primitive, for no benefit.
 *
 * ⚠️ IT IS THEREFORE **EXTRACTED**, NOT WITHHELD. This table joins {@see TenantScopedTables::STRICT}, so the
 * P2b extractor reaches it; `domains.verification_token` is already extracted on exactly this reasoning and
 * the two must agree. Whoever reddens `TenantExtractColumnDriftTest` by adding a column here: decide, do not
 * copy.
 *
 * ── STRICT RLS, ON A TABLE THE UNAUTHENTICATED ACS READS ────────────────────────────────────────────
 * `sso_connections`' argument, unchanged: the protocol routes run `EstablishTenantDatabaseContext` BEFORE any
 * query, so the GUC is set by the host that resolved the tenant and never by anything in the request body. An
 * attacker choosing the tenant chooses only which subdomain to visit, which is public. The ACS has no
 * `app.current_user_id`, and it does not need one — it only ever SELECTs here.
 *
 * @see SsoDomainService for the claim → verify lifecycle and the {@see DnsTxtResolver} reuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_verified_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // 253 is the maximum length of a DNS name in presentation form (RFC 1035 §2.3.4 arithmetic).
            // Lower-cased on the way in by the service — DNS is case-insensitive, and the comparison against
            // an assertion's email domain is exact equality.
            $table->string('domain', 253);

            $table->char('verification_token', 64);
            $table->timestampTz('token_issued_at');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('verification_checked_at')->nullable();
            $table->string('verification_failure_reason', 40)->nullable();

            // Who claimed it, for the audit trail's benefit. `nullOnDelete` rather than cascade: a departed
            // admin must not take a workspace's proof of domain control with them.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // ⚠️ TENANT-LEADING, AND `domain` ALONE IS NOT UNIQUE — see the class docblock. This is also the
            // access path for the provisioner's one question ("is this domain verified for this tenant?"),
            // which is why no second index is needed for it.
            $table->unique(['tenant_id', 'domain'], 'sso_verified_domains_tenant_domain_unique');
        });

        // The failure vocabulary is pinned at the database, generated from the enum, so the reason a tenant
        // reads cannot drift from the one the resolver produces. `domains` carries the same column with no
        // CHECK; this seam's own `sso_auth_failures_reason_check` is the precedent followed here instead,
        // because a column that only ever holds enum values should say so where it is stored.
        $reasons = implode(', ', array_map(
            static fn (DomainVerificationFailure $case): string => "'".$case->value."'",
            DomainVerificationFailure::cases()
        ));
        DB::statement(
            'ALTER TABLE sso_verified_domains ADD CONSTRAINT sso_verified_domains_failure_reason_check '
            ."CHECK (verification_failure_reason IS NULL OR verification_failure_reason IN ({$reasons}))"
        );

        // A verified row cannot also carry a failure reason. The service writes both fields in one statement,
        // so this is a backstop against a future caller that sets one and forgets the other — the state a
        // reader would have to resolve by guessing which field to believe.
        DB::statement(<<<'SQL'
            ALTER TABLE sso_verified_domains
                ADD CONSTRAINT sso_verified_domains_verified_has_no_failure_chk
                CHECK (verified_at IS NULL OR verification_failure_reason IS NULL)
        SQL);

        // ⛔ NO UNIQUE INDEX ON `verification_token`, AND THE `domains` PRECEDENT DOES NOT TRANSFER.
        // `domains_verification_token_unique` earns its keep because `domains.domain` is GLOBALLY unique, so
        // a token colliding across tenants would be a real ambiguity in a shared namespace. Here it would be
        // a unique key on a `tenant_id`-carrying table that omits `tenant_id` — precisely what ADR-0002 §D5
        // forbids, and it would buy nothing: nothing ever looks a row up BY token. {@see SsoDomainService}
        // loads the row by `(tenant_id, domain)` and compares that row's OWN token, so a collision between
        // two tenants' tokens could not authorise anything even if 256 bits of CSPRNG ever produced one.
        // A constraint that cannot refuse a wrong write is decoration with a boundary cost.
        withTenantIsolation('sso_verified_domains'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_verified_domains');
    }
};
