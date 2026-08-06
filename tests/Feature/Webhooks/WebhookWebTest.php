<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Enums\PlanTier;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEndpointStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /webhooks (H14) — the session-authed management + delivery-log UI over the H13a/H13b engine. Route-level
| Inertia render + prop shape (WebhookEndpointPresenter), the create/update/delete/rotate/test/redeliver
| mutations (delegating to WebhookEndpointService), the one-shot secret + test-result flashes, the
| webhooks.manage policy gate, the webhooks feature gate, and validation. Session auth (actingAs), NOT tokens.
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

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function webhookUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/webhooks'.$suffix;
}

/** Create an endpoint under the current (admin) tenant context. */
function makeEndpoint(array $overrides = []): WebhookEndpoint
{
    return WebhookEndpoint::factory()->create(array_merge([
        'name' => 'Relay',
        'url' => 'https://8.8.8.8/hooks/x',
        'event_types' => [DomainEventType::SubmissionCreated->value],
        'status' => WebhookEndpointStatus::Active,
    ], $overrides));
}

it('renders the endpoints index with the entitlement summary and can flags', function (): void {
    $this->withoutVite();
    makeEndpoint(['name' => 'Zapier']);
    makeEndpoint(['name' => 'CRM']);

    $this->actingAs($this->admin)
        ->get(webhookUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('webhooks/Index', false)
            ->has('data', 2)
            ->has('summary.endpoints')
            ->has('summary.deliveries')
            // Against the enum, not a literal: this catalog grows (I3 took it from four to seven), and a
            // hard-coded count turns "the increment added an event" into a red test that says nothing.
            ->has('eventTypes', count(DomainEventType::cases()))
            ->where('can.create', true));
});

