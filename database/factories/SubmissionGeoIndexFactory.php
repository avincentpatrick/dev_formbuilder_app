<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionGeoIndex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionGeoIndex>
 *
 * A single geometry projection row. tenant_id is auto-filled by BelongsToTenant. `geom` is seeded as
 * EWKT text (`SRID=4326;POINT(...)`) — the PostGIS geometry input function parses it and the SRID
 * satisfies the geometry(Geometry, 4326) typmod. Production writes `geom` via GeoIndexProjector's raw
 * ST_GeomFromGeoJSON insert instead; this factory exists for direct table-level tests (e.g. RLS fuzz).
 */
class SubmissionGeoIndexFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lon = fake()->longitude();
        $lat = fake()->latitude();

        return [
            'submission_id' => Submission::factory(),
            'form_version_id' => FormVersion::factory(),
            'form_field_id' => FormField::factory(),
            'field_key' => 'geo_'.fake()->unique()->numerify('######'),
            'geometry_type' => 'Point',
            'captured_accuracy' => fake()->randomFloat(2, 1, 50),
            'geom' => sprintf('SRID=4326;POINT(%F %F)', $lon, $lat),
        ];
    }
}
