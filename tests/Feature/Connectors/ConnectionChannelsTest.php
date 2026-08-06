<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The channel-picker sidecar (H15b) — GET /integrations/connections/{connection}/channels, backed by
| SlackChannelLister + ConnectorChannelDirectory.
|
| The invariant under test throughout: this endpoint answers 200 with `error` in the BODY for every provider
| failure. A 5xx would reach the client as a thrown BuilderRequestError whose message the caller discards, and
| the picker would show "couldn't load" with no reason and no way to act on it.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->connection = Connection::factory()->create(['status' => ConnectionStatus::Active]);

    Http::preventStrayRequests();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function channelsUrl(Connection $connection): string
{
    return 'http://acme.meridian.test/integrations/connections/'.$connection->id.'/channels';
}

function slackChannel(string $id, string $name, bool $isMember = true): array
{
    return ['id' => $id, 'name' => $name, 'is_member' => $isMember];
}

it('returns the workspace channels, sorted, with membership flags', function (): void {
    Http::fake([
        'slack.com/api/conversations.list*' => Http::response([
            'ok' => true,
            'channels' => [
                slackChannel('C2', 'zebra'),
                slackChannel('C1', 'alpha', false),
            ],
        ], 200),
    ]);

    $this->actingAs($this->admin)
        ->getJson(channelsUrl($this->connection))
        ->assertOk()
        ->assertJson([
            'truncated' => false,
            'error' => null,
            'channels' => [
                ['id' => 'C1', 'label' => '#alpha', 'available' => false, 'unavailable_reason' => 'not_a_member'],
                ['id' => 'C2', 'label' => '#zebra', 'available' => true, 'unavailable_reason' => null],
            ],
        ]);
});

it('follows the cursor across pages', function (): void {
    $page = 0;
    Http::fake(['slack.com/api/conversations.list*' => function () use (&$page) {
        $page++;

        return $page === 1
            ? Http::response([
                'ok' => true,
                'channels' => [slackChannel('C1', 'alpha')],
                'response_metadata' => ['next_cursor' => 'abc'],
            ], 200)
            : Http::response(['ok' => true, 'channels' => [slackChannel('C2', 'beta')]], 200);
    }]);

    $this->actingAs($this->admin)
        ->getJson(channelsUrl($this->connection))
        ->assertOk()
        ->assertJsonCount(2, 'channels')
        ->assertJsonPath('truncated', false);

    expect($page)->toBe(2);
});

it('reports the list as truncated when the page budget runs out', function (): void {
    config()->set('connectors.channel_page_limit', 2);

    Http::fake(['slack.com/api/conversations.list*' => Http::response([
        'ok' => true,
        'channels' => [slackChannel('C1', 'alpha')],
        'response_metadata' => ['next_cursor' => 'always-more'],
    ], 200)]);

    $this->actingAs($this->admin)
        ->getJson(channelsUrl($this->connection))
        ->assertOk()
        ->assertJsonPath('truncated', true);
});

it('treats Slack ok:false at HTTP 200 as a failure and explains it', function (): void {
    Http::fake(['slack.com/api/conversations.list*' => Http::response([
        'ok' => false,
        'error' => 'missing_scope',
    ], 200)]);

    $this->actingAs($this->admin)
        ->getJson(channelsUrl($this->connection))
        ->assertOk()
        ->assertJsonPath('channels', [])
        ->assertJsonPath('error', 'The Slack app is missing the permission needed to list destinations. Reconnect this account to grant it.');
});

it('maps a credential rejection to reconnect copy', function (): void {
    Http::fake(['slack.com/api/conversations.list*' => Http::response(['ok' => false, 'error' => 'invalid_auth'], 200)]);

    $this->actingAs($this->admin)
        ->getJson(channelsUrl($this->connection))
        ->assertOk()
        ->assertJsonPath('error', 'Slack rejected our credentials. Reconnect this account, then try again.');
});

it('never echoes an unrecognised provider error code', function (): void {
    Http::fake(['slack.com/api/conversations.list*' => Http::response([
        'ok' => false,
        'error' => '<script>alert(1)</script>',
    ], 200)]);

    $response = $this->actingAs($this->admin)->getJson(channelsUrl($this->connection))->assertOk();

    expect($response->json('error'))->not->toContain('script')
        ->and($response->json('error'))->toContain('We couldn’t load destinations from Slack');
});

it('degrades a transport failure instead of 5xx-ing', function (): void {
    Http::fake(['slack.com/api/conversations.list*' => fn () => throw new ConnectionException('timeout')]);

    $this->actingAs($this->admin)
        ->getJson(channelsUrl($this->connection))
        ->assertOk()
        ->assertJsonPath('channels', [])
        ->assertJsonPath('error', 'We couldn’t reach Slack. Check your connection and refresh the list.');
});

it('refuses to call the provider at all for a non-active grant', function (): void {
    // Http::preventStrayRequests() is the assertion here: a revoked grant must short-circuit before any
    // outbound call, since its token has already been cleared.
    $dead = Connection::factory()->revoked()->create();

    $this->actingAs($this->admin)
        ->getJson(channelsUrl($dead))
        ->assertOk()
        ->assertJsonPath('channels', [])
        ->assertJsonPath('error', 'This workspace needs to be reconnected before we can list its channels.');
});

it('degrades when the provider has no channel lister configured', function (): void {
    config()->set('connectors.providers.slack.channel_lister', null);

    $this->actingAs($this->admin)
        ->getJson(channelsUrl($this->connection))
        ->assertOk()
        ->assertJsonPath('channels', [])
        ->assertJsonPath('error', 'This integration doesn’t offer a destination list. Enter the destination id instead.');
});

it('requests only public, non-archived channels', function (): void {
    // The granted scopes are chat:write + channels:read; asking for private conversations would fail the whole
    // call with missing_scope rather than degrading, so the query is pinned here.
    Http::fake(['slack.com/api/conversations.list*' => Http::response(['ok' => true, 'channels' => []], 200)]);

    $this->actingAs($this->admin)->getJson(channelsUrl($this->connection))->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'types=public_channel')
        && str_contains($request->url(), 'exclude_archived=true'));
});
