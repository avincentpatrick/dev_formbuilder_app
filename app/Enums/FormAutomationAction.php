<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a form automation does when its trigger fires (M132, `R-b7bc5149`). v1 carries two actions; Slack, Delay,
 * Filter and Branch are the `during-testing` remainder row's.
 *
 * - `email` — a notice and a link (`D82`): the form, the reference number and the time, with a link that opens the
 *   response for members who may see it. Never an answer.
 * - `webhook` — the answers, keyed by question, POSTed to a web address and signed (`D83`). Configured only by someone
 *   holding `webhooks.manage`, on a plan that includes `webhooks`.
 *
 * ⛔ An automation runs ACTIONS, never "steps": `docs/workflow-branching-design.md` §2 says the respondent's step is
 * a projection with no table, and the word reused here would read as that invariant broken.
 *
 * The values are pinned in the database by `form_automations_action_check`, generated from {@see values()}.
 */
enum FormAutomationAction: string
{
    case Email = 'email';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Send an email',
            self::Webhook => 'Send to a web address',
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
