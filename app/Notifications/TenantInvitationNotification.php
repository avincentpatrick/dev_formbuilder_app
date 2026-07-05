<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a tenant invitation's accept link (the plaintext token, which is only ever hashed at rest).
 * Minimal by design — the styled invitation email/landing experience lands with the design system in
 * Increment C; B2b proves the lifecycle end-to-end.
 */
final class TenantInvitationNotification extends Notification
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $plainToken,
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
            ->subject("You've been invited to {$this->tenant->name}")
            ->line("You've been invited to join {$this->tenant->name}.")
            ->action('Accept invitation', $this->acceptUrl())
            ->line('This invitation will expire in 7 days.');
    }

    private function acceptUrl(): string
    {
        // Invitations are accepted on the tenant's own subdomain (which is what establishes tenant
        // context so the invite row is even visible under RLS). Fall back to the slug if no domain row.
        $domain = $this->tenant->domains()->value('domain') ?? $this->tenant->slug;

        return "https://{$domain}/invitations/{$this->plainToken}";
    }
}
