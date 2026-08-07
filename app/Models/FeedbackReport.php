<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $screenshot_attachment_id
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

    /**
     * `resolved_at`/`resolved_by` are deliberately ABSENT: they are written only by
     * {@see App\Services\Feedback\FeedbackService::transition()} — the platform support console's
     * transition, never a tenant-side submit — so they are set with `forceFill()` and can never arrive
     * through mass assignment from a request payload.
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'route',
        'remarks',
        'browser_info',
        'screenshot_attachment_id',
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

    /**
     * The reporter.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The platform support member who closed (or re-opened) it.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * The captured screenshot, if any. Resolved through {@see Attachment}'s SoftDeletes global scope —
     * which, not the `ON DELETE SET NULL` FK, is what makes a deleted screenshot stop rendering (an
     * ordinary `delete()` never fires the referential action). Same two-halves-disagree hazard the
     * branding migration documents.
     *
     * @return BelongsTo<Attachment, $this>
     */
    public function screenshot(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'screenshot_attachment_id');
    }
}
