<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen BOTH notification type CHECKs for `member_joined` — Increment J3a.
 *
 * ── TWO CONSTRAINTS, NOT ONE, AND MISSING THE SECOND FAILS LATER AND STRANGER ──────────────────────────
 * `notifications.type` and `notification_preferences.notification_type` each carry a CHECK generated from
 * {@see NotificationType::values()} at their own create-migration. Widening only the first is the easy
 * mistake and produces a genuinely confusing bug: the Owner's notification WRITES fine, and the failure
 * appears the first time that Owner opens their preferences card and turns the new type off — a `23514`
 * from a settings page, in a request that has nothing to do with registration.
 *
 * The column names differ (`type` vs `notification_type`), which is why this is a table⇒column map rather
 * than a loop over table names.
 *
 * ── REGENERATED FROM THE ENUM ──────────────────────────────────────────────────────────────────────────
 * Built from `values()` exactly as the originals were, so the enum stays the single source. `DROP …
 * IF EXISTS` keeps this runnable against a database predating either constraint.
 *
 * ── `down()` NARROWS, AND WILL FAIL IF ROWS EXIST ──────────────────────────────────────────────────────
 * Deliberate, for the same reason as the `audits_event_check` sibling: PostgreSQL validates a new CHECK
 * against the whole table, so a rollback with `member_joined` rows present stops rather than silently
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
     * The vocabulary as it stood before J3a — the eight cases the I11b migration left behind.
     *
     * Spelled out rather than derived, because `down()` must restore what was there BEFORE this migration
     * and `NotificationType::values()` now answers a different question. A future case appended to the enum
     * must add a NEW migration with its own frozen list; editing this one would make `down()` narrow to a
     * vocabulary that never existed.
     */
    private const PRE_J3A_TYPES = [
        'submission_received', 'submission_returned', 'submission_approved', 'review_requested',
        'export_ready', 'member_invited', 'webhook_failed', 'impersonation_started',
    ];

    public function up(): void
    {
        $this->recreate(NotificationType::values());
    }

    public function down(): void
    {
        $this->recreate(self::PRE_J3A_TYPES);
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
