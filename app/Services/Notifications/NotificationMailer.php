<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Support\Branding\BrandPalette;
use App\Support\Notifications\NotificationCopy;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Support\Facades\Notification;

/**
 * The email arm of {@see NotificationDispatcher} (Increment I3), extracted so exactly one file in the
 * application imports `Illuminate\Support\Facades\Notification` alongside notification code — the dispatcher
 * imports {@see \App\Models\Notification}, and neither has to alias the other.
 *
 * Everything that needs live tenant context is resolved HERE, in-request, and handed to the queued
 * notification as scalars (ADR-0007 §D5):
 *
 *   - the recipient's address — readable because `TenantIsolation::usersVisibilitySql()` makes any ACTIVE
 *     member of the current tenant visible even with a NULL user GUC, which is what lets this work from
 *     the guest-submit path where nobody is authenticated;
 *   - the absolute URL, on the tenant's APP host (`TenantUrl::to()`, never `toPublic()`) — every
 *     destination in this catalog is behind auth, and a custom-domain link would bounce the recipient to
 *     the central app;
 *   - the brand palette, because a queued `toMail()` runs with no tenant GUC and would render every
 *     tenant unbranded, silently, with a green test suite (H23a4).
 */
final class NotificationMailer
{
    /**
     * @param  list<string>  $userIds
     * @param  array<string, mixed>  $data
     * @return list<string> the ids actually mailed — a member with no address is skipped, not failed
     */
    public function send(NotificationType $type, array $userIds, array $data): array
    {
        if ($userIds === []) {
            return [];
        }

        $tenant = Tenant::query()->whereKey(TenantContext::currentTenantId())->first(); // RLS-exempt central table

        if (! $tenant instanceof Tenant) {
            return [];
        }

        $copy = NotificationCopy::for($type, $tenant->name, $data);
        $path = $type->pathFor($data);
        $url = $path === null ? null : TenantUrl::to($tenant, $path);
        $brand = BrandPalette::forTenant($tenant);

        $mailed = [];

        foreach (User::query()->whereIn('id', $userIds)->get(['id', 'email']) as $recipient) {
            $email = $recipient->getAttribute('email');

            if (! is_string($email) || $email === '') {
                continue;
            }

            Notification::route('mail', $email)->notify(
                (new EventNotification($type, $tenant->name, $copy['headline'], $copy['body'], $url))
                    ->withBrand($brand)
            );

            $mailed[] = (string) $recipient->getKey();
        }

        return $mailed;
    }
}
