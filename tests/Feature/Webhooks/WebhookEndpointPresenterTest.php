<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEndpointPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| WebhookEndpointPresenter (H14) — the read-model shaping for the management pages, asserted directly (no HTTP).
| Field mapping mirrors the API resources, the signing secret + delivery payload/signature are never mapped,
| and the delivery log is OFFSET-paginated so MdsPagination gets current_page/last_page/total/per_page.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('shapes the index list, the entitlement summary, and the catalogs', function (): void {
    WebhookEndpoint::factory()->count(2)->create();

    $props = app(WebhookEndpointPresenter::class)->index($this->admin);

    expect($props['data'])->toHaveCount(2)
        ->and($props['data'][0])->toHaveKeys(['id', 'name', 'url', 'status', 'event_types', 'form_title', 'form_url', 'secret_masked'])
        ->and($props['data'][0])->not->toHaveKey('secret')
        // Endpoints is a live gauge → used reflects the two just created; no plan → unlimited (null).
        ->and($props['summary']['endpoints']['used'])->toBe(2)
        ->and($props['summary']['endpoints']['limit'])->toBeNull()
        ->and($props['summary']['deliveries'])->toHaveKeys(['used', 'limit'])
        ->and($props['eventTypes'])->toHaveCount(count(DomainEventType::cases()))
        ->and($props['eventTypes'][0])->toHaveKeys(['value', 'label'])
        ->and($props['can']['create'])->toBeTrue();
});

it('shapes the show detail with the delivery log and no sensitive fields', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    WebhookDelivery::factory()->forEndpoint($endpoint)->create([
        'status' => WebhookDeliveryStatus::Succeeded,
        'response_status_code' => 200,
    ]);

    $props = app(WebhookEndpointPresenter::class)->show($this->admin, $endpoint);

    expect($props['endpoint'])->toHaveKeys(['id', 'signing_algorithm', 'secret_masked', 'secret_previous_expires_at', 'updated_at'])
        ->and($props['endpoint'])->not->toHaveKey('secret')
        ->and($props['deliveries']['data'])->toHaveCount(1)
        ->and($props['deliveries']['data'][0])->toHaveKeys(['id', 'event_type', 'status', 'attempt_count', 'response_status_code'])
        ->and($props['deliveries']['data'][0])->not->toHaveKey('payload')
        ->and($props['deliveries']['data'][0])->not->toHaveKey('signature')
        ->and($props['can'])->toBe(['update' => true, 'delete' => true]);
});

it('offset-paginates the delivery log so MdsPagination gets a page count', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();
    WebhookDelivery::factory()->forEndpoint($endpoint)->count(30)->create();

    $props = app(WebhookEndpointPresenter::class)->show($this->admin, $endpoint);

    expect($props['deliveries']['data'])->toHaveCount(25)
        ->and($props['deliveries']['meta']['total'])->toBe(30)
        ->and($props['deliveries']['meta']['last_page'])->toBe(2)
        ->and($props['deliveries']['meta']['per_page'])->toBe(25)
        ->and($props['deliveries']['meta']['current_page'])->toBe(1);
});
