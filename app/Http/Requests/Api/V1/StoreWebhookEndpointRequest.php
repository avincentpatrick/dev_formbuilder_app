<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\DomainEventType;
use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a webhook endpoint (H13a). `url` runs the {@see PublicHttpUrl} SSRF check at save time.
 * `event_types` must be a non-empty subset of the {@see DomainEventType} catalog (the webhook subscription
 * vocabulary and the domain-event vocabulary are the same catalog by design). `form_id` uses `exists:` on the
 * RLS-scoped connection, so another tenant's form fails validation rather than leaking that the id exists.
 * The signing secret is minted server-side, never accepted from the client.
 */
final class StoreWebhookEndpointRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'url' => ['required', 'string', 'max:500', new PublicHttpUrl],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(DomainEventType::values())],
            'form_id' => ['nullable', 'uuid', 'exists:forms,id'],
        ];
    }
}
