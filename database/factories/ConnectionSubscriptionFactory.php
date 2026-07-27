<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\DomainEventType;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConnectionSubscription>
 *
 * `tenant_id` is auto-filled by BelongsToTenant's creating hook. Pair with an existing grant via
 * {@see forConnection()} (which also carries its tenant). Default subscription is `submission.created`,
 * tenant-wide (`form_id` = null), delivering to a Slack channel.
 */
class ConnectionSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory(),
            'form_id' => null,
            'name' => fake()->words(2, true),
            'event_types' => [DomainEventType::SubmissionCreated->value],
            'config' => ['channel_id' => 'C'.strtoupper(Str::random(10)), 'channel_name' => '#general'],
            'status' => ConnectorSubscriptionStatus::Active,
            'consecutive_failure_count' => 0,
            'created_by' => null,
        ];
    }

    /** Attach the subscription to an existing connection (carries its tenant + id). */
    public function forConnection(Connection $connection): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $connection->tenant_id,
            'connection_id' => $connection->getKey(),
        ]);
    }

    /** Subscribe to a specific set of event types. */
    public function subscribedTo(DomainEventType ...$types): static
    {
        return $this->state(fn (): array => [
            'event_types' => array_map(static fn (DomainEventType $t): string => $t->value, $types),
        ]);
    }

    /** Scope the subscription to a single form (non-null form_id). */
    public function forForm(string $formId): static
    {
        return $this->state(fn (): array => ['form_id' => $formId]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => ConnectorSubscriptionStatus::Paused]);
    }
}
