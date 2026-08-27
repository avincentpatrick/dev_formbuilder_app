<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorSubscriptionStatus;
use App\Jobs\Connectors\RefreshOneConnectionJob;
use App\Jobs\Connectors\RefreshTenantConnectorTokensJob;
use App\Jobs\Maintenance\RefreshConnectorTokensJob;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Connectors\ConnectionRevokedNotification;
use App\Services\Connectors\ConnectionTokenRefresher;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

/**
 * Run the per-tenant refresh child, THEN the per-connection jobs it dispatches, and re-enter the tenant.
 *
 * ⚠️ THE SECOND HALF IS NEW IN M6 AND IS THE WHOLE POINT OF THE CHANGE. `sweep()` no longer rotates anything
 * itself — it hands each due grant to a {@see RefreshOneConnectionJob} so that the irreversible provider-side
 * rotation lands in a transaction containing nothing but its own write. A helper that ran only the parent
 * would therefore observe NOTHING happening and every assertion below would be about an empty sweep.
 *
 * `$expected` is asserted rather than inferred: draining "however many jobs happen to be queued" would keep
 * passing if the sweep silently dispatched none, which is precisely the failure this helper must not hide.
 */
function runRefreshSweep(int $expectedDispatches = 1): void
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    RefreshTenantConnectorTokensJob::dispatch($tenant->id);
    workOneJob('scheduled-maintenance');

    expect(DB::table('jobs')->count())->toBe($expectedDispatches);

    for ($i = 0; $i < $expectedDispatches; $i++) {
        workOneJob('scheduled-maintenance');
    }

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

    runRefreshSweep(expectedDispatches: 0); // nothing is due, so the sweep must hand off NOTHING

    expect($connection->fresh()->access_token)->toBe('xoxb-permanent')
        ->and($connection->fresh()->last_refreshed_at)->toBeNull();

    Http::assertNothingSent();
});

it('skips a grant whose expiry is beyond the lead window', function (): void {
    config()->set('connectors.refresh_lead_seconds', 60);

    $connection = Connection::factory()->expiringIn(86400)->create();

    runRefreshSweep(expectedDispatches: 0); // nothing is due, so the sweep must hand off NOTHING

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

    runRefreshSweep(expectedDispatches: 0); // nothing is due, so the sweep must hand off NOTHING

    Http::assertNothingSent();
});

it('fans out one child per active tenant and holds no tenant context itself', function (): void {
    Bus::fake([RefreshTenantConnectorTokensJob::class]);

    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);

    TenantContext::flush();
    (new RefreshConnectorTokensJob)->handle();

    Bus::assertDispatchedTimes(RefreshTenantConnectorTokensJob::class, 2);

    // ⚠️ THE COUNT ABOVE WAS THE ONLY ASSERTION THIS TEST MADE, AND THIS IS THE ONLY PLACE IN THE REPOSITORY
    // WHERE THE PARENT'S LOOP RUNS AT ALL — `runRefreshSweep()` above dispatches the CHILD directly, so no
    // other test in this file reaches `RefreshConnectorTokensJob::sweep()`. Hoisting its loop variable keeps
    // the count at 2 and leaves every tenant but the first with grants nobody refreshes: they expire at their
    // own TTL, hourly and silently, with no failed job and no log line to trace back from. Unlike
    // `gamification:backfill` there is no `--sync` sibling here proving a usable id ever reaches the child.
    //
    // Compared as a whole SET rather than through `Bus::assertDispatched($class, $closure)`, which is an
    // AT-LEAST-ONE-MATCH predicate and is therefore satisfied by the first of two identical jobs — the same
    // trap the gamification half of this pair documents. `Bus::dispatched()` returns the commands themselves
    // (`BusFake.php:564-573`), so the multiset can be pinned in both directions at once.
    $expected = collect([$this->tenant, $other])
        ->map(fn (Tenant $tenant): string => (string) $tenant->getKey())
        ->sort()
        ->values()
        ->all();

    expect(Bus::dispatched(RefreshTenantConnectorTokensJob::class)
        ->map(fn (RefreshTenantConnectorTokensJob $job): string => $job->tenantId)
        ->sort()
        ->values()
        ->all())
        ->toBe($expected);

    // The SECOND half of this test's own name, which it has never actually asserted. A sweep is cross-tenant
    // by definition, so a context left behind here would be inherited by whatever ran next on the worker.
    expect(TenantContext::currentTenantId())->toBeNull();
});

