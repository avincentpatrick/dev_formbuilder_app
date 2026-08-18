<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Enums\ConnectorSubscriptionStatus;
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
use Illuminate\Support\Facades\DB;
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
        // H16c. `channel` or `tabular` — what the rule editor's behaviour keys on, so the client no longer
        // carries a provider literal to decide it.
        'destination_kind',
    ])
        ->and($row['provider_label'])->toBe('Slack')
        ->and($row['connected_by_name'])->toBe($this->admin->name)
        ->and($row['disconnected'])->toBeFalse();

    // The invariant ADR-0009 §D1 exists to protect: no token in any form, not even masked.
    expect(json_encode($props))->not->toContain('xoxb-secret')
        ->and(json_encode($props))->not->toContain('xoxe-secret');

    expect(array_keys($row['rules'][0]))->toEqualCanonicalizing([
        'id', 'connection_id', 'name', 'event_types', 'form_id', 'form_title', 'form_url', 'channel_id',
        'channel_name', 'status', 'consecutive_failure_count', 'last_success_at', 'last_failure_at',
        'created_at',
        // H16b — the tabular destination, projected key by key like the Slack pair, plus the provider-neutral
        // caption and the reason a paused rule is paused. Present on EVERY row including Slack's, because a
        // shape that varies by provider is one the client has to branch on before it can read it.
        // `sheet_id` is H16c's addition: Airtable's stable table id, so a renamed table cannot break a rule.
        // Null for Slack and Sheets, and present on their rows anyway, for the reason above.
        'spreadsheet_id', 'sheet_name', 'sheet_id', 'spreadsheet_url', 'mapping', 'destination_label', 'paused_reason',
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

it('projects the tabular destination and captions it without a Google round trip', function (): void {
    $connection = Connection::factory()->googleSheets()->create();
    ConnectionSubscription::factory()->forConnection($connection)->create([
        'config' => [
            'spreadsheet_id' => 'SHEET_ID_0000000000000012',
            'spreadsheet_title' => 'Q3 Intake',
            'sheet_name' => 'Responses',
            'mapping' => [
                'fingerprint' => 'abc123',
                'columns' => [
                    ['header' => 'full name', 'field_key' => 'full_name'],
                    ['header' => 'notes', 'field_key' => null],
                ],
            ],
            'internal_note' => 'do not ship',
        ],
    ]);

    $props = $this->presenter->index($this->admin);
    $rule = $props['connections'][0]['rules'][0];

    expect($rule['spreadsheet_id'])->toBe('SHEET_ID_0000000000000012')
        ->and($rule['sheet_name'])->toBe('Responses')
        ->and($rule['spreadsheet_url'])->toBe('https://docs.google.com/spreadsheets/d/SHEET_ID_0000000000000012/edit')
        // The caption is stored at authoring time precisely so rendering a list of rules costs zero calls to
        // Google. A renamed spreadsheet showing a stale caption is the correct, harmless trade.
        ->and($rule['destination_label'])->toBe('Q3 Intake · Responses')
        ->and($rule['mapping']['fingerprint'])->toBe('abc123')
        ->and($rule['mapping']['columns'][1]['field_key'])->toBeNull()
        // Same invariant the Slack case above pins: `config` is projected key by key, never shipped whole.
        ->and($rule)->not->toHaveKey('config')
        ->and(json_encode($props))->not->toContain('do not ship');
});

it('reads every rule’s paused reason in ONE query, not one per rule', function (): void {
    // ⚠️ THE POINT OF THIS TEST IS THE COUNT, NOT THE VALUE. `ruleRow()` runs once per rule on a page that
    // renders every rule of every connection, so a lookup inside it is N queries — the 3N trap the JR3 review
    // caught on the forms list. A grouped read is only a grouped read until someone "simplifies" it.
    $connection = Connection::factory()->googleSheets()->create();

    foreach (['One', 'Two', 'Three'] as $name) {
        $rule = ConnectionSubscription::factory()->forConnection($connection)->create([
            'name' => $name,
            'status' => ConnectorSubscriptionStatus::Paused,
        ]);

        WebhookDelivery::factory()->forSubscription($rule)->create([
            'response_body_excerpt' => "[column_drift] The spreadsheet's columns changed ({$name}).",
        ]);
    }

    DB::enableQueryLog();
    $props = $this->presenter->index($this->admin);
    $queries = collect(DB::getQueryLog())->filter(
        fn (array $q): bool => str_contains((string) $q['query'], 'webhook_deliveries'),
    );
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);

    $reasons = array_column($props['connections'][0]['rules'], 'paused_reason', 'name');

    expect($reasons['One'])->toContain('[column_drift]')
        ->and($reasons['Two'])->toContain('(Two)')
        ->and($reasons['Three'])->toContain('(Three)');
});

it('leaves an active rule’s paused reason null even when its ledger holds an old block', function (): void {
    // A rule that was blocked and has since been fixed and resumed must not keep explaining itself. The
    // reason is derived from status, so this cannot drift out of step the way a stored column would.
    $connection = Connection::factory()->googleSheets()->create();
    $rule = ConnectionSubscription::factory()->forConnection($connection)->create([
        'status' => ConnectorSubscriptionStatus::Active,
    ]);
    WebhookDelivery::factory()->forSubscription($rule)->create([
        'response_body_excerpt' => '[column_drift] stale',
    ]);

    $props = $this->presenter->index($this->admin);

    expect($props['connections'][0]['rules'][0]['paused_reason'])->toBeNull();
});

it('never renders a provider’s own response body as the paused reason', function (): void {
    // Only a `blocked` outcome carries an explanation WE wrote; an ordinary failure's excerpt is the
    // provider's raw body, which is exactly the unreviewed third-party text the connector surfaces refuse to
    // put on a page (ConnectorChannelDirectory::message()'s rule).
    $connection = Connection::factory()->googleSheets()->create();
    $rule = ConnectionSubscription::factory()->forConnection($connection)->create([
        'status' => ConnectorSubscriptionStatus::Paused,
    ]);
    WebhookDelivery::factory()->forSubscription($rule)->create([
        'response_body_excerpt' => '<html><body>502 Bad Gateway — nginx/1.2.3</body></html>',
    ]);

    expect($this->presenter->index($this->admin)['connections'][0]['rules'][0]['paused_reason'])->toBeNull();
});

it('offers the provider catalog even with no connections at all', function (): void {
    $props = $this->presenter->index($this->admin);

    // The catalog is EVERY ConnectorProviderKey case, not only the connected ones, so it grows with the enum
    // (H16a added google_sheets). Asserted against the enum rather than a hardcoded count, so adding a third
    // provider does not redden a test that has nothing to say about it — but a provider that fails to appear
    // still does.
    expect($props['connections'])->toBe([])
        ->and(array_column($props['providers'], 'key'))
        ->toEqualCanonicalizing(ConnectorProviderKey::values())
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
