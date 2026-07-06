<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * In-app feedback triage state (PRD Feature #11 / data-dictionary §21). The tenant-side submit path
 * (Increment C3) only ever creates rows as `new`; the reviewed/resolved/wont_fix transitions belong to
 * the platform support console (Phase 1).
 */
enum FeedbackStatus: string
{
    case New = 'new';
    case Reviewed = 'reviewed';
    case Resolved = 'resolved';
    case WontFix = 'wont_fix';
}
