<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Http\Middleware\EnforceTenantTwoFactor;
use App\Models\ImpersonationToken;
use App\Models\User;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Audit\ImpersonationContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| POST /admin/tenants/{tenant}/impersonate — the HTTP boundary (Increment I11b).
|--------------------------------------------------------------------------
| ImpersonationMintTest proves the rules at the service. This file proves the things only the HTTP layer
| can get wrong: the console's three gates actually cover the route, the target arrives as a raw uuid, and
| the response is the 409 an Inertia XHR can follow rather than a 302 it cannot.
|
| ⚠️ THE 409 IS THE POINT OF THIS FILE. A plain redirect to another origin is what stranded I10e for four
| CI cycles, and it is invisible to every other kind of test: the mint still happens, the row is still
| written, and the operator simply lands nowhere.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant('acme');
    $this->member = User::factory()->create();
    enterTenant($this->tenant->id, $this->member->id);
    makeActiveMember($this->member, 'form_editor');

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // I8a — the console carries `step-up`; without a fresh confirmation every request here 302s to
    // /user/confirm-password instead of reaching the controller.
    confirmPasswordNow();
});

afterEach(function (): void {
    ImpersonationContext::forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Turn the workspace 2FA policy ON, through the real write path (the registry memoizes, so forget). */
function enforceTwoFactorOnAcme(): void
{
    enterTenant(test()->tenant->id, test()->member->id);
    app(TenantSettingRegistry::class)->put(
        test()->tenant,
        [SettingKey::SecurityRequireTwoFactor->value => true],
    );
    app(TenantSettingRegistry::class)->forget();
}

function impersonateUrl(): string
{
    return 'http://'.config('tenancy.central_domain').'/admin/tenants/'.test()->tenant->id.'/impersonate';
}

it('answers a 409 Inertia location pointing at the tenant host', function (): void {
    $operator = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $response = $this->actingAs($operator)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->post(impersonateUrl(), ['user_id' => $this->member->id]);

    // 409 + X-Inertia-Location is the protocol's "leave the SPA" instruction. A 302 here would be followed
    // by the XHR, return HTML where JSON was expected, and do nothing visible.
    $response->assertStatus(409);

    $location = (string) $response->headers->get('X-Inertia-Location');

    expect($location)->toContain('acme.')
        ->and($location)->toContain('/impersonate/');
});

it('actually minted the grant behind that redirect', function (): void {
    $operator = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($operator)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->post(impersonateUrl(), ['user_id' => $this->member->id])
        ->assertStatus(409);

    $token = DB::transaction(function (): ?ImpersonationToken {
        TenantContext::applyLocal(test()->tenant->id);

        try {
            return ImpersonationToken::query()->first();
        } finally {
            TenantContext::applyLocal(null, null);
        }
    });

    expect($token)->not->toBeNull()
        ->and($token->operator_id)->toBe($operator->id)
        ->and($token->target_user_id)->toBe($this->member->id);
});

it('returns a validation error rather than a redirect for an ineligible target', function (): void {
    $operator = User::factory()->superAdmin()->confirmedTwoFactor()->create();
    $stranger = User::factory()->create(); // no membership of acme

    $this->actingAs($operator)
        ->post(impersonateUrl(), ['user_id' => $stranger->id])
        ->assertSessionHasErrors('user_id');
});

it('rejects a non-uuid target before it reaches Postgres', function (): void {
    // ⚠️ NOT DECORATION. `impersonation_tokens.target_user_id` is a uuid column, so junk reaches the
    // database as SQLSTATE 22P02 — a 500 — rather than a validation message. The read side learned this in
    // I7b (PlatformAuditFilterRequest); this is the write side's version.
    $operator = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($operator)
        ->post(impersonateUrl(), ['user_id' => 'not-a-uuid'])
        ->assertSessionHasErrors('user_id');
});

it('404s for an authenticated non-super-admin', function (): void {
    // 404 rather than 403 — the console must not confirm its own existence (B2c's non-disclosure rule).
    $this->actingAs(User::factory()->create())
        ->post(impersonateUrl(), ['user_id' => $this->member->id])
        ->assertNotFound();
});

it('redirects a super-admin without confirmed 2FA to enrollment instead of minting', function (): void {
    $operator = User::factory()->superAdmin()->create();

    $this->actingAs($operator)
        ->post(impersonateUrl(), ['user_id' => $this->member->id])
        ->assertRedirect(route('admin.mfa.setup'));

    expect(DB::table('impersonation_tokens')->count())->toBe(0);
});

it('bounces a super-admin whose password confirmation has gone stale', function (): void {
    // PRD Feature #14 names the console a high-blast-radius surface, and nothing in it is higher than this
    // route. `step-up` is INSIDE the mfa group, so an operator confirms a password only after enrolling.
    $operator = User::factory()->superAdmin()->confirmedTwoFactor()->create();
    session()->forget('auth.password_confirmed_at');

    $this->actingAs($operator)
        ->post(impersonateUrl(), ['user_id' => $this->member->id])
        ->assertRedirect(route('password.confirm'));

    expect(DB::table('impersonation_tokens')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The 2FA exemption (EnforceTenantTwoFactor).
|--------------------------------------------------------------------------
*/

it('lets an impersonated session past org-level 2FA enforcement', function (): void {
    // ⭐ THE CASE THE FEATURE IS UNUSABLE WITHOUT, in exactly the workspaces most likely to need support.
    // Without the exemption the operator lands on /dashboard, is redirected to the enrollment interstitial,
    // and the only action available there is to enrol a second factor ON SOMEBODY ELSE'S ACCOUNT.
    $operator = User::factory()->superAdmin()->create();
    $unenrolled = User::factory()->create(['two_factor_confirmed_at' => null]);

    enterTenant($this->tenant->id, $unenrolled->id);
    makeActiveMember($unenrolled, 'form_editor');
    enforceTwoFactorOnAcme();

    Auth::login($unenrolled);
    session([ImpersonationContext::SESSION_KEY => $operator->id]);

    $request = Request::create('/dashboard');
    $request->setLaravelSession(session()->driver());
    $request->setUserResolver(static fn (): User => $unenrolled);

    $response = app(EnforceTenantTwoFactor::class)
        ->handle($request, static fn (): Response => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('still enforces org-level 2FA for an ORDINARY unenrolled member', function (): void {
    // ⭐ THE MUTATION GUARD FOR THAT EXEMPTION. Widen it to an unconditional early return and the case
    // above still passes while this one reddens — which is the only thing separating a considered carve-out
    // from having deleted the feature.
    $unenrolled = User::factory()->create(['two_factor_confirmed_at' => null]);

    enterTenant($this->tenant->id, $unenrolled->id);
    makeActiveMember($unenrolled, 'form_editor');
    enforceTwoFactorOnAcme();

    Auth::login($unenrolled);

    $request = Request::create('/dashboard');
    $request->setLaravelSession(session()->driver());
    $request->setUserResolver(static fn (): User => $unenrolled);

    $response = app(EnforceTenantTwoFactor::class)
        ->handle($request, static fn (): Response => response('ok'));

    expect($response->getContent())->not->toBe('ok')
        ->and($response->getStatusCode())->toBe(302);
});
