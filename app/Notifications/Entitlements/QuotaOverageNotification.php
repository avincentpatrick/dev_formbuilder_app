<?php

declare(strict_types=1);

namespace App\Notifications\Entitlements;

use App\Enums\QueueName;
use App\Jobs\Entitlements\ReconcileTenantUsageJob;
use App\Notifications\Concerns\CarriesTenantBrand;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * The never-block UPSELL (H5b / ADR-0008 §D4). When a tenant's monthly `submissions_count` crosses its plan
 * quota, its forms keep collecting responses — a respondent's completed submission is NEVER rejected over
 * the tenant's billing status — and this heads-up email invites an upgrade instead of gating anything.
 * Dispatched once, when the reconcile first observes the crossing, by {@see ReconcileTenantUsageJob}.
 *
 * QUEUED ON THE ASYNC SUBSTRATE (H3), scalar-only and tenant-less on the worker, sent to an on-demand
 * notifiable (`Notification::route('mail', $email)`) — the H3 {@see TenantInvitationNotification}
 * pattern — so no Eloquent model is ever serialized under a NULL GUC. Listed in scripts/job-payload-lint.php
 * EXEMPT_JOBS for the same reason: a framework queued notification that rides the substrate without
 * extending TenantAwareJob (R2's #[Queue] and R3's scalar payload still apply and are satisfied here).
 */
#[Queue(QueueName::Mail)]
final class QuotaOverageNotification extends Notification implements ShouldQueue
{
    use CarriesTenantBrand;
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly int $used,
        public readonly int $limit,
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
        return $this->branded(
            (new MailMessage)
                ->subject("You've reached your {$this->tenantName} submission limit")
                ->line("Your workspace {$this->tenantName} has collected {$this->used} submissions this period, above your plan's limit of {$this->limit}.")
                ->line('Your forms are still open and every response is being saved — we never turn a respondent away over a billing limit.')
                ->line('Upgrade your plan to lift the limit and keep full reporting on the extra responses.')
        );
    }
}
