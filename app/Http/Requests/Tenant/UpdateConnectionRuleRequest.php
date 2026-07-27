<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\DomainEventType;
use App\Http\Requests\Api\V1\UpdateConnectionSubscriptionRequest;
use App\Services\Connectors\ConnectionSubscriptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partially update a delivery rule from the session-authed Integrations UI (H15b). A byte-for-byte mirror of
 * {@see UpdateConnectionSubscriptionRequest}, delegating to the same {@see ConnectionSubscriptionService}.
 *
 * EVERY field is `sometimes`, which is what makes the pause/resume control possible: it PATCHes `status` alone,
 * and a `required` rule anywhere here would 422 a request that is not trying to change those fields at all.
 * Re-activating resets the failure counter in the service (the webhook-endpoint breaker convention).
 *
 * `config.channel_id` is `required_with:config` rather than `sometimes`: `config` is replaced wholesale by the
 * service, so a partial `config` would silently drop the destination and leave a rule that can never deliver.
 */
final class UpdateConnectionRuleRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:150'],
            'event_types' => ['sometimes', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(DomainEventType::values())],
            'form_id' => ['sometimes', 'nullable', 'uuid', 'exists:forms,id'],
            'config' => ['sometimes', 'array'],
            'config.channel_id' => ['required_with:config', 'string', 'max:64'],
            'config.channel_name' => ['nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(ConnectorSubscriptionStatus::values())],
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'config.channel_id.required_with' => 'Choose a channel to deliver into.',
            'event_types.min' => 'Choose at least one event.',
        ];
    }
}
