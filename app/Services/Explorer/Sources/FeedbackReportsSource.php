<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\FeedbackSource as FeedbackOrigin;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\FeedbackReport;
use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Feedback reports filed from inside the workspace, one row each (ADR-0025 §D5). Gated on `feedback.view`,
 * as `/feedback` is. The report's text and screenshot are not fields.
 */
final class FeedbackReportsSource extends AbstractExplorerSource
{
    public function key(): string
    {
        return 'feedback';
    }

    public function label(): string
    {
        return 'Feedback';
    }

    public function description(): string
    {
        return 'Feedback reports from this workspace: their kind, status and the role of who filed them.';
    }

    public function rowNoun(): string
    {
        return 'reports';
    }

    public function allows(User $user): bool
    {
        return $user->can('feedback.view');
    }

    public function rangeField(): string
    {
        return 'submitted_at';
    }

    public function fields(User $user, ?Form $form): array
    {
        return [
            $this->enumField('type', 'Kind', 'feedback_reports.type', FeedbackType::class, 'Report'),
            $this->enumField('status', 'Status', 'feedback_reports.status', FeedbackStatus::class, 'Report'),
            $this->enumField('origin', 'Filed through', 'feedback_reports.source', FeedbackOrigin::class, 'Report'),
            $this->category('reporter_role', 'Reporter role', 'feedback_reports.reporter_role', 'Report', headline: true),
            $this->time('submitted_at', 'Filed', 'feedback_reports.submitted_at', 'Dates'),
            $this->time('resolved_at', 'Resolved', 'feedback_reports.resolved_at', 'Dates'),
        ];
    }

    public function query(User $user, ?Form $form): Builder
    {
        return FeedbackReport::query()->toBase();
    }
}
