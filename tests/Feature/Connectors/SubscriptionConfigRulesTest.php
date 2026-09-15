<?php

declare(strict_types=1);

use App\Enums\ConnectorProviderKey;
use App\Enums\DomainEventType;
use App\Enums\PlanTier;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Api\ApiAbilities;
use App\Support\Connectors\SubscriptionConfigRules;
use App\Support\Mapping\ColumnFingerprint;
use App\Support\Mapping\ColumnMapping;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H16a — the per-provider `config` validation seam.
|
| H15a's four request classes each hard-coded `config.channel_id` as required. That reads as provider-agnostic
| validation and was not: it is SLACK'S destination shape sitting in the shared layer, so a Google Sheets rule
| was rejected by validation before any adapter was consulted. This file pins BOTH directions — Sheets is
| accepted, Slack is unchanged — because a fix that accepted everything would pass a Sheets-only test while
| quietly dropping the guarantee that a Slack rule always has a channel.
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

    $this->slack = Connection::factory()->create();
    $this->sheets = Connection::factory()->googleSheets()->create();

    $this->token = $this->admin->createToken('ci', [ApiAbilities::MANAGE_INTEGRATIONS])->plainTextToken;
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The tenant HOST is load-bearing: identification runs on the subdomain, and `localhost` has none. */
function subscriptionsUrl(Connection $connection): string
{
    return 'http://acme.meridian.test/api/v1/connections/'.$connection->id.'/subscriptions';
}

function validSheetsConfig(): array
{
    return [
        'spreadsheet_id' => 'SHEET123',
        'sheet_name' => 'Responses',
        'mapping' => ColumnMapping::author(['Name', 'Age'], ['Name' => 'full_name', 'Age' => 'age'])->toArray(),
    ];
}

function createRule(Connection $connection, array $config, string $token): TestResponse
{
    return test()->withToken($token)->postJson(subscriptionsUrl($connection), [
        'name' => 'Sync',
        'event_types' => [DomainEventType::SubmissionCreated->value],
        'config' => $config,
    ]);
}

/**
 * Assert a 422 naming `$field`.
 *
 * NOT `assertJsonValidationErrors()`: this API wraps failures in its own envelope
 * (`error.details.fields.*`, the `validation_failed` code), so the framework helper reads Laravel's default
 * `errors` key, finds nothing, and — because a missing key is indistinguishable from a passing request to it —
 * would report "no validation errors" for a request that was correctly rejected.
 */
function assertRejects(TestResponse $response, string $field): TestResponse
{
    return $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
}

it('accepts a Google Sheets rule — the shape H15a validation rejected outright', function (): void {
    createRule($this->sheets, validSheetsConfig(), $this->token)->assertCreated();

    enterTenant($this->tenant->id);

    $rule = ConnectionSubscription::query()->firstOrFail();

    expect($rule->config['spreadsheet_id'])->toBe('SHEET123')
        // The stored mapping must survive the round trip intact, because the delivery path re-parses it and a
        // lossy write would only surface as a drift pause much later.
        ->and(ColumnMapping::fromArray($rule->config['mapping'])->boundFieldKeys())->toBe(['full_name', 'age']);
});

it('still refuses a Slack rule with no channel', function (): void {
    // The regression guard. The obvious way to "make config provider-agnostic" is to stop validating its
    // contents at all, which passes every Sheets test above while silently allowing a Slack rule that can
    // never deliver — a permanent failure deferred to delivery time, where it looks like an outage.
    assertRejects(createRule($this->slack, ['channel_name' => '#ops'], $this->token), 'config.channel_id');
});

it('refuses a Sheets rule with no spreadsheet, and says so readably', function (): void {
    $response = assertRejects(createRule($this->sheets, ['mapping' => validSheetsConfig()['mapping']], $this->token), 'config.spreadsheet_id');

    // Read the fields map whole and index it, rather than through a dotted `json()` path: the KEY itself
    // contains a dot (`config.spreadsheet_id`), so a dotted lookup descends into a `config` node that does not
    // exist and returns null — which would make this assertion fail against correct output.
    $fields = $response->json('error.details.fields');

    // Not "The config.spreadsheet id field is required." — the message exists so a human can act on it.
    expect($fields['config.spreadsheet_id'][0])->toBe('Choose a spreadsheet to write into.');
});

