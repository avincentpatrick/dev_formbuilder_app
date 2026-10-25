<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Philippine place at one of the four levels a Cascading select offers — M186 (`R-70c7673a`, `D131`; data-dictionary §41).
 *
 * The platform's, not a workspace's: no `tenant_id`, no RLS. Written only by `psgc:sync` from the committed data file;
 * read by `App\Services\Forms\PsgcLists` when a cascade bound to the Philippine places is published or previewed.
 *
 * @property string $id
 * @property string $level region, province, city or barangay
 * @property string $code the PSA's ten-digit code, as text
 * @property string $name
 * @property string|null $parent_code the code of the place one level up; null for a region
 * @property string $publication the newest quarter that carried the place, e.g. 2026Q3
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PsgcPlace extends Model
{
    use HasUuidv7;

    protected $fillable = [
        'level',
        'code',
        'name',
        'parent_code',
        'publication',
    ];
}
