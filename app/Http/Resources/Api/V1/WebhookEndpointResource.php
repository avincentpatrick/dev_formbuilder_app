<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;

/**
 * A webhook endpoint over /api/v1 (H13a). The signing `secret` is NEVER returned in full except once, in the
 * create response ({@see revealSecret()}) — every other representation exposes only {@see WebhookEndpoint::maskedSecret()}
 * (data-dictionary §14 Design Notes). `event_types` is the list of subscribed DomainEventType values.
 *
 * @mixin WebhookEndpoint
 */
final class WebhookEndpointResource extends ApiResource
{
    private bool $revealSecret = false;

    /** Reveal the full plaintext secret once — used only by the create response. */
    public function revealSecret(): static
    {
        $this->revealSecret = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_id' => $this->form_id,
            'name' => $this->name,
            'url' => $this->url,
            'event_types' => $this->event_types,
            'status' => $this->status->value,
            'disabled_reason' => $this->disabled_reason,
            'consecutive_failure_count' => $this->consecutive_failure_count,
            'signing_algorithm' => $this->signing_algorithm,
            'secret_masked' => $this->resource->maskedSecret(),
            // The plaintext secret, present ONLY in the create response; omitted everywhere else.
            'secret' => $this->when($this->revealSecret, fn (): string => $this->resource->secret),
            'last_success_at' => $this->iso($this->last_success_at),
            'last_failure_at' => $this->iso($this->last_failure_at),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
