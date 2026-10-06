<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Forms\ChoiceListMaterializer;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\SchemaTreeCloner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One choice list a form version takes from an uploaded CSV file (M141, `R-f69aab42`, `D95`) — Kobo's
 * `select_one_from_file`. See the `form_version_choice_lists` migration for why the rows are stored parsed.
 *
 * Written by {@see FormChoiceListService} on a draft, turned into a cascade's options by
 * {@see ChoiceListMaterializer} at publish, and copied forward by {@see SchemaTreeCloner}.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_version_id
 * @property string $name
 * @property string $file_name
 * @property list<string> $columns
 * @property list<list<string>> $rows
 * @property int $row_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormVersionChoiceList extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'form_version_id',
        'name',
        'file_name',
        'columns',
        'rows',
        'row_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'rows' => 'array',
            'row_count' => 'integer',
        ];
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /**
     * The header, lower-cased. Read through `getAttribute()`: Larastan types a `jsonb` column from its migration and
     * ignores the cast (`M133`), so a direct property read would type as a string.
     *
     * @return list<string>
     */
    public function header(): array
    {
        $columns = $this->getAttribute('columns');

        return is_array($columns) ? array_values(array_map(strval(...), $columns)) : [];
    }

    /**
     * Every data row, each a list of strings in {@see header()} order.
     *
     * @return list<list<string>>
     */
    public function dataRows(): array
    {
        $rows = $this->getAttribute('rows');

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $row): array => is_array($row) ? array_values(array_map(strval(...), $row)) : [],
            $rows,
        ));
    }
}
