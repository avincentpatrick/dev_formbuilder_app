<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Models\FormField;
use App\Services\Expressions\Coercion;
use Illuminate\Support\Arr;

/**
 * The geometry-projection half of Stage 4 (ADR-0006 D1) — the geo analogue of {@see AnswerIndexProjector}.
 * Maps one top-level geospatial field's validated GeoJSON envelope
 * (`{type, coordinates, accuracy?}`) to the payload the pipeline inserts into `submission_geo_index`:
 * the geometry as a GeoJSON string (fed to `ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)` — a foreign
 * `accuracy` member is ignored by ST_GeomFromGeoJSON), the geometry type, and the captured accuracy.
 *
 * Returns null when there is nothing to project: a non-geo field, or a structurally-empty answer (no
 * usable geometry). By persist time the envelope has already passed Stage-1 coercion + Stage-3 geo
 * validation, so a non-null return is a well-formed geometry.
 */
final class GeoIndexProjector
{
    /**
     * @return array{geometry_type: string, captured_accuracy: ?float, geojson: string}|null
     */
    public function project(FormField $field, mixed $answer): ?array
    {
        if (! $field->field_type->isGeo()) {
            return null;
        }

        if (! is_array($answer)) {
            return null;
        }

        $type = Arr::get($answer, 'type');
        $coordinates = Arr::get($answer, 'coordinates');

        if (! is_string($type) || $type === '' || ! is_array($coordinates)) {
            return null; // structurally empty / not a geometry envelope — nothing to project
        }

        $accuracy = Arr::get($answer, 'accuracy');

        return [
            'geometry_type' => $type,
            'captured_accuracy' => Coercion::isNumericLike($accuracy) ? Coercion::toNumber($accuracy) : null,
            'geojson' => (string) json_encode(['type' => $type, 'coordinates' => $coordinates]),
        ];
    }
}
