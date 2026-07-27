<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Enums\PlanTier;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Connectors\ConnectorOAuthStateService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The OAuth flow end to end (H15a / ADR-0009): the session-authed start on the tenant subdomain, and the
| session-LESS callback on the central domain whose only trust anchor is the signed `state`.
|
| The security properties under test are the ones the ADR spends its §D3–§D5 on: a state minted for tenant A
| can never write into tenant B; a tampered/expired state writes nothing at all; and the post-callback
| redirect is always the tenant's own host, read from `domains`, never echoed from the request.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Http::preventStrayRequests();

    config()->set('connectors.providers.slack.client_id', 'test-client-id');
    config()->set('connectors.providers.slack.client_secret', 'test-client-secret');
    config()->set('connectors.tenant_url_scheme', 'http');
    config()->set('tenancy.central_domain', 'meridian.test');

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    assignPlanTier(PlanTier::Starter);

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The Slack `oauth.v2.access` success body — note Slack nests the workspace under `team`. */
function slackGrantBody(array $overrides = []): array
{
    return array_merge([
        'ok' => true,
        'access_token' => 'xoxb-real-token',
        'scope' => 'chat:write,channels:read',
        'team' => ['id' => 'T0ACME', 'name' => 'Acme HQ'],
    ], $overrides);
}

function connectUrl(): string
{
    return 'http://acme.meridian.test/integrations/slack/connect';
}

function callbackUrl(string $query): string
{
    return 'http://meridian.test/oauth/slack/callback?'.$query;
}

it('redirects an authorized admin to the provider with a signed state and the central callback URI', function (): void {
    $response = $this->actingAs($this->admin)->get(connectUrl());

    $response->assertRedirect();
    $target = $response->headers->get('Location');

    expect($target)->toStartWith('https://slack.com/oauth/v2/authorize');

    parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $query);

    // The redirect_uri is the CENTRAL host — one registered URI serves every tenant (ADR-0009 §D2).
    expect($query['redirect_uri'])->toBe('http://meridian.test/oauth/slack/callback')
        ->and($query['client_id'])->toBe('test-client-id')
        ->and($query['scope'])->toBe('chat:write,channels:read');

    // The state is ours, and names the tenant + user who actually started the flow.
    $state = app(ConnectorOAuthStateService::class)->verify($query['state'], ConnectorProviderKey::Slack);
    expect($state->tenantId)->toBe($this->tenant->id)
        ->and($state->userId)->toBe($this->admin->id);
});

it('refuses to start a flow for a member without integrations.manage', function (): void {
    $this->actingAs($this->viewer)->get(connectUrl())->assertForbidden();
});

it('refuses to start a flow on a plan without native connectors', function (): void {
    enterTenant($this->tenant->id, $this->admin->id);
    assignPlanTier(PlanTier::Free);

    $this->actingAs($this->admin)->get(connectUrl())
        ->assertRedirect()
        ->assertSessionHas('toast');

    expect(session('toast')['type'])->toBe('error');
});

it('404s an unknown provider', function (): void {
    $this->actingAs($this->admin)->get('http://acme.meridian.test/integrations/dropbox/connect')->assertNotFound();
});

it('exchanges the code, stores the grant encrypted, and returns to the tenant host', function (): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackGrantBody(), 200)]);

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Slack);

    // No session, no CSRF token — exactly how a provider redirect arrives.
    $this->get(callbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect('http://acme.meridian.test/integrations?connected=slack');

    enterTenant($this->tenant->id, $this->admin->id);
    $connection = Connection::query()->firstOrFail();

    expect($connection->provider)->toBe(ConnectorProviderKey::Slack)
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->external_account_id)->toBe('T0ACME')
        ->and($connection->external_account_label)->toBe('Acme HQ')
        ->and($connection->scopes)->toBe(['chat:write', 'channels:read'])
        ->and($connection->access_token)->toBe('xoxb-real-token')
        ->and($connection->connected_by)->toBe($this->admin->id)
        // Slack's default bot token never expires, so there is nothing for the refresh sweep to do.
        ->and($connection->token_expires_at)->toBeNull();

    // At rest it is ciphertext, not the token: the row alone is not the credential (ADR-0009 §D1).
    $raw = DB::table('connections')->where('id', $connection->id)->value('access_token');
    expect($raw)->not->toBe('xoxb-real-token')
        ->and(base64_decode((string) $raw, true))->toBeString();
});

