<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\ConnectorProviderKey;
use App\Enums\DomainEventType;
use App\Enums\PlanTier;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Connectors\ConnectionService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /integrations (H15b) — the session-authed connector UI over the H15a framework. Route-level Inertia render +
| prop shape (ConnectionPresenter), the rule create/update/delete mutations (delegating to
| ConnectionSubscriptionService), the disconnect, the synchronous test send, the OAuth return bridge, the
| integrations.manage policy gate, and the native_connectors feature gate. Session auth (actingAs), NOT tokens.
|
| Tenant context is gone after every HTTP call (the middleware forgets it in terminate()), so every
| post-assertion DB read re-enters it.
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

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');
});

// NOTE: no Http::preventStrayRequests() here, unlike the sidecar suite. Inertia's SSR gateway POSTs to the
// render server while building the root view, so a global stray-request guard turns every page render into a
// 500. Tests that care about outbound calls assert with Http::fake() + assertNothingSent() instead.

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function integrationsUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/integrations'.$suffix;
}

/** A live Slack grant under the current (admin) tenant context. */
function makeConnection(array $overrides = []): Connection
{
    return Connection::factory()->create(array_merge([
        'external_account_label' => 'Acme HQ',
        'status' => ConnectionStatus::Active,
    ], $overrides));
}

function makeRule(Connection $connection, array $overrides = []): ConnectionSubscription
{
    return ConnectionSubscription::factory()
        ->forConnection($connection)
        ->create(array_merge(['name' => 'New submissions'], $overrides));
}

/*
|--------------------------------------------------------------------------
| Index render + prop shape
|--------------------------------------------------------------------------
*/

it('renders the integrations index with the provider catalog, connections and their rules', function (): void {
    $this->withoutVite();
    $connection = makeConnection();
    makeRule($connection, ['name' => 'Ops feed']);
    makeRule($connection, ['name' => 'Publishing feed']);

    $this->actingAs($this->admin)
        ->get(integrationsUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('integrations/Index', false)
            ->has('providers', count(ConnectorProviderKey::cases()))
            ->where('providers.0.key', 'slack')
            ->where('providers.0.connected', true)
            ->has('connections', 1)
            ->where('connections.0.external_account_label', 'Acme HQ')
            ->has('connections.0.rules', 2)
            ->has('summary.rules')
            ->has('summary.deliveries')
            ->has('eventTypes', 4)
            ->where('can.create', true));
});

it('reports a provider as unconfigured when the deployment has no app credentials', function (): void {
    // CI and a fresh checkout both have empty Slack credentials — the page must still render, with an
    // explained, disabled connect button rather than a link into an opaque provider error.
    $this->withoutVite();
    config()->set('connectors.providers.slack.client_id', null);

    $this->actingAs($this->admin)
        ->get(integrationsUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('providers.0.configured', false));
});

it('marks a provider configured once credentials are present', function (): void {
    $this->withoutVite();
    config()->set('connectors.providers.slack.client_id', '123.456');

    $this->actingAs($this->admin)
        ->get(integrationsUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('providers.0.configured', true));
});

it('never exposes a connector token in any index prop', function (): void {
    // ADR-0009 §D1: unlike a webhook signing secret, a connector token has no user-facing consumer at all.
    $this->withoutVite();
    $connection = makeConnection(['access_token' => 'xoxb-super-secret-value']);
    makeRule($connection);

    $response = $this->actingAs($this->admin)->get(integrationsUrl())->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->missing('connections.0.access_token')
        ->missing('connections.0.refresh_token'));

    expect($response->getContent())->not->toContain('xoxb-super-secret-value');
});

/*
|--------------------------------------------------------------------------
| The OAuth return bridge (?connected= / ?connect_error=)
|--------------------------------------------------------------------------
*/

it('converts a successful OAuth return into a flash toast and a clean url', function (): void {
    // There is no flash across the central→tenant host boundary, so the outcome arrives as a query param.
    // The controller consumes it into the real flash bridge and redirects, so a refresh cannot re-raise it.
    $this->actingAs($this->admin)
        ->get(integrationsUrl('?connected=slack'))
        ->assertRedirect(integrationsUrl())
        ->assertSessionHas('toast', fn ($t) => $t['type'] === 'success' && str_contains($t['message'], 'Slack connected'));
});

