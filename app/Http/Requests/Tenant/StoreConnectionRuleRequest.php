<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\DomainEventType;
use App\Http\Requests\Api\V1\StoreConnectionSubscriptionRequest;
use App\Services\Connectors\ConnectionSubscriptionService;
use App\Support\Connectors\SubscriptionConfigRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a delivery rule from the session-authed Integrations UI (H15b). Its rules come from the same
 * {@see SubscriptionConfigRules} as {@see StoreConnectionSubscriptionRequest}'s, and both delegate to the same
 * {@see ConnectionSubscriptionService}, so the two surfaces accept the same `config` shape.
 *
 * ⚠️ NO LONGER A BYTE-FOR-BYTE MIRROR (M96), AND THE TWO DIFFERENCES ARE BOTH FOR THE RULE EDITOR:
 *   • `prepareForValidation()` derives `config.mapping.fingerprint` from the posted headers. The editor never
 *     sends one, so until M96 no Google Sheets or Airtable rule could be saved from this page. An API caller
 *     still sends its own — see {@see SubscriptionConfigRules::withStampedFingerprint()} for why.
 *   • `after()` refuses one form field bound to two columns
 *     ({@see SubscriptionConfigRules::duplicateBindingGuard()}).
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

    /**
     * M96 — the editor posts `config.mapping.columns` and no fingerprint, so the server derives it here, from
     * those headers, before the rules that require it run.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(SubscriptionConfigRules::withStampedFingerprint($this->only('config')));
    }

    /**
     * H16b — narrow `event_types` to what the bound connection's provider can actually deliver. M96 — and refuse
     * one form field bound to two columns.
     *
     * In `after()` rather than `rules()` so Scramble's STATIC read of the full-catalog `Rule::in` above stays
     * intact; see {@see SubscriptionConfigRules::eventTypeGuard()} for why that matters to `openapi.json`.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            SubscriptionConfigRules::eventTypeGuard(SubscriptionConfigRules::providerFor($this)),
            SubscriptionConfigRules::duplicateBindingGuard(),
        ];
    }
}
