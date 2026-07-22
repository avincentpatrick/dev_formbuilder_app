<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\TenantStatus;
use App\Models\Audit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The two super-admin audit sites (H4). A platform suspend/reactivate is audited into the AFFECTED tenant's
| OWN log (RBAC §9 transparency), recording the acting super-admin. The audit is written under that tenant's
| context on the app connection — atomic with the status change, no elevated connection or INSERT bypass —
| so this runs entirely in-transaction with no committed fixtures.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->superAdmin = User::factory()->create();
});

it('audits a suspend into the tenant\'s own log, recording the acting super-admin', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);

    app(SuperAdminService::class)->suspendTenant($tenant, $this->superAdmin);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended->value);

    enterTenant($tenant->id);
    $audit = Audit::query()
        ->where('auditable_type', 'tenant')
        ->where('auditable_id', $tenant->id)
        ->where('event', AuditEvent::Updated->value)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->tenant_id)->toBe($tenant->id);          // in the tenant's OWN log
    expect($audit->user_id)->toBe($this->superAdmin->id);  // decision 2: the acting super-admin is recorded
    expect($audit->is_system_action)->toBeFalse();         // a human acted
    expect($audit->old_values['status'])->toBe('active');
    expect($audit->new_values['status'])->toBe('suspended');
});

it('audits a reactivate', function (): void {
    $tenant = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'status' => 'suspended']);

    app(SuperAdminService::class)->reactivateTenant($tenant, $this->superAdmin);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active->value);

    enterTenant($tenant->id);
    $audit = Audit::query()->where('auditable_id', $tenant->id)->where('event', AuditEvent::Updated->value)->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values['status'])->toBe('suspended');
    expect($audit->new_values['status'])->toBe('active');
});

it('does not leak the affected tenant context into the rest of the central-host request', function (): void {
    $tenant = Tenant::create(['name' => 'Gamma', 'slug' => 'gamma', 'status' => 'active']);

    app(SuperAdminService::class)->suspendTenant($tenant, $this->superAdmin);

    // The console runs on the central host with no tenant context; the audit write must not leave one behind.
    expect(TenantContext::currentTenantId())->toBeNull();
});
