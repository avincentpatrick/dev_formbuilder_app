<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Models\FormVersion;

/**
 * Recomputes `forms.capability_flags` from a version's field composition (data-dictionary §2 — "recomputed
 * on every publish", generalizing legacy's OCR-compatibility guard, plan §5). A minimal Phase-1 ruleset;
 * new flags are added as the capabilities they describe land.
 */
final class CapabilityFlags
{
    private const GEO_TYPES = [FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape];

    private const MEDIA_TYPES = [
        FieldType::FileUpload,
        FieldType::ImageCapture,
        FieldType::AudioCapture,
        FieldType::VideoCapture,
        FieldType::Signature,
    ];

    /**
     * @return array<string, bool>
     */
    public function compute(FormVersion $version): array
    {
        /** @var list<FieldType> $types */
        $types = $version->fields()->get()->map(fn ($f): FieldType => $f->field_type)->all();

        $hasGeo = $this->anyOf($types, self::GEO_TYPES);
        $hasMedia = $this->anyOf($types, self::MEDIA_TYPES);

        return [
            'has_geofields' => $hasGeo,
            'has_media' => $hasMedia,
            // OCR can only reconstruct printable/scannable answers, so a form is OCR-compatible when it
            // collects no geo, media, or computed fields — a documented Phase-1 heuristic (plan §5).
            'ocr_compatible' => ! $hasGeo && ! $hasMedia && ! $this->anyOf($types, [FieldType::Calculated]),
        ];
    }

    /**
     * @param  list<FieldType>  $types
     * @param  list<FieldType>  $needles
     */
    private function anyOf(array $types, array $needles): bool
    {
        foreach ($types as $type) {
            if (in_array($type, $needles, true)) {
                return true;
            }
        }

        return false;
    }
}
