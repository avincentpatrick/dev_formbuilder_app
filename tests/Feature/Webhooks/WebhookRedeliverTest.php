<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEndpointService;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Manual redeliver (H13b — webhook-integration-design.md §5) via the committing-job recipe (workOneJob). A
| redeliver resets a terminal delivery to a fresh attempt and re-enters the pipeline: it re-runs the
| delivery-time SSRF re-validation but is EXEMPT from re-metering (the event was metered on its first send).
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    Http::preventStrayRequests();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    enterTenant($this->tenant->id);
});

/** Create an endpoint + a dead-lettered delivery, redeliver it through the worker, and re-enter the tenant. */
function redeliverAndRun(array $endpointAttrs = [], array $deliveryAttrs = []): array
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $endpoint = WebhookEndpoint::factory()->create(array_merge(['url' => 'https://8.8.8.8/hook'], $endpointAttrs));
    $delivery = WebhookDelivery::factory()->forEndpoint($endpoint)->create(array_merge([
        'status' => WebhookDeliveryStatus::DeadLettered,
        'attempt_count' => 10,
        'max_attempts' => 10,
        'next_retry_at' => null,
    ], $deliveryAttrs));

    app(WebhookEndpointService::class)->redeliver($delivery);
    workOneJob('webhooks');
    enterTenant($tenant->id);

    return [$endpoint->fresh(), $delivery->fresh()];
}

it('resets a dead-lettered delivery and delivers it on redeliver', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [, $delivery] = redeliverAndRun();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->attempt_count)->toBe(1) // reset to 0, then this attempt = 1
        ->and($delivery->response_status_code)->toBe(200);

    Http::assertSent(fn (Request $r): bool => $r->hasHeader(WebhookSigner::SIGNATURE_HEADER));
});

it('re-runs the SSRF check on redeliver, never sending to a now-internal target', function (): void {
    Http::fake();

    [, $delivery] = redeliverAndRun(['url' => 'http://127.0.0.1/hook']);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->response_body_excerpt)->toContain('dns_rebinding_blocked');

    Http::assertNothingSent();
});

it('does not re-consume the monthly quota on redeliver (already metered on the first send)', function (): void {
    assignPlanTier(PlanTier::Starter); // webhook_deliveries monthly quota = 5000
    UsageCounter::query()->create([
        'metric' => UsageMetric::WebhookDeliveries->value,
        'period_start' => Carbon::now()->startOfMonth()->toDateString(),
        'period_end' => Carbon::now()->endOfMonth()->toDateString(),
        'value' => 5000, // already at the cap
    ]);

    Http::fake(['*' => Http::response('ok', 200)]);

    [, $delivery] = redeliverAndRun();

    // Delivered despite the tenant being at its cap (redeliver is exempt), and the counter did not move.
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);

    enterTenant(test()->tenant->id);
    expect((int) UsageCounter::query()->where('metric', UsageMetric::WebhookDeliveries->value)->sum('value'))->toBe(5000);
});
