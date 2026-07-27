<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\DomainEventType;
use App\Enums\WebhookEndpointStatus;
use App\Http\Requests\Api\V1\UpdateWebhookEndpointRequest;
use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a webhook endpoint from the management UI (Increment H14) — a partial write (every field `sometimes`).
 * Mirrors {@see UpdateWebhookEndpointRequest} exactly so the web + API surfaces can't
 * diverge. `status` accepts the lifecycle values; setting it to `active` is the manual circuit-breaker reset
 * (performed in the service). The secret is never updated through this request (rotation is its own action).
 * Authorization is the route's `can:update,webhookEndpoint` + `feature:webhooks`.
 */
final class UpdateWebhookRequest extends FormRequest
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
            'url' => ['sometimes', 'string', 'max:500', new PublicHttpUrl],
            'event_types' => ['sometimes', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(DomainEventType::values())],
            'form_id' => ['sometimes', 'nullable', 'uuid', 'exists:forms,id'],
            'status' => ['sometimes', Rule::enum(WebhookEndpointStatus::class)],
        ];
    }
}
