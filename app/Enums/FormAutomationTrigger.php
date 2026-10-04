<?php

declare(strict_types=1);

namespace App\Enums;

use App\Events\SubmissionCreated;

/**
 * When a form automation fires (M132, `R-b7bc5149`). v1 has one trigger: a response is submitted — the
 * {@see SubmissionCreated} event, raised once after commit for every accepted response, whatever the channel (guest,
 * staff encoding, offline sync, a scanned paper form, a promoted draft). The form-abandoned trigger is the
 * `during-testing` remainder row's.
 *
 * The value is the domain event's own name, so the catalogue reads the same in both places. Pinned in the database by
 * `form_automations_trigger_check`, generated from {@see values()}.
 */
enum FormAutomationTrigger: string
{
    case SubmissionCreated = 'submission.created';

    public function label(): string
    {
        return match ($this) {
            self::SubmissionCreated => 'When a response is submitted',
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
