<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ResourceGrant;
use Illuminate\Http\Request;

/**
 * One access grant (Increment G10b). `scopeable_type` is the stable morph ALIAS (`form` | `scope_node`),
 * never a PHP class name — the same value stored in the column and pinned by the
 * `resource_grants_scopeable_type_check` CHECK, so an integration can switch on it safely.
 *
 * @mixin ResourceGrant
 */
final class ResourceGrantResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scopeable_type' => $this->scopeable_type,
            'scopeable_id' => $this->scopeable_id,
            'user_id' => $this->user_id,
            'capacity' => $this->capacity->value,
            'includes_descendants' => $this->includes_descendants,
            'granted_by' => $this->granted_by,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
