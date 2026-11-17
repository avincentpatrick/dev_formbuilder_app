<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Forms\FormGlossaryService;
use App\Services\Forms\SchemaTreeCloner;
use App\Support\Forms\Glossary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One term in a form version's glossary and its definition (M245, `R-d0bbb7bb`). See the
 * `form_version_glossary_entries` migration for why it is a draft-child table.
 *
 * Written by {@see FormGlossaryService} on a draft, frozen into the published snapshot, and copied forward by
 * {@see SchemaTreeCloner}. `term_key` is always {@see Glossary::key()} of `term`, set on every save.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_version_id
 * @property string $term
 * @property string $term_key
 * @property string $definition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormVersionGlossaryEntry extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'form_version_id',
        'term',
        'definition',
    ];

    protected static function booted(): void
    {
        static::saving(static function (FormVersionGlossaryEntry $entry): void {
            $entry->term_key = Glossary::key($entry->term);
        });
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return array{term: string, definition: string} the entry as a snapshot and a template carry it */
    public function toSnapshot(): array
    {
        return ['term' => $this->term, 'definition' => $this->definition];
    }
}
