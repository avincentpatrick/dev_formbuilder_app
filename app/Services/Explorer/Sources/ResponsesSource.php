<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Every response across the forms a reader can reach (ADR-0025 §D5).
 *
 * The explorer's widest source and the one `/analytics` already reads, so it keeps that page's two rules:
 * ADR-0011 §D2's countable predicate — not a draft, not deleted — through the same model scope, and the
 * same form reach. What it adds is that any of its fields can be a group-by, a second group-by or a filter.
 */
final class ResponsesSource extends AbstractExplorerSource
{
    public function __construct(private readonly ExplorerFormReach $reach) {}

    public function key(): string
    {
        return 'responses';
    }

    public function label(): string
    {
        return 'Responses';
    }

    public function description(): string
    {
        return 'Every response to every form you can open: which form, how it arrived, where it is in review.';
    }

    public function rowNoun(): string
    {
        return 'responses';
    }

    public function allows(User $user): bool
    {
        return true;
    }

    public function rangeField(): string
    {
        return 'submitted_at';
    }

    public function fields(User $user, ?Form $form): array
    {
        return [
            $this->category('form', 'Form', 'submissions.form_id', 'Response', lookup: 'forms'),
            $this->enumField('status', 'Status', 'submissions.status', SubmissionStatus::class, 'Response'),
            $this->enumField('source', 'Channel', 'submissions.source', SubmissionSource::class, 'Response'),
            $this->category('locale', 'Language', 'submissions.locale', 'Response'),
            $this->category('reviewed_by', 'Reviewed by', 'submissions.validated_by', 'Response', lookup: 'users'),
            $this->time('submitted_at', 'Submitted', 'submissions.submitted_at', 'Dates'),
            $this->time('reviewed_at', 'Reviewed', 'submissions.validated_at', 'Dates'),
            $this->number('completeness', 'Completeness (%)', 'submissions.completeness_percent', 'Numbers'),
        ];
    }

    public function query(User $user, ?Form $form): Builder
    {
        $query = Submission::query()->countable();

        if (! $this->reach->orgWide($user)) {
            $query->whereIn('submissions.form_id', $this->reach->formIds($user));
        }

        return $query->toBase();
    }
}