it('converts a declined OAuth return into an error toast and a clean url', function (): void {
    $this->actingAs($this->admin)
        ->get(integrationsUrl('?provider=slack&connect_error=access_denied'))
        ->assertRedirect(integrationsUrl())
        ->assertSessionHas('toast', fn ($t) => $t['type'] === 'error' && str_contains($t['message'], 'cancelled'));
});

it('falls back to generic copy for an unrecognised provider error code', function (): void {
    $this->actingAs($this->admin)
        ->get(integrationsUrl('?provider=slack&connect_error='.urlencode('<script>x</script>')))
        ->assertRedirect(integrationsUrl())
        ->assertSessionHas('toast', fn ($t) => ! str_contains($t['message'], 'script'));
});

it('renders normally when no outcome parameter is present', function (): void {
    $this->withoutVite();

    $this->actingAs($this->admin)->get(integrationsUrl())->assertOk();
});

/*
|--------------------------------------------------------------------------
| Rule detail
|--------------------------------------------------------------------------
*/

it('renders a rule detail with its offset-paginated delivery log', function (): void {
    $this->withoutVite();
    $connection = makeConnection();
    $rule = makeRule($connection);

    WebhookDelivery::factory()->count(30)->forSubscription($rule)->create();

    $this->actingAs($this->admin)
        ->get(integrationsUrl('/rules/'.$rule->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('integrations/RuleShow', false)
            ->where('rule.id', $rule->id)
            ->where('connection.id', $connection->id)
            ->has('deliveries.data', 25)
            ->where('deliveries.meta.total', 30)
            ->where('deliveries.meta.last_page', 2)
            ->where('deliveries.meta.per_page', 25)
            ->where('deliveries.meta.current_page', 1)
            ->where('can.update', true)
            ->where('can.delete', true));
});

it('still renders a rule whose workspace has been disconnected', function (): void {
    // disconnect() soft-deletes the grant but keeps its rules, so this page is reachable with a trashed
    // parent — the presenter must read it withTrashed() rather than resolving null and exploding.
    $this->withoutVite();
    $connection = makeConnection();
    $rule = makeRule($connection);

    app(ConnectionService::class)->disconnect($connection);

    $this->actingAs($this->admin)
        ->get(integrationsUrl('/rules/'.$rule->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connection.disconnected', true)
            ->where('connection.status', ConnectionStatus::Revoked->value));
});

/*
|--------------------------------------------------------------------------
| Rule mutations
|--------------------------------------------------------------------------
*/

it('creates a rule and lands on its detail page', function (): void {
    $connection = makeConnection();

    $response = $this->actingAs($this->admin)->post(integrationsUrl('/connections/'.$connection->id.'/rules'), [
        'name' => 'Ops feed',
        'event_types' => [DomainEventType::SubmissionCreated->value],
        'form_id' => null,
        'config' => ['channel_id' => 'C0123456789', 'channel_name' => 'ops'],
    ]);

    enterTenant($this->tenant->id, $this->admin->id);
    $rule = ConnectionSubscription::query()->where('name', 'Ops feed')->firstOrFail();

    $response->assertRedirect(integrationsUrl('/rules/'.$rule->id));
    $response->assertSessionHas('toast');

    expect($rule->connection_id)->toBe($connection->id)
        ->and($rule->config['channel_id'])->toBe('C0123456789')
        ->and($rule->status)->toBe(ConnectorSubscriptionStatus::Active);
});

it('rejects a rule with no channel, no events or no name', function (): void {
    $connection = makeConnection();
    $url = integrationsUrl('/connections/'.$connection->id.'/rules');

    $this->actingAs($this->admin)
        ->post($url, ['name' => '', 'event_types' => [], 'config' => []])
        ->assertSessionHasErrors(['name', 'event_types', 'config.channel_id']);
});

it('rejects an event type outside the catalog', function (): void {
    $connection = makeConnection();

    $this->actingAs($this->admin)
        ->post(integrationsUrl('/connections/'.$connection->id.'/rules'), [
            'name' => 'Bad',
            'event_types' => ['submission.deleted'],
            'config' => ['channel_id' => 'C1'],
        ])
        ->assertSessionHasErrors('event_types.0');
});

it('accepts a status-only patch and resets the failure counter when resuming', function (): void {
    // The pause/resume control PATCHes `status` alone — every other rule must be `sometimes` or this 422s.
    $connection = makeConnection();
    $rule = makeRule($connection, [
        'status' => ConnectorSubscriptionStatus::Paused,
        'consecutive_failure_count' => 20,
    ]);

    $this->actingAs($this->admin)
        ->patch(integrationsUrl('/rules/'.$rule->id), ['status' => 'active'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    $rule->refresh();

    expect($rule->status)->toBe(ConnectorSubscriptionStatus::Active)
        ->and($rule->consecutive_failure_count)->toBe(0);
});

it('deletes a rule and returns to the list', function (): void {
    $connection = makeConnection();
    $rule = makeRule($connection);

    $this->actingAs($this->admin)
        ->delete(integrationsUrl('/rules/'.$rule->id))
        ->assertRedirect(integrationsUrl());

    enterTenant($this->tenant->id, $this->admin->id);

    expect(ConnectionSubscription::query()->find($rule->id))->toBeNull()
        ->and(ConnectionSubscription::withTrashed()->find($rule->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Disconnect
|--------------------------------------------------------------------------
*/

it('disconnects a workspace, clearing its tokens and pausing its rules', function (): void {
    $connection = makeConnection();
    $rule = makeRule($connection);

    $this->actingAs($this->admin)
        ->delete(integrationsUrl('/connections/'.$connection->id))
        ->assertRedirect(integrationsUrl())
        ->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->admin->id);

    $trashed = Connection::withTrashed()->findOrFail($connection->id);
    $rule->refresh();

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->status)->toBe(ConnectionStatus::Revoked)
        ->and($trashed->access_token)->toBe('')
        ->and($trashed->refresh_token)->toBeNull()
        ->and($rule->status)->toBe(ConnectorSubscriptionStatus::Paused);
});

/*
|--------------------------------------------------------------------------
| Test send
|--------------------------------------------------------------------------
*/

it('flashes a delivered test result', function (): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    $connection = makeConnection();
    $rule = makeRule($connection);

    $this->actingAs($this->admin)
        ->post(integrationsUrl('/rules/'.$rule->id.'/test'))
        ->assertRedirect()
        ->assertSessionHas('testResult', fn ($r) => $r['delivered'] === true && $r['response_status'] === 200)
        ->assertSessionHas('toast', fn ($t) => $t['type'] === 'success');
});

it('treats Slack ok:false at HTTP 200 as a failure', function (): void {
    // The single most important Slack behaviour in this vertical: the transport status is never the verdict.
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'not_in_channel'], 200)]);

    $connection = makeConnection();
    $rule = makeRule($connection);

    $this->actingAs($this->admin)
        ->post(integrationsUrl('/rules/'.$rule->id.'/test'))
        ->assertRedirect()
        ->assertSessionHas('testResult', fn ($r) => $r['delivered'] === false
            && $r['response_status'] === 200
            && str_contains((string) $r['error'], '[not_in_channel]'))
        ->assertSessionHas('toast', fn ($t) => $t['type'] === 'error');
});

it('writes no delivery row and consumes no quota for a test send', function (): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    $connection = makeConnection();
    $rule = makeRule($connection);

    $this->actingAs($this->admin)->post(integrationsUrl('/rules/'.$rule->id.'/test'))->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('refuses a test send on a disconnected workspace with a toast, not an error page', function (): void {
    Http::fake();

    $connection = makeConnection();
    $rule = makeRule($connection);

    app(ConnectionService::class)->disconnect($connection);

    $this->actingAs($this->admin)
        ->post(integrationsUrl('/rules/'.$rule->id.'/test'))
        ->assertRedirect()
        ->assertSessionHas('toast', fn ($t) => $t['type'] === 'error' && str_contains($t['message'], 'Reconnect'))
        ->assertSessionMissing('testResult');

    // The grant's token was cleared by disconnect(), so this must short-circuit before any outbound call
    // rather than posting an empty credential at Slack.
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Authorization + entitlement gates
|--------------------------------------------------------------------------
*/

it('forbids a member without integrations.manage on every route', function (): void {
    $this->withoutVite();
    $connection = makeConnection();
    $rule = makeRule($connection);

    $this->actingAs($this->viewer)->get(integrationsUrl())->assertForbidden();
    $this->actingAs($this->viewer)->get(integrationsUrl('/rules/'.$rule->id))->assertForbidden();
    $this->actingAs($this->viewer)->patch(integrationsUrl('/rules/'.$rule->id), ['status' => 'paused'])->assertForbidden();
    $this->actingAs($this->viewer)->delete(integrationsUrl('/rules/'.$rule->id))->assertForbidden();
    $this->actingAs($this->viewer)->post(integrationsUrl('/rules/'.$rule->id.'/test'))->assertForbidden();
    $this->actingAs($this->viewer)->get(integrationsUrl('/connections/'.$connection->id.'/channels'))->assertForbidden();
    $this->actingAs($this->viewer)->delete(integrationsUrl('/connections/'.$connection->id))->assertForbidden();
    $this->actingAs($this->viewer)->post(integrationsUrl('/connections/'.$connection->id.'/rules'), [])->assertForbidden();
});

it('bounces a tenant whose plan lacks native connectors', function (): void {
    $this->withoutVite();
    assignPlanTier(PlanTier::Free);

    $this->actingAs($this->admin)
        ->get(integrationsUrl())
        ->assertRedirect()
        ->assertSessionHas('toast', fn ($t) => $t['type'] === 'error');
});

it('allows a Starter tenant, which is where native connectors begin', function (): void {
    $this->withoutVite();
    assignPlanTier(PlanTier::Starter);

    $this->actingAs($this->admin)->get(integrationsUrl())->assertOk();
});

it('404s a rule id from another tenant', function (): void {
    $this->withoutVite();

    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    enterTenant($other->id, $this->admin->id);
    $foreignRule = makeRule(makeConnection(['external_account_label' => 'Other HQ']));

    enterTenant($this->tenant->id, $this->admin->id);

    // Strict RLS means the row is not merely forbidden, it is invisible — the binding cannot resolve.
    $this->actingAs($this->admin)
        ->get(integrationsUrl('/rules/'.$foreignRule->id))
        ->assertNotFound();
});

it('404s every connection-bound route for another tenant’s grant', function (): void {
    // The three {connection}-bound routes are where a leak would matter most: the channel sidecar would hand
    // back another workspace's channel list, and disconnect would destroy someone else's grant.
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    enterTenant($other->id, $this->admin->id);
    $foreign = makeConnection(['external_account_label' => 'Other HQ']);

    enterTenant($this->tenant->id, $this->admin->id);

    $this->actingAs($this->admin)->getJson(integrationsUrl('/connections/'.$foreign->id.'/channels'))->assertNotFound();
    $this->actingAs($this->admin)->delete(integrationsUrl('/connections/'.$foreign->id))->assertNotFound();
    $this->actingAs($this->admin)->post(integrationsUrl('/connections/'.$foreign->id.'/rules'), [
        'name' => 'Sneaky',
        'event_types' => [DomainEventType::SubmissionCreated->value],
        'config' => ['channel_id' => 'C1'],
    ])->assertNotFound();

    enterTenant($other->id, $this->admin->id);

    expect(Connection::query()->find($foreign->id))->not->toBeNull()
        ->and(ConnectionSubscription::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Delivery-log scoping
|--------------------------------------------------------------------------
*/

it('shows only this rule’s deliveries, never a sibling rule’s or a webhook’s', function (): void {
    // The ledger is shared with the webhook channel (H15a), so the log query has to be owner-scoped.
    $this->withoutVite();
    $connection = makeConnection();
    $rule = makeRule($connection, ['name' => 'Mine']);
    $sibling = makeRule($connection, ['name' => 'Theirs']);

    WebhookDelivery::factory()->count(2)->forSubscription($rule)->create([
        'status' => WebhookDeliveryStatus::Succeeded,
    ]);
    WebhookDelivery::factory()->count(3)->forSubscription($sibling)->create();

    $this->actingAs($this->admin)
        ->get(integrationsUrl('/rules/'.$rule->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deliveries.data', 2)
            ->where('deliveries.meta.total', 2));
});
