<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\SubmissionStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Support\Mapping\ColumnMapping;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H16c — the Airtable channel end to end, on ConnectorDeliveryJobTest's committing-job recipe (workOneJob +
| Http::fake, sharing the test PDO so the fake and the uncommitted rows are both visible).
|
| It reuses the SAME mapping engine as Sheets, so what is worth pinning is only where Airtable genuinely
| differs — and every one of these is a way the increment could ship subtly wrong:
|   • a record is a KEYED OBJECT, not a positional row, so the mapping has to be zipped back onto the
|     destination's VERBATIM field names (the normalised ones would be refused as unknown fields);
|   • an empty value is OMITTED rather than written, which is the opposite of the positional rule;
|   • delivery keys on the TABLE ID, so a renamed table is invisible instead of a 404;
|   • `typecast: true` is sent, and a value Airtable still refuses pauses one rule with our own copy;
|   • a 401 is NOT a dead grant — a 60-minute access token expiring looks identical to a revoked one.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    config()->set('connectors.providers.airtable.client_id', 'client-abc');
    config()->set('connectors.providers.airtable.client_secret', 'secret-xyz');
    Http::preventStrayRequests();

    $this->seed(RolePermissionSeeder::class);

    $this->owner = User::factory()->create(['email' => 'owner@acme.test']);
    $this->tenant = Tenant::create([
        'name' => 'Acme',
        'slug' => 'acme',
        'default_locale' => 'en',
        'owner_user_id' => $this->owner->id,
    ]);
    $this->tenant->domains()->create(['domain' => 'acme']);
    enterTenant($this->tenant->id, $this->owner->id);
    // The owner must be an ACTIVE MEMBER for their users row to resolve under the job's tenant RLS (H15a).
    makeActiveMember($this->owner, 'owner');

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Intake');
    enterTenant($this->tenant->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The rule's stored mapping: two of the form's own fields, one metadata column, one the tenant keeps. */
function airtableMapping(array $fields = ['Full name', 'Colour', 'Reviewer notes', 'Submission ID']): ColumnMapping
{
    return ColumnMapping::author($fields, [
        'Full name' => 'full_name',
        'Colour' => 'color',
        'Submission ID' => '__submission_id',
    ]);
}

function airtableConfig(?ColumnMapping $mapping = null, array $overrides = []): array
{
    return array_merge([
        'spreadsheet_id' => 'appACME0000000001',
        'sheet_id' => 'tblRESPONSES00001',
        'sheet_name' => 'Responses',
        'mapping' => ($mapping ?? airtableMapping())->toArray(),
    ], $overrides);
}

/**
 * Fake the schema READ and the record WRITE independently, so each can be failed alone.
 *
 * The schema stub is listed SECOND and the write FIRST because both live under `api.airtable.com/v0/*` and
 * Laravel takes the first pattern that matches.
 */
function fakeAirtable(array $fieldNames = ['Full name', 'Colour', 'Reviewer notes', 'Submission ID'], ?callable $writeResponse = null): void
{
    Http::fake([
        'api.airtable.com/v0/meta/bases/*/tables' => Http::response([
            'tables' => [[
                'id' => 'tblRESPONSES00001',
                'name' => 'Responses',
                'fields' => array_map(static fn (string $name): array => ['id' => 'fld'.md5($name), 'name' => $name, 'type' => 'singleLineText'], $fieldNames),
            ]],
        ], 200),
        'api.airtable.com/v0/*' => $writeResponse ?? Http::response(['records' => [['id' => 'recNEW0000000001']]], 200),
    ]);
}

/**
 * Seed a submission, an Airtable grant + rule pointed at it, and run one delivery attempt.
 *
 * @return array{0: Connection, 1: ConnectionSubscription, 2: WebhookDelivery}
 */
function runAirtableDelivery(array $connectionAttrs = [], array $config = [], array $envelopeData = []): array
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $submission = seedInboxSubmission(test()->form, test()->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Ana Reyes',
        'color' => 'b',
    ]);

    $connection = Connection::factory()->airtable()->create($connectionAttrs);
    $subscription = ConnectionSubscription::factory()->forConnection($connection)->create([
        'config' => $config === [] ? airtableConfig() : $config,
    ]);
    $delivery = WebhookDelivery::factory()->forSubscription($subscription)->create([
        'payload' => [
            'event_type' => 'submission.created',
            'occurred_at' => '2026-08-13T09:00:00Z',
            'data' => array_merge(['submission_id' => (string) $submission->id, 'form_id' => (string) test()->form->id], $envelopeData),
        ],
    ]);

    DeliverConnectorMessageJob::dispatch($tenant->id, (string) $delivery->id);
    workOneJob('webhooks');

    enterTenant($tenant->id);

    return [$connection->fresh(), $subscription->fresh(), $delivery->fresh()];
}

