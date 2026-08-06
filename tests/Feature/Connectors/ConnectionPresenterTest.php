<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\DomainEventType;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Connectors\ConnectionPresenter;
use App\Services\Connectors\ConnectionService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ConnectionPresenter (H15b) — the read model behind both Integrations pages, asserted directly (no HTTP, no
| render). Pins the exact prop KEY SETS, so adding a field to either page is a deliberate act rather than a
| side effect, and pins the two shapes the client hard-depends on: MdsPagination's page counts, and the
| absence of any credential field.
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

    $this->presenter = app(ConnectionPresenter::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('projects a connection and its rules with a fixed key set and no credentials', function (): void {
    $connection = Connection::factory()->create([
        'external_account_label' => 'Acme HQ',
        'access_token' => 'xoxb-secret',
        'refresh_token' => 'xoxe-secret',
        'connected_by' => $this->admin->id,
    ]);
    ConnectionSubscription::factory()->forConnection($connection)->create(['name' => 'Ops feed']);

    $props = $this->presenter->index($this->admin);

    expect($props['connections'])->toHaveCount(1);

    $row = $props['connections'][0];

    expect(array_keys($row))->toEqualCanonicalizing([
        'id', 'provider', 'provider_label', 'external_account_id', 'external_account_label', 'scopes',
        'status', 'disconnected', 'token_expires_at', 'last_refreshed_at', 'last_error', 'last_error_at',
        'connected_by_name', 'created_at', 'can', 'rules',
    ])
        ->and($row['provider_label'])->toBe('Slack')
        ->and($row['connected_by_name'])->toBe($this->admin->name)
        ->and($row['disconnected'])->toBeFalse();

    // The invariant ADR-0009 §D1 exists to protect: no token in any form, not even masked.
    expect(json_encode($props))->not->toContain('xoxb-secret')
        ->and(json_encode($props))->not->toContain('xoxe-secret');

    expect(array_keys($row['rules'][0]))->toEqualCanonicalizing([
        'id', 'connection_id', 'name', 'event_types', 'form_id', 'form_title', 'channel_id', 'channel_name',
        'status', 'consecutive_failure_count', 'last_success_at', 'last_failure_at', 'created_at',
    ]);
});

it('projects the destination pair out of the config blob rather than shipping it whole', function (): void {
    $connection = Connection::factory()->create();
    ConnectionSubscription::factory()->forConnection($connection)->create([
        'config' => ['channel_id' => 'C0123', 'channel_name' => 'ops', 'internal_note' => 'do not ship'],
    ]);

    $props = $this->presenter->index($this->admin);
    $rule = $props['connections'][0]['rules'][0];

    expect($rule['channel_id'])->toBe('C0123')
        ->and($rule['channel_name'])->toBe('ops')
        ->and($rule)->not->toHaveKey('config')
        ->and(json_encode($props))->not->toContain('do not ship');
});

it('offers the provider catalog even with no connections at all', function (): void {
    $props = $this->presenter->index($this->admin);

    expect($props['connections'])->toBe([])
        ->and($props['providers'])->toHaveCount(1)
        ->and($props['providers'][0]['key'])->toBe('slack')
        ->and($props['providers'][0]['connected'])->toBeFalse()
        ->and($props['providers'][0]['connect_url'])->toBe('/integrations/slack/connect')
        ->and($props['eventTypes'])->toHaveCount(count(DomainEventType::cases()));
});

it('counts active rules against the total across every connection', function (): void {
    $a = Connection::factory()->create();
    $b = Connection::factory()->create();

    ConnectionSubscription::factory()->forConnection($a)->create();
    ConnectionSubscription::factory()->forConnection($a)->paused()->create();
    ConnectionSubscription::factory()->forConnection($b)->create();

    $props = $this->presenter->index($this->admin);

    expect($props['summary']['rules'])->toBe(['active' => 2, 'total' => 3])
        // The delivery quota is the SHARED webhook_deliveries metric, not a connector-specific one.
        ->and($props['summary']['deliveries'])->toHaveKeys(['used', 'limit']);
});

it('offset-paginates the delivery log with the page counts MdsPagination needs', function (): void {
    // A cursor cannot supply last_page/total, which is why this is deliberately not the API's pagination.
    $connection = Connection::factory()->create();
    $rule = ConnectionSubscription::factory()->forConnection($connection)->create();

    WebhookDelivery::factory()->count(30)->forSubscription($rule)->create();

    $props = $this->presenter->ruleShow($this->admin, $rule);

    expect($props['deliveries']['data'])->toHaveCount(25)
        ->and($props['deliveries']['meta'])->toBe([
            'current_page' => 1,
            'last_page' => 2,
            'total' => 30,
            'per_page' => 25,
        ]);
});

it('resolves a soft-deleted grant so a rule survives its workspace being disconnected', function (): void {
    $connection = Connection::factory()->create(['external_account_label' => 'Acme HQ']);
    $rule = ConnectionSubscription::factory()->forConnection($connection)->create();

    app(ConnectionService::class)->disconnect($connection);

    $props = $this->presenter->ruleShow($this->admin, $rule);

    // Without withTrashed() the grant relation resolves to null here and the page loses its whole header.
    expect($props['connection'])->not->toBeNull()
        ->and($props['connection']['external_account_label'])->toBe('Acme HQ')
        ->and($props['connection']['disconnected'])->toBeTrue()
        ->and($props['connection']['status'])->toBe(ConnectionStatus::Revoked->value);
});
