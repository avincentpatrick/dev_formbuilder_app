<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * In-app feedback report (PRD Feature #11 / data-dictionary §21). Strictly tenant-scoped (RLS strict
 * shape). BelongsToTenant fills tenant_id on create; submitted_at + status default at the DB. No
 * created_at/updated_at — the timeline is submitted_at/resolved_at (§21).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $route
 * @property string $remarks
 * @property array<string, mixed> $browser_info
 * @property FeedbackStatus $status
 * @property Carbon $submitted_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 */
class FeedbackReport extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'route',
        'remarks',
        'browser_info',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FeedbackStatus::class,
            'browser_info' => 'array',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
