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
 * same reasoning as {@see GlobalProbe}). Reads rely on RLS directly; the `platform()`/`ownedBy()` scopes
 * replace the ORM scope where a query wants only one side. Because the trait is absent, `tenant_id` is
 * NOT auto-filled — every create path sets it explicitly (and the strict INSERT policy means a tenant
 * connection can only ever write `tenant_id = <own tenant>`; platform rows come from the seeder's
 * elevated connection).
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

    /** Platform (system) templates only — the cross-tenant onboarding gallery rows. */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull('tenant_id');
    }

    /** A specific tenant's own saved templates only. */
    public function scopeOwnedBy(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'source_form_version_id');
    }
}
