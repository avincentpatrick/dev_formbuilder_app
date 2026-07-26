<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainEventType;
use App\Enums\WebhookEndpointStatus;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 *
 * `tenant_id` is auto-filled by BelongsToTenant's creating hook, so the active TenantContext decides
 * ownership. `secret` is a random token the model `encrypted`-casts at rest. Default subscription is
 * `submission.created` (tenant-wide, `form_id` = null).
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => null,
            'name' => fake()->words(2, true),
            'url' => 'https://'.fake()->domainName().'/hooks/'.fake()->uuid(),
            'secret' => 'whsec_'.Str::random(40),
            'event_types' => [DomainEventType::SubmissionCreated->value],
            'status' => WebhookEndpointStatus::Active,
            'disabled_reason' => null,
            'consecutive_failure_count' => 0,
            'signing_algorithm' => 'hmac_sha256',
            'created_by' => null,
        ];
    }

    /** Subscribe the endpoint to a specific set of event types. */
    public function subscribedTo(DomainEventType ...$types): static
    {
        return $this->state(fn (): array => [
            'event_types' => array_map(static fn (DomainEventType $t): string => $t->value, $types),
        ]);
    }

    /** Scope the endpoint to a single form (non-null form_id). */
    public function forForm(string $formId): static
    {
        return $this->state(fn (): array => ['form_id' => $formId]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookEndpointStatus::Paused,
            'disabled_reason' => 'too_many_failures',
        ]);
    }
}
