<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\DomainEventType;
use App\Enums\UsageMetric;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use App\Models\Form;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read model for the webhook management UI (Increment H14). The session-authed Inertia surface over the
 * H13a/H13b engine: the controller stays a thin adapter and every prop shape is built here. Field names mirror
 * {@see WebhookEndpointResource} / {@see WebhookDeliveryResource}
 * 1:1 so the web + /api/v1 surfaces describe the same records — but the mapping is INLINE rather than reusing
 * those `Api\V1` resources: the web presenter must not couple to an API-versioned class, it adds derivations
 * the resources don't (`form_title`, the entitlement summary, the `can` map), and inline mapping keeps the
 * signing `secret` out by construction (only the create/rotate flash path ever reveals it).
 *
 * The delivery log is OFFSET-paginated (`->paginate()`), NOT cursor-paginated like the API controller — the
 * `MdsPagination` primitive needs `current_page`/`last_page`/`total`/`per_page`, which a cursor cannot supply.
 * `payload` + `signature` are never mapped (the same default-exclude-sensitive-content posture the resources hold).
 */
final class WebhookEndpointPresenter
{
    private const DELIVERIES_PER_PAGE = 25;

    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * The endpoints list, the plan cap/quota summary, and the create-modal option catalogs.
     *
     * @return array<string, mixed>
     */
    public function index(User $user): array
    {
        $endpoints = WebhookEndpoint::query()
            ->with('form:id,title')
            ->latest('created_at')
            ->get();

        return [
            'data' => $endpoints->map(fn (WebhookEndpoint $e): array => $this->row($e))->all(),
            'summary' => [
                'endpoints' => [
                    'used' => $this->entitlements->usage(UsageMetric::WebhookEndpointsCount),
                    'limit' => $this->entitlements->quota(UsageMetric::WebhookEndpointsCount),
                ],
                'deliveries' => [
                    'used' => $this->entitlements->usage(UsageMetric::WebhookDeliveries),
                    'limit' => $this->entitlements->quota(UsageMetric::WebhookDeliveries),
                ],
            ],
            'forms' => $this->formOptions(),
            'eventTypes' => $this->eventTypeOptions(),
            'can' => ['create' => $user->can('create', WebhookEndpoint::class)],
        ];
    }

    /**
     * One endpoint's detail plus its offset-paginated delivery log and the edit-modal option catalogs.
     *
     * @return array<string, mixed>
     */
    public function show(User $user, WebhookEndpoint $endpoint): array
    {
        $endpoint->loadMissing('form:id,title');

        $paginator = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->getKey())
            ->latest('created_at')
            ->paginate(self::DELIVERIES_PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, WebhookDelivery> $items */
        $items = collect($paginator->items());

        return [
            'endpoint' => $this->detail($endpoint),
            'deliveries' => [
                'data' => $items->map(fn (WebhookDelivery $d): array => $this->deliveryRow($d))->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                ],
            ],
            'forms' => $this->formOptions(),
            'eventTypes' => $this->eventTypeOptions(),
            'can' => [
                'update' => $user->can('update', $endpoint),
                'delete' => $user->can('delete', $endpoint),
            ],
        ];
    }

    /**
     * A list-row projection of an endpoint (never carries the secret).
     *
     * @return array<string, mixed>
     */
    private function row(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'status' => $endpoint->status->value,
            'event_types' => $endpoint->event_types,
            'form_id' => $endpoint->form_id,
            'form_title' => $this->formTitle($endpoint),
            'secret_masked' => $endpoint->maskedSecret(),
            'disabled_reason' => $endpoint->disabled_reason,
            'consecutive_failure_count' => $endpoint->consecutive_failure_count,
            'last_success_at' => $this->iso($endpoint->last_success_at),
            'last_failure_at' => $this->iso($endpoint->last_failure_at),
            'created_at' => $this->iso($endpoint->created_at),
        ];
    }

    /**
     * The row projection plus the detail-only fields the Show page renders.
     *
     * @return array<string, mixed>
     */
    private function detail(WebhookEndpoint $endpoint): array
    {
        return [
            ...$this->row($endpoint),
            'signing_algorithm' => $endpoint->signing_algorithm,
            'secret_previous_expires_at' => $this->iso($endpoint->secret_previous_expires_at),
            'updated_at' => $this->iso($endpoint->updated_at),
        ];
    }

    /**
     * A delivery-log row. Mirrors {@see WebhookDeliveryResource}; `payload` +
     * `signature` are intentionally absent (potential PII / credential-adjacent).
     *
     * @return array<string, mixed>
     */
    private function deliveryRow(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type->value,
            'status' => $delivery->status->value,
            'attempt_count' => $delivery->attempt_count,
            'max_attempts' => $delivery->max_attempts,
            'next_retry_at' => $this->iso($delivery->next_retry_at),
            'last_attempted_at' => $this->iso($delivery->last_attempted_at),
            'response_status_code' => $delivery->response_status_code,
            'response_body_excerpt' => $delivery->response_body_excerpt,
            'response_time_ms' => $delivery->response_time_ms,
            'created_at' => $this->iso($delivery->created_at),
            'updated_at' => $this->iso($delivery->updated_at),
        ];
    }

    /**
     * The tenant's forms as scope options for the create/edit modal (the "Tenant-wide" null choice is added
     * by the client). RLS-scoped, so only this tenant's forms appear.
     *
     * @return list<array{value: string, label: string}>
     */
    private function formOptions(): array
    {
        return array_values(Form::query()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Form $f): array => ['value' => $f->id, 'label' => $f->title])
            ->all());
    }

    /**
     * The subscribable event catalog ({@see DomainEventType}) with human labels for the checkbox group.
     *
     * @return list<array{value: string, label: string}>
     */
    private function eventTypeOptions(): array
    {
        return array_map(
            static fn (DomainEventType $t): array => ['value' => $t->value, 'label' => $t->label()],
            DomainEventType::cases(),
        );
    }

    private function formTitle(WebhookEndpoint $endpoint): ?string
    {
        $title = data_get($endpoint, 'form.title');

        return is_string($title) ? $title : null;
    }

    private function iso(?Carbon $value): ?string
    {
        return $value?->toIso8601String();
    }
}
