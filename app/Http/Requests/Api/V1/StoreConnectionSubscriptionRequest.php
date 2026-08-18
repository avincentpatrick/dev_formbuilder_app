<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\DomainEventType;
use App\Support\Connectors\SubscriptionConfigRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a delivery rule on a connection (ADR-0009, H15a). `event_types` must be a non-empty subset of the
 * {@see DomainEventType} catalog — connectors subscribe to the same vocabulary webhooks do, deliberately, so
 * there is one event catalog rather than a per-channel dialect. `form_id` uses `exists:` on the RLS-scoped
 * connection, so another tenant's form fails validation rather than leaking that the id exists.
 *
 * A rule with no destination can never deliver, so one is required: accepting it would defer a permanent
 * failure to delivery time, where it looks like an outage instead of a mistake. WHICH destination keys those
 * are is the provider's business, not this class's — H16a moved that dispatch into
 * {@see SubscriptionConfigRules}, because until then this file hard-coded Slack's `config.channel_id` in the
 * shared layer and a Google Sheets rule was rejected by validation before any adapter was consulted.
 *
 * Destinations are validated as a SHAPE, never against the provider: confirming a channel or a spreadsheet
 * exists needs an API call this request has no business making, and a destination that has since gone away is
 * a delivery-time condition the adapter reports as `blocked`.
 *
 * **Reading the exported contract:** `config` lists every destination key ANY provider accepts, all shown as
 * optional, because WHICH are required depends on the provider of the connection being posted to — Slack
 * requires `channel_id`; Google Sheets requires `spreadsheet_id` + `mapping`. The server enforces the
 * provider's set and returns a 422 naming the missing field.
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
            ...SubscriptionConfigRules::documentedShape(),
            ...SubscriptionConfigRules::requiredFor(SubscriptionConfigRules::providerFor($this)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return SubscriptionConfigRules::attributesFor(SubscriptionConfigRules::providerFor($this)) + [
            'event_types' => 'events',
            'form_id' => 'scope',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SubscriptionConfigRules::messagesFor(SubscriptionConfigRules::providerFor($this)) + [
            'event_types.required' => 'Choose at least one event.',
            'event_types.min' => 'Choose at least one event.',
        ];
    }

    /**
     * H16b — narrow `event_types` to what the bound connection's provider can actually deliver.
     *
     * In `after()` rather than `rules()` so Scramble's STATIC read of the full-catalog `Rule::in` above stays
     * intact; see {@see SubscriptionConfigRules::eventTypeGuard()} for why that matters to `openapi.json`.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [SubscriptionConfigRules::eventTypeGuard(SubscriptionConfigRules::providerFor($this))];
    }
}