/** The `fields` object of the record-create call, or null if none was made. */
function writtenFields(): ?array
{
    $fields = null;

    Http::recorded(function (Request $request) use (&$fields): bool {
        if ($request->method() === 'POST' && ! str_contains($request->url(), '/meta/')) {
            // Cast: the adapter sends `(object)` so an all-empty map serialises as `{}` and not `[]`,
            // which Airtable refuses. `data()` hands back what was passed, so it is still an stdClass here.
            $fields = (array) ($request->data()['records'][0]['fields'] ?? []);
        }

        return true;
    });

    return $fields;
}

it('writes one record keyed by the destination’s own field names', function (): void {
    fakeAirtable();

    [, , $delivery] = runAirtableDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);

    $fields = writtenFields();

    // ⚠️ THE KEYS ARE THE VERBATIM FIELD NAMES, NOT THE MAPPING'S NORMALISED ONES. `ColumnFingerprint` stores
    // `full name`; writing to that would be an UNKNOWN_FIELD_NAME and Airtable refuses the whole record.
    expect(array_keys((array) $fields))->toEqualCanonicalizing(['Full name', 'Colour', 'Submission ID'])
        ->and($fields['Full name'])->toBe('Ana Reyes')
        // "Reviewer notes" is a real field the tenant keeps for themselves — bound to nothing, so it is
        // OMITTED rather than sent as ''. In a positional spreadsheet row a blank is load-bearing; in a keyed
        // object an absent key is simply "leave it alone", and it spares a typed field an empty string.
        ->and($fields)->not->toHaveKey('Reviewer notes');
});

it('sends typecast so a text answer can land in a typed field', function (): void {
    fakeAirtable();

    runAirtableDelivery();

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || str_contains($request->url(), '/meta/')) {
            return false;
        }

        // Without it, the first submission into a Number/Date/Single-select field 422s and pauses the rule,
        // which reads as a broken integration rather than a field-type mismatch (user decision 2026-08-13).
        return ($request->data()['typecast'] ?? null) === true;
    });
});

it('writes to the table ID, so renaming the table cannot break the rule', function (): void {
    fakeAirtable();

    // The stored `sheet_name` is deliberately STALE here — the tenant renamed the table after the rule was
    // made. Keying the write on the name would 404; keying on the id makes the rename a non-event.
    runAirtableDelivery(config: airtableConfig(overrides: ['sheet_name' => 'What it used to be called']));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/v0/appACME0000000001/tblRESPONSES00001'));
});

it('falls back to the table name when a rule predates the stored id', function (): void {
    // A rule written before `sheet_id` existed still has to deliver rather than reading as unconfigured.
    fakeAirtable();

    $config = airtableConfig();
    unset($config['sheet_id']);

    [, , $delivery] = runAirtableDelivery(config: $config);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/v0/appACME0000000001/Responses'));
});

it('pauses one rule and writes nothing when the table’s fields have drifted', function (): void {
    // The tenant renamed a field in Airtable. The stored fingerprint no longer describes the table, so the
    // record would be filed under the wrong headings — a mistake that is neither visible nor reversible.
    fakeAirtable(['Full name', 'Colour', 'Reviewer notes', 'Ref']);

    [$connection, $subscription, $delivery] = runAirtableDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        // The GRANT is healthy — only this rule is broken, and revoking the connection would take out every
        // other rule on it.
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        // ⚠️ THE PREFIX IS THE ASSERTION. `ConnectionPresenter::pausedReasons()` selects on `LIKE '[%]%'`,
        // so an unprefixed drift summary is stored and then silently dropped — which is exactly what both
        // adapters did until this test was written. Without the code, the tenant gets a paused rule and no
        // reason, on the one failure the drift card exists to explain.
        ->and($delivery->response_body_excerpt)->toStartWith('[column_drift]');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('pauses the rule with OUR copy when Airtable refuses a value', function (): void {
    fakeAirtable(writeResponse: fn () => Http::response([
        'error' => ['type' => 'INVALID_VALUE_FOR_COLUMN', 'message' => 'Field "Colour" cannot accept the provided value'],
    ], 422));

    [$connection, $subscription, $delivery] = runAirtableDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        ->and($delivery->response_body_excerpt)->toStartWith('[invalid_value]')
        // ⚠️ The excerpt is shown on the rule page AND reaches an email, so it must be ours. Airtable's own
        // message names the field and is still third-party text we have not reviewed.
        ->and($delivery->response_body_excerpt)->not->toContain('cannot accept the provided value');
});

it('treats a 401 as retryable, never as a dead grant', function (): void {
    // Airtable access tokens live 60 minutes, so a 401 is overwhelmingly an ordinary expiry. Revoking on it
    // would clear the tenant's tokens and email them roughly hourly, forever.
    fakeAirtable(writeResponse: fn () => Http::response(['error' => ['type' => 'UNAUTHORIZED']], 401));

    [$connection, $subscription, $delivery] = runAirtableDelivery();

    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Active)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_retry_at)->not->toBeNull();
});

