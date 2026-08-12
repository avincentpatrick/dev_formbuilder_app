<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Enums\PlanTier;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connectors\ConnectionTokenRefresher;
use App\Support\Connectors\ConnectorOAuthStateService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The Airtable OAuth flow (H16c / ADR-0009). A SEPARATE file from `ConnectorOAuthFlowTest`, which pins the
| framework-level properties once against Slack — tenant isolation, tampered state, the return host — and is
| Slack-specific end to end. Repeating those here would assert the framework twice and the provider once.
|
| What is genuinely new, and is all this file is about, is the three ways Airtable's OAuth differs from both
| predecessors: PKCE is mandatory and its verifier has to survive a hop between two hosts; the token endpoint
| authenticates with HTTP Basic rather than form fields; and refresh tokens ROTATE, so the returned one must
| replace the stored one or the connection dies 60 minutes after it is made.
|
| ⚠️ The helper names are prefixed. Pest loads every test file into one process, so a second `connectUrl()`
| beside `ConnectorOAuthFlowTest`'s would be a fatal redeclare rather than a shadow.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Http::preventStrayRequests();

    // Never from `.env`: `AIRTABLE_CONNECTOR_*` are blank in `.env.example`, CI copies that file verbatim, and
    // a realistic-shaped secret committed in a test is what the gitleaks gate exists to stop.
    config()->set('connectors.providers.airtable.client_id', 'test-client-id');
    config()->set('connectors.providers.airtable.client_secret', 'test-client-secret');
    config()->set('connectors.tenant_url_scheme', 'http');
    config()->set('tenancy.central_domain', 'meridian.test');

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    assignPlanTier(PlanTier::Starter);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The Airtable token success body. Flat, unlike Slack's nested `team` — identity comes from `whoami`. */
function airtableGrantBody(array $overrides = []): array
{
    return array_merge([
        'access_token' => 'oaaFIRSTACCESS',
        'refresh_token' => 'oarFIRSTREFRESH',
        'token_type' => 'Bearer',
        'scope' => 'schema.bases:read data.records:write',
        'expires_in' => 3600,
    ], $overrides);
}

/** Fake the token endpoint and `whoami` independently, so either can be failed alone. */
function fakeAirtableOAuth(array $grant = [], string $userId = 'usrACME0000001'): void
{
    Http::fake([
        'airtable.com/oauth2/v1/token' => Http::response(airtableGrantBody($grant), 200),
        'api.airtable.com/v0/meta/whoami' => Http::response(['id' => $userId], 200),
    ]);
}

function airtableConnectUrl(): string
{
    return 'http://acme.meridian.test/integrations/airtable/connect';
}

function airtableCallbackUrl(string $query): string
{
    return 'http://meridian.test/oauth/airtable/callback?'.$query;
}

/** The `code_challenge` Airtable will compare against, computed the way RFC 7636 §4.2 specifies. */
function challengeOf(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

it('sends the consent screen a PKCE challenge derived from this flow, and S256', function (): void {
    $response = $this->actingAs($this->admin)->get(airtableConnectUrl());

    $target = $response->assertRedirect()->headers->get('Location');
    expect($target)->toStartWith('https://airtable.com/oauth2/v1/authorize');

    parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $query);

    expect($query['redirect_uri'])->toBe('http://meridian.test/oauth/airtable/callback')
        ->and($query['client_id'])->toBe('test-client-id')
        ->and($query['response_type'])->toBe('code')
        // SPACE-delimited, like Google and unlike Slack's commas. A comma-joined list is not rejected as
        // malformed — Airtable reads it as one unknown scope and grants nothing.
        ->and($query['scope'])->toBe('schema.bases:read data.records:write')
        // `plain` is refused outright by Airtable rather than downgraded to, so this is not a nicety.
        ->and($query['code_challenge_method'])->toBe('S256');

    // The challenge must be the S256 of the verifier the CALLBACK will independently re-derive from this same
    // state. That is the whole PKCE design here, and it is the one property no unit test of either half can
    // see on its own.
    $expected = challengeOf(app(ConnectorOAuthStateService::class)->codeVerifierFor($query['state']));

    expect($query['code_challenge'])->toBe($expected);
});

