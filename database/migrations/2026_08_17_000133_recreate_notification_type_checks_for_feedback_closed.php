<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen BOTH notification type CHECKs for `feedback_closed` — M175 (`R-9bfd832e`, `D139`): a reporter is told
 * when their feedback is resolved or declined.
 *
 * The shape of `2026_08_17_000103_recreate_notification_type_checks_for_badge_earned.php` and its two
 * predecessors, for their reasons: both `notifications.type` and `notification_preferences.notification_type`
 * carry a CHECK generated from {@see NotificationType::values()}, and widening only the first fails later and
 * stranger (a `23514` the first time the reporter turns the new type off on their preferences card).
 *
 * ⚠️ NO TEST CAN SEE THIS FILE: `migrate:fresh` builds both constraints from `values()` at run time, so a fresh
 * database admits the new type with or without it. It exists for databases already migrated — the dev stacks
 * and the testing server — and is verified by review.
 *
 * ⛔ ADDITIVE IN EFFECT (`D137`): each constraint is dropped and re-added WIDER inside the migration's one
 * transaction; no row is touched and every existing value stays admitted. `down()` narrows, and fails if a
 * `feedback_closed` row exists — deliberately, as the precedent explains.
 */
return new class extends Migration
{
    /** table => column carrying the type value. */
    private const CONSTRAINED = [
        'notifications' => 'type',
        'notification_preferences' => 'notification_type',
    ];

    /**
     * The vocabulary as it stood before M175 — the ten cases K1b left behind. Spelled out rather than derived:
     * `down()` must restore what was there before this migration, and `values()` now answers a different
     * question. A future case gets a NEW migration with its own frozen list.
     */
    private const PRE_M175_TYPES = [
        'submission_received', 'submission_returned', 'submission_approved', 'review_requested',
        'export_ready', 'member_invited', 'webhook_failed', 'impersonation_started', 'member_joined',
        'badge_earned',
    ];

    public function up(): void
    {
        $this->recreate(NotificationType::values());
    }

    public function down(): void
    {
        $this->recreate(self::PRE_M175_TYPES);
    }

    /**
     * @param  list<string>  $types
     */
    private function recreate(array $types): void
    {
        $list = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $types));

        foreach (self::CONSTRAINED as $table => $column) {
            $constraint = $table.'_type_check';
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$list}))");
        }
    }
};
