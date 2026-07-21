<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Assign a form to a scope node, or un-assign it (Increment G10b2).
 *
 * Its own request — deliberately NOT folded into {@see FormMetadataRequest} — because writing
 * `forms.scope_node_id` is a grant-equivalent act, not a metadata edit: it confers capacity on the form AND
 * its whole submission history to every holder of a grant on that node, and on any ancestor whose grant sets
 * `includes_descendants`. The route therefore stacks `can:viewAny,ScopeNode` on top of `can:update,form`.
 *
 * `present` rather than `required`: null is meaningful (un-scope the form), and `required` rejects it.
 */
final class AssignFormScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (the stacked can: gates) owns authorization
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'scope_node_id' => ['present', 'nullable', 'uuid', 'exists:scope_nodes,id'],
        ];
    }
}
