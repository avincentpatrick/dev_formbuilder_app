<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\PlatformApiToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M175 (`R-78f43f26`) — the support tokens card on the console's Settings page.
|--------------------------------------------------------------------------
| A platform super-admin creates the token the dev session's feedback pull uses, sees it ONCE, and can revoke
| it. The card sits behind the console's own gates (`superadmin`, `superadmin.mfa`, `step-up` —
| AdminConsoleGateTest sweeps every `admin/*` route, these two included). The expiry is required and at most
| 90 days: the token carries no session, so its lifetime is the only clock on it.
|
| The operator is COMMITTED (`consoleSuperAdmin()`): issuing writes a platform audit row on the elevated
| connection, whose foreign key to `users` cannot see a user inside RefreshDatabase's open transaction.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->beforeApplicationDestroyed(purgeCommittedFeedbackFixtures(...));
    $this->withoutVite();
    confirmPasswordNow();
});

it('issues a token shown once, stores only its hash, and lists tokens without any secret', function (): void {
    $admin = consoleSuperAdmin(Str::random(8).'@feedbackconsoletest.local');

    $this->actingAs($admin)
        ->post(adminUrl('/support-tokens'), ['name' => 'Dev laptop — feedback pull', 'expires_in_days' => 90])
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertSessionHas('newSupportToken');

    $flash = session('newSupportToken');
    expect($flash['token'])->toStartWith('msp_')
        ->and($flash['name'])->toBe('Dev laptop — feedback pull');

    /** @var PlatformApiToken $token */
    $token = PlatformApiToken::query()->sole();
    expect($token->token_hash)->toBe(hash('sha256', $flash['token']))
        ->and($token->user_id)->toBe($admin->id)
        ->and($token->expires_at->between(now()->addDays(89), now()->addDays(91)))->toBeTrue();

    $this->actingAs($admin)->get(adminUrl('/settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Settings')
            ->has('support_tokens', 1)
            ->where('support_tokens.0.name', 'Dev laptop — feedback pull')
            ->where('support_tokens.0.owner.email', $admin->email)
            ->missing('support_tokens.0.token_hash')
            ->missing('support_tokens.0.token'));
});

it('refuses an expiry beyond 90 days, a token with no expiry, and one with no name', function (array $payload, string $field): void {
    $admin = consoleSuperAdmin(Str::random(8).'@feedbackconsoletest.local');

    $this->actingAs($admin)->post(adminUrl('/support-tokens'), $payload)->assertSessionHasErrors($field);

    expect(PlatformApiToken::query()->count())->toBe(0);
})->with([
    'a year' => [['name' => 'Too long', 'expires_in_days' => 365], 'expires_in_days'],
    'no expiry' => [['name' => 'Forever'], 'expires_in_days'],
    'no name' => [['name' => '', 'expires_in_days' => 30], 'name'],
]);

it('revokes a token, after which it opens nothing', function (): void {
    $admin = consoleSuperAdmin(Str::random(8).'@feedbackconsoletest.local');
    [$token, $plain] = issueSupportToken();

    $this->actingAs($admin)->delete(adminUrl("/support-tokens/{$token->id}"))->assertSessionHasNoErrors()->assertRedirect();

    expect($token->fresh()?->revoked_at)->not->toBeNull();

    $this->flushSession();
    auth()->logout();
    $this->withToken($plain)->getJson(platformApi(workspaceHost()))->assertUnauthorized();
});

it('records issuing and revoking in the platform audit log, never the secret', function (): void {
    $admin = consoleSuperAdmin(Str::random(8).'@feedbackconsoletest.local');

    $this->actingAs($admin)->post(adminUrl('/support-tokens'), ['name' => 'Audited', 'expires_in_days' => 30]);
    $plain = session('newSupportToken')['token'];
    $token = PlatformApiToken::query()->sole();
    $this->actingAs($admin)->delete(adminUrl("/support-tokens/{$token->id}"));

    $rows = DB::connection('pgsql_privileged')->table('audits')
        ->whereNull('tenant_id')->where('auditable_type', 'platform_api_tokens')->where('auditable_id', $token->id)
        ->orderBy('id')->get();

    expect($rows->pluck('event')->all())->toBe([AuditEvent::Created->value, AuditEvent::Updated->value])
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$admin->id])
        ->and(json_encode($rows))->not->toContain($plain)
        ->and(json_encode($rows))->not->toContain($token->token_hash);
});
