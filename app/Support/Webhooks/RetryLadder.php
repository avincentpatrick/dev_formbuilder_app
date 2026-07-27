<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Jobs\Webhooks\DeliverWebhookJob;
use Illuminate\Support\Carbon;

/**
 * The one place that turns "attempt N failed" into "try again at T, or give up" (H13a's ladder, extracted in
 * H15a so both outbound channels share it rather than drifting apart).
 *
 * The ladder lives in `config('webhooks.retry_ladder')` as MINUTES between attempts and is driven by the
 * ledger's `next_retry_at` column swept by `SweepWebhookRetriesJob` — deliberately NOT via job
 * `$tries`/`backoff()` (webhook-integration-design.md §1): a destination being down for hours is an expected
 * business condition warranting a ~7-day ladder, whereas a job failing three times is a defect.
 *
 * Both {@see DeliverWebhookJob} and {@see DeliverConnectorMessageJob} record failures through this, so a
 * connector delivery and a webhook delivery cannot end up on different schedules — which matters more than
 * usual here, because they share one table and one retry sweep.
 */
final class RetryLadder
{
    /**
     * When the next attempt is due, or null when the delivery is exhausted (the caller dead-letters it).
     *
     * `$attempt` is the 1-based number of the attempt that just failed; the delay is read at `[$attempt - 1]`,
     * so the ladder's first entry is the gap after the first failure. A ladder shorter than `$maxAttempts`
     * also ends the run — the config is the authority on how long to keep trying, not the counter.
     */
    public static function nextRetryAt(int $attempt, int $maxAttempts): ?Carbon
    {
        /** @var list<int> $ladder */
        $ladder = config('webhooks.retry_ladder', []);

        if ($attempt >= $maxAttempts || ! isset($ladder[$attempt - 1])) {
            return null;
        }

        return Carbon::now()->addMinutes($ladder[$attempt - 1]);
    }
}
