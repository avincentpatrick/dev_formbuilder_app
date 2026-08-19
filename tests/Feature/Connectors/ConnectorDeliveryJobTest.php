<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\Connectors\ConnectionRevokedNotification;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\UsageMeter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| DeliverConnectorMessageJob (H15a) — the committing-job recipe (workOneJob) with Http::fake, the
| DeliverWebhookJob pattern. The in-process worker shares the test PDO, so the fake and the uncommitted rows
| are both visible.
|
| The behaviours worth pinning are the ones a webhook has no equivalent for: Slack reports failure with HTTP
| 200, and a CREDENTIAL rejection is terminal rather than retryable.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    config()->set('connectors.tenant_url_scheme', 'http');
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
    // The owner must be an ACTIVE MEMBER for their users row to resolve under the job's tenant RLS — the
    // WebhookAutoDisableNotificationTest recipe, and the reason an owner-notification test can fail silently.
    makeActiveMember($this->owner, 'owner');
    enterTenant($this->tenant->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Create a grant + rule + pending delivery, run one attempt through the worker, and re-enter the tenant. */
function runConnectorDelivery(array $connectionAttrs = [], array $subscriptionAttrs = [], array $deliveryAttrs = []): array
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $connection = Connection::factory()->create($connectionAttrs);
    $subscription = ConnectionSubscription::factory()->forConnection($connection)->create($subscriptionAttrs);
    $delivery = WebhookDelivery::factory()->forSubscription($subscription)->create($deliveryAttrs);

    DeliverConnectorMessageJob::dispatch($tenant->id, (string) $delivery->id);
    workOneJob('webhooks');

    enterTenant($tenant->id);

    return [$connection->fresh(), $subscription->fresh(), $delivery->fresh()];
}

it('posts to the configured channel with the bot token and records success', function (): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    [, $subscription, $delivery] = runConnectorDelivery(
        ['access_token' => 'xoxb-live'],
        ['config' => ['channel_id' => 'C123', 'channel_name' => '#ops']],
    );

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->response_status_code)->toBe(200)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($subscription->consecutive_failure_count)->toBe(0)
        ->and($subscription->last_success_at)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->hasHeader('Authorization', 'Bearer xoxb-live')
            && $body['channel'] === 'C123'
            && str_contains((string) $body['text'], 'New submission')
            && $body['blocks'][0]['type'] === 'section';
    });
});

it('meters the delivery against the shared outbound quota', function (): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    runConnectorDelivery();

    // One outbound-delivery budget across both channels — not a second accounting system for the same egress.
    expect((int) UsageCounter::query()->where('metric', UsageMetric::WebhookDeliveries->value)->sum('value'))->toBe(1);
});

it('treats an ok:false body as a failure even though Slack returns HTTP 200', function (): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'ratelimited'], 200)]);

    [, $subscription, $delivery] = runConnectorDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->attempt_count)->toBe(1)
        // Scheduled on the shared ladder's first step, not abandoned.
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($delivery->response_body_excerpt)->toContain('[ratelimited]')
        ->and($subscription->consecutive_failure_count)->toBe(1);
});

it('revokes the grant, dead-letters, and notifies the owner when the credential is rejected', function (): void {
    Notification::fake();
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'token_revoked'], 200)]);

    [$connection, $subscription, $delivery] = runConnectorDelivery();

    // Terminal, not retried: the token will never work again, so a seven-day ladder is pure noise.
    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($connection->status)->toBe(ConnectionStatus::Revoked)
        // The dead credential is cleared, and the rules that used it are paused rather than left failing.
        ->and($connection->access_token)->toBe('')
        ->and($connection->refresh_token)->toBeNull()
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused);

    Notification::assertSentOnDemand(ConnectionRevokedNotification::class);
});

it('dead-letters without sending when the monthly delivery quota is exhausted', function (): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    // Starter meters 5,000 deliveries a month; consume every one of them first.
    enterTenant($this->tenant->id);
    assignPlanTier(PlanTier::Starter);
    $quota = app(EntitlementService::class)->quota(UsageMetric::WebhookDeliveries);
    expect($quota)->toBeInt();
    app(UsageMeter::class)->increment(UsageMetric::WebhookDeliveries, (int) $quota);

    [, , $delivery] = runConnectorDelivery();

    // A hard cap: the message is not sent at all, and the row records why rather than retrying into it.
    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->response_body_excerpt)->toContain('[quota_exceeded]');

    Http::assertNothingSent();
});

it('does not deliver through a paused rule or a dead grant', function (array $connectionAttrs, array $subscriptionAttrs): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    [, , $delivery] = runConnectorDelivery($connectionAttrs, $subscriptionAttrs);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending);
    Http::assertNothingSent();
})->with([
    'paused rule' => [[], ['status' => ConnectorSubscriptionStatus::Paused]],
]);

it('dead-letters a delivery whose GRANT is dead, rather than leaving it to be re-swept forever', function (): void {
    // ⚠️ SPLIT OUT OF THE CASE ABOVE IN M6, BECAUSE THE TWO STOPPED BEING THE SAME OUTCOME — and the
    // difference is a latent loop, not a nicety. Leaving the row `pending`/`failed` with its `next_retry_at`
    // intact and `attempt_count` untouched means `WebhookRetrySweeper`'s `attempt_count < max_attempts`
    // predicate stays true forever, so the delivery is re-dispatched every five minutes for the life of the
    // row. It never surfaced because the pre-flight refresh used to catch a revocation one attempt earlier
    // and dead-letter it; M6 moved that refresh into its own job, which would have left the loop as the only
    // path. A PAUSED RULE is deliberately still the silent case — an admin un-pauses it and the queued
    // deliveries resume, which is the whole point of `paused` being distinct from `disabled`.
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    [, , $delivery] = runConnectorDelivery(['status' => ConnectionStatus::Revoked], []);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($delivery->response_body_excerpt)->toContain('[grant_expired]');

    Http::assertNothingSent();
});

it('fails a rule with no destination without sending', function (): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true], 200)]);

    [, , $delivery] = runConnectorDelivery([], ['config' => []]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->response_body_excerpt)->toContain('[missing_channel]');

    Http::assertNothingSent();
});

it('dead-letters once the ladder is exhausted and trips the breaker at the threshold', function (): void {
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'channel_not_found'], 200)]);

    config()->set('webhooks.breaker_threshold', 1);

    [, $subscription, $delivery] = runConnectorDelivery([], [], ['attempt_count' => 9, 'max_attempts' => 10]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->attempt_count)->toBe(10)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($subscription->status)->toBe(ConnectorSubscriptionStatus::Paused);
});
