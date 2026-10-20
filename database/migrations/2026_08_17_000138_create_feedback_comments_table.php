<?php

declare(strict_types=1);

use App\Enums\FeedbackCommentAction;
use App\Enums\FeedbackParty;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `feedback_comments` — a thread on every feedback report (M179, `R-129c44c3`, `D151`; data-dictionary §21a).
 *
 * Anyone who may see a report may comment on it; a tester's **It works** and **Still a problem** each write a
 * comment carrying their `action`; the support team's replies land here too (`author_kind = support`). One
 * row per message, never edited — so there is no `updated_at`, and `created_at` is set by Eloquent rather than
 * by a database function (data-dictionary's note on function-shaped defaults stays true).
 *
 * ── BOTH WORKSPACE-SCOPED KEYS ARE COMPOSITE ───────────────────────────────────────────────────────────────
 * PostgreSQL checks a foreign key without row security, so a single-column key to `feedback_reports(id)` would
 * let a comment name another workspace's report from any connection RLS does not confine. Both keys therefore
 * carry the tenant (ADR-0002 §D5; the `2026_08_17_000120` shape): the report's through
 * `feedback_reports_tenant_id_id_unique` (`000136`), the screenshot's through `attachments_tenant_id_id_unique`
 * (`000121`) with `ON DELETE SET NULL (screenshot_attachment_id)` — the column list matters, because a bare
 * `SET NULL` would null `tenant_id`, which is NOT NULL. `users` holds no tenant, so `user_id` keeps the plain
 * shape `feedback_reports.user_id` has; it is NULL on a support reply (the support team is not a member).
 *
 * The two CHECKs are generated from {@see FeedbackParty::values()} and {@see FeedbackCommentAction::values()}.
 * There is no relational CHECK (say, "a member comment has a user"): `DocumentedCheckConstraintDriftTest` reads
 * any `column = 'literal'` CHECK as a value domain, so those rules live in the lifecycle service instead.
 *
 * ⛔ ADDITIVE (`D137`): a new table; nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('feedback_report_id');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_kind', 10);   // FeedbackParty
            $table->string('action', 15)->nullable(); // FeedbackCommentAction; NULL on an ordinary comment
            $table->text('body')->nullable();    // user-authored, conditional PII; required except on a confirm
            $table->uuid('screenshot_attachment_id')->nullable();
            $table->timestampTz('created_at');   // set by Eloquent

            // tenant_id leads (ADR-0002 §D1); the thread is always read for one report, oldest first.
            $table->index(['tenant_id', 'feedback_report_id', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE feedback_comments ADD CONSTRAINT feedback_comments_report_fk '
            .'FOREIGN KEY (tenant_id, feedback_report_id) REFERENCES feedback_reports (tenant_id, id) '
            .'ON UPDATE CASCADE ON DELETE CASCADE'
        );

        DB::statement(
            'ALTER TABLE feedback_comments ADD CONSTRAINT feedback_comments_screenshot_fk '
            .'FOREIGN KEY (tenant_id, screenshot_attachment_id) REFERENCES attachments (tenant_id, id) '
            .'ON UPDATE CASCADE ON DELETE SET NULL (screenshot_attachment_id)'
        );

        $quoted = static fn (array $values): string => implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            $values,
        ));

        DB::statement(
            'ALTER TABLE feedback_comments ADD CONSTRAINT feedback_comments_author_kind_check '
            .'CHECK (author_kind IN ('.$quoted(FeedbackParty::values()).'))'
        );

        DB::statement(
            'ALTER TABLE feedback_comments ADD CONSTRAINT feedback_comments_action_check '
            .'CHECK (action IN ('.$quoted(FeedbackCommentAction::values()).'))'
        );

        withTenantIsolation('feedback_comments');
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_comments');
    }
};
