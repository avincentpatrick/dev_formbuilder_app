<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Authorization\ResourceGrantResolver;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The durable, logical form record (data-dictionary §2). Deliberately thin — almost all content lives
 * versioned in form_versions/form_sections/form_fields. Strictly tenant-scoped (strict RLS).
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $current_published_version_id
 * @property ?string $draft_version_id
 * @property string $title
 * @property ?string $description
 * @property FormStatus $status
 * @property ?string $public_slug
 * @property bool $allow_guest_submissions
 * @property bool $single_page_mode
 * @property array<string, mixed> $capability_flags
 * @property string $default_locale
 * @property array<int, string> $supported_locales
 * @property string $owner_user_id
 * @property ?string $scope_node_id
 * @property string $created_by
 * @property ?Carbon $published_at
 * @property ?Carbon $archived_at
 */
class Form extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<FormFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'current_published_version_id',
        'draft_version_id',
        'title',
        'description',
        'status',
        'public_slug',
        'allow_guest_submissions',
        'allow_manual_encoding',
        'allow_ocr_single',
        'allow_ocr_linelist',
        'allow_api_import',
        'allow_offline_sync',
        'single_page_mode',
        'save_and_resume',
        'default_locale',
        'supported_locales',
        'capability_flags',
        'theme',
        'owner_user_id',
        'scope_node_id',
        'created_by',
        'updated_by',
        'published_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FormStatus::class,
            'allow_guest_submissions' => 'boolean',
            'allow_manual_encoding' => 'boolean',
            'allow_ocr_single' => 'boolean',
            'allow_ocr_linelist' => 'boolean',
            'allow_api_import' => 'boolean',
            'allow_offline_sync' => 'boolean',
            'single_page_mode' => 'boolean',
            'save_and_resume' => 'boolean',
            'supported_locales' => 'array',
            'capability_flags' => 'array',
            'theme' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<FormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'draft_version_id');
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function currentPublishedVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_published_version_id');
    }

    /** @return BelongsTo<ScopeNode, $this> */
    public function scopeNode(): BelongsTo
    {
        return $this->belongsTo(ScopeNode::class, 'scope_node_id');
    }

    /**
     * Per-instance access grants naming THIS form directly (Increment G10a — replaces `collaborators()`).
     * Note a user may also reach this form through a grant on its {@see scopeNode()}; only
     * {@see ResourceGrantResolver} answers "who can do what here" completely.
     *
     * @return MorphMany<ResourceGrant, $this>
     */
    public function grants(): MorphMany
    {
        return $this->morphMany(ResourceGrant::class, 'scopeable');
    }
}
