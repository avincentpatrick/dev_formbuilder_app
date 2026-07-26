<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEndpointStatus;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| DeliverWebhookJob (H13a) — the committing-job recipe (workOneJob), with Http::fake (the repo's first).
| The in-process worker shares the test PDO, so the fake and the uncommitted rows are both visible.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    Http::preventStrayRequests();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    enterTenant($this->tenant->id);
});

/** Create an endpoint + a delivery for it, run one delivery attempt through the worker, and re-enter the tenant. */
function runDelivery(array $endpointAttrs = [], array $deliveryAttrs = []): array
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $endpoint = WebhookEndpoint::factory()->create(array_merge(['url' => 'https://8.8.8.8/hook'], $endpointAttrs));
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create($deliveryAttrs);

    DeliverWebhookJob::dispatch($tenant->id, (string) $delivery->id);
    workOneJob('webhooks');

    enterTenant($tenant->id);

    return [$endpoint->fresh(), $delivery->fresh()];
}

it('marks a 2xx delivery succeeded, resets the breaker, meters it, and signs the request', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [$endpoint, $delivery] = runDelivery(['consecutive_failure_count' => 3]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->response_status_code)->toBe(200)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($endpoint->consecutive_failure_count)->toBe(0)
        ->and($endpoint->last_success_at)->not->toBeNull();

    expect((int) UsageCounter::query()->where('metric', UsageMetric::WebhookDeliveries->value)->sum('value'))->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader(WebhookSigner::SIGNATURE_HEADER)
        && str_starts_with($request->header(WebhookSigner::SIGNATURE_HEADER)[0], 'sha256=')
        && $request->hasHeader(WebhookSigner::EVENT_ID_HEADER));
});

it('marks a 5xx delivery failed and schedules the first retry', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    [$endpoint, $delivery] = runDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_retry_at)->not->toBeNull()
        ->and($endpoint->consecutive_failure_count)->toBe(1);

    // First ladder step is 1 minute.
    expect($delivery->next_retry_at->between(Carbon::now()->addSeconds(30), Carbon::now()->addMinutes(2)))->toBeTrue();
});

it('treats a 3xx as failure (redirects are never followed)', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal'])]);

    [, $delivery] = runDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->response_status_code)->toBe(302);
});

it('trips the circuit breaker at the failure threshold', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    [$endpoint] = runDelivery(['consecutive_failure_count' => 19]); // threshold is 20

    expect($endpoint->consecutive_failure_count)->toBe(20)
        ->and($endpoint->status)->toBe(WebhookEndpointStatus::Paused)
        ->and($endpoint->disabled_reason)->toBe('too_many_failures');
});

it('dead-letters after the final attempt exhausts max_attempts', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    [, $delivery] = runDelivery([], ['status' => WebhookDeliveryStatus::Failed, 'attempt_count' => 9, 'max_attempts' => 10]);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->attempt_count)->toBe(10)
        ->and($delivery->next_retry_at)->toBeNull();
});

it('blocks a delivery whose target now resolves internally (DNS-rebinding), never sending it', function (): void {
    Http::fake();

    [, $delivery] = runDelivery(['url' => 'http://127.0.0.1/hook']);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->response_body_excerpt)->toContain('dns_rebinding_blocked');

    Http::assertNothingSent();
});

it('dead-letters (does not send) a first delivery over the monthly quota', function (): void {
    assignPlanTier(PlanTier::Starter); // webhook_deliveries monthly quota = 5000
    UsageCounter::query()->create([
        'metric' => UsageMetric::WebhookDeliveries->value,
        'period_start' => Carbon::now()->startOfMonth()->toDateString(),
        'period_end' => Carbon::now()->endOfMonth()->toDateString(),
        'value' => 5000,
    ]);

    Http::fake();

    [, $delivery] = runDelivery();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::DeadLettered)
        ->and($delivery->response_body_excerpt)->toContain('quota_exceeded')
        ->and($delivery->attempt_count)->toBe(0);

    Http::assertNothingSent();
});
