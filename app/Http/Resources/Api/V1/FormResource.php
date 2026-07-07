<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Form;
use Illuminate\Http\Request;

/**
 * The public API representation of a durable form record (Increment E). Shapes {@see Form}
 * into stable JSON for /api/v1 consumers. Version content lives behind the versions endpoints; this is
 * the thin durable record + pointers to its current published version and its draft.
 *
 * @mixin Form
 */
final class FormResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'default_locale' => $this->default_locale,
            'capability_flags' => $this->jsonObject($this->capability_flags),
            'current_published_version_id' => $this->current_published_version_id,
            'draft_version_id' => $this->draft_version_id,
            'published_at' => $this->iso($this->published_at),
            'archived_at' => $this->iso($this->archived_at),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
