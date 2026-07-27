<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorSubscriptionStatus;
use App\Jobs\Connectors\RefreshTenantConnectorTokensJob;
use App\Jobs\Maintenance\RefreshConnectorTokensJob;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Connectors\ConnectionRevokedNotification;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The proactive token-refresh sweep (H15a / ADR-0009 §D6) — the committing MaintenanceJob fan-out recipe.
|
| The two behaviours that matter most are negative ones: a grant with nothing to refresh (Slack's default,
| non-expiring bot token) must be SKIPPED rather than failed, and a refused refresh must be TERMINAL rather
| than retried forever — only a human re-running the OAuth flow can fix it.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    Http::preventStrayRequests();
    config()->set('connectors.providers.slack.client_id', 'test-client-id');
    config()->set('connectors.providers.slack.client_secret', 'test-client-secret');
    $this->seed(RolePermissionSeeder::class);

    $this->owner = User::factory()->create();
    $this->tenant = Tenant::create([
        'name' => 'Acme',
        'slug' => 'acme',
        'default_locale' => 'en',
        'owner_user_id' => $this->owner->id,
    ]);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    enterTenant($this->tenant->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Run the per-tenant refresh child through the worker and re-enter the tenant. */
function runRefreshSweep(): void
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    RefreshTenantConnectorTokensJob::dispatch($tenant->id);
    workOneJob('scheduled-maintenance');

    enterTenant($tenant->id);
}

it('refreshes a grant inside the lead window and keeps it active', function (): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response([
        'ok' => true,
        'access_token' => 'xoxb-refreshed',
        'refresh_token' => 'xoxe-next',
        'expires_in' => 43200,
        'scope' => 'chat:write,channels:read',
        'team' => ['id' => 'T0ACME', 'name' => 'Acme HQ'],
    ], 200)]);

    $connection = Connection::factory()->expiringIn(60)->create(['access_token' => 'xoxb-stale']);

    runRefreshSweep();

    $connection->refresh();
    expect($connection->access_token)->toBe('xoxb-refreshed')
        ->and($connection->refresh_token)->toBe('xoxe-next')
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->last_refreshed_at)->not->toBeNull()
        ->and($connection->token_expires_at?->isFuture())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->data()['grant_type'] === 'refresh_token'
        && $request->data()['refresh_token'] !== '');
});

it('keeps the stored refresh token when the provider returns none', function (): void {
    // Some providers return only a new access token and expect the existing refresh token to be reused;
    // nulling it would silently turn a rotating grant into an unrefreshable one on its first refresh.
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response([
        'ok' => true,
        'access_token' => 'xoxb-refreshed',
        'expires_in' => 3600,
        'team' => ['id' => 'T0ACME', 'name' => 'Acme HQ'],
    ], 200)]);

    $connection = Connection::factory()->expiringIn(60)->create();
    $originalRefreshToken = $connection->refresh_token;

    runRefreshSweep();

    expect($connection->fresh()->refresh_token)->toBe($originalRefreshToken);
});

it('skips a grant that cannot expire', function (): void {
    // Slack's default bot token: no expiry, no refresh token. The sweep must leave it alone, not fail on it.
    $connection = Connection::factory()->create(['access_token' => 'xoxb-permanent']);

    runRefreshSweep();

    expect($connection->fresh()->access_token)->toBe('xoxb-permanent')
        ->and($connection->fresh()->last_refreshed_at)->toBeNull();

    Http::assertNothingSent();
});

it('skips a grant whose expiry is beyond the lead window', function (): void {
    config()->set('connectors.refresh_lead_seconds', 60);

    $connection = Connection::factory()->expiringIn(86400)->create();

    runRefreshSweep();

    expect($connection->fresh()->last_refreshed_at)->toBeNull();
    Http::assertNothingSent();
});

it('marks the grant dead, pauses its rules, and notifies the owner when the refresh is refused', function (): void {
    Notification::fake();
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(['ok' => false, 'error' => 'invalid_grant'], 200)]);

    $connection = Connection::factory()->expiringIn(60)->create();
    $subscription = ConnectionSubscription::factory()->forConnection($connection)->create();

    runRefreshSweep();

    $connection->refresh();
    expect($connection->status)->toBe(ConnectionStatus::RefreshFailed)
        ->and($connection->last_error)->toBe('invalid_grant')
        ->and($connection->last_error_at)->not->toBeNull()
        // The dead credential is cleared, not kept around in the hope it starts working again.
        ->and($connection->access_token)->toBe('')
        ->and($connection->refresh_token)->toBeNull()
        ->and($subscription->fresh()->status)->toBe(ConnectorSubscriptionStatus::Paused);

    Notification::assertSentOnDemand(ConnectionRevokedNotification::class);
});

it('does not retry a grant it already marked dead', function (): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(['ok' => true, 'access_token' => 'x'], 200)]);

    Connection::factory()->expiringIn(60)->refreshFailed()->create();

    runRefreshSweep();

    Http::assertNothingSent();
});

it('fans out one child per active tenant and holds no tenant context itself', function (): void {
    Bus::fake([RefreshTenantConnectorTokensJob::class]);

    Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);

    TenantContext::flush();
    (new RefreshConnectorTokensJob)->handle();

    Bus::assertDispatchedTimes(RefreshTenantConnectorTokensJob::class, 2);
});
