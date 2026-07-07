<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\FormVersion;
use Illuminate\Http\Request;

/**
 * The public API representation of a form version (Increment E). The immutable `schema_snapshot` (the
 * pinned, renderable structure of a published version) is heavy, so it is included only on the single-
 * version show + publish responses, not in the versions list.
 *
 * @mixin FormVersion
 */
final class FormVersionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_id' => $this->form_id,
            'version_number' => $this->version_number,
            'status' => $this->status->value,
            'title' => $this->title,
            'description' => $this->description,
            'change_summary' => $this->change_summary,
            'checksum' => $this->checksum,
            'schema_snapshot' => $this->when(
                $request->routeIs('api.v1.forms.versions.show', 'api.v1.forms.versions.publish'),
                fn (): object => $this->jsonObject($this->schema_snapshot),
            ),
            'published_at' => $this->iso($this->published_at),
            'superseded_at' => $this->iso($this->superseded_at),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
