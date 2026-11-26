<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Auth\SignInPass;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Cookie;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M253 (`R-43038865`, `D236`) — the shared sign-in pass
|--------------------------------------------------------------------------
| iConnect Analytics moves to its own host, where Collection's host-only session cookie never arrives. So the signed-in
| workspace pages also set `iconnect-signin` for the parent domain, and `whoami` — only `whoami` — takes it through a
| second guard. What matters: it is issued only where the session itself was admitted; it names a user, a workspace and
| a LIVE session, and dies with that session; it is honoured on that workspace's host alone; and nothing else in the
| application reads it. Off unless SIGNIN_PASS_DOMAIN is set.
|
| ⚠️ `withCredentials()` on every pass request: the test client sends no cookie with a JSON request without it, so
| a refusal case would pass on a request that carried no pass at all (measured: every refusal here was green before).
|
| ⚠️ One `whoami` request per case: a request guard keeps the user it resolved for the life of the application, and a
| Pest case shares one application across its requests.
*/

const SIGNIN_PASS_WHOAMI = 'http://acme.meridian.test/api/v1/auth/whoami';

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    enterTenant($this->tenant->id);

    // The pass guard reads users through the RLS-aware provider on `pgsql_auth`, a separate session that cannot
    // see this case's uncommitted rows, so the member is a committed identity.
    $this->member = committedTenantIdentity('Pass Holder');
    enterTenant($this->tenant->id, $this->member->id);
    makeActiveMember($this->member, 'viewer');

    config(['session.signin_pass_domain' => 'meridian.test', 'session.driver' => 'database']);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A live session row for `$user`, as the `database` driver writes one, and its id. */
function signInPassSession(User $user, int $lastActivity = 0): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => $lastActivity !== 0 ? $lastActivity : time(),
    ]);

    return $id;
}

/**
 * The pass's plain value, which the test client encrypts as `EncryptCookies` would.
 *
 * @param  array<string, mixed>  $overrides
 */
function signInPassValue(string $userId, string $tenantId, string $sessionId, array $overrides = []): string
{
    return (string) json_encode([...['u' => $userId, 't' => $tenantId, 's' => $sessionId, 'x' => time() + 3600], ...$overrides]);
}

it('issues nothing while SIGNIN_PASS_DOMAIN is unset', function (): void {
    config(['session.signin_pass_domain' => null, 'session.driver' => 'array']);

    $response = $this->actingAs($this->member)->withoutVite()->get('http://acme.meridian.test/dashboard')->assertOk();

    $names = array_map(fn (Cookie $c): string => $c->getName(), $response->headers->getCookies());
    expect($names)->not->toContain(SignInPass::COOKIE);
});

it('issues an HttpOnly, Secure, Lax pass for the parent domain, naming the user, the workspace and the session', function (): void {
    config(['session.driver' => 'array']);

    $response = $this->actingAs($this->member)->withoutVite()->get('http://acme.meridian.test/dashboard')->assertOk();

    $cookie = collect($response->headers->getCookies())->first(fn (Cookie $c): bool => $c->getName() === SignInPass::COOKIE);
    expect($cookie)->not->toBeNull('the signed-in page set no pass');
    expect($cookie->getDomain())->toBe('meridian.test')
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_LAX);

    // EncryptCookies encrypted it on the way out; decrypted, it names exactly these three and an expiry.
    $response->assertCookie(SignInPass::COOKIE);
    $plain = json_decode((string) $response->getCookie(SignInPass::COOKIE)?->getValue(), true);
    expect($plain)->toHaveKeys(['u', 't', 's', 'x'])
        ->and($plain['u'])->toBe($this->member->id)
        ->and($plain['t'])->toBe((string) $this->tenant->getKey())
        ->and($plain['x'])->toBeGreaterThan(time());
});

it('lets whoami answer the member a valid pass names, with no session at all', function (): void {
    $sessionId = signInPassSession($this->member);

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) $this->tenant->getKey(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $this->member->id,
            'name' => $this->member->name,
            'email' => $this->member->email,
        ]]);
});

it('refuses a pass whose session has ended, which is what signing out does', function (): void {
    $sessionId = signInPassSession($this->member);
    DB::table('sessions')->where('id', $sessionId)->delete();

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) $this->tenant->getKey(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertUnauthorized();
});

it('refuses a pass whose session has gone quiet past the session lifetime', function (): void {
    $sessionId = signInPassSession($this->member, time() - (int) config('session.lifetime') * 60 - 60);

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) $this->tenant->getKey(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertUnauthorized();
});

it('refuses an expired pass even while its session lives', function (): void {
    $sessionId = signInPassSession($this->member);

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) $this->tenant->getKey(), $sessionId, ['x' => time() - 1]))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertUnauthorized();
});

it('refuses a pass issued in another workspace', function (): void {
    $sessionId = signInPassSession($this->member);

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) Str::uuid(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertUnauthorized();
});

it('refuses a pass naming another user than its session', function (): void {
    $sessionId = signInPassSession($this->member);
    $other = committedTenantIdentity('Someone Else');

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($other->id, (string) $this->tenant->getKey(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertUnauthorized();
});

it('refuses a pass this application did not encrypt', function (): void {
    $sessionId = signInPassSession($this->member);

    $this->withCredentials()->withUnencryptedCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) $this->tenant->getKey(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertUnauthorized();
});

it('honours no pass at all while SIGNIN_PASS_DOMAIN is unset', function (): void {
    $sessionId = signInPassSession($this->member);
    config(['session.signin_pass_domain' => null]);

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) $this->tenant->getKey(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertUnauthorized();
});

it('honours no pass under a session driver it cannot check a session against', function (): void {
    $sessionId = signInPassSession($this->member);
    config(['session.driver' => 'array']);

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) $this->tenant->getKey(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertUnauthorized();
});

it('still refuses a removed member, as whoami does for a session', function (): void {
    $sessionId = signInPassSession($this->member);
    TenantUser::query()->where('user_id', $this->member->id)->update(['status' => TenantUserStatus::Removed]);

    $this->withCredentials()->withCookie(SignInPass::COOKIE, signInPassValue($this->member->id, (string) $this->tenant->getKey(), $sessionId))
        ->getJson(SIGNIN_PASS_WHOAMI)
        ->assertForbidden();
});

it('clears the pass for the parent domain at sign-out', function (): void {
    config(['session.driver' => 'array']);

    $response = $this->actingAs($this->member)->post('http://acme.meridian.test/logout');

    $cookie = collect($response->headers->getCookies())->first(fn (Cookie $c): bool => $c->getName() === SignInPass::COOKIE);
    expect($cookie)->not->toBeNull('sign-out sent no cleared pass')
        ->and($cookie->getDomain())->toBe('meridian.test')
        ->and($cookie->isCleared())->toBeTrue();
});

it('is a way into whoami and into nothing else', function (): void {
    $withPass = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => collect($route->gatherMiddleware())
            ->contains(fn ($m): bool => is_string($m) && str_contains($m, SignInPass::GUARD)))
        ->map(fn (RoutingRoute $route): string => (string) $route->getName())
        ->values()
        ->all();

    expect($withPass)->toBe(['api.v1.session.whoami']);
});
