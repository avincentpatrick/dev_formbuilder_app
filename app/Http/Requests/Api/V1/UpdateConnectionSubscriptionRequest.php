<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\DomainEventType;
use App\Support\Connectors\SubscriptionConfigRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Partially update a delivery rule (ADR-0009, H15a). Every field is `sometimes`, so a caller may send only
 * what it changes; `config` is replaced wholesale when present (it is one small destination object, and a
 * deep merge would make "clear this key" unexpressible).
 *
 * `status` accepts the full {@see ConnectorSubscriptionStatus} vocabulary — setting it back to `active` is
 * how a tenant clears a circuit-breaker pause, which also resets the failure counter in the service. H16a
 * gives that a second, more common caller: a rule paused by column drift is re-enabled the same way, after
 * the mapping is corrected in the same PATCH.
 *
 * The `config.*` shape comes from {@see SubscriptionConfigRules} in its partial mode (H16a) rather than being
 * hard-coded to Slack's `channel_id` here.
 *
 * **Reading the exported contract:** `config` lists every destination key ANY provider accepts, all shown as
 * optional, because WHICH are required depends on the provider of the connection being posted to — Slack
 * requires `channel_id`; Google Sheets requires `spreadsheet_id` + `mapping`. The server enforces the
 * provider's set and returns a 422 naming the missing field.
 */
final class UpdateConnectionSubscriptionRequest extends FormRequest
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
        return SubscriptionConfigRules::attributesFor(SubscriptionConfigRules::providerFor($this));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SubscriptionConfigRules::messagesFor(SubscriptionConfigRules::providerFor($this));
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
