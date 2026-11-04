<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Http\Middleware\EnforceTenantTwoFactor;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M207 — GET /api/v1/auth/whoami, the one contract between Meridian and BHW Connect (ADR-0024 §D5, D178).
|--------------------------------------------------------------------------
| BHW's server forwards the browser's session cookie here from /bhw-connect on the same origin, and BHW's own
| admin_users decides the rest. So the properties that matter are who it answers for, what it answers, and
| that nothing but a session reaches it.
*/

const WHOAMI_URL = 'http://acme.meridian.test/api/v1/auth/whoami';

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    enterTenant($this->tenant->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('answers a guest with the 401 envelope', function (): void {
    $this->getJson(WHOAMI_URL)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('answers a signed-in member with exactly their id, name and email, uncacheable', function (): void {
    $member = apiMember('viewer');

    $response = $this->actingAs($member)
        ->getJson(WHOAMI_URL, ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
        ]]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('refuses an account that is not an active member of this workspace', function (): void {
    // Users are global and Group A asks nothing about membership, so this is the controller's own check.
    // Both accounts are set up before any request: a request's teardown clears the RLS context.
    $stranger = User::factory()->create();

    // A removed member whose session is still open is the case that matters for BHW's admins.
    $removed = apiMember('admin');
    TenantUser::query()->where('user_id', $removed->id)->update(['status' => TenantUserStatus::Removed]);

    $this->actingAs($stranger)
        ->getJson(WHOAMI_URL)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    $this->actingAs($removed)
        ->getJson(WHOAMI_URL)
        ->assertForbidden();
});

it('refuses an unverified member, as the mint does', function (): void {
    $unverified = User::factory()->unverified()->create();
    enterTenant($this->tenant->id, $unverified->id);
    makeActiveMember($unverified, 'viewer');

    $this->actingAs($unverified)
        ->getJson(WHOAMI_URL)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('cannot be reached with a bearer token', function (): void {
    // Group A authenticates by session only, so a key — however it was obtained — answers nothing here.
    $member = apiMember('admin');
    $token = $member->createToken('k', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson(WHOAMI_URL)
        ->assertUnauthorized();
});

it('sits in Group A, under its own limiter, and outside every Group B filter', function (): void {
    $route = Route::getRoutes()->getByName('api.v1.session.whoami');
    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();
    expect($middleware)->toContain('web', 'auth', 'verified', EnforceTenantTwoFactor::class, 'throttle:whoami');

    $groupB = array_map(static fn ($r): string => (string) $r->getName(), groupBRoutes());
    expect($groupB)->not->toContain('api.v1.session.whoami')
        ->and(count($groupB))->toBeGreaterThan(20);
});

it('keys its limiter on the member, not on the address every call shares', function (): void {
    // Every call arrives from BHW's server on 127.0.0.1, so an address key would put everyone in one bucket.
    $member = apiMember('viewer');
    $request = Request::create(WHOAMI_URL, 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
    $request->setUserResolver(static fn (): User => $member);

    $limit = RateLimiter::limiter('whoami')($request);

    expect($limit->key)->toBe('user:'.$member->id);
});
