<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The offline sync-manifest request (Increment G8b). Authorization is the route's `ability:` middleware +
 * RLS, not a policy, so authorize() is true. `form_version_id` arrives as a query parameter — the device
 * asks for the exact pinned version it collected against.
 */
final class SyncManifestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'form_version_id' => ['required', 'uuid'],
        ];
    }

    public function formVersionId(): string
    {
        return (string) $this->input('form_version_id');
    }
}