// ── M6 — an irreversible rotation is never left inside a transaction that can undo it ────────────────────
//
// These two were written as a REPRODUCTION and passed against the unfixed code: driven at the old
// inline-rotating sweep, the first showed both tokens rotated at Airtable while the database rolled back to
// the ones Airtable had just destroyed, and the second showed the next sweep then killing the connection
// outright. They are kept, inverted, because the mutation that proves them is the absence of the fix — and
// `git stash push -- app/` reproduces exactly that in one command.

it('commits each rotated token before anything else in the sweep can throw', function (): void {
    // Airtable ROTATES: every refresh returns a new pair and INVALIDATES the previous one, so the provider's
    // half of this exchange cannot be rolled back and ours must not be either.
    Http::fake(['airtable.com/oauth2/v1/token' => Http::response([
        'access_token' => 'oaa-rotated',
        'refresh_token' => 'oar-rotated',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ], 200)]);

    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $a = Connection::factory()->airtable()->create(['token_expires_at' => Carbon::now()->addSeconds(60)]);
    $b = Connection::factory()->airtable()->create(['token_expires_at' => Carbon::now()->addSeconds(60)]);

    $storedA = $a->refresh_token;
    $storedB = $b->refresh_token;

    // EXACTLY `TenantAwareJob::handle()`'s body -- transaction, applyLocal, the work -- plus the thing this
    // row is about: something throws afterwards. The 60s `$timeout` is the likeliest real instance of it, and
    // a tenant with several due Airtable grants can genuinely reach it at 5s connect + 10s read apiece.
    try {
        DB::transaction(function () use ($tenant): void {
            TenantContext::applyLocal($tenant->id, null);
            app(ConnectionTokenRefresher::class)->sweep(Carbon::now());

            throw new RuntimeException('the job dies after the sweep has handed its work off');
        });
    } catch (RuntimeException) {
        // swallowed: the worker would fail or release the job here, and the transaction is already gone
    }

    enterTenant($tenant->id);

    // THE CONTROL, and it is what makes the assertions below mean anything: the rollback took the DISPATCHES
    // with it, so nothing has rotated yet. Before M6 this read "two tokens already rotated and lost".
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertNothingSent();

    expect($a->fresh()->refresh_token)->toBe($storedA)
        ->and($b->fresh()->refresh_token)->toBe($storedB);

    // And when the sweep is NOT interrupted, each rotation lands in a transaction of its own and survives.
    runRefreshSweep(expectedDispatches: 2);

    expect($a->fresh()->refresh_token)->toBe('oar-rotated')
        ->and($b->fresh()->refresh_token)->toBe('oar-rotated');
});

it('rotates one grant at a time, so a concurrent refresh cannot burn the same token twice', function (): void {
    // The sibling defect, same cure. `ensureFresh()` was a plain read-check-then-refresh with no lock, so two
    // workers could exchange the SAME rotating refresh token: the first wins and the second is answered
    // `invalid_grant`, killing a perfectly healthy grant. Latent only because docker-compose.yml runs exactly
    // one queue:work -- and that same line names more workers as the scaling path.
    Http::fake(['airtable.com/oauth2/v1/token' => Http::response([
        'access_token' => 'oaa-rotated',
        'refresh_token' => 'oar-rotated',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ], 200)]);

    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $connection = Connection::factory()->airtable()->create(['token_expires_at' => Carbon::now()->addSeconds(60)]);

    // Hold the grant's lock, exactly as a concurrent worker mid-exchange would.
    $held = Cache::lock('connector-refresh:'.$connection->getKey(), 30);
    expect($held->get())->toBeTrue();

    RefreshOneConnectionJob::dispatch($tenant->id, (string) $connection->getKey());
    workOneJob('scheduled-maintenance');

    enterTenant($tenant->id);

    // The loser sends NOTHING rather than exchanging a token another worker is already spending.
    Http::assertNothingSent();
    expect($connection->fresh()->refresh_token)->not->toBe('oar-rotated');

    $held->release();

    // Released, the same job does its work -- proving the guard is the lock and not a broken job.
    RefreshOneConnectionJob::dispatch($tenant->id, (string) $connection->getKey());
    workOneJob('scheduled-maintenance');

    enterTenant($tenant->id);

    expect($connection->fresh()->refresh_token)->toBe('oar-rotated');
});
