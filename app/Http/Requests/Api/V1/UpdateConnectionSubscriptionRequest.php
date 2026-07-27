<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ConnectorSubscriptionStatus;
use App\Enums\DomainEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partially update a delivery rule (ADR-0009, H15a). Every field is `sometimes`, so a caller may send only
 * what it changes; `config` is replaced wholesale when present (it is one small destination object, and a
 * deep merge would make "clear this key" unexpressible).
 *
 * `status` accepts the full {@see ConnectorSubscriptionStatus} vocabulary — setting it back to `active` is
 * how a tenant clears a circuit-breaker pause, which also resets the failure counter in the service.
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
            'config' => ['sometimes', 'array'],
            'config.channel_id' => ['required_with:config', 'string', 'max:64'],
            'config.channel_name' => ['nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(ConnectorSubscriptionStatus::values())],
        ];
    }
}
