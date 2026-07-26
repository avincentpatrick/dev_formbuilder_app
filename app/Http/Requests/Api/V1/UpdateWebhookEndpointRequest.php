<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\DomainEventType;
use App\Enums\WebhookEndpointStatus;
use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a webhook endpoint (H13a). Every field is `sometimes` (a partial write). `status` accepts the
 * lifecycle values; setting it to `active` is the manual circuit-breaker reset. The secret is never updated
 * through this request (rotation is H13b).
 */
final class UpdateWebhookEndpointRequest extends FormRequest
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
            'url' => ['sometimes', 'string', 'max:500', new PublicHttpUrl],
            'event_types' => ['sometimes', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(DomainEventType::values())],
            'form_id' => ['sometimes', 'nullable', 'uuid', 'exists:forms,id'],
            'status' => ['sometimes', Rule::enum(WebhookEndpointStatus::class)],
        ];
    }
}
