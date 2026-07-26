<?php

declare(strict_types=1);

use App\Enums\WebhookEndpointStatus;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\Webhooks\WebhookAutoDisabledNotification;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Circuit-breaker auto-disable notification (H13b — H3 queued mail). When the breaker trips (the threshold
| consecutive failure), the tenant OWNER is emailed once via an on-demand notifiable; a sub-threshold failure
| notifies no one. The owner is an ACTIVE MEMBER so their users row resolves under the job's tenant RLS (the
| UsageRollupTest overage-notification recipe).
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    Http::preventStrayRequests();
    $this->seed(RolePermissionSeeder::class);

    $this->owner = User::factory()->create();
    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->tenant->forceFill(['owner_user_id' => $this->owner->id])->save();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('emails the tenant owner once when the breaker trips', function (): void {
    Notification::fake();
    Http::fake(['*' => Http::response('boom', 500)]);

    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://8.8.8.8/hook', 'consecutive_failure_count' => 19]);
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create();

    DeliverWebhookJob::dispatch($this->tenant->id, (string) $delivery->id);
    workOneJob('webhooks');
    enterTenant($this->tenant->id);

    expect($endpoint->fresh()->status)->toBe(WebhookEndpointStatus::Paused);
    Notification::assertSentOnDemand(WebhookAutoDisabledNotification::class);
});

it('does not notify on a failure below the threshold', function (): void {
    Notification::fake();
    Http::fake(['*' => Http::response('boom', 500)]);

    $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://8.8.8.8/hook', 'consecutive_failure_count' => 0]);
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create();

    DeliverWebhookJob::dispatch($this->tenant->id, (string) $delivery->id);
    workOneJob('webhooks');

    Notification::assertNothingSent();
});
