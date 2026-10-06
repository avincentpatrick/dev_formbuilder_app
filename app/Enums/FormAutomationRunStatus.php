<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one automation's run for one response stands (M132, `R-b7bc5149`).
 *
 * - `pending` — recorded, queued, not attempted yet.
 * - `retrying` — a web address failed and the next attempt is scheduled (`RetryLadder`).
 * - `succeeded` — a web address answered 2xx, or the emails were handed to the mail queue.
 * - `failed` — every attempt failed; nothing more will be tried.
 * - `skipped` — deliberately not run: the plan no longer includes webhooks, the month's delivery quota is spent, the
 *   automation was switched off before its turn came, or (M142) the response did not match the automation's condition
 *   (`condition_not_met`) or the condition could not be read for it (`condition_error`).
 *
 * Pinned in the database by `form_automation_runs_status_check`, generated from {@see values()}.
 */
enum FormAutomationRunStatus: string
{
    case Pending = 'pending';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Retrying => 'Retrying',
            self::Succeeded => 'Sent',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
