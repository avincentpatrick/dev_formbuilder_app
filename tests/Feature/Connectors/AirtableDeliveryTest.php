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
use Illuminate\Http\Client\ConnectionException;
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

// ── M5 — the create is non-idempotent, so an unconfirmed one is never re-driven blind ────────────────────
//
// The defect these cover was REPRODUCED before it was fixed, with this exact fixture: driven against the
// unfixed adapter the reconciliation case below reads `[$writes, $probes] === [2, 0]` -- two identical
// records in the tenant's own base for one submission -- and against the fixed one it reads `[1, 1]`.
//
// ⚠️ AND THE FIXTURE ITSELF NEARLY TOLD THE WRONG STORY, TWICE.
// (1) `Http::fake()` INVOKES EVERY STUB FOR EVERY REQUEST and keeps the first non-null answer
//     (`Factory::handler()` maps, then filters), so a counter inside one stub counts requests that stub
//     never answered -- the schema read inflated the probe count until `scriptedAirtable()` skipped `/meta/`
//     explicitly. A probe count that is really "every GET" would have read as a reconciliation that never
//     happened.
// (2) The default `airtableMapping()` binds `__submission_id`, so EVERY case here would have exercised the
//     reconcilable path and none the other one. That is M4's stub-shaped-green in a new costume, and it is
//     why the unmapped case below builds its own mapping and asserts the duplicate it still permits.

/**
 * An Airtable stub whose record-create answers are SCRIPTED per attempt, with the probe answered separately.
 *
 * @param  list<mixed>  $writeAnswers  one per create, in order: a Response, or a callable to throw from
 * @param  callable|null  $probeAnswer  the answer to a reconciliation GET (default: the record is present)
 */
function scriptedAirtable(array $writeAnswers, ?callable $probeAnswer = null, array $fieldNames = ['Full name', 'Colour', 'Reviewer notes', 'Submission ID']): callable
{
    $writes = 0;
    $probes = 0;

    Http::fake([
        'api.airtable.com/v0/meta/bases/*/tables' => Http::response([
            'tables' => [[
                'id' => 'tblRESPONSES00001',
                'name' => 'Responses',
                'fields' => array_map(
                    static fn (string $name): array => ['id' => 'fld'.md5($name), 'name' => $name, 'type' => 'singleLineText'],
                    $fieldNames,
                ),
            ]],
        ], 200),
        'api.airtable.com/v0/*' => function (Request $request) use (&$writes, &$probes, $writeAnswers, $probeAnswer) {
            // See note (1) above: this stub is called for the schema read too, and must decline it.
            if (str_contains($request->url(), '/meta/')) {
                return null;
            }

            if ($request->method() === 'GET') {
                $probes++;

                return $probeAnswer === null
                    ? Http::response(['records' => [['id' => 'recNEW0000000001', 'fields' => []]]], 200)
                    : $probeAnswer();
            }

            $answer = $writeAnswers[$writes] ?? Http::response(['records' => [['id' => 'recTAIL']]], 200);
            $writes++;

            return is_callable($answer) ? $answer() : $answer;
        },
    ]);

    // BY REFERENCE, and this is not a style choice: an arrow function captures by VALUE at the moment it
    // is created, so `fn () => ['writes' => $writes]` would answer 0 forever and every count below would be a
    // property of the closure rather than of the run.
    return function () use (&$writes, &$probes): array {
        return ['writes' => $writes, 'probes' => $probes];
    };
}

/**
 * Seed an Airtable grant, rule and pending delivery WITHOUT running it, so a case can drive several attempts.
 *
 * @return array{0: ConnectionSubscription, 1: WebhookDelivery}
 */
function seedAirtableDelivery(?ColumnMapping $mapping = null): array
{
    $submission = seedInboxSubmission(test()->form, test()->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Ana Reyes',
        'color' => 'b',
    ]);

    test()->m5AirtableSubmissionId = (string) $submission->id;

    $connection = Connection::factory()->airtable()->create();
    $subscription = ConnectionSubscription::factory()->forConnection($connection)->create([
        'config' => airtableConfig($mapping),
    ]);
    $delivery = WebhookDelivery::factory()->forSubscription($subscription)->create([
        'payload' => [
            'event_type' => 'submission.created',
            'occurred_at' => '2026-08-19T09:00:00Z',
            'data' => ['submission_id' => (string) $submission->id, 'form_id' => (string) test()->form->id],
        ],
    ]);

    return [$subscription, $delivery];
}

