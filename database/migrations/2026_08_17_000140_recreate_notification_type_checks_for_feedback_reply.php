<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen BOTH notification type CHECKs for `feedback_reply` — M179 (`R-129c44c3`, `D151`): the support team's
 * reply in a feedback report's thread tells the reporter and everyone who wrote in it.
 *
 * The shape of `2026_08_17_000133_recreate_notification_type_checks_for_feedback_closed.php` and its precedents,
 * for their reasons: both `notifications.type` and `notification_preferences.notification_type` carry a CHECK
 * generated from {@see NotificationType::values()}, and widening only the first fails later and stranger.
 *
 * ⚠️ A FRESH DATABASE NEVER NEEDS THIS FILE — `migrate:fresh` builds both constraints from `values()` at run
 * time. It exists for databases already migrated (the dev stacks and the testing server), and unlike its
 * precedent it is not left to review: `FeedbackCheckWideningTest` rebuilds the narrow constraints and runs it.
 *
 * ⛔ ADDITIVE IN EFFECT (`D137`): each constraint is dropped and re-added WIDER inside the migration's one
 * transaction; no row is touched. `down()` narrows, and fails if a `feedback_reply` row exists — deliberately,
 * as the precedent explains.
 */
return new class extends Migration
{
    /** table => column carrying the type value. */
    private const CONSTRAINED = [
        'notifications' => 'type',
        'notification_preferences' => 'notification_type',
    ];

    /**
     * The vocabulary as it stood before M179 — `000133`'s eleven. Spelled out rather than derived: `down()` must
     * restore what was there, and `values()` now answers a different question. A future case gets a NEW
     * migration with its own frozen list.
     */
    private const PRE_M179_TYPES = [
        'submission_received', 'submission_returned', 'submission_approved', 'review_requested',
        'export_ready', 'member_invited', 'webhook_failed', 'impersonation_started', 'member_joined',
        'badge_earned', 'feedback_closed',
    ];

    public function up(): void
    {
        $this->recreate(NotificationType::values());
    }

    public function down(): void
    {
        $this->recreate(self::PRE_M179_TYPES);
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