it('writes a redacted audit row for the connect', function (): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackGrantBody(), 200)]);

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Slack);

    $this->get(callbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])));

    enterTenant($this->tenant->id, $this->admin->id);
    $audit = DB::table('audits')->where('auditable_type', 'connection')->where('event', 'created')->first();

    expect($audit)->not->toBeNull();
    expect(json_decode((string) $audit->redacted_fields, true))->toContain('access_token');
    expect((string) $audit->new_values)->not->toContain('xoxb-real-token');
});

it('never writes into another tenant, whatever the callback claims', function (): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackGrantBody(), 200)]);

    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'other']);

    // A state legitimately minted for the OTHER tenant, replayed at the same callback URL.
    $state = app(ConnectorOAuthStateService::class)
        ->mint($other->id, $this->admin->id, ConnectorProviderKey::Slack);

    $this->get(callbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect('http://other.meridian.test/integrations?connected=slack');

    // The write landed in the tenant the SIGNED state named, and nowhere else.
    enterTenant($other->id);
    expect(Connection::query()->count())->toBe(1);

    enterTenant($this->tenant->id);
    expect(Connection::query()->count())->toBe(0);
});

it('writes nothing and discloses nothing when the state is unverifiable', function (string $state): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackGrantBody(), 200)]);

    $this->get(callbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect(config('app.url'));

    enterTenant(test()->tenant->id);
    expect(Connection::query()->count())->toBe(0);

    // The exchange never happened — a bad state is refused before any provider call.
    Http::assertNothingSent();
})->with([
    'forged' => ['v1.Zm9v.YmFy'],
    'empty' => [''],
    'not a token at all' => ['nonsense'],
]);

it('writes nothing when the state has expired', function (): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackGrantBody(), 200)]);

    $expired = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Slack, time() - 86400);

    $this->get(callbackUrl(http_build_query(['code' => 'auth-code', 'state' => $expired])))
        ->assertRedirect(config('app.url'));

    enterTenant($this->tenant->id);
    expect(Connection::query()->count())->toBe(0);
});

it('bounces home with the reason when the user declines consent', function (): void {
    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Slack);

    $this->get(callbackUrl(http_build_query(['error' => 'access_denied', 'state' => $state])))
        ->assertRedirect('http://acme.meridian.test/integrations?provider=slack&connect_error=access_denied');

    enterTenant($this->tenant->id);
    expect(Connection::query()->count())->toBe(0);
});

it('bounces home with the provider error when the exchange is refused', function (): void {
    // Slack reports failure with HTTP 200 — the adapter must read `ok`, not the status code.
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(['ok' => false, 'error' => 'invalid_code'], 200)]);

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Slack);

    $this->get(callbackUrl(http_build_query(['code' => 'stale-code', 'state' => $state])))
        ->assertRedirect('http://acme.meridian.test/integrations?provider=slack&connect_error=invalid_code');

    enterTenant($this->tenant->id);
    expect(Connection::query()->count())->toBe(0);
});

it('updates the existing grant in place when the same workspace reconnects', function (): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackGrantBody(['access_token' => 'xoxb-rotated']), 200)]);

    enterTenant($this->tenant->id, $this->admin->id);
    $existing = Connection::factory()->create([
        'external_account_id' => 'T0ACME',
        'external_account_label' => 'Old name',
        'access_token' => 'xoxb-stale',
    ]);

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Slack);

    $this->get(callbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);

    expect(Connection::query()->count())->toBe(1);
    $existing->refresh();
    expect($existing->access_token)->toBe('xoxb-rotated')
        ->and($existing->external_account_label)->toBe('Acme HQ');
});

it('restores a previously disconnected grant rather than colliding with it', function (): void {
    Http::fake(['slack.com/api/oauth.v2.access' => Http::response(slackGrantBody(), 200)]);

    enterTenant($this->tenant->id, $this->admin->id);
    $disconnected = Connection::factory()->create(['external_account_id' => 'T0ACME']);
    $disconnected->delete();

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Slack);

    $this->get(callbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect('http://acme.meridian.test/integrations?connected=slack');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Connection::query()->count())->toBe(1)
        ->and(Connection::query()->firstOrFail()->id)->toBe($disconnected->id);
});
