<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Api\ApiAbilities;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| GET /api/v1/audits (H4) — read-only, Owner/Admin only, tenant-scoped.
|
| Real tokens via withToken() (per ApiV1Test): actingAs() resolves to a TransientToken that passes every
| ability, making the two-layer `ability:` + `can:` assertions vacuous. The Viewer test is the load-bearing
| one: a token CARRYING the ability is still refused because the acting user lacks `audit_log.view` — proof
| the policy re-checks the real principal, not just the token scope.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function auditsUrl(): string
{
    return 'http://acme.meridian.test/api/v1/audits';
}

it('lets an Admin (holding audit_log.view) list the tenant\'s audit rows', function (): void {
    app(AuditLogger::class)->record(AuditEvent::Created, 'form', Uuid::uuid7()->toString(), actorId: $this->admin->id);
    app(AuditLogger::class)->record(AuditEvent::Published, 'form_version', Uuid::uuid7()->toString(), actorId: $this->admin->id);

    $token = $this->admin->createToken('ci', [ApiAbilities::READ_AUDIT_LOG])->plainTextToken;

    $this->withToken($token)->getJson(auditsUrl())
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'auditable_type', 'auditable_id', 'event', 'user_id', 'is_system_action', 'created_at']], 'meta' => ['has_more']]);
});

it('refuses a Viewer even when their token carries the ability (the policy re-checks the user)', function (): void {
    $token = $this->viewer->createToken('ci', [ApiAbilities::READ_AUDIT_LOG])->plainTextToken;

    // The ability middleware passes (token has it), but can:viewAny,Audit denies — Viewer lacks audit_log.view.
    $this->withToken($token)->getJson(auditsUrl())->assertForbidden();
});

it('never lists another tenant\'s audit rows', function (): void {
    // A row for a different tenant, written under that tenant's context.
    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    enterTenant($other->id);
    $foreign = Audit::factory()->create();

    enterTenant($this->tenant->id, $this->admin->id);
    app(AuditLogger::class)->record(AuditEvent::Created, 'form', Uuid::uuid7()->toString(), actorId: $this->admin->id);

    $token = $this->admin->createToken('ci', [ApiAbilities::READ_AUDIT_LOG])->plainTextToken;

    $ids = collect($this->withToken($token)->getJson(auditsUrl())->assertOk()->json('data'))->pluck('id');
    expect($ids)->toHaveCount(1)->not->toContain($foreign->id);
});

it('returns already-redacted values — a secret is never exposed through the read API', function (): void {
    // Written through AuditLogger, which redacts at write time (spec §2). The API returns stored values verbatim.
    app(AuditLogger::class)->record(
        AuditEvent::Updated,
        'users',
        (string) $this->admin->id,
        old: ['password' => 'old-secret'],
        new: ['password' => 'new-secret', 'name' => 'Ada'],
        actorId: $this->admin->id,
    );

    $token = $this->admin->createToken('ci', [ApiAbilities::READ_AUDIT_LOG])->plainTextToken;

    $row = $this->withToken($token)->getJson(auditsUrl().'?auditable_type=users')->assertOk()->json('data.0');

    expect($row['new_values']['password'])->toBe('[REDACTED]');
    expect($row['new_values']['name'])->toBe('Ada');
    expect($row['redacted_fields'])->toContain('password');
});
