<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app feedback reports (PRD Feature #11 / data-dictionary §21). One row per submission from an
 * authenticated tenant user. Strictly tenant-scoped → the standard strict RLS shape via
 * withTenantIsolation(): a tenant's users never see another tenant's reports. The cross-tenant platform
 * support console reads these via the elevated super-admin carve-out (ADR-0002 §D3) — that carve-out +
 * the `screenshot_attachment_id` FK land in Phase 1 with the `attachments` table and the support UI; C3
 * builds only the tenant-side submit path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // tenant_id/user_id are NOT nullable — feedback always originates from a specific tenant's
            // authenticated user in the initial release (data-dictionary §21).
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('route', 255);                 // the page the reporter was on
            $table->text('remarks');                       // user-authored, conditional PII
            $table->jsonb('browser_info')->default('{}');  // user agent + viewport (conditional PII)
            $table->string('status', 15)->default('new');  // FeedbackStatus enum
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'status']); // tenant_id leads (ADR-0002 §D1); status for triage
        });

        withTenantIsolation('feedback_reports');
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_reports');
    }
};
