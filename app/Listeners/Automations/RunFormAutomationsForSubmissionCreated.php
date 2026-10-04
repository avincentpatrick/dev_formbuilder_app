<?php

declare(strict_types=1);

namespace App\Listeners\Automations;

use App\Events\SubmissionCreated;
use App\Services\Automations\FormAutomationDispatcher;
use Throwable;

/**
 * A form's automations fire when a response is submitted (M132, `R-b7bc5149`) — guest, staff encoding, offline sync,
 * a scanned paper form or a promoted draft, because {@see SubmissionCreated} is raised once, after commit, for all of
 * them. Auto-discovered, synchronous, and all it does is create run rows and queue jobs (`D62` = A).
 *
 * ⛔ IT NEVER LETS A FAILURE REACH THE RESPONDENT. It runs after the response is stored, inside the request that
 * stored it, so a throw here would answer a respondent 500 for a response that exists — and an offline device would
 * then replay it. So a failure is reported and swallowed; `TenantContext::runFor()`'s savepoint has already put the
 * database back. That is `D62`'s own reason for the queue: an author's automation is never the respondent's outage.
 */
final class RunFormAutomationsForSubmissionCreated
{
    public function __construct(private readonly FormAutomationDispatcher $dispatcher) {}

    public function handle(SubmissionCreated $event): void
    {
        try {
            $this->dispatcher->dispatchFor($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
