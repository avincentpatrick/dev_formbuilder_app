<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Policies\FormFolderPolicy;
use App\Services\Forms\FormFolderService;
use Database\Factories\FormFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A folder the forms list files forms into (M131, `R-9e634897`, `D78`) — flat, shared by the whole
 * workspace, one per form. It is a FILING axis and grants nothing: see the `form_folders` migration for why
 * `scope_node_id` could not be reused, and {@see FormFolderPolicy} for who may do what (`D79`).
 *
 * Written only through {@see FormFolderService}, which audits every change and turns a duplicate name into a
 * field error. `created_by` is not fillable; the service sets it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormFolder extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<FormFolderFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'name',
    ];

    /** @return HasMany<Form, $this> */
    public function forms(): HasMany
    {
        return $this->hasMany(Form::class, 'folder_id');
    }
}