/** One more attempt on an existing delivery — exactly what `WebhookRetrySweeper::sweep()` re-dispatches. */
function attemptAirtableDelivery(WebhookDelivery $delivery): WebhookDelivery
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    DeliverConnectorMessageJob::dispatch($tenant->id, (string) $delivery->id);
    workOneJob('webhooks');

    enterTenant($tenant->id);

    return $delivery->fresh();
}

it('records a create whose answer was lost as UNCONFIRMED, not merely failed', function (): void {
    $counts = scriptedAirtable([fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    [, $delivery] = seedAirtableDelivery();
    $delivery = attemptAirtableDelivery($delivery);

    // THE CONTROL: the schema read succeeded and the POST was genuinely issued, so the outcome under test is
    // the WRITE's lost answer and not some earlier refusal that never reached Airtable at all.
    expect($counts()['writes'])->toBe(1)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($delivery->unconfirmed_write_at)->not->toBeNull();
});

it('does NOT call a create unconfirmed when Airtable answered with a status', function (): void {
    // The control that the flag is not simply "the attempt failed". A 503 is a RESPONSE: Airtable answered
    // rather than silently committing, so the ladder may re-drive it and the next attempt needs no probe.
    scriptedAirtable([Http::response(['error' => ['type' => 'SERVICE_UNAVAILABLE']], 503)]);

    [, $delivery] = seedAirtableDelivery();
    $delivery = attemptAirtableDelivery($delivery);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->unconfirmed_write_at)->toBeNull();
});

it('clears the unconfirmed mark once a later attempt gets a real answer', function (): void {
    // Without this, a THIRD attempt would probe -- and a probe that found the record from attempt 2's
    // successful-looking write would be right, but one that found nothing after a 503 would still skip.
    // The flag has to describe the immediately preceding attempt or it is worse than not having it.
    scriptedAirtable([
        fn () => throw new ConnectionException('timeout'),
        Http::response(['error' => ['type' => 'SERVICE_UNAVAILABLE']], 503),
    ], probeAnswer: fn () => Http::response(['records' => []], 200));

    [, $delivery] = seedAirtableDelivery();
    $delivery = attemptAirtableDelivery($delivery);

    expect($delivery->unconfirmed_write_at)->not->toBeNull();

    $delivery = attemptAirtableDelivery($delivery);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->unconfirmed_write_at)->toBeNull();
});

it('reconciles instead of adding a second record when the first attempt may have landed', function (): void {
    $counts = scriptedAirtable([fn () => throw new ConnectionException('timeout')]);

    [, $delivery] = seedAirtableDelivery();
    $delivery = attemptAirtableDelivery($delivery);
    $delivery = attemptAirtableDelivery($delivery);

    // ONE create for one submission, and the delivery is settled. Against the unfixed adapter this same
    // fixture produced two creates and no probe at all.
    expect($counts())->toBe(['writes' => 1, 'probes' => 1])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->response_body_excerpt)->toBe('ok (already present)')
        ->and($delivery->unconfirmed_write_at)->toBeNull();

    // And the probe asked the right question: the submission id, against the destination's VERBATIM field
    // name -- `ColumnFingerprint` stores `submission id`, and Airtable would answer an unknown-field error.
    Http::assertSent(function (Request $request) use ($delivery): bool {
        // Parsed off the URL rather than through `Request::data()`: a GET carries no form or JSON body, so
        // that accessor answers `[]` here and the assertion would be vacuously false-then-fixed-by-loosening.
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $formula = $query['filterByFormula'] ?? null;

        return $request->method() === 'GET'
            && is_string($formula)
            && str_starts_with($formula, '{Submission ID}=')
            && str_contains($formula, (string) ($delivery->payload['data']['submission_id'] ?? 'missing'));
    });
});

