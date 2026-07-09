<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The submission record (data-dictionary §7) — the head of the answer set every ingest channel funnels
 * into. The structurally authoritative link is `form_version_id` (the immutable version this was
 * collected against, never repointed on republish — plan §2.3's core fix); `form_id` is a denormalized
 * convenience for cross-version dashboard rollups.
 *
 * RLS: the plain `strict` shape. Submissions mutate through their review lifecycle (draft → submitted →
 * approved/…), so neither the `form_version` (publish-immutability) nor `draft_child` guard applies —
 * a submission is an ordinary tenant-owned, mutable record.
 *
 * `client_submission_uuid` is an UNtrusted client-supplied idempotency key (offline replay, plan §2.4),
 * distinct from the server-assigned `id`; it is unique per tenant only when present, so channels that
 * never mint one (manual, guest) leave it NULL without colliding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->foreignUuid('form_version_id')->constrained('form_versions')->cascadeOnDelete();
            $table->foreignUuid('respondent_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('draft'); // SubmissionStatus enum
            $table->string('source', 20);                   // SubmissionSource enum
            $table->uuid('client_submission_uuid')->nullable(); // untrusted dedup key (see docblock)

            // Guest-channel provenance (PII — scrubbed in place on GDPR erasure, see pii_erased_at).
            $table->string('guest_token', 128)->nullable();
            $table->string('guest_ip', 45)->nullable();     // widened to native inet below
            $table->text('guest_user_agent')->nullable();
            $table->string('guest_contact_email', 255)->nullable();

            $table->string('device_id', 100)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('locale', 10)->nullable();
            $table->uuid('source_batch_id')->nullable(); // groups one ingest pass (linelist OCR / bulk import)

            // Review workflow.
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->text('returned_reason')->nullable();
            $table->text('remarks')->nullable();

            $table->timestampTz('submitted_at')->nullable();  // respondent finalized (vs created_at)
            $table->timestampTz('finalized_at')->nullable();  // reached a terminal state
            $table->timestampTz('pii_erased_at')->nullable(); // GDPR in-place scrub marker
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'form_id']);          // "all responses to this form" rollups
            $table->index(['tenant_id', 'form_version_id']);  // version-scoped reads
            $table->index(['tenant_id', 'status']);           // review-queue scoping
        });

        // guest_ip as native inet (data-dictionary §7) — Blueprint has no inet type; convert the empty
        // varchar column in place, the same raw-DDL idiom the D1 migrations use for CHECKs.
        DB::statement('ALTER TABLE submissions ALTER COLUMN guest_ip TYPE inet USING guest_ip::inet');

        // Idempotency: one client_submission_uuid per tenant, but unlimited NULLs (partial unique index —
        // not expressible via Blueprint's ->unique()).
        DB::statement(
            'CREATE UNIQUE INDEX submissions_tenant_client_uuid_unique '
            .'ON submissions (tenant_id, client_submission_uuid) '
            .'WHERE client_submission_uuid IS NOT NULL'
        );

        withTenantIsolation('submissions');
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
