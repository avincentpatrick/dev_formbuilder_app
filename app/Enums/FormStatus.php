<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Top-level form lifecycle (data-dictionary §2). `draft` = never published; `published` = has an
 * active published version and may be accepting responses; `archived` = the owner retired the form
 * regardless of version state. Distinct from {@see FormVersionStatus}, which tracks each individual
 * version's own state — a form can be `published` while its current draft is mid-edit.
 */
enum FormStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
