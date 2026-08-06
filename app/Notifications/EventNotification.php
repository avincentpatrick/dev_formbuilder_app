<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Enums\QueueName;
use App\Notifications\Concerns\CarriesTenantBrand;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;
use Tests\Feature\Mail\QueuedMailContractTest;

/**
 * The email arm of the notification substrate (Increment I3) — ONE class for the whole catalog, not one per
 * {@see NotificationType}.
 *
 * Every sibling in this namespace is bespoke because each reports a differently-shaped thing (a quota
 * overage, a revoked OAuth grant, a PDF outcome). These are the opposite: seven variants of "something
 * happened in your workspace, here is the link", and seven near-identical classes would be seven places to
 * forget `withBrand()`, seven entries in `QueuedMailContractTest`, and seven exemptions in
 * `scripts/job-payload-lint.php`. The words come from {@see NotificationCopy}, which is pure and
 * unit-tested; this class only carries them.
 *
 * **`via()` returns `['mail']` and must never return `'database'`** — the in-app half is
 * {@see \App\Models\Notification}, written by {@see NotificationDispatcher};
 * the framework's database channel expects a `notifiable_type`/`notifiable_id` shape our `notifications`
 * table does not have. `NotificationTableGuardTest` pins that.
 *
 * The payload is one backed enum plus four builtins — `job-payload-lint` R3 admits both (the
 * `SubmissionPdfOutcome` precedent) — and `$brand` rides in from {@see CarriesTenantBrand}, resolved at
 * DISPATCH because a worker has no tenant context to read a palette from.
 */
#[Queue(QueueName::Mail)]
final class EventNotification extends Notification implements ShouldQueue
{
    use CarriesTenantBrand;
    use Queueable;

    public function __construct(
        public readonly NotificationType $type,
        public readonly string $tenantName,
        public readonly string $headline,
        public readonly string $body,
        /** Absolute, pre-built on the tenant's APP host — a worker cannot resolve one. */
        public readonly ?string $actionUrl = null,
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
        $mail = (new MailMessage)
            ->subject($this->type->label().' — '.$this->tenantName)
            ->line($this->headline)
            ->line($this->body);

        if ($this->actionUrl !== null) {
            $mail->action($this->type->actionLabel(), $this->actionUrl);
        }

        return $this->branded($mail);
    }
}
