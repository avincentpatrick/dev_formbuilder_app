<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\DomainEventType;
use App\Enums\WebhookDeliveryStatus;
use App\Models\Form;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Database\Query\Builder;

/**
 * Outgoing deliveries to webhooks and integrations, one row per attempt chain (ADR-0025 §D5).
 *
 * A reader sees the half they could open a page for: webhook deliveries with `webhooks.manage` and the
 * webhooks feature, integration deliveries with `integrations.manage` and the connectors feature. Neither
 * the payload nor the response body is a field — both can carry answers.
 */
final class DeliveriesSource extends AbstractExplorerSource
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function key(): string
    {
        return 'deliveries';
    }

    public function label(): string
    {
        return 'Deliveries';
    }

    public function description(): string
    {
        return 'What this workspace sent to webhooks and integrations: whether it arrived, and how fast.';
    }

    public function rowNoun(): string
    {
        return 'deliveries';
    }

    public function allows(User $user): bool
    {
        return $this->webhooks($user) || $this->integrations($user);
    }

    public function rangeField(): string
    {
        return 'created_at';
    }

    public function fields(User $user, ?Form $form): array
    {
        return [
            $this->enumField('status', 'Outcome', 'webhook_deliveries.status', WebhookDeliveryStatus::class, 'Delivery'),
            $this->enumField('event', 'Event', 'webhook_deliveries.event_type', DomainEventType::class, 'Delivery'),
            $this->category(
                'kind',
                'Sent to',
                "(case when webhook_deliveries.webhook_endpoint_id is not null then 'Webhook' else 'Integration' end)",
                'Delivery',
            ),
            $this->category('response_code', 'Response code', 'webhook_deliveries.response_status_code', 'Delivery'),
            $this->time('created_at', 'Queued', 'webhook_deliveries.created_at', 'Dates'),
            $this->number('response_ms', 'Response time (ms)', 'webhook_deliveries.response_time_ms', 'Numbers'),
            $this->number('attempts', 'Attempts', 'webhook_deliveries.attempt_count', 'Numbers'),
        ];
    }

    public function query(User $user, ?Form $form): Builder
    {
        $query = WebhookDelivery::query()->toBase();

        if (! $this->webhooks($user)) {
            $query->whereNull('webhook_deliveries.webhook_endpoint_id');
        }

        if (! $this->integrations($user)) {
            $query->whereNull('webhook_deliveries.connection_subscription_id');
        }

        return $query;
    }

    private function webhooks(User $user): bool
    {
        return $user->can('webhooks.manage') && $this->featureOn('webhooks');
    }

    private function integrations(User $user): bool
    {
        return $user->can('integrations.manage') && $this->featureOn('native_connectors');
    }

    /** The `feature:` middleware's own test: a workspace with no plan at all is not gated. */
    private function featureOn(string $key): bool
    {
        return $this->entitlements->currentPlan() === null || $this->entitlements->feature($key);
    }
}
