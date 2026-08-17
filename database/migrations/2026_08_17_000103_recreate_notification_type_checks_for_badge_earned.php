<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen BOTH notification type CHECKs for `badge_earned` — Increment K1b.
 *
 * ── TWO CONSTRAINTS, NOT ONE, AND MISSING THE SECOND FAILS LATER AND STRANGER ──────────────────────────
 * `notifications.type` and `notification_preferences.notification_type` each carry a CHECK generated from
 * {@see NotificationType::values()} at their own create-migration. Widening only the first is the easy
 * mistake and produces a genuinely confusing bug: the member's badge notification WRITES fine, and the
 * failure appears the first time that member opens their preferences card and turns the new type off — a
 * `23514` from a settings page, in a request that has nothing to do with gamification.
 *
 * The column names differ (`type` vs `notification_type`), which is why this is a table⇒column map rather
 * than a loop over table names.
 *
 * ── ⚠️ THIS MIGRATION CANNOT BE VALIDATED BY THE TEST SUITE, AND SAYING SO IS THE POINT ────────────────
 * `RefreshDatabase` runs `migrate:fresh`, and both create-migrations build their CHECK from `values()` **at
 * run time** — so on a freshly-migrated database the constraint already admits `badge_earned` whether this
 * file exists or not. Every test would pass with it deleted. It exists for databases that are ALREADY
 * migrated (the dev stacks, and any deployed one), and it is verified by review rather than by a gate. That
 * asymmetry is why the two predecessors below were written the same way and why none of the three has a
 * test of its own.
 *
 * ── `down()` NARROWS, AND WILL FAIL IF ROWS EXIST ──────────────────────────────────────────────────────
 * Deliberate, for the same reason as the `audits_event_check` sibling: PostgreSQL validates a new CHECK
 * against the whole table, so a rollback with `badge_earned` rows present stops rather than silently
 * leaving the vocabulary unconstrained.
 *
 * ── ALTER-ONLY, NO RLS RE-EMIT ─────────────────────────────────────────────────────────────────────────
 * Both tables already carry strict isolation and those policies are ROW-scoped; a constraint change does
 * not touch them. `scripts/migration-lint.php` early-returns on a migration that creates no table, so this
 * moves the migration COUNT and nothing else.
 */
return new class extends Migration
{
    /** table => column carrying the type value. */
    private const CONSTRAINED = [
        'notifications' => 'type',
        'notification_preferences' => 'notification_type',
    ];

    /**
     * The vocabulary as it stood before K1b — the nine cases the J3a migration left behind.
     *
     * Spelled out rather than derived, because `down()` must restore what was there BEFORE this migration
     * and `NotificationType::values()` now answers a different question. A future case appended to the enum
     * must add a NEW migration with its own frozen list; editing this one — or the two before it — would
     * make `down()` narrow to a vocabulary that never existed.
     */
    private const PRE_K1B_TYPES = [
        'submission_received', 'submission_returned', 'submission_approved', 'review_requested',
        'export_ready', 'member_invited', 'webhook_failed', 'impersonation_started', 'member_joined',
    ];

    public function up(): void
    {
        $this->recreate(NotificationType::values());
    }

    public function down(): void
    {
        $this->recreate(self::PRE_K1B_TYPES);
    }

    /** @param  list<string>  $types */
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
