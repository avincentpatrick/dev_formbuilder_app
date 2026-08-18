<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 *
 * `tenant_id` is auto-filled by BelongsToTenant's creating hook; `user_id` is the caller's.
 *
 * Every state here writes BOTH channels explicitly. The table's column defaults are `true`/`true`, which
 * disagrees with {@see NotificationType::SubmissionReceived}'s email default of `false` — a factory that
 * leaned on the column default would mint a row meaning "email me every submission" while looking like it
 * meant "leave me on the default".
 */
class NotificationPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_type' => NotificationType::SubmissionReceived,
            'in_app_enabled' => true,
            'email_enabled' => true,
        ];
    }

    public function ofType(NotificationType $type): static
    {
        return $this->state(fn (): array => ['notification_type' => $type]);
    }

    /** An explicit opt-out of both channels — the "stop telling me about this" row. */
    public function silenced(): static
    {
        return $this->state(fn (): array => [
            'in_app_enabled' => false,
            'email_enabled' => false,
        ]);
    }

    public function inAppOnly(): static
    {
        return $this->state(fn (): array => [
            'in_app_enabled' => true,
            'email_enabled' => false,
        ]);
    }
}
