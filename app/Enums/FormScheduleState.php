<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Forms\FormScheduleSweeper;
use App\Services\Submissions\FormAcceptanceGuard;

/**
 * The persisted lifecycle position of a scheduled form's OPEN/CLOSE WINDOW (Increment H12a). It exists for a
 * single purpose: to make the `form.opened`/`form.closed` domain events fire EXACTLY ONCE. The state-flip
 * sweep ({@see FormScheduleSweeper}) advances it monotonically `scheduled → open → closed` and emits the
 * matching event only on the transition; a repeat tick finds the state already advanced and does nothing.
 *
 * It is NOT read by submission enforcement — {@see FormAcceptanceGuard} computes
 * acceptance LIVE from `opens_at`/`closes_at`/`now()` (and the response cap), so a submission is refused the
 * instant the window closes, never lagging the ≤5-minute sweep. `schedule_state` is null for a form with no
 * schedule (neither time set), which the sweep skips. It tracks only the TIME window: hitting `max_responses`
 * does not advance it and does not emit `form.closed` in H12a.
 */
enum FormScheduleState: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
