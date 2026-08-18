<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\NotificationType;
use App\Enums\TenantUserStatus;
use App\Models\Audit;
use App\Models\ImpersonationToken;
use App\Models\Notification;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Admin\ImpersonationService;
use App\Support\Audit\ImpersonationContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Minting an impersonation grant — Increment I11b, rbac §9 resolved decision 1.
|--------------------------------------------------------------------------
| The console half: eligibility, the token row, the ledger entry in the AFFECTED TENANT's log, and the
| Owner's notification. The consume half is ImpersonationConsumeTest.
|
| ⚠️ THE MINT RUNS WITH NO AMBIENT TENANT CONTEXT, which is the whole reason this is interesting. Every
| write it performs lands in a strict-RLS table, so each case below is also a test that the adopted-context
| transaction actually adopted something — an assertion that a row EXISTS is, here, an assertion that the
| `SET LOCAL` inside `DB::transaction()` was not a silent no-op.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->operator = User::factory()->create(['is_super_admin' => true]);

    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->tenant->forceFill(['owner_user_id' => $this->owner->id])->save();

    enterTenant($this->tenant->id, $this->member->id);
    makeActiveMember($this->member, 'form_editor');

    // The console's real condition: no tenant, no team. Anything below that depends on ambient context is
    // therefore depending on context the service established for itself.
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

