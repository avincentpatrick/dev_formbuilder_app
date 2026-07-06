<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable, logical form record (data-dictionary §2) — deliberately thin; almost all form content
 * lives versioned in form_versions/form_sections/form_fields. Strictly tenant-scoped → strict RLS.
 *
 * The two version pointers (current_published_version_id, draft_version_id) are declared here as BARE
 * nullable uuid columns with NO foreign key yet: form_versions.form_id references forms.id, so the two
 * tables form a cycle. The FKs on these columns are added by a later ALTER migration
 * (add_form_version_fks_to_forms), the same technique 2026_07_05_032600 used for tenant_users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Version pointers — FKs added later (see class docblock). Kept as explicit pointers rather
            // than derived from form_versions so "which draft/published version is live" is unambiguous.
            $table->uuid('current_published_version_id')->nullable();
            $table->uuid('draft_version_id')->nullable();

            // Denormalized copies of the current draft's title/description (kept in sync on save) so
            // list/search/dashboard reads never touch JSONB.
            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->string('status', 20)->default('draft'); // FormStatus enum
            $table->string('public_slug', 120)->nullable();

            // Capability flags (Main Features #1-#7).
            $table->boolean('allow_guest_submissions')->default(false);
            $table->boolean('allow_manual_encoding')->default(true);
            $table->boolean('allow_ocr_single')->default(false);
            $table->boolean('allow_ocr_linelist')->default(false);
            $table->boolean('allow_api_import')->default(true);
            $table->boolean('allow_offline_sync')->default(true);
            $table->boolean('single_page_mode')->default(false);

            $table->string('default_locale', 10); // copied from the tenant's default_locale at creation
            $table->jsonb('supported_locales')->default('[]');
            $table->jsonb('capability_flags')->default('{}'); // recomputed on every publish
            $table->jsonb('theme')->nullable();

            $table->foreignUuid('owner_user_id')->constrained('users');
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('published_at')->nullable(); // denormalized first-publish time
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'public_slug']); // slug unique per tenant (NULLs distinct)
            $table->index(['tenant_id', 'status']);        // tenant_id leads (ADR-0002 §D1)
            $table->index(['tenant_id', 'owner_user_id']); // dashboard scoping by owner
        });

        withTenantIsolation('forms');
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
