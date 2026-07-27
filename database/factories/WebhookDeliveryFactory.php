<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainEventType;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ConnectionSubscription;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 *
 * `tenant_id` is auto-filled by BelongsToTenant's creating hook. Pair with an existing endpoint via
 * {@see forEndpoint()} (which also carries its tenant); the default `payload` is a minimal valid envelope.
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = (string) Str::uuid();

        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event_id' => $eventId,
            'event_type' => DomainEventType::SubmissionCreated,
            'payload' => [
                'event_id' => $eventId,
                'event_type' => DomainEventType::SubmissionCreated->value,
                'occurred_at' => Carbon::now()->toIso8601String(),
                'tenant_id' => null,
                'api_version' => 'v1',
                'data' => [],
            ],
            'status' => WebhookDeliveryStatus::Pending,
            'attempt_count' => 0,
            'max_attempts' => 10,
            'next_retry_at' => null,
        ];
    }

    /** Attach the delivery to an existing endpoint (carries its tenant + id). */
    public function forEndpoint(WebhookEndpoint $endpoint): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->getKey(),
            'connection_subscription_id' => null,
        ]);
    }

    /**
     * Attach the delivery to a connector subscription instead of a webhook endpoint (H15a). The two owners
     * are mutually exclusive — `webhook_deliveries_owner_check` rejects a row with both or neither — so this
     * nulls the endpoint the default definition would otherwise create.
     */
    public function forSubscription(ConnectionSubscription $subscription): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $subscription->tenant_id,
            'webhook_endpoint_id' => null,
            'connection_subscription_id' => $subscription->getKey(),
        ]);
    }

    /** A failed delivery due for re-attempt (the retry-sweep target). */
    public function dueForRetry(): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookDeliveryStatus::Failed,
            'attempt_count' => 1,
            'next_retry_at' => Carbon::now()->subMinute(),
        ]);
    }
}
