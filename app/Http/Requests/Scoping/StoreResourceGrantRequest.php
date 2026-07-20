<?php

declare(strict_types=1);

namespace App\Http\Requests\Scoping;

use App\Enums\ResourceCapacity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grant a capacity on a scope node (Increment G10b2).
 *
 * Deliberately NARROWER than the /api/v1 twin: the web surface takes a `scope_node_id`, not a
 * `scopeable_type` + `scopeable_id` pair. G10b2 manages grants on the hierarchy only — a form-targeted grant
 * has no UI — so accepting a polymorphic target here would be an untested morph write path reachable from a
 * session cookie. The controller resolves the node through the tenant-scoped model and the service does the
 * `->scopeable()->associate()`; the morph columns are never mass-assigned (that is what
 * `ResourceGrant::$fillable` excludes them for).
 *
 * `user_id` uses `exists:` on the tenant connection, where the `users_visibility` RLS policy already restricts
 * to ACTIVE co-tenants plus self — so an invited or removed member fails validation here rather than
 * surviving to the service's `assertActiveMember()` guard.
 */
final class StoreResourceGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scope_node_id' => ['required', 'uuid', 'exists:scope_nodes,id'],
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'capacity' => ['required', 'string', Rule::in(ResourceCapacity::values())],
            'includes_descendants' => ['nullable', 'boolean'],
        ];
    }
}