afterEach(function (): void {
    ImpersonationContext::forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function mintFor(User $operator, User $target): string
{
    return app(ImpersonationService::class)->mint(test()->tenant, $operator->id, $target->id, '203.0.113.7');
}

/** Read a strict-RLS table from outside any request, the way an assertion must. */
function inTenant(callable $fn): mixed
{
    return DB::transaction(function () use ($fn): mixed {
        TenantContext::applyLocal(test()->tenant->id);

        try {
            return $fn();
        } finally {
            TenantContext::applyLocal(null, null);
        }
    });
}

it('mints a redeemable token and returns a URL on the tenant APP host', function (): void {
    $url = mintFor($this->operator, $this->member);

    // The APP host, never the public/custom one: ADR-0009 §D2 scopes a custom domain to the guest runtime,
    // and an operator signing in is the authenticated app by definition.
    expect($url)->toContain('acme.')
        ->and($url)->toContain('/impersonate/');

    $token = inTenant(fn (): ?ImpersonationToken => ImpersonationToken::query()->first());

    expect($token)->not->toBeNull()
        ->and($token->operator_id)->toBe($this->operator->id)
        ->and($token->target_user_id)->toBe($this->member->id)
        ->and($token->consumed_at)->toBeNull()
        ->and($token->ip_address)->toBe('203.0.113.7');
});

it('never stores the plaintext token — only its sha256', function (): void {
    // ⭐ THE MUTATION GUARD FOR THE HASHING. Store the plaintext and this is the only case that reddens;
    // every functional test would still pass, because a plaintext column round-trips perfectly.
    $url = mintFor($this->operator, $this->member);
    $plain = (string) substr($url, (int) strrpos($url, '/') + 1);

    $token = inTenant(fn (): ?ImpersonationToken => ImpersonationToken::query()->first());

    expect($plain)->toHaveLength(64)
        ->and($token->token_hash)->not->toBe($plain)
        ->and($token->token_hash)->toBe(hash('sha256', $plain));

    // And nothing anywhere in the row carries it, including a column added later.
    $raw = inTenant(fn (): array => (array) DB::table('impersonation_tokens')->first());
    expect(json_encode($raw))->not->toContain($plain);
});

it('expires the grant in 60 seconds, not in a session lifetime', function (): void {
    mintFor($this->operator, $this->member);

    $token = inTenant(fn (): ?ImpersonationToken => ImpersonationToken::query()->first());

    // A handoff, not a credential. Asserted as a bound rather than an equality so the case survives a
    // clock tick, but tight enough that widening the TTL to minutes reddens it.
    expect($token->expires_at->diffInSeconds(now()))->toBeLessThanOrEqual(ImpersonationService::TOKEN_TTL_SECONDS)
        ->and($token->expires_at->isAfter(now()))->toBeTrue();
});

it('writes the started row into the TENANT ledger, as the target, by the operator', function (): void {
    mintFor($this->operator, $this->member);

    $audit = inTenant(fn (): ?Audit => Audit::query()
        ->where('event', AuditEvent::ImpersonationStarted->value)
        ->first());

    expect($audit)->not->toBeNull()
        // The affected tenant's OWN log — §9's transparency posture. A NULL tenant_id here would put it in
        // the platform ledger, which is the one place the decision of record says it must not be.
        ->and($audit->tenant_id)->toBe($this->tenant->id)
        // `user_id` is the EFFECTIVE actor and `acting_as_user_id` is the real human. Reversing them would
        // still write a row, still look well-formed, and silently invert what I11a exists to record.
        ->and($audit->user_id)->toBe($this->member->id)
        ->and($audit->acting_as_user_id)->toBe($this->operator->id);
});

it('leaves no operator pinned in the static after the mint', function (): void {
    // ⭐ `ImpersonationContext::set()` is a STATIC. On a long-lived worker one that survived this call would
    // attribute the next, unrelated action to an operator who had gone home — the failure the class's own
    // docblock warns about. Delete the `finally` in recordBoundary() and only this case reddens.
    mintFor($this->operator, $this->member);

    expect(ImpersonationContext::operatorId())->toBeNull();
});

it('notifies the workspace OWNER, and names the member rather than the operator', function (): void {
    mintFor($this->operator, $this->member);

    $rows = inTenant(fn (): array => Notification::query()
        ->where('type', NotificationType::ImpersonationStarted->value)
        ->get()
        ->all());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->user_id)->toBe($this->owner->id)
        ->and($rows[0]->data['target_user_id'])->toBe($this->member->id);

    // ⚠️ The operator's identity must not be in a payload every member of the tenant can read under the
    // strict policy. I11a's S2 finding was exactly this leak on a different surface.
    expect(json_encode($rows[0]->data))->not->toContain($this->operator->id)
        ->and(json_encode($rows[0]->data))->not->toContain($this->operator->name);
});

it('refuses to impersonate yourself', function (): void {
    // Even for an operator who IS a member — the case that makes this reachable at all.
    enterTenant($this->tenant->id, $this->operator->id);
    makeActiveMember($this->operator, 'owner');
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    expect(fn (): string => mintFor($this->operator, $this->operator))
        ->toThrow(RuntimeException::class);

    expect(inTenant(fn (): int => ImpersonationToken::query()->count()))->toBe(0);
});

it('refuses to impersonate another super-admin', function (): void {
    $otherStaff = User::factory()->create(['is_super_admin' => true]);
    enterTenant($this->tenant->id, $otherStaff->id);
    makeActiveMember($otherStaff, 'admin');
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    expect(fn (): string => mintFor($this->operator, $otherStaff))
        ->toThrow(RuntimeException::class);

    expect(inTenant(fn (): int => ImpersonationToken::query()->count()))->toBe(0);
});

it('refuses a member who is not ACTIVE', function (): void {
    $suspended = User::factory()->create();
    enterTenant($this->tenant->id, $suspended->id);
    makeActiveMember($suspended, 'form_editor');
    TenantUser::query()->where('user_id', $suspended->id)
        ->update(['status' => TenantUserStatus::Suspended->value]);
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Borrowing a suspended member's account would grant MORE access than the member themselves has —
    // the one direction impersonation must never go.
    expect(fn (): string => mintFor($this->operator, $suspended))
        ->toThrow(RuntimeException::class);
});

it('refuses a member of a DIFFERENT workspace', function (): void {
    $otherTenant = inboxTenant('northwind');
    $stranger = User::factory()->create();
    enterTenant($otherTenant->id, $stranger->id);
    makeActiveMember($stranger, 'owner');
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // ⭐ The isolation case. `isEligible()` scopes its membership check by tenant_id explicitly rather than
    // relying on ambient RLS, so this stays red if the adopted context is ever widened.
    expect(fn (): string => mintFor($this->operator, $stranger))
        ->toThrow(RuntimeException::class);
});

it('writes nothing at all when the target is ineligible', function (): void {
    // ⚠️ The whole mint is ONE transaction. A guard that threw after the audit row was written would leave
    // a ledger entry for an impersonation that never happened — an accusation, not a record.
    try {
        mintFor($this->operator, $this->operator);
    } catch (RuntimeException) {
        // expected
    }

    expect(inTenant(fn (): int => ImpersonationToken::query()->count()))->toBe(0)
        ->and(inTenant(fn (): int => Audit::query()
            ->where('event', AuditEvent::ImpersonationStarted->value)->count()))->toBe(0)
        ->and(inTenant(fn (): int => Notification::query()
            ->where('type', NotificationType::ImpersonationStarted->value)->count()))->toBe(0);
});

it('offers only eligible members in the console picker', function (): void {
    $staff = User::factory()->create(['is_super_admin' => true]);
    enterTenant($this->tenant->id, $staff->id);
    makeActiveMember($staff, 'admin');

    // The operator themselves, holding a real membership — the case I11a proved is possible.
    enterTenant($this->tenant->id, $this->operator->id);
    makeActiveMember($this->operator, 'admin');

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $ids = array_column(
        app(ImpersonationService::class)->eligibleTargets($this->tenant, $this->operator->id),
        'id'
    );

    expect($ids)->toContain($this->owner->id)
        ->and($ids)->toContain($this->member->id)
        ->and($ids)->not->toContain($staff->id)        // never another super-admin
        ->and($ids)->not->toContain($this->operator->id); // never yourself
});