it('completes the round trip, sending back the verifier matching the challenge it published', function (): void {
    fakeAirtableOAuth();

    // Start the flow for real rather than minting a state directly: the point under test is that two separate
    // requests agree, and a hand-minted state would prove only that one function is deterministic.
    $target = (string) $this->actingAs($this->admin)->get(airtableConnectUrl())->headers->get('Location');
    parse_str((string) parse_url($target, PHP_URL_QUERY), $authorize);

    $this->get(airtableCallbackUrl(http_build_query(['code' => 'auth-code', 'state' => $authorize['state']])))
        ->assertRedirect('http://acme.meridian.test/integrations?connected=airtable');

    $sentVerifier = null;
    Http::recorded(function (Request $request) use (&$sentVerifier): bool {
        if (str_contains($request->url(), '/oauth2/v1/token')) {
            $sentVerifier = $request->data()['code_verifier'] ?? null;
        }

        return true;
    });

    expect($sentVerifier)->toBeString()
        ->and(challengeOf((string) $sentVerifier))->toBe($authorize['code_challenge']);
});

it('authenticates the token endpoint with HTTP Basic and keeps the secret out of the body', function (): void {
    fakeAirtableOAuth();

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Airtable);

    $this->get(airtableCallbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])));

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/oauth2/v1/token')) {
            return false;
        }

        // Airtable REQUIRES the header for a confidential client and refuses it for a public one, so this is
        // not the interchangeable choice it is at Slack's and Google's endpoints. Sending the secret as a
        // form field instead fails with an error that reads like a bad code.
        return $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client-id:test-client-secret'))
            && ! array_key_exists('client_secret', $request->data());
    });
});

it('stores the grant against the Airtable account id, encrypted', function (): void {
    fakeAirtableOAuth(userId: 'usrACME0000001');

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Airtable);

    $this->get(airtableCallbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect('http://acme.meridian.test/integrations?connected=airtable');

    enterTenant($this->tenant->id, $this->admin->id);
    $connection = Connection::query()->firstOrFail();

    expect($connection->provider)->toBe(ConnectorProviderKey::Airtable)
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        // A REAL account id, not the constant Google's adapter is forced into: `whoami` costs no scope, so
        // `connections_tenant_provider_account_unique` can tell two Airtable accounts apart.
        ->and($connection->external_account_id)->toBe('usrACME0000001')
        ->and($connection->scopes)->toBe(['schema.bases:read', 'data.records:write'])
        ->and($connection->access_token)->toBe('oaaFIRSTACCESS')
        ->and($connection->refresh_token)->toBe('oarFIRSTREFRESH')
        // Airtable access tokens live 60 minutes, so the sweep has something to do — a null here would mean
        // every refresh code path silently skips this provider.
        ->and($connection->token_expires_at)->not->toBeNull();
});

it('lets one tenant hold two Airtable accounts side by side', function (): void {
    // The payoff of paying for a real identity. With a constant id (the Google narrowing) the second connect
    // would UPDATE the first in place, and a tenant with a personal and a team Airtable account would silently
    // lose one.
    //
    // ⚠️ ONE `Http::fake()` WITH A COUNTER, NOT ONE PER ITERATION. Calling `Http::fake()` again APPENDS stubs
    // rather than replacing them, and matching takes the FIRST stub that matches — so a per-iteration fake
    // leaves the first `whoami` answering both connects, both grants land on one account id, and the test
    // fails against correct code. Cost me a red run; it is the same stateful-closure shape
    // `ConnectionChannelsTest` uses for pagination.
    $whoami = 0;
    Http::fake([
        'airtable.com/oauth2/v1/token' => Http::response(airtableGrantBody(), 200),
        'api.airtable.com/v0/meta/whoami' => function () use (&$whoami) {
            $whoami++;

            return Http::response(['id' => $whoami === 1 ? 'usrPERSONAL001' : 'usrTEAMWORK001'], 200);
        },
    ]);

    foreach ([1, 2] as $ignored) {
        $state = app(ConnectorOAuthStateService::class)
            ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Airtable);

        $this->get(airtableCallbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])));
    }

    enterTenant($this->tenant->id, $this->admin->id);

    expect(Connection::query()->pluck('external_account_id')->all())
        ->toEqualCanonicalizing(['usrPERSONAL001', 'usrTEAMWORK001']);
});

