<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\FormVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The offline sync manifest for a pinned form version (Increment G8b, docs/offline-first-sync-design.md §2):
 * the renderable, id-free schema snapshot + its checksum, which a device caches before collecting offline.
 * `choice_lists` and `media_refs` are forward-compatible: choice lists are inline in each field's `config`
 * (the snapshot carries them), and there is no static form-level media feature yet — both are emitted empty so
 * the contract shape is stable for future clients.
 *
 * @mixin FormVersion
 */
final class SyncManifestResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'form_version_id' => $this->id,
            'checksum' => $this->checksum,
            'schema_snapshot' => $this->jsonObject($this->schema_snapshot),
            'choice_lists' => $this->jsonObject([]),
            'media_refs' => $this->mediaRefs(),
            'manifest_generated_at' => $this->iso(Carbon::now()),
        ];
    }

    /**
     * Static, form-level media references (empty until that feature lands). Built via array_map so the
     * generated contract emits a plain `array` rather than a fixed-length empty tuple (which would carry the
     * draft-07 `additionalItems` keyword that is invalid under OpenAPI 3.1 / JSON Schema 2020-12).
     *
     * @return list<array<string, mixed>>
     */
    private function mediaRefs(): array
    {
        return array_map(static fn (array $ref): array => $ref, []);
    }
}
