<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Rules\ContentBlocks;

/**
 * Whether a note's images are this form's own (M129, `R-f0c5b682` — the database half of the publish check).
 *
 * {@see ContentBlocks} checks an image block's SHAPE, and a rule cannot ask the database. This asks the rest, in one
 * query per note: each id must name a live attachment (a soft-deleted one is not found) of kind `form_content_image`,
 * owned by THIS form (`D58` = B), whose virus check has not found anything. A pending check passes — a scan that has
 * not run yet is not a reason to refuse a publish, and the read path withholds the bytes until it has.
 *
 * It sits in `StructuralValidationGate`'s namespace so the gate calls it with no `use` line: ADR-0011 cites the
 * gate's opening lines by number, and an import would move them.
 *
 * ⚠️ A copy made by save-as-template or the question library keeps the ORIGINAL form's ids, so the copy is refused
 * here until each image is uploaded again. That is a filed row, not an accident: refusing is the safe half, because
 * the alternative is one form's note showing another form's file.
 */
final class ContentImageOwnership
{
    /**
     * The ids in `$ids` that are not `$formId`'s own usable content image, in the order given.
     *
     * @param  list<string>  $ids  lowercased uuids, as {@see ContentBlocks::imageIds()} returns them
     * @return list<string>
     */
    public static function foreign(string $formId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $owned = Attachment::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->where('kind', AttachmentKind::FormContentImage)
            ->where('attachable_type', 'form')
            ->where('attachable_id', $formId)
            ->where('virus_scan_status', '!=', ScanStatus::Infected)
            ->pluck('id')
            ->map(static fn (mixed $id): string => strtolower((string) $id))
            ->all();

        return array_values(array_filter($ids, static fn (string $id): bool => ! in_array($id, $owned, true)));
    }
}
