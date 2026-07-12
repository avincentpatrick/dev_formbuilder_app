<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use App\Services\Submissions\GeoIndexProjector;
use Database\Factories\SubmissionGeoIndexFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The PostGIS geometry projection of a single top-level geospatial answer (Increment G5b1 / ADR-0006 D1).
 * Internal projection row → `bigint` auto-increment PK (not UUID), so it does NOT use HasUuidv7. The
 * default table name would pluralize to `submission_geo_indices`, so it is pinned explicitly.
 *
 * In production the `geom` column is written by {@see GeoIndexProjector} via a
 * raw `ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)` insert inside the persist transaction — never through
 * this model. It is listed in `$fillable` only so the test factory can seed fixtures with EWKT text; the
 * value read back through Eloquent is opaque PostGIS binary, so tests read it via raw `ST_AsText`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $submission_id
 * @property string $form_version_id
 * @property string $form_field_id
 * @property string $field_key
 * @property string $geometry_type
 * @property ?float $captured_accuracy
 */
class SubmissionGeoIndex extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<SubmissionGeoIndexFactory> */
    use HasFactory;

    protected $table = 'submission_geo_index';

    protected $fillable = [
        'tenant_id',
        'submission_id',
        'form_version_id',
        'form_field_id',
        'field_key',
        'geometry_type',
        'captured_accuracy',
        'geom', // test-fixture only (EWKT); production writes it via GeoIndexProjector's raw ST_ insert
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_accuracy' => 'float',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<FormField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }
}
