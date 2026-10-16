<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The support team's reply, the reporter's role, and a CHECK on the status (M175, `R-9bfd832e`, `D139`;
 * data-dictionary §21).
 *
 * `resolution_note` is the reply written with a status change — read by the reporter on My reports and in the
 * Resolved/Declined email, redacted in the audit. `reporter_role` is the reporter's role when they sent the
 * report, a snapshot so the console can say who saw the problem without reading roles across workspaces.
 *
 * ── THE STATUS CHECK CANNOT TAKE THE DEPLOY DOWN ───────────────────────────────────────────────────────────
 * `feedback_reports.status` had no CHECK, and `deploy.ps1` leaves the site down on purpose when `migrate` fails.
 * A plain `ADD CONSTRAINT` validates every live row inside the migration, so one value nobody expected would
 * stop the deploy under the testers. So the constraint is added `NOT VALID` — enforced at once for every new
 * write — and then validated inside a SAVEPOINT: if a live row disagrees, the savepoint rolls back, the
 * constraint stays in place unvalidated, and the reason is logged for a person to read. Validation is not
 * subject to row-level security, so it sees every row. The pre-merge census reads the distinct values first,
 * so this is the belt to that braces.
 *
 * ⛔ ADDITIVE ONLY (`D137`). Two nullable columns and one CHECK; no existing row is rewritten or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->text('resolution_note')->nullable();
            $table->string('reporter_role', 50)->nullable();
        });

        $statuses = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            FeedbackStatus::values()
        ));

        DB::statement(
            'ALTER TABLE feedback_reports ADD CONSTRAINT feedback_reports_status_check '
            ."CHECK (status IN ({$statuses})) NOT VALID"
        );

        try {
            DB::transaction(static function (): void {
                DB::statement('ALTER TABLE feedback_reports VALIDATE CONSTRAINT feedback_reports_status_check');
            });
        } catch (Throwable $e) {
            Log::warning('feedback_reports_status_check left NOT VALID: a live row holds a status outside the enum.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE feedback_reports DROP CONSTRAINT IF EXISTS feedback_reports_status_check');

        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->dropColumn(['resolution_note', 'reporter_role']);
        });
    }
};
