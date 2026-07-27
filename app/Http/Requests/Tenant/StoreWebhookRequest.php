<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\DomainEventType;
use App\Http\Requests\Api\V1\StoreWebhookEndpointRequest;
use App\Rules\PublicHttpUrl;
use App\Services\Webhooks\WebhookEndpointService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a webhook endpoint from the session-authed management UI (Increment H14). A byte-for-byte mirror of
 * the {@see StoreWebhookEndpointRequest} rules — the web surface and the /api/v1
 * surface accept exactly the same shape and delegate to the same {@see WebhookEndpointService}
 * — so validation can never drift between them. `url` runs the {@see PublicHttpUrl} SSRF check at save time;
 * `event_types` must be a non-empty subset of the {@see DomainEventType} catalog; `form_id` uses `exists:` on
 * the RLS-scoped connection (another tenant's form fails as "not found"). The secret is minted server-side,
 * never accepted from the client. Authorization is the route's `can:create,WebhookEndpoint` + `feature:webhooks`.
 */
final class StoreWebhookRequest extends FormRequest
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
            'url' => ['required', 'string', 'max:500', new PublicHttpUrl],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(DomainEventType::values())],
            'form_id' => ['nullable', 'uuid', 'exists:forms,id'],
        ];
    }
}
