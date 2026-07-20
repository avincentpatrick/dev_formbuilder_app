<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-parent a scope node (Increment G10b). A null `parent_id` re-roots it.
 *
 * `present` rather than `required`: null is a meaningful value here (move to the top level), and
 * `required` rejects it. The key must still appear, so a caller cannot omit it and silently re-root.
 */
final class MoveScopeNodeRequest extends FormRequest
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
            'parent_id' => ['present', 'nullable', 'uuid', 'exists:scope_nodes,id'],
        ];
    }
}
