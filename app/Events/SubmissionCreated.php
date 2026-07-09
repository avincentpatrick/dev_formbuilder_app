<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Submission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised once a submission has been persisted and its transaction has COMMITTED (technical-architecture.md
 * §4.1 — events are dispatched post-commit only, never inside the transaction). A forward hook for
 * later increments (review-queue notifications, webhooks, dashboards); there are no listeners in Phase 1,
 * so dispatch is a harmless no-op today. Not fired on an idempotent replay no-op (nothing new was created).
 */
final class SubmissionCreated
{
    use Dispatchable;

    public function __construct(public readonly Submission $submission) {}
}
