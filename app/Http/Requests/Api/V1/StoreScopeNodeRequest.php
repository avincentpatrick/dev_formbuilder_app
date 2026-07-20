<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a scope node (Increment G10b).
 *
 * `path`/`depth` are absent by design — they are derived authorization inputs written only by
 * ScopeNodeService, and there is deliberately no field here that could carry them.
 *
 * `parent_id` uses `exists:` on the tenant connection, which RLS scopes, so another tenant's node fails
 * validation rather than leaking that the id exists.
 */
final class StoreScopeNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (ability + can:) owns authorization
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'uuid', 'exists:scope_nodes,id'],
            'code' => ['nullable', 'string', 'max:60'],
            'node_type' => ['nullable', 'string', 'max:60'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
