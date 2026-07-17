<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\FormTemplate;
use Illuminate\Http\Request;

/**
 * The public API representation of a form template (Increment G9a). Deliberately LIGHT: the heavy
 * `schema_blueprint` jsonb is never serialized on the list (a consumer instantiates via the app, not the API),
 * only its `field_count` is derived — mirroring how {@see FormVersionResource} keeps the collection lean.
 *
 * `field_count` comes from {@see FormTemplate::fieldCount()}, which reads the SQL projection that
 * {@see FormTemplate::scopeWithoutBlueprint} adds on the list path (so the blueprint is never loaded just to be
 * counted) and falls back to the in-memory blueprint on the `store` path, where the model was just created.
 *
 * @mixin FormTemplate
 */
final class FormTemplateResource extends ApiResource
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
            'is_public' => $this->is_public,
            'is_platform' => $this->tenant_id === null,
            'field_count' => $this->fieldCount(),
            'usage_count' => $this->usage_count,
            'source_form_version_id' => $this->source_form_version_id,
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
