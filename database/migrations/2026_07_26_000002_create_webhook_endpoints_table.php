<?php

declare(strict_types=1);

use App\Enums\WebhookEndpointStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-configured webhook delivery targets (data-dictionary §14, webhook-integration-design.md, H13a).
 * Strictly tenant-scoped, ordinary writes → the plain strict RLS shape.
 *
 * `form_id` is nullable: NULL = a tenant-wide endpoint subscribing across all forms; non-null = scoped to
 * one form. Its FK is a COMPOSITE `(tenant_id, form_id) → forms (tenant_id, id)` (ADR-0002 §D5, the
 * `scope_nodes` precedent): PostgreSQL runs referential actions BYPASSING RLS, so a single-column FK could
 * let one tenant's form delete cascade into another tenant's endpoint. MATCH SIMPLE (the default) means a
 * NULL `form_id` skips the constraint entirely, so tenant-wide endpoints are free.
 *
 * `secret` is stored via the model's `encrypted` cast (never plaintext at rest) and never returned in full
 * after creation (masked suffix) — data-dictionary §14 Design Notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Composite FK below (not foreignUuid()->constrained(), which emits a single-column FK that a
            // referential action could follow across tenants). Nullable = tenant-wide endpoint.
            $table->uuid('form_id')->nullable();

            $table->string('name', 150);
            $table->string('url', 500);
            // TEXT, not varchar(255): the model `encrypted` cast inflates even a short secret to ~290 chars
            // (base64(JSON{iv,value,mac})), so the data-dictionary's varchar(255) — which assumed plaintext —
            // cannot hold the at-rest form. Masked in the API; never returned in full after creation.
            $table->text('secret');
            $table->jsonb('event_types')->default('[]');        // App\Enums\DomainEventType values (subscription set)
            $table->string('status', 20)->default(WebhookEndpointStatus::Active->value);
            // Small constrained free text (`too_many_failures` / `manual` / `tenant_suspended`), informational only.
            $table->string('disabled_reason', 30)->nullable();
            $table->integer('consecutive_failure_count')->default(0); // drives the circuit breaker
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->string('signing_algorithm', 20)->default('hmac_sha256');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Composite-FK target (Postgres requires a UNIQUE on the referenced column list). Also used by
            // webhook_deliveries' composite FK back to this table.
            $table->unique(['tenant_id', 'id'], 'webhook_endpoints_tenant_id_id_unique');

            // cascadeOnDelete: a form-scoped endpoint dies with its form on a hard delete (avoids silently
            // converting it to tenant-wide). Forms soft-delete, so this only fires on a true teardown.
            $table->foreign(['tenant_id', 'form_id'], 'webhook_endpoints_form_fk')
                ->references(['tenant_id', 'id'])->on('forms')->cascadeOnDelete();

            $table->index(['tenant_id', 'status'], 'webhook_endpoints_tenant_status_idx');
            $table->index(['tenant_id', 'form_id'], 'webhook_endpoints_tenant_form_idx');
        });

        // Pin the status vocabulary at the database, from the enum so the two cannot drift.
        $statuses = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            WebhookEndpointStatus::values()
        ));
        DB::statement(
            'ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_status_check '
            ."CHECK (status IN ({$statuses}))"
        );

        withTenantIsolation('webhook_endpoints'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
