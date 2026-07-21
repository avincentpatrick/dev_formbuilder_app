<?php

declare(strict_types=1);

namespace App\Http\Requests\Scoping;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename / re-label / (de)activate a scope node (Increment G10b2).
 *
 * No `parent_id`: re-parenting is the dedicated `move` route, a locked subtree-wide operation with its own
 * refusals (cycle, depth cap).
 *
 * `is_active` IS accepted here, but the controller must route it to ScopeNodeService::setActive() rather than
 * update() — update() hard-filters it out, and setActive() is the arm that clears the resolver memo.
 */
final class UpdateScopeNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:60'],
            'node_type' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