it('refuses a Sheets rule with no column mapping', function (): void {
    assertRejects(createRule($this->sheets, ['spreadsheet_id' => 'SHEET123'], $this->token), 'config.mapping');
});

it('refuses a mapping whose columns are empty', function (): void {
    assertRejects(createRule($this->sheets, [
        'spreadsheet_id' => 'SHEET123',
        'mapping' => ['fingerprint' => str_repeat('a', 64), 'columns' => []],
    ], $this->token), 'config.mapping.columns');
});

it('applies the SHEETS shape to a Slack connection\'s rule and vice versa, never a merged one', function (): void {
    // Dispatch is on the CONNECTION, so the two vocabularies must not leak into each other: a Slack rule
    // carrying a spreadsheet id is not "extra config", it is a rule pointed at a destination its adapter
    // cannot use.
    assertRejects(createRule($this->slack, ['spreadsheet_id' => 'SHEET123', 'mapping' => validSheetsConfig()['mapping']], $this->token), 'config.channel_id');

    assertRejects(createRule($this->sheets, ['channel_id' => 'C123'], $this->token), 'config.spreadsheet_id');
});

it('resolves the provider on the FLAT tenant-web update route, which binds no connection', function (): void {
    // The update/delete routes are deliberately flat (`/integrations/rules/{rule}`) because a nested
    // {connection} would 404 after a disconnect — which soft-deletes the grant while KEEPING its rules. So the
    // provider has to be resolved through the rule's `grant` relation, and a resolver that only looked at
    // `route('connection')` would fall back to the no-provider rules and validate nothing at all here.
    $rule = ConnectionSubscription::factory()->forConnection($this->sheets)->create(['config' => validSheetsConfig()]);

    $this->actingAs($this->admin)
        ->from('http://acme.meridian.test/integrations')
        ->patch('http://acme.meridian.test/integrations/rules/'.$rule->id, ['config' => ['sheet_name' => 'Tab2']])
        ->assertSessionHasErrors('config.spreadsheet_id');
});

it('gives every provider in the enum at least one required destination key', function (): void {
    // A third provider added without a `config` shape would silently fall through to "config must be an
    // array" — accepting a rule with no destination at all, which is a permanent failure deferred to delivery
    // time. Asserted as "at least one required key" rather than a count, because the two providers legitimately
    // have different numbers of them and a count would pin the shape rather than the property.
    foreach (ConnectorProviderKey::cases() as $provider) {
        $rules = SubscriptionConfigRules::rulesFor($provider);

        $required = array_filter(
            $rules,
            fn (array $rule, string $field): bool => $field !== 'config' && in_array('required', $rule, true),
            ARRAY_FILTER_USE_BOTH,
        );

        expect($required)->not->toBeEmpty("Provider {$provider->value} would accept a rule with no destination.");
    }
});

it('refuses a spreadsheet rule subscribed to an event that carries no answers (H16b)', function (): void {
    // `GoogleSheetsConnector::deliver()` already blocks this with `[unsupported_event]`, and blocking there
    // is right — an adapter cannot trust that a stored rule came from our own form. But that refusal happens
    // at DELIVERY time, where `blocked` pauses the rule and emails the owner: a rule that could never have
    // worked spends its first real event teaching the tenant their integration is broken.
    $response = $this->actingAs($this->admin)
        ->from('http://acme.meridian.test/integrations')
        ->post('http://acme.meridian.test/integrations/connections/'.$this->sheets->id.'/rules', [
            'name' => 'Publishes to a sheet',
            'event_types' => [DomainEventType::SubmissionCreated->value, DomainEventType::FormPublished->value],
            'config' => validSheetsConfig(),
        ]);

    // Keyed to the OFFENDING INDEX, not to `event_types` as a whole: the form renders a checkbox per event,
    // and an error on the group cannot point at the box that caused it.
    $response->assertSessionHasErrors('event_types.1');

    expect(ConnectionSubscription::query()->where('name', 'Publishes to a sheet')->exists())->toBeFalse();
});

