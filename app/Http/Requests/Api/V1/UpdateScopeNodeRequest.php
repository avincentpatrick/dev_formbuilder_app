<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a scope node's labels (Increment G10b).
 *
 * No `parent_id`: re-parenting is the dedicated `move` endpoint, because it is a locked, subtree-wide
 * operation with its own refusals (cycle, depth cap) and folding it in here would make both the contract
 * and the controller's branching worse.
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
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
