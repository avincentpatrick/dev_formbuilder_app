<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\PlanTier;
use App\Models\Audit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The super-admin plan-assign (H5a / ADR-0008 §D10) — the FIRST new consumer of the H4 AuditLogger. It runs
// from the central console with NO tenant context, adopts the affected tenant's context to write the
// subscription + the `subscription.updated` audit atomically on the app connection (no elevated connection,
// no bypass), and restores the context after — exactly the H4 SuperAdminService::changeStatus pattern.

beforeEach(function (): void {
    TenantContext::flush();
    $this->superAdmin = User::factory()->create();
});

it('assigns a plan and audits it into the tenant\'s own log, recording the super-admin', function (): void {
    $starter = Plan::factory()->tier(PlanTier::Starter)->create();
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);

    app(SuperAdminService::class)->assignPlan($tenant, $starter, $this->superAdmin);

    enterTenant($tenant->id);

    $sub = Subscription::query()->where('name', 'default')->first();
    expect($sub)->not->toBeNull();
    expect($sub->tenant_id)->toBe($tenant->id);
    expect($sub->plan_id)->toBe($starter->id);
    expect($sub->stripe_status)->toBe('active');

    $audit = Audit::query()
        ->where('auditable_type', 'subscription')
        ->where('auditable_id', $sub->id)
        ->where('event', AuditEvent::Updated->value)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->tenant_id)->toBe($tenant->id);           // in the tenant's OWN log
    expect($audit->user_id)->toBe($this->superAdmin->id);   // the acting super-admin is recorded
    expect($audit->is_system_action)->toBeFalse();          // a human acted
    expect($audit->old_values)->toBeNull();                 // first assignment
    expect($audit->new_values['plan_code'])->toBe('starter');
});

it('upserts the default subscription and records old→new when changing plans', function (): void {
    $free = Plan::factory()->tier(PlanTier::Free)->create();
    $pro = Plan::factory()->tier(PlanTier::Professional)->create();
    $tenant = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'active']);

    $service = app(SuperAdminService::class);
    $service->assignPlan($tenant, $free, $this->superAdmin);
    $service->assignPlan($tenant, $pro, $this->superAdmin);

    enterTenant($tenant->id);

    // Upsert, not accumulate: one default subscription, now on Professional.
    expect(Subscription::query()->where('name', 'default')->count())->toBe(1);
    $sub = Subscription::query()->where('name', 'default')->firstOrFail();
    expect($sub->plan_id)->toBe($pro->id);

    // Two audit rows on the same subscription; the latest captures free → professional.
    expect(Audit::query()->where('auditable_id', $sub->id)->count())->toBe(2);
    $latest = Audit::query()->where('auditable_id', $sub->id)->orderByDesc('id')->firstOrFail();
    expect($latest->old_values['plan_id'])->toBe($free->id);
    expect($latest->new_values['plan_id'])->toBe($pro->id);
});

it('does not leak the affected tenant context into the central-host request', function (): void {
    $starter = Plan::factory()->tier(PlanTier::Starter)->create();
    $tenant = Tenant::create(['name' => 'Gamma', 'slug' => 'gamma', 'status' => 'active']);

    app(SuperAdminService::class)->assignPlan($tenant, $starter, $this->superAdmin);

    expect(TenantContext::currentTenantId())->toBeNull();
});

it('can assign a held-from-sale plan (business) that self-serve signup could not select', function (): void {
    $business = Plan::factory()->tier(PlanTier::Business)->inactive()->create();
    $tenant = Tenant::create(['name' => 'Delta', 'slug' => 'delta', 'status' => 'active']);

    app(SuperAdminService::class)->assignPlan($tenant, $business, $this->superAdmin);

    enterTenant($tenant->id);
    expect(Subscription::query()->where('name', 'default')->value('plan_id'))->toBe($business->id);
});
