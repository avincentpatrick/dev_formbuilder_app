<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\FieldType;

/**
 * The ONE place the geospatial lat/lon order flips between this product and XLSForm/ODK (Increment G7 /
 * docs/xlsform-interop-spec.md §3.1, pinned by ADR-0006 §D2). Everything internal — JSONB, the PHP + TS
 * engines, the PostGIS projection — is a GeoJSON envelope in **longitude-first** `[lon, lat, (alt)]` order;
 * the XLSForm/ODK wire form is a space-delimited **latitude-first** `"lat lon alt accuracy"` string
 * (geotrace/geoshape are `;`-delimited point lists; a geoshape repeats its first point to close the ring).
 *
 * Confining the swap here — and unit-testing it in both directions — is what keeps the §3 "Full" geo
 * round-trip claim honest; it is called out in the spec as "the single most likely source of a silent
 * import/export bug". Its concrete G7 consumer is a geo field's `default` value in the survey sheet.
 */
final class GeoWireConverter
{
    /**
     * GeoJSON envelope (lon-first) → ODK wire string (lat-first). Returns '' for an empty/degenerate
     * envelope (no coordinates), which the exporter writes as a blank `default` cell.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function geoJsonToWire(FieldType $type, array $envelope): string
    {
        $coordinates = $envelope['coordinates'] ?? null;
        if (! is_array($coordinates) || $coordinates === []) {
            return '';
        }

        return match ($type) {
            FieldType::Geopoint => $this->formatPoint(
                $this->positionOf($coordinates),
                is_numeric($envelope['accuracy'] ?? null) ? (float) $envelope['accuracy'] : null,
            ),
            FieldType::Geotrace => $this->formatPointList($coordinates),
            // A Polygon's coordinates is [ring]; operate on the first (outer) ring.
            FieldType::Geoshape => $this->formatPointList($this->closeRing(
                is_array($coordinates[0] ?? null) ? $coordinates[0] : [],
            )),
            default => '',
        };
    }

    /**
     * ODK wire string (lat-first) → GeoJSON envelope (lon-first), or null for a blank string (an empty
     * answer). A geopoint keeps a non-zero `accuracy` as a foreign member; altitude is kept in the position
     * only when non-zero, so a 2-D `"lat lon 0 0"` round-trips back to a clean 2-D `[lon, lat]`.
     *
     * @return array<string, mixed>|null
     */
    public function wireToGeoJson(FieldType $type, string $wire): ?array
    {
        $wire = trim($wire);
        if ($wire === '') {
            return null;
        }

        return match ($type) {
            FieldType::Geopoint => $this->parsePointEnvelope($wire),
            FieldType::Geotrace => ['type' => 'LineString', 'coordinates' => $this->parsePointList($wire)],
            FieldType::Geoshape => ['type' => 'Polygon', 'coordinates' => [$this->closeRing($this->parsePointList($wire))]],
            default => null,
        };
    }

    /**
     * @param  array<int, float>  $position  a lon-first `[lon, lat, (alt)]` position
     */
    private function formatPoint(array $position, ?float $accuracy): string
    {
        $lon = $position[0] ?? 0.0;
        $lat = $position[1] ?? 0.0;
        $alt = $position[2] ?? 0.0;

        // ODK order: latitude longitude altitude accuracy.
        return implode(' ', [$this->num($lat), $this->num($lon), $this->num($alt), $this->num($accuracy ?? 0.0)]);
    }

    /**
     * @param  array<int, mixed>  $positions  a list of lon-first positions
     */
    private function formatPointList(array $positions): string
    {
        $points = [];
        foreach ($positions as $position) {
            if (is_array($position)) {
                $points[] = $this->formatPoint($this->numericList($position), null);
            }
        }

        return implode('; ', $points);
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePointEnvelope(string $wire): array
    {
        [$lon, $lat, $alt, $accuracy] = $this->parsePoint($wire);

        $coordinates = $alt !== 0.0 ? [$lon, $lat, $alt] : [$lon, $lat];
        $envelope = ['type' => 'Point', 'coordinates' => $coordinates];
        if ($accuracy !== 0.0) {
            $envelope['accuracy'] = $accuracy;
        }

        return $envelope;
    }

    /**
     * @return list<array<int, float>> lon-first positions
     */
    private function parsePointList(string $wire): array
    {
        $positions = [];
        foreach (explode(';', $wire) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            [$lon, $lat, $alt] = $this->parsePoint($chunk);
            $positions[] = $alt !== 0.0 ? [$lon, $lat, $alt] : [$lon, $lat];
        }

        return $positions;
    }

    /**
     * Parse one ODK `"lat lon alt accuracy"` chunk into a lon-first tuple `[lon, lat, alt, accuracy]`.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function parsePoint(string $chunk): array
    {
        $parts = preg_split('/\s+/', trim($chunk)) ?: [];
        $lat = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 0.0;
        $lon = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 0.0;
        $alt = isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : 0.0;
        $accuracy = isset($parts[3]) && is_numeric($parts[3]) ? (float) $parts[3] : 0.0;

        return [$lon, $lat, $alt, $accuracy];
    }

    /**
     * Ensure a polygon ring is closed (first == last). Idempotent on an already-closed ring.
     *
     * @param  array<int, mixed>  $ring
     * @return list<array<int, float>>
     */
    private function closeRing(array $ring): array
    {
        $positions = [];
        foreach ($ring as $position) {
            if (is_array($position)) {
                $positions[] = $this->numericList($position);
            }
        }

        $count = count($positions);
        if ($count >= 1 && $positions[0] !== $positions[$count - 1]) {
            $positions[] = $positions[0];
        }

        return $positions;
    }

    /**
     * The first position of a coordinates array (a Point's is the array itself; a list's is element 0).
     *
     * @param  array<int, mixed>  $coordinates
     * @return array<int, float>
     */
    private function positionOf(array $coordinates): array
    {
        // A Point's coordinates is a flat numeric position; a list's first element is a position.
        if (isset($coordinates[0]) && is_array($coordinates[0])) {
            return $this->numericList($coordinates[0]);
        }

        return $this->numericList($coordinates);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<float>
     */
    private function numericList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[] = is_numeric($value) ? (float) $value : 0.0;
        }

        return $out;
    }

    /** Format a coordinate for the wire without float noise ("14.60000000" → "14.6", "-0" → "0"). */
    private function num(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8f', $value), '0'), '.');

        return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
    }
}
