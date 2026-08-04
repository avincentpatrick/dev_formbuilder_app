<?php

declare(strict_types=1);

use App\Http\Middleware\InitializeTenancyByPublicHost;
use App\Services\Tenancy\CustomDomainService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H22a / ADR-0012 — the custom-domain verification columns.
 *
 * `domains` is the RLS-EXEMPT resolution table (scripts/migration-lint.php:35): it is read BEFORE any tenant
 * context exists, because it is what establishes which tenant a request belongs to. It stays exempt, and this
 * alter-only migration is outside the linter's scope regardless (it keys on Schema::create).
 *
 * ── WHY THERE IS NO `kind` OR `status` COLUMN ───────────────────────────────────────────────────────
 * The DOT is the discriminator, and after H22a it is an EXACT partition of the two producers rather than a
 * heuristic: the subdomain arm can only ever hand the resolver `$parts[0]`, a single dotless label, and
 * {@see InitializeTenancyByPublicHost::isSubdomain()} guarantees the domain arm only
 * ever receives a dotted host. A `kind` column would be a SECOND source of truth that can disagree with the
 * first — and it disagrees on day one, since GeneratePdfJobTest already writes `forms.acme.com` and would
 * take any sane default. Without a default it would break sixty write sites in one commit.
 * `domains_custom_requires_token_chk` makes the two unable to drift. If a materialised discriminator is ever
 * wanted, `GENERATED ALWAYS AS (position('.' in domain) > 0) STORED` is the zero-risk addition.
 *
 * ── STATE IS DERIVED FROM THREE NULLABLE TIMESTAMPS, SO NO DEFAULT CAN BE FAIL-OPEN ─────────────────
 *   subdomain  domain has no dot                                        → always routes (the app host)
 *   pending    dotted, verified_at IS NULL                              → routes nowhere, appears in no link
 *   verified   dotted, verified_at set, activated_at NULL               → routes nowhere, appears in no link
 *   live       dotted, activated_at set                                 → routes; may appear in public links
 *
 * `verified → live` is an OPERATOR transition (`php artisan domains:activate`), never the tenant's, because
 * TLS/ACME per-domain issuance is structurally Track B and Track B is deferred. A tenant can prove control of
 * a hostname on its own; it cannot put its respondents on a plaintext origin. That is the ratified
 * "verification + routing + a manual-cert runbook, failing closed" boundary made mechanical.
 *
 * NOTE for whoever adds the next column here: stancl's Domain model is `$guarded = []`, so a mass-assigned
 * create() would happily set any of these. {@see CustomDomainService} constructs its
 * arrays explicitly for that reason, and CustomDomainClaimTest pins it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            // 256-bit CSPRNG, hex-encoded. Deliberately plaintext and deliberately NOT in
            // AuditRedactor::SECRETS: it is published in public DNS at a name only the zone's controller can
            // write, so knowing it helps nobody who does not already control that zone. See ADR-0012.
            $table->string('verification_token', 64)->nullable();
            $table->timestampTz('token_issued_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('verification_checked_at')->nullable();
            $table->string('verification_failure_reason', 40)->nullable();
        });

        // A dotted row is a custom domain and MUST carry a token. This is what stops a hand-written or
        // mass-assigned FQDN row from existing in a state the verification service never minted, and it is
        // what keeps the dot-as-discriminator honest without a second column to drift.
        DB::statement(<<<'SQL'
            ALTER TABLE domains
                ADD CONSTRAINT domains_custom_requires_token_chk
                CHECK (position('.' in domain) = 0 OR verification_token IS NOT NULL)
        SQL);

        // Token reuse across rows becomes a database error rather than a review question. Partial, because
        // every subdomain row leaves it NULL and NULLs are not comparable under a plain unique index anyway —
        // stated explicitly so the predicate is not read as decoration.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX domains_verification_token_unique
                ON domains (verification_token)
                WHERE verification_token IS NOT NULL
        SQL);

        // The sweep's access path: oldest-checked-first among custom rows. Partial so it stays small — a
        // deployment has one subdomain row per tenant and, initially, no custom rows at all.
        DB::statement(<<<'SQL'
            CREATE INDEX domains_custom_sweep_idx
                ON domains (verification_checked_at NULLS FIRST)
                WHERE position('.' in domain) > 0
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS domains_custom_sweep_idx');
        DB::statement('DROP INDEX IF EXISTS domains_verification_token_unique');
        DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_custom_requires_token_chk');

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn([
                'verification_token',
                'token_issued_at',
                'verified_at',
                'activated_at',
                'verification_checked_at',
                'verification_failure_reason',
            ]);
        });
    }
};
