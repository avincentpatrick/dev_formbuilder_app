<?php

declare(strict_types=1);

namespace App\Http\Requests\Scoping;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a scope node from the tenant-web hierarchy page (Increment G10b2). Mirrors the /api/v1 twin.
 *
 * `path`/`depth` are absent by design — derived authorization inputs written only by ScopeNodeService.
 *
 * `is_active` is absent too, and that is a DELIBERATE divergence from the API request. ScopeNodeService::create()
 * honours an `is_active` override but — unlike setActive/move/delete — never clears the resolver memo, and
 * `inactivePaths()` is memoized per tenant for the whole request. Creating a node active and then calling
 * setActive() keeps that invalidation on the one path that performs it.
 *
 * `parent_id` uses `exists:` on the tenant connection, which RLS scopes, so another tenant's node fails
 * validation rather than leaking that the id exists.
 */
final class StoreScopeNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:) owns authorization
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
        ];
    }
}
