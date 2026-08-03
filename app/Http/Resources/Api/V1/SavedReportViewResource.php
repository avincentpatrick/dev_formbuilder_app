<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\SavedReportView;
use App\Services\Analytics\SavedReportViewService;
use Illuminate\Http\Request;

/**
 * A saved analytics report over /api/v1 (ADR-0011 §D8, Increment H24a).
 *
 * `definition` is returned as stored — a DECLARATION, not a materialized result — and `resolution` reports
 * whether it can still be read. That pairing is §D8's whole mechanism: a view naming a form, a scope node or
 * a `field_key` that has since gone degrades to a STATED refusal the client can render beside the working
 * views, rather than to a wrong number or a page that fails to load.
 *
 * @mixin SavedReportView
 */
final class SavedReportViewResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolution = app(SavedReportViewService::class)->resolve($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'definition' => $this->jsonObject($this->definition),
            'resolution' => [
                'refused' => $resolution['refused'],
                'reason' => $resolution['reason'],
                'message' => $resolution['message'],
            ],
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
