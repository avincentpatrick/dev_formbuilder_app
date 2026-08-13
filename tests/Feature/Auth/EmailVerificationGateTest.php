<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\ImpersonationService;
use App\Support\Audit\ImpersonationContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Email-verification enforcement (Increment J3a).
|
| ⚠️ EVERY PART OF THIS FEATURE EXISTED BEFORE J3a EXCEPT THE PART THAT DOES ANYTHING. `User implements
| MustVerifyEmail`, Fortify's `emailVerification()` feature is on, `Pages/auth/VerifyEmail.vue` renders the
| notice, and `QueuedVerifyEmail` sends a branded signed link — and `verified` appeared on ZERO routes, so
| the product asked people to confirm an address and then never looked. These cases are the gate.
|
| The interesting half is not "unverified is bounced". It is the four places the gate must NOT fire, each of
| which is a way this ships broken: an impersonated support session, the 2FA interstitial it would otherwise
| ping-pong with, the invitation flow (which proves the mailbox by construction), and a JSON sidecar that
| cannot follow a 302 to HTML.
*/

beforeEach(function (): void {
    TenantContext::flush();
    ImpersonationContext::forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
});

afterEach(function (): void {
    ImpersonationContext::forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** An ACTIVE member of the ambient tenant, verified or not. */
function memberOfTenant(Tenant $tenant, bool $verified, string $role = 'owner'): User
{
    $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, $role);

    return $user;
}

it('bounces an unverified member from the dashboard to the verification notice', function (): void {
    $user = memberOfTenant($this->tenant, verified: false);

    $this->actingAs($user)
        ->get('http://acme.meridian.test/dashboard')
        ->assertRedirect(route('verification.notice'));
});

it('lets a verified member through to the dashboard', function (): void {
    $this->withoutVite();
    $user = memberOfTenant($this->tenant, verified: true);

    // ⚠️ THE ANTI-VACUITY HALF. Without it the case above is satisfied by ANY redirect — a broken tenant
    // host, a missing role, a 2FA interstitial — and the suite would go green over a gate that bounces
    // everyone. This is the assertion that makes the other one mean "unverified", not "redirected".
    $this->actingAs($user)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard', false));
});

it('records url.intended, so verifying returns the member where they were going', function (): void {
    $user = memberOfTenant($this->tenant, verified: false);

    $this->actingAs($user)->get('http://acme.meridian.test/submissions');

    // `redirect()->guest()` stores this; `redirect()->to()` does not. The difference is invisible on the
    // redirect itself and only shows up as someone landing on the dashboard after verifying, having been
    // on their way somewhere else.
    expect(session('url.intended'))->toContain('/submissions');
});

it('answers a JSON sidecar with a 403, never a 302 into HTML', function (): void {
    $user = memberOfTenant($this->tenant, verified: false);

    // The bell polls this every sixty seconds. `EnforceTenantTwoFactor` lets its own 302 reach it and
    // documents that `notificationsClient` swallows the resulting SyntaxError; this gate does better by
    // answering in the shape the caller asked for, so nothing ever parses markup as JSON.
    //
    // ⚠️ NOT the `{"error":{"code":"forbidden"}}` envelope, and a first draft of this test asserted that it
    // was. `bootstrap/app.php`'s renderers are gated on `$request->is('api/v1/*')`, and `/notifications` is a
    // TENANT sidecar outside that prefix — so it gets the framework's default JSON body. The envelope claim
    // is true for the API group and is asserted there, one case below.
    $response = $this->actingAs($user)
        ->getJson('http://acme.meridian.test/notifications')
        ->assertForbidden();

    expect($response->headers->get('content-type'))->toContain('application/json');
});

it('exempts an impersonated session, because the notice page can only mail the MEMBER', function (): void {
    $this->withoutVite();
    $member = memberOfTenant($this->tenant, verified: false);
    $operator = User::factory()->superAdmin()->create();

    // ⚠️ BOTH SESSION KEYS, NOT JUST THE MARKER — and the first draft of this test set only the static.
    // `EnforceImpersonationTimeout` runs later in the same group and treats "impersonating, with no
    // deadline" as EXPIRED (fail-closed, by design), so a marker-only fixture is torn down and the request
    // 302s for a reason that has nothing to do with this gate. That would have made the test look like a
    // failure of the exemption while the exemption worked perfectly. `ImpersonationSessionTest` writes the
    // same pair; this is that fixture, driven through a real request.
    $this->actingAs($member)
        ->withSession([
            ImpersonationContext::SESSION_KEY => (string) $operator->getKey(),
            ImpersonationService::DEADLINE_SESSION_KEY => now()->addMinutes(5)->getTimestamp(),
        ])
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk();
});

it('leaves the two-factor interstitial reachable, so the two gates cannot ping-pong', function (): void {
    $this->withoutVite();
    $user = memberOfTenant($this->tenant, verified: false);

    // Structurally exempt — its own group, outside the guarded one. If `verified` were ever added there, an
    // unverified member under org-2FA enforcement would bounce between /email/verify and
    // /two-factor/required with no way out.
    $this->actingAs($user)
        ->get('http://acme.meridian.test/two-factor/required')
        ->assertOk();
});

it('does not gate the token-issuance API group for a verified member', function (): void {
    $user = memberOfTenant($this->tenant, verified: true);

    $this->actingAs($user)
        ->getJson('http://acme.meridian.test/api/v1/auth/tokens')
        ->assertOk();
});

it('refuses to mint an API token for an unverified member, in the documented envelope', function (): void {
    $user = memberOfTenant($this->tenant, verified: false);

    // Group B authenticates by TOKEN and has nowhere to send anyone, so the mint is the only place this
    // property can be established — after which every key issued belongs to a verified account.
    $response = $this->actingAs($user)
        ->postJson('http://acme.meridian.test/api/v1/auth/tokens', ['name' => 'ci', 'abilities' => []])
        ->assertForbidden();

    expect($response->json('error.code'))->toBe('forbidden');
});

it('stops bouncing a member the moment they verify', function (): void {
    $this->withoutVite();
    $user = memberOfTenant($this->tenant, verified: false);

    $this->actingAs($user)->get('http://acme.meridian.test/dashboard')->assertRedirect();

    // ⚠️ NO `->fresh()`. `users` carries fail-closed join-shape RLS and the request above dropped the tenant
    // GUC, so a re-read here returns NULL and `actingAs()` dies with a TypeError rather than an assertion
    // failure. The in-memory model is already the saved state, which is all this case needs.
    $user->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($user)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk();
});
