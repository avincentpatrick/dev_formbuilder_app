<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Events\FormPublished;
use App\Events\SubmissionCreated;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Listeners\Connectors\DispatchConnectorsForFormClosed;
use App\Listeners\Connectors\DispatchConnectorsForFormOpened;
use App\Listeners\Connectors\DispatchConnectorsForFormPublished;
use App\Listeners\Connectors\DispatchConnectorsForSubmissionCreated;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Connectors\ConnectorEventDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ConnectorEventDispatcher (H15a) — subscription matching, idempotency, and the one condition the webhook
| twin has no analogue for: a rule whose GRANT is dead must not produce deliveries that can only dead-letter.
*/

beforeEach(function (): void {
    TenantContext::flush();
    Bus::fake([DeliverConnectorMessageJob::class]);

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    enterTenant($this->tenant->id);

    $this->connection = Connection::factory()->create();
});

/** A `submission.created` event carrying scalar ids, exactly as the pipeline emits it. */
function connectorSubmissionEvent(?string $formId = null): SubmissionCreated
{
    $submission = new Submission;
    $submission->forceFill([
        'id' => (string) Str::uuid(),
        'tenant_id' => test()->tenant->id,
        'form_id' => $formId ?? (string) Str::uuid(),
        'form_version_id' => (string) Str::uuid(),
        'source' => SubmissionSource::Guest,
        'status' => SubmissionStatus::Submitted,
    ]);

    return SubmissionCreated::for($submission);
}

it('creates one delivery per matching subscription and enqueues it', function (): void {
    $subscription = ConnectionSubscription::factory()->forConnection($this->connection)->create();

    app(ConnectorEventDispatcher::class)->fanOut(connectorSubmissionEvent());

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->connection_subscription_id)->toBe($subscription->id)
        // The shared ledger's XOR owner check: a connector row has no endpoint side.
        ->and($delivery->webhook_endpoint_id)->toBeNull()
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->event_type)->toBe(DomainEventType::SubmissionCreated)
        ->and($delivery->payload['data']['submission_id'])->not->toBeNull();

    Bus::assertDispatched(DeliverConnectorMessageJob::class, 1);
});

it('is idempotent on (subscription, event) so a re-emitted event delivers once', function (): void {
    ConnectionSubscription::factory()->forConnection($this->connection)->create();

    $event = connectorSubmissionEvent();

    app(ConnectorEventDispatcher::class)->fanOut($event);
    app(ConnectorEventDispatcher::class)->fanOut($event);

    expect(WebhookDelivery::query()->count())->toBe(1);
    Bus::assertDispatched(DeliverConnectorMessageJob::class, 1);
});

it('skips a subscription that is not subscribed to the event type', function (): void {
    ConnectionSubscription::factory()->forConnection($this->connection)
        ->subscribedTo(DomainEventType::FormPublished)
        ->create();

    app(ConnectorEventDispatcher::class)->fanOut(connectorSubmissionEvent());

    expect(WebhookDelivery::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('skips a paused subscription', function (): void {
    ConnectionSubscription::factory()->forConnection($this->connection)->paused()->create();

    app(ConnectorEventDispatcher::class)->fanOut(connectorSubmissionEvent());

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('skips an active subscription whose grant is dead', function (): void {
    // The condition the webhook channel has no analogue for: the tenant cannot fix this by editing the rule,
    // and manufacturing deliveries for it would only build a dead-letter backlog.
    $revoked = Connection::factory()->revoked()->create();
    ConnectionSubscription::factory()->forConnection($revoked)->create();

    app(ConnectorEventDispatcher::class)->fanOut(connectorSubmissionEvent());

    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('honours form scoping in both directions', function (): void {
    // Real forms: `form_id` carries a composite `(tenant_id, form_id)` FK, so a scoped rule cannot name a
    // form that does not exist — the constraint that stops one tenant's delete cascading into another's.
    $author = User::factory()->create();
    $form = makeForm($author, 'Scoped');
    $otherForm = makeForm($author, 'Unrelated');

    $tenantWide = ConnectionSubscription::factory()->forConnection($this->connection)->create();
    $matching = ConnectionSubscription::factory()->forConnection($this->connection)->forForm($form->id)->create();
    ConnectionSubscription::factory()->forConnection($this->connection)->forForm($otherForm->id)->create();

    app(ConnectorEventDispatcher::class)->fanOut(connectorSubmissionEvent($form->id));

    expect(WebhookDelivery::query()->pluck('connection_subscription_id')->sort()->values()->all())
        ->toBe(collect([$tenantWide->id, $matching->id])->sort()->values()->all());
});

it('fans out every subscribed event type, not just submissions', function (): void {
    ConnectionSubscription::factory()->forConnection($this->connection)
        ->subscribedTo(DomainEventType::FormPublished)
        ->create();

    $form = makeForm(User::factory()->create());
    $version = makeDraftVersion($form);
    $version->forceFill(['published_at' => now(), 'version_number' => 3])->save();

    app(ConnectorEventDispatcher::class)->fanOut(FormPublished::for($version, User::factory()->create()));

    expect(WebhookDelivery::query()->where('event_type', DomainEventType::FormPublished->value)->count())->toBe(1);
});

it('is registered as an auto-discovered listener for all four events', function (): void {
    // The four thin listeners are the only wiring between the event catalog and this dispatcher; a missing
    // one is a silently dead channel for that event type.
    foreach ([
        DispatchConnectorsForSubmissionCreated::class,
        DispatchConnectorsForFormPublished::class,
        DispatchConnectorsForFormOpened::class,
        DispatchConnectorsForFormClosed::class,
    ] as $listener) {
        expect(class_exists($listener))->toBeTrue()
            ->and(is_subclass_of($listener, ShouldQueue::class))->toBeFalse();
    }

    $subscription = ConnectionSubscription::factory()->forConnection($this->connection)->create();

    // Dispatched through the real event bus, so listener auto-discovery is what carries it.
    event(connectorSubmissionEvent());

    expect(WebhookDelivery::query()->where('connection_subscription_id', $subscription->id)->count())->toBe(1);
});

it('never matches another tenant subscription, even for the same event type', function (): void {
    // Acme has a rule that would match; the event belongs to a different tenant, and the dispatcher runs
    // under that tenant's context — so RLS is what makes the match set empty, not application filtering.
    ConnectionSubscription::factory()->forConnection($this->connection)->create();

    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    enterTenant($other->id);

    $submission = new Submission;
    $submission->forceFill([
        'id' => (string) Str::uuid(),
        'tenant_id' => $other->id,
        'form_id' => (string) Str::uuid(),
        'form_version_id' => (string) Str::uuid(),
        'source' => SubmissionSource::Guest,
        'status' => SubmissionStatus::Submitted,
    ]);

    app(ConnectorEventDispatcher::class)->fanOut(SubmissionCreated::for($submission));

    enterTenant($this->tenant->id);
    expect(WebhookDelivery::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});
