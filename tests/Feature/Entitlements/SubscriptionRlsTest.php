<?php

declare(strict_types=1);

use App\Enums\BillingInterval;
use App\Enums\PlanTier;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// subscriptions + usage_counters carry tenant_id and get the strict RLS shape. These prove the isolation
// on the real tables (the SQL generator itself is covered by TenantIsolationSqlTest): a foreign tenant's
// rows are invisible, and a write of a foreign tenant_id is denied by WITH CHECK (ADR-0002 §D1, ADR-0008).

beforeEach(function (): void {
    TenantContext::flush();
    $this->plan = Plan::factory()->tier(PlanTier::Free)->create(); // plans is global (no RLS)
    $this->acme = inboxTenant('acme');
    $this->beta = inboxTenant('beta');
});

it('makes one tenant\'s subscription invisible to another', function (): void {
    enterTenant($this->acme->id);
    Subscription::factory()->forPlan($this->plan)->create();
    expect(Subscription::count())->toBe(1);

    enterTenant($this->beta->id);
    expect(Subscription::count())->toBe(0);
});

it('denies inserting a subscription for another tenant (WITH CHECK)', function (): void {
    enterTenant($this->beta->id);

    $foreign = new Subscription;
    $foreign->forceFill([
        'tenant_id' => $this->acme->id, // not the active context
        'plan_id' => $this->plan->id,
        'name' => 'default',
        'stripe_status' => 'active',
        'billing_interval' => BillingInterval::Monthly,
    ]);

    expect(fn () => $foreign->save())->toThrow(QueryException::class);
});

it('makes one tenant\'s usage counters invisible to another', function (): void {
    enterTenant($this->acme->id);
    UsageCounter::factory()->create();
    expect(UsageCounter::count())->toBe(1);

    enterTenant($this->beta->id);
    expect(UsageCounter::count())->toBe(0);
});
