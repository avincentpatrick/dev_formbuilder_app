<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ScopeNode;
use Illuminate\Http\Request;

/**
 * One node of a tenant's scoping hierarchy (Increment G10b).
 *
 * `path` and `depth` are exposed read-only: a client rendering a tree needs them to order and indent, and
 * `path` is what makes ancestry a prefix comparison client-side too. They remain unwritable — the API has
 * no field that accepts them, and re-parenting is the dedicated `move` endpoint.
 *
 * @mixin ScopeNode
 */
final class ScopeNodeResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'code' => $this->code,
            'node_type' => $this->node_type,
            'path' => $this->path,
            'depth' => $this->depth,
            'position' => $this->position,
            'is_active' => $this->is_active,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
