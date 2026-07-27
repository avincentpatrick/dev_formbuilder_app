<?php

declare(strict_types=1);

use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\DomainEventType;
use App\Enums\PlanTier;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Entitlements\EntitlementService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /api/v1/connections (H15a) — the read + disconnect surface for grants, CRUD for their delivery rules, and
| the shared delivery log. Real tokens via withToken() (the WebhookEndpointApiTest pattern): actingAs() would
| resolve a TransientToken that passes every ability, making the two-layer ability+policy check vacuous.
|
| The property this file exists to pin above all others: NO representation of a connection ever contains a
| token, in any form (ADR-0009 §D1).
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
    assignPlanTier(PlanTier::Starter);

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');

    $this->connection = Connection::factory()->create(['access_token' => 'xoxb-super-secret']);

    // Tokens are minted ONCE, here, while tenant context is live: `personal_access_tokens` is RLS-guarded,
    // and every HTTP request in a test forgets the context in terminate(), so a later createToken() would
    // hit an insufficient-privilege error rather than an authorization one.
    $this->apiToken = $this->admin->createToken('ci', [ApiAbilities::MANAGE_INTEGRATIONS])->plainTextToken;
    $this->webhookScopedToken = $this->admin->createToken('ci-webhooks', [ApiAbilities::MANAGE_WEBHOOKS])->plainTextToken;
    $this->viewerToken = $this->viewer
        ->createToken('ci-viewer', ApiAbilities::intersect($this->viewer, [ApiAbilities::MANAGE_INTEGRATIONS]))
        ->plainTextToken;
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function connectionsUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/api/v1/connections'.$suffix;
}

function adminIntegrationsToken(): string
{
    return (string) test()->apiToken;
}

it('lists connections without ever exposing a token', function (): void {
    $response = $this->withToken(adminIntegrationsToken())->getJson(connectionsUrl())->assertOk();

    $response->assertJsonPath('data.0.provider', 'slack')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonMissingPath('data.0.access_token')
        ->assertJsonMissingPath('data.0.refresh_token');

    // Not masked, not truncated, not present at all — including any accidental suffix affordance.
    expect($response->getContent())->not->toContain('xoxb-super-secret')
        ->and($response->getContent())->not->toContain('secret_masked');
});

it('shows one connection without a token', function (): void {
    $response = $this->withToken(adminIntegrationsToken())
        ->getJson(connectionsUrl('/'.$this->connection->id))
        ->assertOk()
        ->assertJsonPath('data.external_account_label', $this->connection->external_account_label);

    expect($response->getContent())->not->toContain('xoxb-super-secret');
});

it('offers no way to create or update a grant over the API', function (): void {
    // A credential may only arrive through the interactive OAuth flow (ADR-0009): an API that accepted one
    // would be an unauthenticated path to writing a token the platform then acts with.
    $this->withToken(adminIntegrationsToken())
        ->postJson(connectionsUrl(), ['provider' => 'slack', 'access_token' => 'xoxb-injected'])
        ->assertStatus(405);

    $this->withToken(adminIntegrationsToken())
        ->patchJson(connectionsUrl('/'.$this->connection->id), ['access_token' => 'xoxb-injected'])
        ->assertStatus(405);
});

it('disconnects a grant, clearing the credential and pausing its rules', function (): void {
    $subscription = ConnectionSubscription::factory()->forConnection($this->connection)->create();

    $this->withToken(adminIntegrationsToken())
        ->deleteJson(connectionsUrl('/'.$this->connection->id))
        ->assertNoContent();

    enterTenant($this->tenant->id, $this->admin->id);

    expect(Connection::query()->withTrashed()->findOrFail($this->connection->id))
        ->deleted_at->not->toBeNull()
        ->access_token->toBe('')
        ->refresh_token->toBeNull();

    expect($subscription->fresh()->status)->toBe(ConnectorSubscriptionStatus::Paused);
});

