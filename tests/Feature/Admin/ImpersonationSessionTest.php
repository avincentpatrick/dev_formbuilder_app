<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\TenantUserStatus;
use App\Http\Middleware\EnforceImpersonationTimeout;
use App\Models\Audit;
use App\Models\ImpersonationToken;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Admin\ImpersonationService;
use App\Support\Audit\ImpersonationContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Redeeming, running and ending an impersonated session — Increment I11b.
|--------------------------------------------------------------------------
| The tenant-host half. ImpersonationMintTest covers the console side.
|
| These cases drive the SERVICE and the middleware rather than the HTTP routes, for one reason: the arrival
| route lives on a tenant subdomain, and a Pest request against it would exercise stancl's host resolution
| rather than anything this increment wrote. The route wiring is proved by the e2e spec, which is the only
| place a real cross-host hop exists. What is proved here is every rule the route delegates.
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

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

afterEach(function (): void {
    ImpersonationContext::forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Mint through the real service and hand back the plaintext the URL carries. */
function mintPlainToken(): string
{
    $url = app(ImpersonationService::class)->mint(
        test()->tenant,
        test()->operator->id,
        test()->member->id,
        '203.0.113.7',
    );

    return (string) substr($url, (int) strrpos($url, '/') + 1);
}

/** Redeeming happens with the tenant GUC set and NO authenticated user — the arrival route's condition. */
function redeemAsArrival(string $plain): mixed
{
    return DB::transaction(function () use ($plain): mixed {
        TenantContext::applyLocal(test()->tenant->id);

        try {
            return app(ImpersonationService::class)->redeem($plain, '198.51.100.4');
        } finally {
            TenantContext::applyLocal(null, null);
        }
    });
}

function inTenantScope(callable $fn): mixed
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

it('redeems a fresh token, returning the member AND the operator behind them', function (): void {
    $redeemed = redeemAsArrival(mintPlainToken());

    expect($redeemed)->not->toBeNull()
        ->and($redeemed->user->id)->toBe($this->member->id)
        // ⭐ The operator id is the whole reason redeem() returns a pair. Without it the controller has no
        // way to write the session marker, and `AuditLogger` would attribute every subsequent action to the
        // member alone — silently, and exactly the way I11a exists to prevent.
        ->and($redeemed->operatorId)->toBe($this->operator->id);
});

it('marks the token consumed and stamps the REDEEMER’s ip', function (): void {
    redeemAsArrival(mintPlainToken());

    $token = inTenantScope(fn (): ?ImpersonationToken => ImpersonationToken::query()->first());

    expect($token->consumed_at)->not->toBeNull()
        // The mint ip is already on the `impersonation_started` audit row; what this column can uniquely
        // answer afterwards is whether the address that redeemed is the one that asked.
        ->and($token->ip_address)->toBe('198.51.100.4');
});

it('refuses the SAME token twice', function (): void {
    $plain = mintPlainToken();

    expect(redeemAsArrival($plain))->not->toBeNull()
        // ⭐ The replay guard. Drop `whereNull('consumed_at')` from scopeRedeemable() and this is the case
        // that reddens — a stolen URL would otherwise stay usable for its whole minute, repeatedly.
        ->and(redeemAsArrival($plain))->toBeNull();
});

it('refuses an expired token', function (): void {
    $plain = mintPlainToken();

    inTenantScope(function (): void {
        ImpersonationToken::query()->update(['expires_at' => now()->subSecond()]);
    });

    // ⭐ The TTL guard. Drop the `expires_at` predicate and this reddens while every other case here stays
    // green, which is the point of testing the two halves of `redeemable()` separately.
    expect(redeemAsArrival($plain))->toBeNull();
});

it('refuses an unknown token without disclosing that it is unknown', function (): void {
    // Same null as expired and as already-consumed. The controller turns every one into the same 404.
    expect(redeemAsArrival(bin2hex(random_bytes(32))))->toBeNull();
});

it('refuses a token whose member lost their membership after it was minted', function (): void {
    $plain = mintPlainToken();

    inTenantScope(function (): void {
        TenantUser::query()->where('user_id', $this->member->id)
            ->update(['status' => TenantUserStatus::Removed->value]);
    });

    // ⭐ THE RE-CHECK. Sixty seconds is enough for an Owner to revoke access, and a mint-only check would
    // let this grant outlive the authority it was issued against. Delete the isEligible() call in redeem()
    // and this is the only case in the suite that goes red.
    expect(redeemAsArrival($plain))->toBeNull();
});

it('refuses a token whose member became platform staff after it was minted', function (): void {
    $plain = mintPlainToken();

    // ⚠️ INSIDE THE TENANT CONTEXT, and the first draft of this case was wrong without it. PostgreSQL
    // applies SELECT policies to an UPDATE that carries a WHERE clause, so with no context set
    // `users_users_visibility` matched nothing, the update affected ZERO rows, raised nothing, and the case
    // "passed" against a member who had never been promoted. A silent no-op write is the same trap
    // SuperAdminService::updatePlatformSettings() documents for `settings`.
    inTenantScope(function (): void {
        User::query()->whereKey($this->member->id)->update(['is_super_admin' => true]);
    });

    expect(redeemAsArrival($plain))->toBeNull();
});

it('cannot be redeemed from another workspace’s host', function (): void {
    $plain = mintPlainToken();
    $otherTenant = inboxTenant('northwind');

    // ⭐ THE ISOLATION CASE, and the reason strict RLS is the right shape for this table: the lookup is by
    // hash, so the ONLY thing stopping a token minted for acme from resolving on northwind's host is the
    // policy. Nothing in the query says "tenant".
    $redeemed = DB::transaction(function () use ($plain, $otherTenant): mixed {
        TenantContext::applyLocal($otherTenant->id);

        try {
            return app(ImpersonationService::class)->redeem($plain, '198.51.100.4');
        } finally {
            TenantContext::applyLocal(null, null);
        }
    });

    expect($redeemed)->toBeNull();

    // And the real token is untouched — a failed cross-tenant attempt must not burn it.
    expect(inTenantScope(fn (): ?ImpersonationToken => ImpersonationToken::query()->first())->consumed_at)
        ->toBeNull();
});

it('writes the ended row as the target, by the operator, in the tenant ledger', function (): void {
    inTenantScope(function (): void {
        app(ImpersonationService::class)->recordEnded($this->operator->id, $this->member->id);
    });

    $audit = inTenantScope(fn (): ?Audit => Audit::query()
        ->where('event', AuditEvent::ImpersonationEnded->value)->first());

    expect($audit)->not->toBeNull()
        ->and($audit->tenant_id)->toBe($this->tenant->id)
        ->and($audit->user_id)->toBe($this->member->id)
        ->and($audit->acting_as_user_id)->toBe($this->operator->id);
});

it('refuses at the DATABASE to record an operator impersonating themselves', function (): void {
    // ⭐ The I11a CHECK, exercised through I11b's own writer. Its realistic trigger is not a bug in this
    // service but an `impersonator_id` left in a session after exit — so this is the guard that catches
    // what the two application-level checks structurally cannot see.
    expect(fn (): mixed => inTenantScope(function (): void {
        app(ImpersonationService::class)->recordEnded($this->member->id, $this->member->id);
    }))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| The 30-minute cap (EnforceImpersonationTimeout).
|--------------------------------------------------------------------------
| ⚠️ THE REQUEST MUST CARRY AN ATTACHED SESSION STORE, not just a seeded one. Laravel's `session()` helper
| writes to the session MANAGER; `StartSession` is what attaches a store to the REQUEST. A test that seeded
| via the helper alone left `$request->hasSession()` false, and the middleware — which reads the deadline
| off the request — threw rather than measuring anything. That is the same two-stores trap
| `ImpersonationContext::fromSession()` documents from the other side, and it is why this helper exists
| instead of four hand-rolled `request()` calls.
*/

/** Drive the middleware on a request shaped the way `StartSession` + `auth` would have shaped it. */
function runTimeoutMiddleware(User $actor): Response
{
    $request = Request::create('/dashboard');
    $request->setLaravelSession(session()->driver());
    $request->setUserResolver(static fn (): User => $actor);

    return app(EnforceImpersonationTimeout::class)
        ->handle($request, static fn (): Response => response('ok'));
}

it('lets an unexpired impersonated session through untouched', function (): void {
    enterTenant($this->tenant->id, $this->member->id);
    Auth::login($this->member);
    session([
        ImpersonationContext::SESSION_KEY => $this->operator->id,
        ImpersonationService::DEADLINE_SESSION_KEY => now()->addMinutes(5)->getTimestamp(),
    ]);

    $response = runTimeoutMiddleware($this->member);

    expect($response->getContent())->toBe('ok')
        ->and(Auth::check())->toBeTrue()
        ->and(session(ImpersonationContext::SESSION_KEY))->toBe($this->operator->id);
});

it('ends the session once the deadline passes, writing the ledger row', function (): void {
    enterTenant($this->tenant->id, $this->member->id);
    Auth::login($this->member);
    session([
        ImpersonationContext::SESSION_KEY => $this->operator->id,
        ImpersonationService::DEADLINE_SESSION_KEY => now()->subSecond()->getTimestamp(),
    ]);

    runTimeoutMiddleware($this->member);

    expect(Auth::check())->toBeFalse()
        ->and(session(ImpersonationContext::SESSION_KEY))->toBeNull();

    $audit = inTenantScope(fn (): ?Audit => Audit::query()
        ->where('event', AuditEvent::ImpersonationEnded->value)->first());

    // ⚠️ The compliance point of the cap: a session the operator abandoned still closes its bracket. A
    // ledger showing platform access that never appears to have finished is a record of half the access.
    expect($audit)->not->toBeNull()
        ->and($audit->acting_as_user_id)->toBe($this->operator->id);
});

it('treats a marker with no deadline as expired — fail closed', function (): void {
    enterTenant($this->tenant->id, $this->member->id);
    Auth::login($this->member);
    session([ImpersonationContext::SESSION_KEY => $this->operator->id]);

    runTimeoutMiddleware($this->member);

    // "Impersonating, with no expiry" is not a state this feature has a use for. Flip the guard to let it
    // through and an edited session would run forever.
    expect(Auth::check())->toBeFalse();
});

it('does not touch an ordinary session', function (): void {
    enterTenant($this->tenant->id, $this->member->id);
    Auth::login($this->member);

    $response = runTimeoutMiddleware($this->member);

    // The overwhelmingly common path. A middleware that logged ordinary users out on a missing session key
    // would be catastrophic and is exactly what the fail-closed branch above risks getting wrong.
    expect($response->getContent())->toBe('ok')
        ->and(Auth::check())->toBeTrue();

    expect(inTenantScope(fn (): int => Audit::query()
        ->where('event', AuditEvent::ImpersonationEnded->value)->count()))->toBe(0);
});
