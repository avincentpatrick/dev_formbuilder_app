<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Admin\SuperAdminService;
use App\Services\Feedback\FeedbackService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * In-app feedback report (PRD Feature #11 / data-dictionary §21). Strictly tenant-scoped (RLS strict
 * shape). BelongsToTenant fills tenant_id on create; submitted_at + status default at the DB. No
 * created_at/updated_at — the timeline is submitted_at/resolved_at (§21).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property FeedbackType|null $type
 * @property string $route
 * @property string|null $page_url
 * @property string|null $page_title
 * @property string $remarks
 * @property array<string, mixed> $browser_info
 * @property string|null $screenshot_attachment_id
 * @property FeedbackStatus $status
 * @property Carbon $submitted_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 * @property string|null $resolution_note the support team's reply to the reporter (M175)
 * @property string|null $reporter_role the reporter's role when they sent it (M175)
 * @property FeedbackSource $source the Feedback button, or relayed through the support API (M179, `D150`)
 * @property string|null $source_label where a relayed report came from, as its card says it (M179)
 * @property string|null $reference the code the testers know a relayed report by, unique per workspace (M179)
 * @property string|null $title what a relayed report is called on its card (M179)
 * @property Carbon|null $confirmed_at when a tester pressed It works (M179, `D151`)
 * @property string|null $confirmed_by who pressed It works (M179)
 * @property Carbon|null $last_comment_at the newest comment in the report's thread (M179)
 */
class FeedbackReport extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    public $timestamps = false;

    /**
     * `resolved_at`/`resolved_by`/`resolution_note` are deliberately ABSENT: they are written only by
     * {@see SuperAdminService::transitionFeedback()} — the platform support team's
     * transition, never a tenant-side submit — so they are set with `forceFill()` and can never arrive
     * through mass assignment from a request payload. `reporter_role` (M175) is likewise server-written, by
     * {@see FeedbackService::submit()}, from the reporter's live role. The M179 columns — `source`,
     * `source_label`, `reference`, `title`, `confirmed_*`, `last_comment_at` — are absent for the same reason:
     * the relay and the feedback lifecycle write them with `forceFill()`, and no request may set them.
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'route',
        'page_url',
        'page_title',
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
            'type' => FeedbackType::class,
            'browser_info' => 'array',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'source' => FeedbackSource::class,
            'confirmed_at' => 'datetime',
            'last_comment_at' => 'datetime',
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
     * The report's thread, oldest first (M179, `D151`).
     *
     * @return HasMany<FeedbackComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(FeedbackComment::class, 'feedback_report_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * The tester who pressed **It works** (M179, `D151`).
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
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
