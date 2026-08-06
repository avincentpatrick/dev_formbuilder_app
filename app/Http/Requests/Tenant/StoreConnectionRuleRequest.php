<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\DomainEventType;
use App\Http\Requests\Api\V1\StoreConnectionSubscriptionRequest;
use App\Services\Connectors\ConnectionSubscriptionService;
use App\Support\Connectors\SubscriptionConfigRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a delivery rule from the session-authed Integrations UI (H15b). A byte-for-byte mirror of the
 * {@see StoreConnectionSubscriptionRequest} rules — the web surface and the /api/v1 surface accept exactly the
 * same shape and delegate to the same {@see ConnectionSubscriptionService} — so validation can never drift
 * between them (the {@see StoreWebhookRequest} convention).
 *
 * The `config.*` shape is per-provider and comes from {@see SubscriptionConfigRules} (H16a) — this file used
 * to hard-code Slack's `config.channel_id`, which is what made a Google Sheets rule unvalidatable. It is still
 * validated as a SHAPE, never against the provider: confirming a channel or spreadsheet exists needs an API
 * call this request has no business making.
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
     * The default "config.channel id field is required" is unreadable, and the destination is the field a
     * tenant is most likely to leave empty (the picker starts unselected).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SubscriptionConfigRules::messagesFor(SubscriptionConfigRules::providerFor($this)) + [
            'event_types.required' => 'Choose at least one event.',
            'event_types.min' => 'Choose at least one event.',
        ];
    }
}
