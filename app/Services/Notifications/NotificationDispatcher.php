<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Support\Carbon;

/**
 * The single write path into `notifications` (Increment I3, data-dictionary §22's design note: raised from
 * post-commit domain events, "never a separate in-controller write path").
 *
 * ── Two entry points, because two things are genuinely different ────────────────────────────────────────
 * {@see self::dispatch()} owns the whole delivery: resolve each recipient's preference, write the in-app
 * rows, queue the email copy, stamp `emailed_at`. {@see self::record()} writes an in-app row for a type
 * whose email was already sent by the site that raised it — `export_ready` and `webhook_failed`, whose
 * purpose-built branded emails predate this table. That is a stamp, not a second send; collapsing the two
 * into one method with a flag would put "did I already email this?" into every caller's head.
 *
 * ── One row, two channels — so `emailed_at` is not a complete send log ──────────────────────────────────
 * §22 models an emailed notification as the same row with `emailed_at` set, not a second row. A recipient
 * who has turned the bell OFF for a type but left email ON therefore gets the email and NO row, and nothing
 * in this table records it. That is inherent to the one-row model rather than a gap here, and it is the
 * reason nothing should ever count `emailed_at` to answer "how many emails went out".
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationPreferenceResolver $preferences,
        private readonly NotificationMailer $mailer,
    ) {}

    /**
     * @param  list<string>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function dispatch(NotificationType $type, array $userIds, array $data): void
    {
        // The clean no-op every caller relies on: a guest submission has no respondent to tell, and an
        // empty recipient set must cost zero queries rather than a `whereIn ()`.
        if ($userIds === []) {
            return;
        }

        $resolved = $this->preferences->resolve($type, $userIds);

        $rows = [];
        $toMail = [];

        foreach ($userIds as $userId) {
            $channels = $resolved[$userId] ?? ['in_app' => $type->defaultInApp(), 'email' => $type->defaultEmail()];

            if ($channels['in_app']) {
                // `tenant_id` is filled by BelongsToTenant's creating hook from the live context.
                $rows[$userId] = Notification::query()->create([
                    'user_id' => $userId,
                    'type' => $type,
                    'data' => $data,
                ]);
            }

            if ($channels['email']) {
                $toMail[] = $userId;
            }
        }

        $mailed = $this->mailer->send($type, $toMail, $data);
        $stamped = array_values(array_intersect($mailed, array_keys($rows)));

        if ($stamped !== []) {
            Notification::query()
                ->whereIn('id', array_map(static fn (string $id): string => (string) $rows[$id]->getKey(), $stamped))
                ->update(['emailed_at' => Carbon::now()]);
        }
    }

    /**
     * Write an in-app row for a notification whose email the CALLER already sent.
     *
     * Still honours `in_app_enabled`: the recipient can silence the bell for this type without silencing
     * the operational email, which is the split {@see NotificationType::honorsEmailPreference()} describes.
     * `$emailed` is false when the caller could not resolve an address — the row is still written, because
     * the in-app record is the durable half and losing it too would leave no trace at all.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(NotificationType $type, string $userId, array $data, bool $emailed): void
    {
        $channels = $this->preferences->resolve($type, [$userId]);

        if (($channels[$userId]['in_app'] ?? true) === false) {
            return;
        }

        Notification::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'data' => $data,
            'emailed_at' => $emailed ? Carbon::now() : null,
        ]);
    }
}
