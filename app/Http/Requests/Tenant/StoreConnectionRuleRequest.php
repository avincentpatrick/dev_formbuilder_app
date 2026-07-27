<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\DomainEventType;
use App\Http\Requests\Api\V1\StoreConnectionSubscriptionRequest;
use App\Models\Connection;
use App\Services\Connectors\ConnectionSubscriptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a delivery rule from the session-authed Integrations UI (H15b). A byte-for-byte mirror of the
 * {@see StoreConnectionSubscriptionRequest} rules — the web surface and the /api/v1 surface accept exactly the
 * same shape and delegate to the same {@see ConnectionSubscriptionService} — so validation can never drift
 * between them (the {@see StoreWebhookRequest} convention).
 *
 * `config.channel_id` is validated as a SHAPE, not against Slack: confirming a channel exists needs an API call
 * this request has no business making, and the picker already offers real channels. `config.channel_name` is
 * nullable and that is load-bearing rather than lax — when the channel list fails to load the modal falls back
 * to a manual channel id, which by definition has no name, and requiring one would break the exact path the
 * fallback exists to serve.
 *
 * `form_id` uses `exists:` on the RLS-scoped connection, so another tenant's form fails as "not found".
 * Authorization is the route's `can:update,connection` + `feature:native_connectors`.
 */
final class StoreConnectionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (can: + feature:) owns authorization
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'config.channel_id' => 'channel',
            'config.channel_name' => 'channel name',
            'event_types' => 'events',
            'form_id' => 'scope',
        ];
    }

    /**
     * The default "config.channel id field is required" is unreadable, and this field is the one a tenant is
     * most likely to leave empty (the picker starts unselected). {@see Connection} destinations are the only
     * thing `config` carries today.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'config.channel_id.required' => 'Choose a channel to deliver into.',
            'event_types.required' => 'Choose at least one event.',
            'event_types.min' => 'Choose at least one event.',
        ];
    }
}