it('persists the ROTATED refresh token when a grant is renewed', function (): void {
    // The failure this guards is invisible for exactly one hour. Airtable invalidates the previous pair on
    // every refresh, so keeping the old token turns the second renewal into `invalid_grant`, which is TERMINAL:
    // the connection is marked dead, its rules are paused and the owner is emailed.
    $connection = Connection::factory()->airtable(expiresInSeconds: 30)->create([
        'refresh_token' => 'oarORIGINAL',
    ]);

    Http::fake([
        'airtable.com/oauth2/v1/token' => Http::response(airtableGrantBody([
            'access_token' => 'oaaSECONDACCESS',
            'refresh_token' => 'oarROTATED',
        ]), 200),
    ]);

    app(ConnectionTokenRefresher::class)->ensureFresh($connection, Carbon::now());

    expect($connection->fresh()->refresh_token)->toBe('oarROTATED')
        ->and($connection->fresh()->access_token)->toBe('oaaSECONDACCESS');

    // And `whoami` is NOT called on this path: a blip on it during the hourly sweep would surface as a refresh
    // failure, which revokes a perfectly healthy grant.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'whoami'));
});

it('refuses a first exchange that returns no refresh token', function (): void {
    // Not a tenant error — it means the integration was registered without offline access, and accepting it
    // would store a connection that works for 60 minutes and then cannot be renewed by anything.
    Http::fake([
        'airtable.com/oauth2/v1/token' => Http::response(airtableGrantBody(['refresh_token' => null]), 200),
        'api.airtable.com/v0/meta/whoami' => Http::response(['id' => 'usrACME0000001'], 200),
    ]);

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Airtable);

    $this->get(airtableCallbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect('http://acme.meridian.test/integrations?provider=airtable&connect_error=missing_refresh_token');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Connection::query()->count())->toBe(0);
});

it('writes nothing when the account behind the token cannot be confirmed', function (): void {
    // Storing the grant anyway would need a placeholder account id, and two different Airtable accounts
    // sharing one would overwrite each other's tokens through the unique index. The user is standing right
    // there and can click Connect again.
    Http::fake([
        'airtable.com/oauth2/v1/token' => Http::response(airtableGrantBody(), 200),
        'api.airtable.com/v0/meta/whoami' => Http::response(['error' => 'UNAUTHORIZED'], 401),
    ]);

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Airtable);

    $this->get(airtableCallbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect('http://acme.meridian.test/integrations?provider=airtable&connect_error=identity_unavailable');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Connection::query()->count())->toBe(0);
});

it('bounces home with the provider error when the exchange is refused', function (): void {
    Http::fake([
        'airtable.com/oauth2/v1/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::Airtable);

    $this->get(airtableCallbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect('http://acme.meridian.test/integrations?provider=airtable&connect_error=invalid_grant');

    // `whoami` is never reached — the identity call happens only after a successful exchange.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'whoami'));
});

it('refuses a state minted for another provider at the Airtable callback', function (): void {
    // `prov` binding, exercised for the first time between two REAL cases rather than a forged payload: until
    // H16c the enum was small enough that the sibling test in ConnectorOAuthStateTest had to hand-build one.
    $state = app(ConnectorOAuthStateService::class)
        ->mint($this->tenant->id, $this->admin->id, ConnectorProviderKey::GoogleSheets);

    $this->get(airtableCallbackUrl(http_build_query(['code' => 'auth-code', 'state' => $state])))
        ->assertRedirect();

    Http::assertNothingSent();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Connection::query()->count())->toBe(0);
});
