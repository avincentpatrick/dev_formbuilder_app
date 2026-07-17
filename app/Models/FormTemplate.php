<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Services\Forms\SchemaBlueprintMaterializer;
use App\Services\Forms\SchemaSnapshotSerializer;
use Database\Factories\FormTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A whole-form blueprint (data-dictionary §12). NULLABLE-GLOBAL shape (ADR-0002 §D2 named exception):
 * a NULL `tenant_id` is a platform/system template readable by every tenant; a non-null one is a tenant's
 * own saved template. `schema_blueprint` holds the id-free, key-referenced snapshot produced by
 * {@see SchemaSnapshotSerializer::snapshot()} and is materialized back into a fresh
 * form's draft by {@see SchemaBlueprintMaterializer} on instantiate.
 *
 * Deliberately does NOT use {@see BelongsToTenant}: the ORM strict-equality scope
 * would hide the platform (NULL) rows the widened SELECT policy is specifically written to reveal (the
 * same reasoning as {@see GlobalProbe}). Reads rely on RLS directly — a plain `query()` already returns
 * the tenant's own rows PLUS platform rows; a query that wants only the platform gallery adds
 * `whereNull('tenant_id')`. Because the trait is absent, `tenant_id` is NOT auto-filled — every create
 * path sets it explicitly (and the strict INSERT policy means a tenant connection can only ever write
 * `tenant_id = <own tenant>`; platform rows come from the seeder's elevated connection).
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $name
 * @property ?string $description
 * @property ?string $category
 * @property ?string $cover_image_attachment_id
 * @property array<string, mixed> $schema_blueprint
 * @property ?string $source_form_version_id
 * @property bool $is_public
 * @property int $usage_count
 * @property ?string $created_by
 */
class FormTemplate extends Model
{
    /** @use HasFactory<FormTemplateFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    /**
     * `field_count` derived in SQL rather than by decoding the blueprint in PHP (see {@see scopeWithoutBlueprint}).
     * Guarded by `jsonb_typeof` so a blueprint missing `fields`, or holding a non-array there, counts 0 instead of
     * raising — matching the `count($blueprint['fields'] ?? [])` semantics this replaced.
     */
    private const FIELD_COUNT_SQL = "COALESCE(CASE WHEN jsonb_typeof(schema_blueprint->'fields') = 'array' "
        ."THEN jsonb_array_length(schema_blueprint->'fields') END, 0)";

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'category',
        'cover_image_attachment_id',
        'schema_blueprint',
        'source_form_version_id',
        'is_public',
        'usage_count',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_blueprint' => 'array',
            'is_public' => 'boolean',
            'usage_count' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * How many fields this template's blueprint holds, for a list card. Prefers the SQL projection added by
     * {@see scopeWithoutBlueprint} — which is the whole point of that scope, so the list paths never load the
     * jsonb — and falls back to counting an in-memory blueprint for a model loaded whole, e.g. the row
     * save-as-template just wrote and hands straight to a resource.
     */
    public function fieldCount(): int
    {
        $projected = $this->getAttribute('field_count');

        if ($projected !== null) {
            return (int) $projected;
        }

        return count($this->schema_blueprint['fields'] ?? []);
    }

    /**
     * The LIST projection: every card/resource column except the heavy `schema_blueprint`, plus a `field_count`
     * derived in SQL. The gallery and the API list only ever wanted that integer, and a plain `query()` would
     * pull every blueprint's full jsonb out of Postgres and decode it in PHP to produce it — unbounded work in
     * the tenant's own saved templates. Instantiate still loads the model normally, blueprint and all.
     *
     * @param  Builder<FormTemplate>  $query
     * @return Builder<FormTemplate>
     */
    public function scopeWithoutBlueprint(Builder $query): Builder
    {
        return $query
            ->select([
                'id',
                'tenant_id',
                'name',
                'description',
                'category',
                'cover_image_attachment_id',
                'source_form_version_id',
                'is_public',
                'usage_count',
                'created_by',
                'created_at',
                'updated_at',
                'deleted_at',
            ])
            ->selectRaw(self::FIELD_COUNT_SQL.' AS field_count');
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'source_form_version_id');
    }
}
