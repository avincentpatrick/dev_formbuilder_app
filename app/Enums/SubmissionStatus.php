<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Submission review lifecycle (data-dictionary §7). `draft` = started but not yet finalized (the
 * Phase-1 in-session save path and the resting state of a new row); `submitted` = the respondent
 * finalized it and it entered the review queue; `under_review`/`approved`/`returned` are the reviewer
 * transitions (renaming legacy's "Pending Validation" to `under_review`); `archived` is a terminal
 * retention state. Every channel funnels into one `SubmissionPipeline`, so this enum is source-agnostic.
 */
enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Returned = 'returned';
    case Archived = 'archived';

    /** Human label for the inbox filter, detail header, and export metadata column (single source). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Returned => 'Returned',
            self::Archived => 'Archived',
        };
    }
}
