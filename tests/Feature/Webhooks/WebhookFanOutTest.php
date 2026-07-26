<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\FormOpened;
use App\Events\FormPublished;
use App\Events\SubmissionCreated;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEventDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Event → delivery fan-out (H13a). The dispatcher matches active, subscribed, form-scoped endpoints and
| creates one idempotent delivery row + one DeliverWebhookJob each. Queue::fake captures the job so nothing
| is actually delivered; the delivery ROWS are real DB writes under RLS.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    // A real form so a form-scoped endpoint's composite FK (tenant_id, form_id) → forms is satisfiable.
    $this->form = makeForm($this->user);
    $this->formId = $this->form->id;
    Queue::fake();
});

function subEvent(string $tenantId, string $formId): SubmissionCreated
{
    $s = new Submission([
        'tenant_id' => $tenantId,
        'form_id' => $formId,
        'form_version_id' => (string) Str::uuid(),
        'status' => SubmissionStatus::Submitted,
        'source' => SubmissionSource::Guest,
    ]);
    $s->id = (string) Str::uuid();

    return SubmissionCreated::for($s);
}

it('fans a submission.created out to a matching tenant-wide endpoint', function (): void {
    $endpoint = WebhookEndpoint::factory()->create(['form_id' => null]);

    app(WebhookEventDispatcher::class)->fanOut(subEvent($this->tenant->id, $this->formId));

    enterTenant($this->tenant->id);
    $delivery = WebhookDelivery::query()->firstOrFail();
    expect($delivery->webhook_endpoint_id)->toBe($endpoint->id)
        ->and($delivery->event_type)->toBe(DomainEventType::SubmissionCreated)
        ->and($delivery->payload['data']['form_id'])->toBe($this->formId);

    Queue::assertPushed(DeliverWebhookJob::class, 1);
});

it('is idempotent on (endpoint, event_id) — a re-emit fans out at most one delivery', function (): void {
    WebhookEndpoint::factory()->create(['form_id' => null]);
    $event = subEvent($this->tenant->id, $this->formId);

    app(WebhookEventDispatcher::class)->fanOut($event);
    app(WebhookEventDispatcher::class)->fanOut($event); // same event_id

    enterTenant($this->tenant->id);
    expect(WebhookDelivery::query()->count())->toBe(1);
    Queue::assertPushed(DeliverWebhookJob::class, 1);
});

it('respects form scoping', function (): void {
    $otherForm = (string) Str::uuid();
    WebhookEndpoint::factory()->forForm($this->formId)->create();

    // An event for a DIFFERENT form does not match the form-scoped endpoint.
    app(WebhookEventDispatcher::class)->fanOut(subEvent($this->tenant->id, $otherForm));
    enterTenant($this->tenant->id);
    expect(WebhookDelivery::query()->count())->toBe(0);

    // An event for THIS form does.
    app(WebhookEventDispatcher::class)->fanOut(subEvent($this->tenant->id, $this->formId));
    enterTenant($this->tenant->id);
    expect(WebhookDelivery::query()->count())->toBe(1);
});

it('skips endpoints not subscribed to the event type, and paused endpoints', function (): void {
    WebhookEndpoint::factory()->subscribedTo(DomainEventType::FormPublished)->create();       // wrong type
    WebhookEndpoint::factory()->paused()->subscribedTo(DomainEventType::SubmissionCreated)->create(); // paused

    app(WebhookEventDispatcher::class)->fanOut(subEvent($this->tenant->id, $this->formId));

    enterTenant($this->tenant->id);
    expect(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('fans out form.published and form.opened to their subscribers', function (): void {
    WebhookEndpoint::factory()->subscribedTo(DomainEventType::FormPublished)->create();
    WebhookEndpoint::factory()->subscribedTo(DomainEventType::FormOpened)->create();

    $version = new FormVersion(['tenant_id' => $this->tenant->id, 'form_id' => $this->formId, 'version_number' => 1, 'change_summary' => 'Initial version.']);
    $version->id = (string) Str::uuid();
    $publisher = new User;
    $publisher->id = (string) Str::uuid();

    $form = new Form(['tenant_id' => $this->tenant->id]);
    $form->id = $this->formId;
    $form->timezone = 'UTC';

    app(WebhookEventDispatcher::class)->fanOut(FormPublished::for($version, $publisher));
    app(WebhookEventDispatcher::class)->fanOut(FormOpened::for($form));

    enterTenant($this->tenant->id);
    expect(WebhookDelivery::query()->where('event_type', DomainEventType::FormPublished->value)->count())->toBe(1)
        ->and(WebhookDelivery::query()->where('event_type', DomainEventType::FormOpened->value)->count())->toBe(1);
});

it('creates delivery rows through the real auto-discovered listener when the event fires', function (): void {
    WebhookEndpoint::factory()->create(['form_id' => null]);

    event(subEvent($this->tenant->id, $this->formId));

    enterTenant($this->tenant->id);
    expect(WebhookDelivery::query()->count())->toBe(1);
    Queue::assertPushed(DeliverWebhookJob::class, 1);
});
