<?php

declare(strict_types=1);

use App\Services\Tenancy\CustomDomainService;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H22b / ADR-0012 — which of a tenant's LIVE custom domains its respondents' links are built on.
 *
 * `domains` is the RLS-EXEMPT resolution table and stays exempt; this alter-only migration is outside
 * `scripts/migration-lint.php`'s scope regardless (it keys on Schema::create).
 *
 * ── WHY A COLUMN AT ALL, WHEN H22a WAS DELIBERATELY DERIVED ─────────────────────────────────────────
 * H22a derived every piece of state from timestamps precisely so no default could be fail-open, and
 * ADR-0012's *When to Revisit* recorded `is_primary` as belonging "with H22b's UI, not before it". The
 * difference is that this is not STATE, it is a PREFERENCE: nothing about it can be computed from the
 * lifecycle, because both candidate rows are equally live. Until now {@see TenantUrl}
 * broke the tie by oldest-activated — deterministic, but not the tenant's choice, and unchangeable.
 *
 * `default(false)` is safe for the same reason a `status` default was not: FALSE is the FAIL-CLOSED value.
 * It means "no preference expressed — fall back to oldest-activated", which is exactly the pre-H22b
 * behaviour, so every existing row keeps emitting the host it emitted yesterday.
 *
 * ── THE CHECK IS WHAT KEEPS THIS FROM BECOMING A BACK-DOOR ACTIVATION ───────────────────────────────
 * ADR-0012 §D6 makes `php artisan domains:activate` the ONLY code path that can put a host into service,
 * and `OpenApiContractTest` asserts there is no activate endpoint. H22b adds a tenant-reachable write to
 * this table, so the constraint states in DDL what the service also enforces: a row may be primary only if
 * an operator has already activated it. A tenant chooses AMONG live hosts; it cannot create one.
 *
 * ⚠️ THE CONSEQUENCE FOR `--deactivate`: taking a live host out of service must clear this flag in the same
 * write, or the UPDATE violates the constraint. {@see CustomDomainService::deactivate()}
 * does exactly that, and ActivateCustomDomainCommandTest pins it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false);
        });

        // Only a LIVE row may be primary. Stated in DDL as well as in the service because this is the one
        // property that keeps a tenant-reachable write off the activation path (ADR-0012 §D6).
        DB::statement(<<<'SQL'
            ALTER TABLE domains
                ADD CONSTRAINT domains_primary_requires_live_chk
                CHECK (is_primary = false OR activated_at IS NOT NULL)
        SQL);

        // One primary per tenant, enforced by the DATABASE rather than by a service that could race two
        // concurrent "make this one primary" clicks into two winners. Partial, so it indexes the handful of
        // rows that are primary rather than every subdomain row in the deployment.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX domains_primary_per_tenant_unique
                ON domains (tenant_id)
                WHERE is_primary
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS domains_primary_per_tenant_unique');
        DB::statement('ALTER TABLE domains DROP CONSTRAINT IF EXISTS domains_primary_requires_live_chk');

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn('is_primary');
        });
    }
};