it('leaves Slack free to subscribe to every event in the catalog', function (): void {
    // The guard is keyed to `isTabular()`, not to "Google" — but it must not narrow a provider whose
    // destination is a message, which every non-submission event has something to say to.
    $this->actingAs($this->admin)
        ->from('http://acme.meridian.test/integrations')
        ->post('http://acme.meridian.test/integrations/connections/'.$this->slack->id.'/rules', [
            'name' => 'Everything to ops',
            'event_types' => DomainEventType::values(),
            'config' => ['channel_id' => 'C0OPS'],
        ])
        ->assertSessionHasNoErrors();
});

it('narrows the deliverable catalog for a tabular provider and nothing else', function (): void {
    // Asserted as a PROPERTY over the enum rather than a hardcoded list, so a new DomainEventType case joins
    // the right side automatically — and a new `submission.*` case is not silently excluded from sheets.
    $tabular = SubscriptionConfigRules::deliverableEventTypes(ConnectorProviderKey::GoogleSheets);

    expect($tabular)->toBe(array_values(array_filter(
        DomainEventType::values(),
        fn (string $v): bool => str_starts_with($v, 'submission.'),
    )))
        ->and($tabular)->not->toBeEmpty()
        ->and(SubscriptionConfigRules::deliverableEventTypes(ConnectorProviderKey::Slack))->toBe(DomainEventType::values())
        // An unresolved provider must not narrow anything — the request is being rejected on other grounds
        // anyway, and guessing would reject a Slack rule the resolver simply failed to type.
        ->and(SubscriptionConfigRules::deliverableEventTypes(null))->toBe(DomainEventType::values());
});

// ── M96: the rule editor's own payload saves, and the tenant rule requests stamp the fingerprint ───────────
//
// Every case above builds its mapping with ColumnMapping::author(), which already carries a fingerprint, so
// none of them sends what RuleFormModal actually sends: `mapping: { columns }` and nothing else. That shape
// was refused on `config.mapping.fingerprint`, a key neither editor renders, so no Sheets or Airtable rule
// could be saved from the UI and the modal simply stayed open.

function webRulesUrl(Connection $connection): string
{
    return 'http://acme.meridian.test/integrations/connections/'.$connection->id.'/rules';
}

/**
 * The `config` RuleFormModal posts for a Google Sheets rule — no fingerprint, and a null `sheet_id`.
 *
 * @param  list<array{header: string, field_key: string|null}>  $columns
 */
function editorSheetsConfig(array $columns): array
{
    return [
        'spreadsheet_id' => 'SHEET123',
        'spreadsheet_title' => 'Q3 Intake',
        'sheet_name' => 'Responses',
        'sheet_id' => null,
        'mapping' => ['columns' => $columns],
    ];
}

/** Post one rule through the tenant-web store route, the way the Integrations page does. */
function postWebRule(Connection $connection, string $name, array $config): TestResponse
{
    return test()->actingAs(test()->admin)
        ->from('http://acme.meridian.test/integrations')
        ->post(webRulesUrl($connection), [
            'name' => $name,
            'event_types' => [DomainEventType::SubmissionCreated->value],
            'config' => $config,
        ]);
}

/** The stored rule named `$name`, read back under the tenant's own context. */
function storedRule(string $name): ConnectionSubscription
{
    enterTenant(test()->tenant->id);

    return ConnectionSubscription::query()->where('name', $name)->firstOrFail();
}

it('saves a Sheets rule from the editor’s payload, which carries no fingerprint (M96)', function (): void {
    postWebRule($this->sheets, 'From the editor', editorSheetsConfig([
        ['header' => 'Full name', 'field_key' => 'full_name'],
        ['header' => 'Submission ID', 'field_key' => '__submission_id'],
    ]))->assertSessionHasNoErrors();

    // The digest delivery compares against, taken from the headers in column order.
    expect(storedRule('From the editor')->config['mapping']['fingerprint'])
        ->toBe(ColumnFingerprint::forHeaders(['Full name', 'Submission ID'])->digest);
});

it('saves an Airtable rule from the editor’s payload, which carries no fingerprint (M96)', function (): void {
    $airtable = Connection::factory()->airtable()->create();

    postWebRule($airtable, 'Into Airtable', [
        'spreadsheet_id' => 'appACME0000000001',
        'spreadsheet_title' => 'Client Intake CRM',
        'sheet_name' => 'Responses',
        'sheet_id' => 'tblRESPONSES00001',
        'mapping' => ['columns' => [
            ['header' => 'Full name', 'field_key' => 'full_name'],
            ['header' => 'Submission ID', 'field_key' => '__submission_id'],
        ]],
    ])->assertSessionHasNoErrors();

    expect(storedRule('Into Airtable')->config['mapping']['fingerprint'])
        ->toBe(ColumnFingerprint::forHeaders(['Full name', 'Submission ID'])->digest);
});

