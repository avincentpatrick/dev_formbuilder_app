<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Widen `feedback_reports_status_check` for `confirmed` — M179 (`R-129c44c3`, `D151`): a tester's **It works**
 * moves a Resolved report to Confirmed ("Confirmed working").
 *
 * ⚠️ A FRESH DATABASE NEVER NEEDS THIS FILE. `2026_08_17_000132` builds the constraint from
 * {@see FeedbackStatus::values()} at run time, so `migrate:fresh` already admits `confirmed`. This migration is
 * for the databases that ran `000132` before the case existed — the dev stacks and the testing server — where
 * the narrow constraint would refuse the first **It works** with a `23514` under the testers. That is why
 * `tests/Feature/Migrations/FeedbackCheckWideningTest.php` rebuilds the narrow constraint first and runs this
 * file against it: without it, no test could see this file at all (the `000133` warning).
 *
 * ── THE `000132` SHAPE, SO A SURPRISE CANNOT TAKE THE DEPLOY DOWN ──────────────────────────────────────────
 * The constraint is dropped and re-added `NOT VALID` — enforced at once for every new write — then validated
 * inside a SAVEPOINT. Every live row already holds one of the four older values, so validation passes; if one
 * ever did not, the constraint stays in place unvalidated and the reason is logged, instead of `migrate`
 * failing and `deploy.ps1` leaving the site down under the testers.
 *
 * ⛔ ADDITIVE IN EFFECT (`D137`): the constraint is replaced by a WIDER one inside the migration's
 * transaction; no row is touched and every existing value stays admitted. `down()` narrows it back to the
 * four values M175 left behind — frozen here, because `values()` now answers a different question — and does
 * so the same tolerant way, so a `confirmed` row left behind leaves the constraint unvalidated rather than
 * failing a rollback.
 */
return new class extends Migration
{
    /** The vocabulary `000132` constrained the column to, before M179. Spelled out: see the docblock. */
    private const PRE_M179_STATUSES = ['new', 'reviewed', 'resolved', 'wont_fix'];

    public function up(): void
    {
        $this->recreate(FeedbackStatus::values());
    }

    public function down(): void
    {
        $this->recreate(self::PRE_M179_STATUSES);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function recreate(array $statuses): void
    {
        $list = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $statuses));

        DB::statement('ALTER TABLE feedback_reports DROP CONSTRAINT IF EXISTS feedback_reports_status_check');
        DB::statement(
            'ALTER TABLE feedback_reports ADD CONSTRAINT feedback_reports_status_check '
            ."CHECK (status IN ({$list})) NOT VALID"
        );

        try {
            DB::transaction(static function (): void {
                DB::statement('ALTER TABLE feedback_reports VALIDATE CONSTRAINT feedback_reports_status_check');
            });
        } catch (Throwable $e) {
            Log::warning('feedback_reports_status_check left NOT VALID: a live row holds a status outside the list.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
};
