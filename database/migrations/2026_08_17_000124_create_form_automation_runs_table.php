<?php

declare(strict_types=1);

use App\Enums\FormAutomationRunStatus;
use App\Support\Tenancy\TenantScopedTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One automation's run for one response (M132, `R-b7bc5149`) — the ledger the settings section reads to say what
 * happened, and the idempotency key that makes a second dispatch of the same event do nothing.
 *
 * ── ITS OWN TABLE, NOT `webhook_deliveries` ─────────────────────────────────────────────────────────────────
 * That ledger's exactly-one-owner CHECK and its retry sweeper's two-way branch would misroute a third owner, an email
 * has no place in it, and it STORES its payloads — which here would be respondents' answers, in a table nothing
 * prunes. A run stores no payload and no response body: the webhook job rebuilds the payload from the submission at
 * send time, and a run keeps only a status code and a short machine-readable reason.
 *
 * ── UNIQUE PER AUTOMATION PER EVENT ──────────────────────────────────────────────────────────────────────────
 * `SubmissionCreated` carries a stable `event_id`, so a re-raised event finds its run and dispatches nothing.
 * `submission_id` carries no foreign key, as `ocr_scans.submission_id` does not: a run is history, and it outlives a
 * deleted response.
 *
 * Strict RLS; composite key to the automation, CASCADE. The table joins {@see TenantScopedTables::STRICT}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_automation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('form_automation_id');
            $table->uuid('submission_id');
            $table->uuid('event_id');
            $table->string('status', 20)->default(FormAutomationRunStatus::Pending->value);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            // A web address's answer to the last attempt; null for an email, or when nothing answered.
            $table->unsignedSmallInteger('response_status')->nullable();
            // Why the last attempt did not succeed, as a short code (`http_500`, `transport_error`, `blocked_url`,
            // `quota_exceeded`, `plan_feature`, `submission_missing`, `disabled`). Never a response body.
            $table->string('error_code', 40)->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'form_automation_id', 'event_id'], 'form_automation_runs_event_unique');
            $table->index(['tenant_id', 'form_automation_id', 'created_at']);

            $table->foreign(['tenant_id', 'form_automation_id'], 'form_automation_runs_automation_fk')
                ->references(['tenant_id', 'id'])->on('form_automations')->cascadeOnDelete();
        });

        $statuses = implode(', ', array_map(static fn (string $v): string => "'".$v."'", FormAutomationRunStatus::values()));
        DB::statement("ALTER TABLE form_automation_runs ADD CONSTRAINT form_automation_runs_status_check CHECK (status IN ({$statuses}))");

        withTenantIsolation('form_automation_runs'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('form_automation_runs');
    }
};