it('retries a rate limit rather than pausing the rule, and KEEPS the provider body for it', function (): void {
    fakeAirtable(writeResponse: fn () => Http::response(['error' => ['type' => 'RATE_LIMIT_REACHED']], 429));

    [, $subscription, $delivery] = runAirtableDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Active)
        // ⚠️ M4's RESIDUAL, ASSERTED AS A PASSING TEST RATHER THAN DESCRIBED. M4 stopped the SUCCESS path
        // echoing the provider body, because Airtable's create-record response repeats the answers just
        // written. The retryable fall-through still stores the body verbatim, ON PURPOSE: a 429 or 5xx body
        // is the only diagnostic an operator has for an outage, and these statuses do not echo a payload.
        // Every arm a TENANT reads already replaces Airtable's copy with ours. If that fall-through is ever
        // sanitised wholesale, this fails and its author has to read why it was left.
        ->and($delivery->response_body_excerpt)->toContain('RATE_LIMIT_REACHED');
});

it('pauses the rule when the table is no longer in the base', function (): void {
    Http::fake([
        'api.airtable.com/v0/meta/bases/*/tables' => Http::response([
            'tables' => [['id' => 'tblSOMETHINGELSE1', 'name' => 'Other', 'fields' => []]],
        ], 200),
    ]);

    [, $subscription, $delivery] = runAirtableDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        ->and($delivery->response_body_excerpt)->toStartWith('[not_found]');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('refuses an event that carries no submission', function (): void {
    fakeAirtable();

    [, $subscription, $delivery] = runAirtableDelivery(envelopeData: ['submission_id' => null]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        ->and($delivery->response_body_excerpt)->toStartWith('[unsupported_event]');

    // Refused before either call — a form.published rule on a table has nothing to write, and adding a record
    // of blanks on every publish would be worse than saying so.
    Http::assertNothingSent();
});

it('refuses a rule with no destination configured', function (): void {
    fakeAirtable();

    [, $subscription, $delivery] = runAirtableDelivery(config: airtableConfig(overrides: [
        'spreadsheet_id' => '',
        'sheet_id' => '',
        'sheet_name' => '',
    ]));

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        ->and($delivery->response_body_excerpt)->toStartWith('[missing_destination]');

    Http::assertNothingSent();
});

it('refuses a rule whose stored mapping cannot be read', function (): void {
    fakeAirtable();

    [, $subscription, $delivery] = runAirtableDelivery(config: airtableConfig(overrides: [
        'mapping' => ['columns' => 'not-an-array'],
    ]));

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        ->and($delivery->response_body_excerpt)->toStartWith('[invalid_mapping]');

    Http::assertNothingSent();
});

it('does not write the respondent answers into the shared delivery ledger', function (): void {
    // ⚠️ THE STUB ABOVE IS WHY NO TEST EVER SAW THIS. Airtable's real create-record response ECHOES the
    // `fields` object just written; `fakeAirtable()`'s default returns an id only, so every existing success
    // case exercised a body that carried nothing to leak. This one uses the provider's real shape.
    $body = [
        'records' => [[
            'id' => 'recNEW0000000001',
            'createdTime' => '2026-08-19T09:00:00.000Z',
            'fields' => ['Full name' => 'Ana Reyes', 'Colour' => 'b', 'Submission ID' => 'sub-1'],
        ]],
    ];

    // The control, asserted before the delivery runs: the provider's response really does carry the
    // respondent's answer, so a clean excerpt below is the adapter's doing and not the stub's.
    expect(json_encode($body))->toContain('Ana Reyes');

    fakeAirtable(writeResponse: fn () => Http::response($body, 200));

    [, , $delivery] = runAirtableDelivery();

    // `docs/data-privacy-gdpr-compliance.md` §7 offers "the delivery ledger is not a second copy" as a
    // STRUCTURAL property, and `webhook_deliveries` has no retention job — deleting the submission does not
    // touch this row, so anything landing here outlives an erasure request. The sibling adapter sends 'ok'
    // for exactly this reason (GoogleSheetsConnector's class docblock, bullet 1).
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->response_body_excerpt)->toBe('ok')
        ->and($delivery->response_body_excerpt)->not->toContain('Ana Reyes');
});
