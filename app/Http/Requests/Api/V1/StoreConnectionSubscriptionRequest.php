<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\DomainEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a delivery rule on a connection (ADR-0009, H15a). `event_types` must be a non-empty subset of the
 * {@see DomainEventType} catalog — connectors subscribe to the same vocabulary webhooks do, deliberately, so
 * there is one event catalog rather than a per-channel dialect. `form_id` uses `exists:` on the RLS-scoped
 * connection, so another tenant's form fails validation rather than leaking that the id exists.
 *
 * `config.channel_id` is required because a Slack rule with no destination can never deliver: accepting one
 * would defer a permanent failure to delivery time, where it looks like an outage instead of a mistake. It is
 * validated as a shape, not against Slack — verifying the channel exists needs an API call this request has
 * no business making (H15b's picker offers real channels).
 */
final class StoreConnectionSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (ability + can: + feature) owns authorization
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(DomainEventType::values())],
            'form_id' => ['nullable', 'uuid', 'exists:forms,id'],
            'config' => ['required', 'array'],
            'config.channel_id' => ['required', 'string', 'max:64'],
            'config.channel_name' => ['nullable', 'string', 'max:150'],
        ];
    }
}
