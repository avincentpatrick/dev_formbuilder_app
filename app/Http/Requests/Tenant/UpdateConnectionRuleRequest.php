<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\DomainEventType;
use App\Http\Requests\Api\V1\UpdateConnectionSubscriptionRequest;
use App\Services\Connectors\ConnectionSubscriptionService;
use App\Support\Connectors\SubscriptionConfigRules;
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
 * The destination keys are `required_with:config` rather than `sometimes`: `config` is replaced wholesale by
 * the service, so a partial `config` would silently drop the destination and leave a rule that can never
 * deliver. WHICH keys those are is per-provider and comes from {@see SubscriptionConfigRules} (H16a).
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
            ...SubscriptionConfigRules::documentedShape(partial: true),
            ...SubscriptionConfigRules::requiredFor(SubscriptionConfigRules::providerFor($this), partial: true),
            'status' => ['sometimes', Rule::in(ConnectorSubscriptionStatus::values())],
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
            'event_types.min' => 'Choose at least one event.',
        ];
    }
}
