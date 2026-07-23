<?php

declare(strict_types=1);

use App\Enums\BillingInterval;
use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's subscription to a plan (data-dictionary §17, ADR-0008). Strictly tenant-scoped — one row-set
 * per tenant, ordinary writes — so it gets the plain strict RLS shape. Kept Cashier-shaped
 * (`stripe_customer_id`/`stripe_subscription_id`/`stripe_status`) for Phase 4, but **admin-assigned, no
 * Cashier** in Phase 3: a super-admin assigns a plan through the console and the write sets
 * `stripe_status = 'active'`.
 *
 * `stripe_status` is deliberately **free text with NO CHECK and no PHP enum** — the one explicitly-flagged
 * exception to the enum-everywhere rule (data-dictionary Conventions, §17 Design Notes): Stripe owns that
 * vocabulary (`trialing`/`active`/`past_due`/`canceled`/…) and can extend it outside the app's release
 * cycle, so a local CHECK would risk hard-rejecting a legitimate future status. A tenant's current plan is
 * the plan of its ACTIVE subscription — `stripe_status ∈ {active, trialing}` AND `ended_at IS NULL`
 * ({@see Subscription::scopeActive}); an unsubscribed tenant resolves to the `free` tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // restrictOnDelete: never orphan a live subscription by deleting the plan out from under it
            // (retire a plan with is_active = false instead).
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();

            // Cashier's internal subscription-name slot — supports multiple concurrent subscriptions per
            // tenant (add-ons) beyond the primary plan. H5a assigns the primary 'default' slot.
            $table->string('name', 50)->default('default');

            // External billing identifiers — present but DORMANT until Phase 4 (ADR-0008 §D1).
            $table->string('stripe_customer_id', 100)->nullable();
            $table->string('stripe_subscription_id', 100)->nullable();
            // FREE TEXT, no CHECK, no enum — mirrors Stripe's own status vocabulary verbatim (see docblock).
            $table->string('stripe_status', 40);

            $table->string('billing_interval', 10); // App\Enums\BillingInterval
            $table->integer('quantity')->default(1); // seat count, where applicable

            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_starts_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->timestampTz('cancels_at')->nullable();   // scheduled cancel-at-period-end
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();

            $table->timestampsTz();

            // EXPLICIT SHORT NAMES (PostgreSQL NAMEDATALEN = 63), all tenant_id-leading (ADR-0002 §D1).
            $table->index(['tenant_id', 'name'], 'subscriptions_tenant_name_idx');   // resolve the 'default' slot
            $table->index(['tenant_id', 'plan_id'], 'subscriptions_tenant_plan_idx'); // reverse: who is on a plan
        });

        // Pin the billing-interval vocabulary at the database, from the enum so the two cannot drift.
        $intervals = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            BillingInterval::values()
        ));
        DB::statement(
            'ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_billing_interval_check '
            ."CHECK (billing_interval IN ({$intervals}))"
        );

        withTenantIsolation('subscriptions'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