it('renders an endpoint show with its offset-paginated delivery log', function (): void {
    $this->withoutVite();
    $endpoint = makeEndpoint();
    WebhookDelivery::factory()->forEndpoint($endpoint)->count(3)->create();

    $this->actingAs($this->admin)
        ->get(webhookUrl('/'.$endpoint->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('webhooks/Show', false)
            ->where('endpoint.id', $endpoint->id)
            ->has('endpoint.secret_masked')
            ->has('deliveries.data', 3)
            ->where('deliveries.meta.total', 3)
            ->where('deliveries.meta.current_page', 1)
            ->where('can.update', true)
            ->where('can.delete', true));
});

it('never exposes the plaintext secret in the show props', function (): void {
    $this->withoutVite();
    $endpoint = makeEndpoint();

    $this->actingAs($this->admin)
        ->get(webhookUrl('/'.$endpoint->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('endpoint.secret'));
});

it('creates an endpoint, flashing the plaintext secret once and redirecting to its page', function (): void {
    $response = $this->actingAs($this->admin)->post(webhookUrl(), [
        'name' => 'Ops relay',
        'url' => 'https://8.8.8.8/hooks/inbound',
        'event_types' => [DomainEventType::SubmissionCreated->value, DomainEventType::FormPublished->value],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('newSecret', fn ($s) => is_array($s) && str_starts_with($s['secret'], 'whsec_'));
    $response->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->admin->id);
    $endpoint = WebhookEndpoint::query()->where('name', 'Ops relay')->firstOrFail();
    expect($endpoint->event_types)->toBe([
        DomainEventType::SubmissionCreated->value,
        DomainEventType::FormPublished->value,
    ]);
    $response->assertRedirect(webhookUrl('/'.$endpoint->id));
});

it('maps a blank form scope to a tenant-wide (null) endpoint', function (): void {
    $this->actingAs($this->admin)->post(webhookUrl(), [
        'name' => 'Wide',
        'url' => 'https://8.8.8.8/hooks/wide',
        'event_types' => [DomainEventType::SubmissionCreated->value],
        'form_id' => null,
    ])->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(WebhookEndpoint::query()->where('name', 'Wide')->firstOrFail()->form_id)->toBeNull();
});

it('rotates the signing secret, flashing a fresh plaintext and setting the grace expiry', function (): void {
    $endpoint = makeEndpoint();
    $original = $endpoint->secret;

    $this->actingAs($this->admin)
        ->post(webhookUrl('/'.$endpoint->id.'/rotate-secret'))
        ->assertRedirect()
        ->assertSessionHas('newSecret', fn ($s) => is_array($s) && str_starts_with($s['secret'], 'whsec_') && $s['secret'] !== $original);

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = $endpoint->fresh();
    expect($fresh->secret)->not->toBe($original)
        ->and($fresh->secret_previous)->toBe($original)
        ->and($fresh->secret_previous_expires_at)->not->toBeNull();
});

it('pauses an active endpoint', function (): void {
    $endpoint = makeEndpoint();

    $this->actingAs($this->admin)
        ->patch(webhookUrl('/'.$endpoint->id), ['status' => WebhookEndpointStatus::Paused->value])
        ->assertRedirect()
        ->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->admin->id);
    expect($endpoint->fresh()->status)->toBe(WebhookEndpointStatus::Paused);
});

it('re-enabling a paused endpoint resets the circuit breaker', function (): void {
    $endpoint = makeEndpoint([
        'status' => WebhookEndpointStatus::Paused,
        'disabled_reason' => 'too_many_failures',
        'consecutive_failure_count' => 20,
    ]);

    $this->actingAs($this->admin)
        ->patch(webhookUrl('/'.$endpoint->id), ['status' => WebhookEndpointStatus::Active->value])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = $endpoint->fresh();
    expect($fresh->status)->toBe(WebhookEndpointStatus::Active)
        ->and($fresh->consecutive_failure_count)->toBe(0)
        ->and($fresh->disabled_reason)->toBeNull();
});

it('soft-deletes an endpoint and returns to the list', function (): void {
    $endpoint = makeEndpoint();

    $this->actingAs($this->admin)
        ->delete(webhookUrl('/'.$endpoint->id))
        ->assertRedirect(webhookUrl())
        ->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(WebhookEndpoint::query()->find($endpoint->id))->toBeNull()
        ->and(WebhookEndpoint::withTrashed()->find($endpoint->id))->not->toBeNull();
});

it('sends a test ping and flashes the synchronous result', function (): void {
    Http::fake(['*' => Http::response('OK', 200)]);
    $endpoint = makeEndpoint();

    $this->actingAs($this->admin)
        ->post(webhookUrl('/'.$endpoint->id.'/test'))
        ->assertRedirect()
        ->assertSessionHas('testResult', fn ($r) => is_array($r) && $r['delivered'] === true && $r['response_status'] === 200);
});

it('redelivers a delivery on an active endpoint, resetting it to pending', function (): void {
    Queue::fake();
    $endpoint = makeEndpoint();
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create([
        'status' => WebhookDeliveryStatus::DeadLettered,
        'attempt_count' => 10,
        'response_status_code' => 500,
    ]);

    $this->actingAs($this->admin)
        ->post(webhookUrl('/'.$endpoint->id.'/deliveries/'.$delivery->id.'/redeliver'))
        ->assertRedirect()
        ->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = $delivery->fresh();
    expect($fresh->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($fresh->attempt_count)->toBe(0)
        ->and($fresh->response_status_code)->toBeNull();
});

it('refuses a redeliver on a non-active endpoint with an error toast and no 409 page', function (): void {
    Queue::fake();
    $endpoint = makeEndpoint(['status' => WebhookEndpointStatus::Paused]);
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create([
        'status' => WebhookDeliveryStatus::DeadLettered,
    ]);

    $this->actingAs($this->admin)
        ->post(webhookUrl('/'.$endpoint->id.'/deliveries/'.$delivery->id.'/redeliver'))
        ->assertRedirect()
        ->assertSessionHas('toast', fn ($t) => is_array($t) && $t['type'] === 'error');

    enterTenant($this->tenant->id, $this->admin->id);
    expect($delivery->fresh()->status)->toBe(WebhookDeliveryStatus::DeadLettered);
});

it('404s a redeliver when the delivery is not under the bound endpoint', function (): void {
    $endpointA = makeEndpoint(['name' => 'A']);
    $endpointB = makeEndpoint(['name' => 'B']);
    $deliveryB = WebhookDelivery::factory()->forEndpoint($endpointB)->create();

    $this->actingAs($this->admin)
        ->post(webhookUrl('/'.$endpointA->id.'/deliveries/'.$deliveryB->id.'/redeliver'))
        ->assertNotFound();
});

it('forbids a member without webhooks.manage', function (): void {
    $this->withoutVite();
    $endpoint = makeEndpoint();

    $this->actingAs($this->viewer)->get(webhookUrl())->assertForbidden();
    $this->actingAs($this->viewer)->get(webhookUrl('/'.$endpoint->id))->assertForbidden();
    $this->actingAs($this->viewer)->post(webhookUrl(), [
        'name' => 'Nope',
        'url' => 'https://8.8.8.8/x',
        'event_types' => [DomainEventType::SubmissionCreated->value],
    ])->assertForbidden();
});

it('rejects a private/internal URL (SSRF) and a missing event subscription', function (): void {
    $this->actingAs($this->admin)->post(webhookUrl(), [
        'name' => 'Internal',
        'url' => 'http://169.254.169.254/latest/meta-data',
        'event_types' => [DomainEventType::SubmissionCreated->value],
    ])->assertSessionHasErrors('url');

    $this->actingAs($this->admin)->post(webhookUrl(), [
        'name' => 'No events',
        'url' => 'https://8.8.8.8/x',
        'event_types' => [],
    ])->assertSessionHasErrors('event_types');
});

it('bounces a tenant whose plan lacks the webhooks feature (Free)', function (): void {
    $this->withoutVite();
    assignPlanTier(PlanTier::Free);

    $this->actingAs($this->admin)
        ->get(webhookUrl())
        ->assertRedirect();
});
