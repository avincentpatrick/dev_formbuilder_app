<?php

declare(strict_types=1);

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| POST /api/v1/webhooks/{endpoint}/deliveries/{delivery}/redeliver (H13b) — the controller layer: the
| nested-ownership 404, the not-active 409 (re-enable first), and the 202 that resets the delivery + enqueues
| a skip-meter job. Real tokens (the WebhookEndpointApiTest pattern) so the ability+policy check is real.
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
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function redeliverToken(): string
{
    /** @var User $admin */
    $admin = test()->admin;

    return $admin->createToken('ci', [ApiAbilities::MANAGE_WEBHOOKS])->plainTextToken;
}

function redeliverUrl(string $endpointId, string $deliveryId): string
{
    return "http://acme.meridian.test/api/v1/webhooks/{$endpointId}/deliveries/{$deliveryId}/redeliver";
}

it('accepts a redeliver, resetting the delivery and enqueuing a skip-meter job', function (): void {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://8.8.8.8/hook']);
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create([
        'status' => WebhookDeliveryStatus::DeadLettered,
        'attempt_count' => 10,
    ]);

    $this->withToken(redeliverToken())
        ->postJson(redeliverUrl($endpoint->id, $delivery->id))
        ->assertStatus(202);

    enterTenant($this->tenant->id, $this->admin->id);
    expect($delivery->fresh()->status)->toBe(WebhookDeliveryStatus::Pending);
    Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->skipMeter === true);
});

it('404s when the delivery does not belong to the bound endpoint', function (): void {
    Queue::fake();
    $endpointA = WebhookEndpoint::factory()->create();
    $endpointB = WebhookEndpoint::factory()->create();
    $delivery = WebhookDelivery::factory()->forEndpoint($endpointB)->create();

    $this->withToken(redeliverToken())
        ->postJson(redeliverUrl($endpointA->id, $delivery->id))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

it('409s when the endpoint is paused (must be re-enabled first)', function (): void {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->paused()->create();
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create([
        'status' => WebhookDeliveryStatus::DeadLettered,
    ]);

    $this->withToken(redeliverToken())
        ->postJson(redeliverUrl($endpoint->id, $delivery->id))
        ->assertStatus(409);

    Queue::assertNothingPushed();
});
