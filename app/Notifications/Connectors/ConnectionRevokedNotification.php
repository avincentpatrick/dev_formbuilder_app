<?php

declare(strict_types=1);

namespace App\Notifications\Connectors;

use App\Enums\QueueName;
use App\Jobs\Connectors\DeliverConnectorMessageJob;
use App\Notifications\Webhooks\WebhookAutoDisabledNotification;
use App\Services\Connectors\ConnectionTokenRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * Tells a tenant owner that a native connection stopped working and needs a human to re-connect (ADR-0009
 * §D6/§D7, H15a). Dispatched once, on the edge, from either side of "the grant is dead":
 * {@see DeliverConnectorMessageJob} when a provider rejects the credential mid-delivery, or
 * {@see ConnectionTokenRefresher} when a refresh is refused.
 *
 * Unlike a paused webhook — which the tenant fixes by fixing their own receiver — a dead grant can ONLY be
 * fixed by re-running the OAuth flow, so this mail is the only signal a tenant gets before their
 * integrations go quiet.
 *
 * QUEUED ON THE ASYNC SUBSTRATE (H3), scalar-only and tenant-less on the worker, sent to an on-demand
 * notifiable — the {@see WebhookAutoDisabledNotification} pattern — so no Eloquent model is ever serialized
 * under a NULL GUC. Listed in scripts/job-payload-lint.php EXEMPT_JOBS for the same reason. It carries no
 * token and no error body: the provider's error code is on the connection row, not in an email.
 */
#[Queue(QueueName::Mail)]
final class ConnectionRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $providerLabel,
        public readonly string $accountLabel,
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
            ->subject("Your {$this->providerLabel} connection needs attention")
            ->line("We can no longer post to {$this->accountLabel}: {$this->providerLabel} rejected our access, which usually means the app was removed or its permissions were revoked there.")
            ->line('Everything sending to that workspace has been paused, so no messages are being lost in the background.')
            ->line("To start them again, reconnect {$this->providerLabel} from your integration settings — your existing delivery rules are kept and will resume as soon as the connection is live.");
    }
}
