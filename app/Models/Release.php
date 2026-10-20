<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One release of the product and what changed in it — M181 (`R-c7247dbf`, `D125` C; data-dictionary §40).
 *
 * The platform's, not a workspace's: no `tenant_id`, no RLS. Written only by `releases:sync` from the committed notes
 * file; read by `App\Support\Platform\WhatsNew` for the prompt and the What's new page.
 *
 * @property string $id
 * @property string $version
 * @property string $title
 * @property list<array{kind: string, text: string, href?: string}> $items
 * @property Carbon $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Release extends Model
{
    use HasUuidv7;

    protected $fillable = [
        'version',
        'title',
        'items',
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'released_at' => 'datetime',
        ];
    }
}