it('creates, reads, updates and deletes a delivery rule', function (): void {
    $created = $this->withToken(adminIntegrationsToken())
        ->postJson(connectionsUrl('/'.$this->connection->id.'/subscriptions'), [
            'name' => 'Ops channel',
            'event_types' => [DomainEventType::SubmissionCreated->value],
            'config' => ['channel_id' => 'C123', 'channel_name' => '#ops'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ops channel')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.config.channel_id', 'C123');

    $id = $created->json('data.id');

    $this->withToken(adminIntegrationsToken())
        ->getJson(connectionsUrl('/'.$this->connection->id.'/subscriptions/'.$id))
        ->assertOk()->assertJsonPath('data.id', $id);

    $this->withToken(adminIntegrationsToken())
        ->patchJson(connectionsUrl('/'.$this->connection->id.'/subscriptions/'.$id), [
            'name' => 'Renamed',
            'status' => ConnectorSubscriptionStatus::Paused->value,
        ])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.status', 'paused');

    $this->withToken(adminIntegrationsToken())
        ->deleteJson(connectionsUrl('/'.$this->connection->id.'/subscriptions/'.$id))
        ->assertNoContent();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(ConnectionSubscription::query()->count())->toBe(0);
});

it('resets the failure counter when a rule is re-enabled', function (): void {
    $subscription = ConnectionSubscription::factory()->forConnection($this->connection)
        ->paused()->create(['consecutive_failure_count' => 20]);

    $this->withToken(adminIntegrationsToken())
        ->patchJson(connectionsUrl('/'.$this->connection->id.'/subscriptions/'.$subscription->id), [
            'status' => ConnectorSubscriptionStatus::Active->value,
        ])
        ->assertOk();

    // Re-enter the tenant: the request's terminate() forgot the RLS context, so a read here would see nothing.
    enterTenant($this->tenant->id, $this->admin->id);
    expect($subscription->fresh()->consecutive_failure_count)->toBe(0);
});

it('rejects a rule with no destination or an unknown event type', function (): void {
    // A rule with no channel can never deliver; refusing it here keeps that a mistake rather than a
    // permanent delivery-time failure that looks like an outage.
    $this->withToken(adminIntegrationsToken())
        ->postJson(connectionsUrl('/'.$this->connection->id.'/subscriptions'), [
            'name' => 'No channel',
            'event_types' => [DomainEventType::SubmissionCreated->value],
            'config' => [],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['config.channel_id']]]]);

    // Connectors subscribe to the same DomainEventType catalog webhooks do — there is no per-channel dialect.
    $this->withToken(adminIntegrationsToken())
        ->postJson(connectionsUrl('/'.$this->connection->id.'/subscriptions'), [
            'name' => 'Bad event',
            'event_types' => ['submission.deleted'],
            'config' => ['channel_id' => 'C123'],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['event_types.0']]]]);
});

it('404s a rule reached through the wrong connection', function (): void {
    $otherConnection = Connection::factory()->create(['external_account_id' => 'T0OTHER']);
    $subscription = ConnectionSubscription::factory()->forConnection($this->connection)->create();

    $this->withToken(adminIntegrationsToken())
        ->getJson(connectionsUrl('/'.$otherConnection->id.'/subscriptions/'.$subscription->id))
        ->assertNotFound();
});

it('returns the rule delivery log from the shared ledger', function (): void {
    $subscription = ConnectionSubscription::factory()->forConnection($this->connection)->create();
    WebhookDelivery::factory()->forSubscription($subscription)->create();

    $this->withToken(adminIntegrationsToken())
        ->getJson(connectionsUrl('/'.$this->connection->id.'/subscriptions/'.$subscription->id.'/deliveries'))
        ->assertOk()
        ->assertJsonPath('data.0.connection_subscription_id', $subscription->id)
        ->assertJsonPath('data.0.webhook_endpoint_id', null)
        // The log never carries the delivered body or a signature — the H13a resource contract.
        ->assertJsonMissingPath('data.0.payload')
        ->assertJsonMissingPath('data.0.signature');
});

it('refuses a token without the integrations ability', function (): void {
    // The point of a NEW ability: an already-minted webhook token gains no authority over OAuth grants.
    $this->withToken($this->webhookScopedToken)->getJson(connectionsUrl())->assertForbidden();
});

it('refuses a member without integrations.manage even with the right ability', function (): void {
    // A viewer cannot even hold the ability (intersect drops it), so their token is scope-less and the
    // ability gate refuses first — the two layers agree rather than one covering for the other.
    expect(ApiAbilities::intersect($this->viewer, [ApiAbilities::MANAGE_INTEGRATIONS]))->toBe([]);

    $this->withToken($this->viewerToken)->getJson(connectionsUrl())->assertForbidden();
});

it('refuses a plan without native connectors', function (): void {
    // Turn OFF only `native_connectors` on the tenant's current plan. Dropping to Free would refuse earlier
    // and for a different reason (Group B's own `feature:api_access` gate), which would pass this test
    // without ever exercising the route's connector gate.
    enterTenant($this->tenant->id, $this->admin->id);
    $plan = app(EntitlementService::class)->currentPlan();
    $flags = $plan->feature_flags;
    $flags['native_connectors'] = false;
    $plan->forceFill(['feature_flags' => $flags])->save();
    app(EntitlementService::class)->forget();

    $this->withToken(adminIntegrationsToken())->getJson(connectionsUrl())
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'feature_not_available')
        ->assertJsonPath('error.details.feature', 'native_connectors');
});

it('never exposes another tenant grant', function (): void {
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    enterTenant($other->id);
    $foreign = Connection::factory()->create();

    enterTenant($this->tenant->id, $this->admin->id);

    $this->withToken(adminIntegrationsToken())->getJson(connectionsUrl('/'.$foreign->id))->assertNotFound();

    $this->withToken(adminIntegrationsToken())->getJson(connectionsUrl())
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