it('saves a sheet whose heading row has a blank cell, which the middleware turns into null (M96)', function (): void {
    // TrimStrings and ConvertEmptyStringsToNull run before validation, so the blank heading reaches the rules
    // as null and `'string'` refuses it. The editor renders that column as "Column B (no heading)", a real
    // column, so the save has to accept it and store it as the empty string it was.
    postWebRule($this->sheets, 'Blank heading', editorSheetsConfig([
        ['header' => 'Name', 'field_key' => 'full_name'],
        ['header' => '', 'field_key' => null],
        ['header' => 'Notes', 'field_key' => null],
    ]))->assertSessionHasNoErrors();

    $mapping = storedRule('Blank heading')->config['mapping'];

    expect(array_column($mapping['columns'], 'header'))->toBe(['Name', '', 'Notes'])
        ->and($mapping['fingerprint'])->toBe(ColumnFingerprint::forHeaders(['Name', '', 'Notes'])->digest);
});

it('overwrites a fingerprint the browser sent with the one the server derives (M96)', function (): void {
    // A digest the client made up would make every delivery block as drift, or hide a real change.
    $config = editorSheetsConfig([['header' => 'Full name', 'field_key' => 'full_name']]);
    $config['mapping']['fingerprint'] = 'not-the-digest';

    postWebRule($this->sheets, 'Sent its own', $config)->assertSessionHasNoErrors();

    expect(storedRule('Sent its own')->config['mapping']['fingerprint'])
        ->toBe(ColumnFingerprint::forHeaders(['Full name'])->digest);
});

it('stamps the fingerprint when an edit sends a new mapping (M96)', function (): void {
    $rule = ConnectionSubscription::factory()->forConnection($this->sheets)->create(['config' => validSheetsConfig()]);

    $this->actingAs($this->admin)
        ->from('http://acme.meridian.test/integrations')
        ->patch('http://acme.meridian.test/integrations/rules/'.$rule->id, [
            'config' => editorSheetsConfig([
                ['header' => 'Full name', 'field_key' => 'full_name'],
                ['header' => 'Colour', 'field_key' => null],
            ]),
        ])
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id);

    expect($rule->fresh()->config['mapping']['fingerprint'])
        ->toBe(ColumnFingerprint::forHeaders(['Full name', 'Colour'])->digest);
});

it('refuses one form field bound to two columns when a rule is created (M96)', function (): void {
    // A fingerprint is sent so that the only thing wrong with this payload is the repeated field.
    $config = editorSheetsConfig([
        ['header' => 'Name', 'field_key' => 'full_name'],
        ['header' => 'Also name', 'field_key' => 'full_name'],
    ]);
    $config['mapping']['fingerprint'] = str_repeat('a', 64);

    // Keyed to `config.mapping.columns`, which both editors render under their column map.
    postWebRule($this->sheets, 'Twice', $config)
        ->assertSessionHasErrors(['config.mapping.columns' => 'Each form field can fill only one column.']);

    enterTenant($this->tenant->id);

    expect(ConnectionSubscription::query()->where('name', 'Twice')->exists())->toBeFalse();
});

it('refuses one form field bound to two columns when a rule is edited (M96)', function (): void {
    $rule = ConnectionSubscription::factory()->forConnection($this->sheets)->create(['config' => validSheetsConfig()]);

    $config = editorSheetsConfig([
        ['header' => 'Name', 'field_key' => 'full_name'],
        ['header' => 'Also name', 'field_key' => 'full_name'],
    ]);
    $config['mapping']['fingerprint'] = str_repeat('a', 64);

    $this->actingAs($this->admin)
        ->from('http://acme.meridian.test/integrations')
        ->patch('http://acme.meridian.test/integrations/rules/'.$rule->id, ['config' => $config])
        ->assertSessionHasErrors(['config.mapping.columns' => 'Each form field can fill only one column.']);
});
