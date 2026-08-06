<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Notification>
 *
 * `tenant_id` is auto-filled by BelongsToTenant's creating hook, so the active TenantContext decides
 * ownership; `user_id` must be supplied by the caller, because a notification with no recipient is not a
 * thing this table models. Default state is an unread, never-emailed submission notice.
 */
class NotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => NotificationType::SubmissionReceived,
            'data' => [
                'submission_id' => fake()->uuid(),
                'form_id' => fake()->uuid(),
                'form_title' => fake()->words(3, true),
            ],
            'read_at' => null,
            'emailed_at' => null,
        ];
    }

    public function ofType(NotificationType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => Carbon::now()]);
    }

    public function emailed(): static
    {
        return $this->state(fn (): array => ['emailed_at' => Carbon::now()]);
    }
}