it('writes exactly once more when the reconciliation proves the record is absent', function (): void {
    $counts = scriptedAirtable(
        [fn () => throw new ConnectionException('timeout'), Http::response(['records' => [['id' => 'recNEW2']]], 200)],
        probeAnswer: fn () => Http::response(['records' => []], 200),
    );

    [, $delivery] = seedAirtableDelivery();
    $delivery = attemptAirtableDelivery($delivery);
    $delivery = attemptAirtableDelivery($delivery);

    // The other half of the trade, and the one that matters more: refusing to write when we cannot confirm
    // would turn every lost answer into a submission that never arrives, which nobody would ever notice.
    expect($counts())->toBe(['writes' => 2, 'probes' => 1])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->response_body_excerpt)->toBe('ok')
        ->and($delivery->unconfirmed_write_at)->toBeNull();
});

it('still duplicates when the rule maps no Submission ID column, and that residual is the row', function (): void {
    // ⛔ ASSERTED AS A PASSING TEST SO IT CANNOT BE MISTAKEN FOR COVERAGE. Nothing we write identifies the
    // submission unless the tenant bound that column, so there is nothing to search for and the write goes
    // ahead -- today's behaviour exactly. Matching the whole record instead was rejected: two respondents
    // giving identical answers is ordinary, and a false match is a row that never arrives.
    $counts = scriptedAirtable([fn () => throw new ConnectionException('timeout')]);

    [, $delivery] = seedAirtableDelivery(ColumnMapping::author(
        ['Full name', 'Colour', 'Reviewer notes', 'Submission ID'],
        ['Full name' => 'full_name', 'Colour' => 'color'],
    ));

    $delivery = attemptAirtableDelivery($delivery);

    // The mark is still recorded -- the write really was unconfirmed -- and the probe is simply unavailable.
    expect($delivery->unconfirmed_write_at)->not->toBeNull();

    $delivery = attemptAirtableDelivery($delivery);

    expect($counts())->toBe(['writes' => 2, 'probes' => 0])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

it('retries the reconciliation rather than gambling on a create when the probe fails', function (): void {
    $counts = scriptedAirtable(
        [fn () => throw new ConnectionException('timeout')],
        probeAnswer: fn () => Http::response(['error' => ['type' => 'SERVICE_UNAVAILABLE']], 503),
    );

    [, $delivery] = seedAirtableDelivery();
    $delivery = attemptAirtableDelivery($delivery);
    $delivery = attemptAirtableDelivery($delivery);

    // "The probe failed" says nothing about whether the record is there, and a blind create is the one move
    // that cannot be taken back -- so the ladder retries the QUESTION and the mark survives to ask it again.
    expect($counts())->toBe(['writes' => 1, 'probes' => 1])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->response_body_excerpt)->toStartWith('[unconfirmed_write]')
        ->and($delivery->unconfirmed_write_at)->not->toBeNull();
});

it('keeps the unconfirmed mark on a delivery that dead-letters while still unconfirmed', function (): void {
    // The one deliberate exception to "clear it wherever the outcome is settled", and it is documented in
    // three places, so it needs a guard rather than three sentences agreeing with each other. A dead-lettered
    // delivery is never retried, so nothing can act on the mark -- and it is then the ONLY record anyone has
    // that this record may or may not be sitting in the tenant's base.
    $counts = scriptedAirtable([fn () => throw new ConnectionException('timeout')]);

    [$subscription] = seedAirtableDelivery();

    // `max_attempts = 1` makes the FIRST failure terminal, which is the state this pins without walking the
    // whole seven-day ladder.
    $delivery = WebhookDelivery::factory()->forSubscription($subscription)->create([
        'max_attempts' => 1,
        'payload' => [
            'event_type' => 'submission.created',
            'occurred_at' => '2026-08-19T09:00:00Z',
            'data' => ['submission_id' => (string) test()->m5AirtableSubmissionId, 'form_id' => (string) test()->form->id],
        ],
    ]);

    $delivery = attemptAirtableDelivery($delivery);

    expect($counts()['writes'])->toBe(1)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($delivery->unconfirmed_write_at)->not->toBeNull();
});
