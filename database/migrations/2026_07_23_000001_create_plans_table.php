<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's global pricing catalog (data-dictionary §16, ADR-0008). This is the ONE table with no
 * `tenant_id`: it is shared read-only reference data every tenant reads and only a super-admin writes, so
 * it gets NO `withTenantIsolation()` and — deliberately — NO `EXEMPT_TABLES` entry either. The migration
 * linter short-circuits on any created table declaring no literal `tenant_id`, so `plans` is simply skipped;
 * adding it to the exemption allowlist would be actively misleading (exemptions are for tables that DO
 * carry a tenant identifier but omit RLS, per ADR-0002 §D2).
 *
 * Kept **Cashier-shaped** for Phase 4 (`stripe_price_id`) but **admin-assigned, no Cashier** in Phase 3
 * (ADR-0008 §D1) — nothing consumes the Stripe column yet. `feature_flags`/`quotas` are JSONB maps with no
 * DB CHECK on their keys; they are validated at the application layer against the feature-flag key set and
 * {@see UsageMetric}. A tenant's *current* plan is the plan of its active `subscriptions` row —
 * there is deliberately no `plan_id` on `tenants` (ADR-0008 §D2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code', 20)->unique(); // App\Enums\PlanTier — one row per tier
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Cashier/Stripe linkage — present but DORMANT (ADR-0008 §D1): neither laravel/cashier nor
            // stripe/stripe-php is installed and payments are Phase 4, so nothing reads this yet. NULL for a
            // free tier with no Stripe price object.
            $table->string('stripe_price_id', 100)->nullable();

            $table->integer('monthly_price_cents')->nullable();
            $table->integer('yearly_price_cents')->nullable();
            $table->string('currency', 3)->default('usd'); // ISO 4217, open reference data — not an enum

            // Which BillingInterval values this plan offers.
            $table->jsonb('billing_interval_options')->default('["monthly", "yearly"]');
            // Feature-gate key → bool (e.g. {"offline_sync": true, "custom_domain": false}).
            $table->jsonb('feature_flags')->default('{}');
            // UsageMetric key → numeric limit (absent/null key ⇒ unlimited).
            $table->jsonb('quotas')->default('{}');

            // Whether NEW subscriptions may SELECT this plan (self-serve signup). Retiring a plan keeps it
            // visible for existing subscribers rather than breaking their row. A super-admin can ASSIGN any
            // plan regardless of this flag (ADR-0008 §D6: business/enterprise seed is_active = false —
            // built + gated but held from sale — yet remain admin-assignable).
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0); // display order on the pricing page

            $table->timestampsTz();
        });

        // Pin the tier vocabulary at the database, generated from the enum so the two cannot drift.
        $codes = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            PlanTier::values()
        ));
        DB::statement(
            'ALTER TABLE plans ADD CONSTRAINT plans_code_check '
            ."CHECK (code IN ({$codes}))"
        );

        // NO withTenantIsolation(): global catalog, no tenant_id (see class docblock).
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
