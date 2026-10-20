<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\FeedbackSource;
use App\Models\FeedbackReport;
use App\Models\User;

/**
 * Who may take part in a feedback report's thread (M179, `R-129c44c3`, `D150`, `D151`) — the first policy on
 * this model; until M179 a report was only ever written by its reporter and moved by the support console.
 *
 * Taking part means: comment, and on a fixed report say **It works** or **Still a problem**. It follows who may
 * SEE the report, and nothing wider:
 *
 * - the **reporter**, on their own report;
 * - **any member**, on a RELAYED report — every member of the workspace sees those under Testing-round comments,
 *   because the point of relaying a comment is that the testers can follow it and check the fix (`D150`);
 * - **Owner/Admin** (`feedback.view`), on any report in their workspace — they already read them all at
 *   `/feedback`.
 *
 * A colleague's report sent with the Feedback button stays theirs. The workspace boundary is not this class's
 * job: the route binds the report under the request's tenant context, so another workspace's report is a 404
 * before any policy runs. No permission is minted — every role holds `feedback.submit`, which the routes require.
 */
final class FeedbackReportPolicy
{
    public function participate(User $user, FeedbackReport $report): bool
    {
        return (string) $report->user_id === (string) $user->getKey()
            || $report->source === FeedbackSource::Relayed
            || $user->can('feedback.view');
    }
}
