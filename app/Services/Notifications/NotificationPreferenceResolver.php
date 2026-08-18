<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * "Which channels does each of these people want this type on?" — the read side of data-dictionary §23's
 * **absence means default** rule (Increment I3).
 *
 * The table is sparse by design: a row exists only where someone diverged. So this never iterates the
 * table to build an answer — it asks for the overrides that exist, in one query, and fills everything else
 * from {@see NotificationType}'s defaults. That is what lets the platform change a default without a
 * backfill across every member of every tenant.
 */
final class NotificationPreferenceResolver
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, array{in_app: bool, email: bool}> keyed by user id; EVERY id is present
     */
    public function resolve(NotificationType $type, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // RLS supplies the tenant predicate. One query for the whole set, never one per recipient — this
        // runs on the synchronous submit path.
        $overrides = NotificationPreference::query()
            ->where('notification_type', $type->value)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'in_app_enabled', 'email_enabled'])
            ->keyBy('user_id');

        $resolved = [];

        foreach ($userIds as $userId) {
            $resolved[$userId] = $this->channels($type, $overrides->get($userId));
        }

        return $resolved;
    }

    /**
     * "Which channels does THIS person want, on every type?" — the read model behind I4's preferences card.
     *
     * The mirror image of {@see self::resolve()}: that answers ONE type across MANY people (the synchronous
     * dispatch path), this answers ALL SEVEN types for ONE person (the settings page). Both are one query,
     * and both fill the gaps from {@see NotificationType} rather than from the table — which is the whole
     * point of §23's sparseness, and what lets the platform change a default without a backfill across
     * every member of every tenant.
     *
     * @return array<string, array{in_app: bool, email: bool}> keyed by NotificationType->value; EVERY case present
     */
    public function resolveAll(User $user): array
    {
        $overrides = NotificationPreference::query()
            ->forUser($user)
            ->get(['notification_type', 'in_app_enabled', 'email_enabled'])
            ->keyBy(fn (NotificationPreference $row): string => $row->notification_type->value);

        $resolved = [];

        foreach (NotificationType::cases() as $type) {
            $resolved[$type->value] = $this->channels($type, $overrides->get($type->value));
        }

        return $resolved;
    }

    /**
     * The per-row channel decision, in ONE place because both read paths need it and the locked-type rule
     * below must not come to exist twice.
     *
     * @return array{in_app: bool, email: bool}
     */
    private function channels(NotificationType $type, ?NotificationPreference $row): array
    {
        return [
            'in_app' => $row instanceof NotificationPreference
                ? $row->in_app_enabled
                : $type->defaultInApp(),
            // A type whose email is owned by the emitting site ignores the stored value entirely; the
            // preference card renders that toggle as unavailable rather than lying about it.
            'email' => $type->honorsEmailPreference()
                ? ($row instanceof NotificationPreference ? $row->email_enabled : $type->defaultEmail())
                : $type->defaultEmail(),
        ];
    }

    /**
     * Write one person's override (I4's preferences card).
     *
     * BOTH booleans are always written explicitly. The columns default to `true`/`true`, which disagrees
     * with {@see NotificationType::SubmissionReceived}'s email default of `false` — so a partial write that
     * leaned on the column default would silently opt a user INTO the one type the PRD says to keep quiet.
     */
    public function set(User $user, NotificationType $type, bool $inApp, bool $email): NotificationPreference
    {
        return NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'notification_type' => $type->value],
            ['in_app_enabled' => $inApp, 'email_enabled' => $email],
        );
    }
}
