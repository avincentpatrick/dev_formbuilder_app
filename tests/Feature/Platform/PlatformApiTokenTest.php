<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSupportTokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M175 (`R-78f43f26`, `D139`, `D141`) — the platform support token: the WHOLE authorization of the support API.
|--------------------------------------------------------------------------
| The API answers on any platform host — the workspace host included, because on the testing server the
| central host is the agency's website and only the box reaches it (`D141`). It carries no session, so the
| console's step-up never applies; what stands in for it is here: a token that is hashed at rest, carries one
| ability, EXPIRES, can be revoked, and whose owner is re-checked — still a super-admin, still enrolled in 2FA —
| on every call. Each refusal answers 401 inside the API's `{error:{code,message,details}}` envelope.
|
| The owner is COMMITTED (`consoleSuperAdmin()`): the authenticator resolves users on the auth connection,
| which cannot see rows inside RefreshDatabase's open transaction.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->beforeApplicationDestroyed(purgeCommittedFeedbackFixtures(...));
});

it('answers a valid support token on the workspace host and on the central host', function (): void {
    [, $plain] = issueSupportToken();

    $this->withToken($plain)->getJson(platformApi(workspaceHost()))->assertOk()->assertJsonStructure(['data', 'meta']);
    $this->withToken($plain)->getJson(platformApi((string) config('tenancy.central_domain')))->assertOk();
});

it('stores only a hash of the token, which is shown once with a recognisable prefix', function (): void {
    [$token, $plain] = issueSupportToken();

    expect($plain)->toStartWith('msp_')
        ->and(strlen($plain))->toBeGreaterThanOrEqual(44)
        ->and($token->token_hash)->toBe(hash('sha256', $plain))
        ->and(DB::table('platform_api_tokens')->where('id', $token->id)->value('token_hash'))->not->toBe($plain);
});

it('refuses a missing, an unknown, an expired and a revoked token', function (): void {
    [$token, $plain] = issueSupportToken();

    $this->getJson(platformApi(workspaceHost()))
        ->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $this->withToken('msp_'.Str::random(48))->getJson(platformApi(workspaceHost()))->assertUnauthorized();

    $token->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->withToken($plain)->getJson(platformApi(workspaceHost()))->assertUnauthorized();

    [$second, $secondPlain] = issueSupportToken();
    app(PlatformSupportTokenService::class)->revoke($second, consoleSuperAdmin(Str::random(8).'@feedbackconsoletest.local'));
    $this->withToken($secondPlain)->getJson(platformApi(workspaceHost()))->assertUnauthorized();
});

it('refuses a token whose owner is no longer a super-admin, or no longer enrolled in two-factor', function (string $column, mixed $value): void {
    [$token, $plain] = issueSupportToken();

    DB::connection('pgsql_privileged')->table('users')->where('id', $token->user_id)->update([$column => $value]);

    $this->withToken($plain)->getJson(platformApi(workspaceHost()))->assertUnauthorized();
})->with([
    'demoted' => ['is_super_admin', false],
    '2FA cleared' => ['two_factor_confirmed_at', null],
]);

it('refuses a tenant API key and a signed-in session — only a support token opens it', function (): void {
    $admin = consoleSuperAdmin(Str::random(8).'@feedbackconsoletest.local');

    // A tenant Sanctum key has the `<id>|<secret>` shape and lives in another table entirely.
    $this->withToken('1|'.Str::random(40))->getJson(platformApi(workspaceHost()))->assertUnauthorized();

    // A browser session is not a credential here, even a super-admin's: the console's step-up guards that.
    $this->actingAs($admin)->getJson(platformApi(workspaceHost()))->assertUnauthorized();
});

it('404s a custom domain inside the envelope, as every platform surface does', function (): void {
    [, $plain] = issueSupportToken();

    $this->withToken($plain)->getJson(platformApi('forms.example.org'))
        ->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('stamps when a token was last used', function (): void {
    [$token, $plain] = issueSupportToken();
    expect($token->last_used_at)->toBeNull();

    $this->withToken($plain)->getJson(platformApi(workspaceHost()))->assertOk();

    expect($token->fresh()?->last_used_at)->not->toBeNull();
});

it('rate-limits each token on its own, keyed by a hash of the token rather than the address', function (): void {
    $route = Route::getRoutes()->getByName('platform.v1.feedback.index');
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:platform-api');

    $limiter = RateLimiter::limiter('platform-api');
    expect($limiter)->not->toBeNull();

    $keys = function (string $bearer) use ($limiter): array {
        $request = request()->duplicate();
        $request->headers->set('Authorization', 'Bearer '.$bearer);

        return collect(Arr::wrap($limiter($request)))->map(fn (Limit $limit): string => (string) $limit->key)->all();
    };

    expect($keys('msp_one'))->not->toBe($keys('msp_two'))
        ->and(implode(' ', $keys('msp_one')))->not->toContain('msp_one');
});
