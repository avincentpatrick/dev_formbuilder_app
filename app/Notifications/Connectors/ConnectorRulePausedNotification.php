<?php

declare(strict_types=1);

namespace App\Notifications\Connectors;

use App\Enums\QueueName;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Support\Mapping\MappingDrift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * Tells a tenant owner that ONE delivery rule was paused because its destination stopped matching the rule
 * (H16a) — a spreadsheet whose columns changed, or one this connection can no longer reach. Dispatched once,
 * on the edge, from {@see DeliverConnectorMessageJob}'s `blocked` arm.
 *
 * DELIBERATELY NOT {@see ConnectionRevokedNotification}, though the delivery shape is identical. That mail's
 * advice is "reconnect your account", which is wrong here and expensively so: the OAuth grant is perfectly
 * healthy, every other rule on it is still delivering, and a tenant who follows that advice re-runs a consent
 * flow, changes nothing, and still has a paused rule. The two conditions differ in what a human must DO, which
 * is the only thing a notification is for.
 *
 * `$reason` is OUR text — a {@see MappingDrift::summary()} line or our own copy — never a
 * provider error string echoed onward. It reaches an inbox, and unreviewed third-party text does not belong
 * there; it is also why the drift summary bounds the header names it quotes.
 *
 * QUEUED ON THE ASYNC SUBSTRATE (H3), scalar-only and tenant-less on the worker, sent to an on-demand
 * notifiable — the {@see ConnectionRevokedNotification} / `WebhookAutoDisabledNotification` pattern — so no
 * Eloquent model is ever serialized under a NULL GUC, and it is listed in `scripts/job-payload-lint.php`
 * EXEMPT_JOBS for that reason.
 */
#[Queue(QueueName::Mail)]
final class ConnectorRulePausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $ruleName,
        public readonly string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("A delivery rule was paused: {$this->ruleName}")
            ->line("We paused “{$this->ruleName}” because its destination no longer matches how the rule was set up.")
            ->line($this->reason)
            ->line('We stopped rather than guessing, because writing to a table whose columns have moved would put the wrong answers under the wrong headings — and that is much harder to notice, and to undo, than a pause.')
            ->line('Your connection itself is fine and your other rules are still running. Open the rule, confirm which column each field should go to, and switch it back on.');
    }
}
