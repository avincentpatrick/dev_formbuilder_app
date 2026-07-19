<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\FieldLibrary;
use Illuminate\Http\Request;

/**
 * The public API representation of a question-library item (Increment G9b). Deliberately LIGHT: the heavy
 * `default_config` / `default_validations` jsonb is never serialized on the list (a consumer inserts via the
 * app builder, not the API) — only the identifying/summary columns, mirroring {@see FormTemplateResource}.
 *
 * @mixin FieldLibrary
 */
final class FieldLibraryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'field_type' => $this->field_type,
            'default_label' => $this->default_label,
            'is_platform' => $this->tenant_id === null,
            'is_active' => $this->is_active,
            'usage_count' => $this->usage_count,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
