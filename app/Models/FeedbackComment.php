<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackCommentAction;
use App\Enums\FeedbackParty;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a feedback report's thread (M179, `R-129c44c3`, `D151`; data-dictionary §21a). Strictly
 * tenant-scoped, like its report; the platform support console reads every workspace's threads through a
 * SELECT-only carve-out and writes its replies by adopting the report's tenant context.
 *
 * Never edited, so there is no `updated_at`; `created_at` is set here rather than by a database default.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $feedback_report_id
 * @property string|null $user_id the member who wrote it; NULL on a support reply
 * @property FeedbackParty $author_kind
 * @property FeedbackCommentAction|null $action set on the comment a tester's It works / Still a problem wrote
 * @property string|null $body
 * @property string|null $screenshot_attachment_id
 * @property Carbon $created_at
 */
class FeedbackComment extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    public const UPDATED_AT = null;

    /**
     * Nothing is mass-assignable: every comment is written by the feedback lifecycle with `forceFill()`, which
     * decides the author, the side and the action itself — none of them may arrive from a request payload.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_kind' => FeedbackParty::class,
            'action' => FeedbackCommentAction::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FeedbackReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(FeedbackReport::class, 'feedback_report_id');
    }

    /**
     * The member who wrote it — NULL on a support reply, and once an erased account's row is gone.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The screenshot attached to it, if any — a `FeedbackScreenshot` attachment owned by the REPORT, so
     * ADR-0015 §D9's rule (read under `feedback.view` on every route, or none) covers it unchanged.
     *
     * @return BelongsTo<Attachment, $this>
     */
    public function screenshot(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'screenshot_attachment_id');
    }
}
