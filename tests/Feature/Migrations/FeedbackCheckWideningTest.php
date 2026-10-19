<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * M179 (`R-129c44c3`, `D151`) — the CHECK rebuilds that only an already-migrated database can see.
 *
 * `2026_08_17_000133_recreate_notification_type_checks_for_feedback_closed.php` says it in as many words: "NO
 * TEST CAN SEE THIS FILE" — `migrate:fresh` builds every value-domain CHECK from the enum's `values()` at run
 * time, so a fresh test database admits a new case with or without the migration that widens it. The
 * migration exists for the databases that ran before the case existed: the dev stacks, and the testing
 * server, where a narrow CHECK would refuse the first write of the new value under the testers.
 *
 * So each case here rebuilds the PRE-migration constraint first — the shape staging holds — then runs the
 * migration's `up()` and `down()` against it. PostgreSQL DDL is transactional, so the narrowing rolls back
 * with `RefreshDatabase`'s transaction and cannot leak into another file (the `SubmissionReferenceBackfillTest`
 * precedent).
 */
uses(RefreshDatabase::class);

/** A constraint's definition as PostgreSQL holds it, and whether it is validated. */
function feedbackCheckWideningDefinition(string $table, string $constraint): ?object
{
    /** @var object{definition: string, validated: bool}|null $row */
    $row = DB::selectOne(
        'SELECT pg_get_constraintdef(c.oid) AS definition, c.convalidated AS validated
           FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
          WHERE t.relname = ? AND c.conname = ?',
        [$table, $constraint],
    );

    return $row;
}

it('widens feedback_reports_status_check for Confirmed on a database migrated before it, and narrows it back', function (): void {
    DB::statement('ALTER TABLE feedback_reports DROP CONSTRAINT feedback_reports_status_check');
    DB::statement(
        'ALTER TABLE feedback_reports ADD CONSTRAINT feedback_reports_status_check '
        ."CHECK (status IN ('new', 'reviewed', 'resolved', 'wont_fix'))"
    );
    expect(feedbackCheckWideningDefinition('feedback_reports', 'feedback_reports_status_check')?->definition)
        ->not->toContain("'confirmed'");

    $migration = require base_path('database/migrations/2026_08_17_000135_recreate_feedback_reports_status_check_for_confirmed.php');

    $migration->up();
    $widened = feedbackCheckWideningDefinition('feedback_reports', 'feedback_reports_status_check');
    expect($widened?->definition)->toContain("'confirmed'")
        ->toContain("'new'")->toContain("'reviewed'")->toContain("'resolved'")->toContain("'wont_fix'")
        ->and($widened?->validated)->toBeTrue();

    $migration->down();
    $narrowed = feedbackCheckWideningDefinition('feedback_reports', 'feedback_reports_status_check');
    expect($narrowed?->definition)->not->toContain("'confirmed'")
        ->toContain("'new'")->toContain("'reviewed'")->toContain("'resolved'")->toContain("'wont_fix'");
});

it('widens both notification type CHECKs for feedback_reply, and narrows them back', function (): void {
    $pre = "'submission_received', 'submission_returned', 'submission_approved', 'review_requested', 'export_ready', "
        ."'member_invited', 'webhook_failed', 'impersonation_started', 'member_joined', 'badge_earned', 'feedback_closed'";

    foreach (['notifications' => 'type', 'notification_preferences' => 'notification_type'] as $table => $column) {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_type_check CHECK ({$column} IN ({$pre}))");
    }

    $migration = require base_path('database/migrations/2026_08_17_000140_recreate_notification_type_checks_for_feedback_reply.php');

    $migration->up();
    foreach (['notifications', 'notification_preferences'] as $table) {
        expect(feedbackCheckWideningDefinition($table, "{$table}_type_check")?->definition)
            ->toContain("'feedback_reply'")->toContain("'feedback_closed'")->toContain("'submission_received'");
    }

    $migration->down();
    foreach (['notifications', 'notification_preferences'] as $table) {
        expect(feedbackCheckWideningDefinition($table, "{$table}_type_check")?->definition)
            ->not->toContain("'feedback_reply'")->toContain("'feedback_closed'");
    }
});
