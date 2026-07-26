<?php

declare(strict_types=1);

use App\Enums\DomainEventType;
use App\Enums\WebhookDeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Individual webhook delivery attempt-records (data-dictionary §15, webhook-integration-design.md, H13a).
 * Strictly tenant-scoped, and — unlike the append-only `audits` ledger — MUTATED post-insert (status,
 * attempt_count, next_retry_at, response_* observability columns) as attempts run, so it gets the plain
 * strict RLS shape (SELECT + INSERT + UPDATE), NOT the append-only shape.
 *
 * `(webhook_endpoint_id, event_id)` is UNIQUE — the idempotency key (a domain event's `event_id` is
 * generated once, so re-emitting the same event fans out at most one delivery per endpoint). The FK to
 * `webhook_endpoints` is a COMPOSITE `(tenant_id, webhook_endpoint_id)` (ADR-0002 §D5; referential actions
 * bypass RLS). `payload_attachment_id` is a dormant nullable seam for H13b oversized-payload archival
 * (`AttachmentKind::WebhookPayloadArchive`) — no FK in H13a.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Composite FK below (not foreignUuid()->constrained()). ON DELETE CASCADE (data-dictionary §15):
            // deleting an endpoint removes its delivery history.
            $table->uuid('webhook_endpoint_id');

            $table->uuid('event_id');                       // the domain event's stable id — idempotency key
            $table->string('event_type', 60);               // App\Enums\DomainEventType
            $table->jsonb('payload');                        // the full delivered envelope (may embed answer data)
            $table->uuid('payload_attachment_id')->nullable(); // H13b archival seam — no FK in H13a

            $table->string('status', 20)->default(WebhookDeliveryStatus::Pending->value);
            $table->integer('attempt_count')->default(0);
            $table->integer('max_attempts')->default(10);
            $table->timestampTz('next_retry_at')->nullable();   // the retry-ladder sweep target
            $table->timestampTz('last_attempted_at')->nullable();
            $table->integer('response_status_code')->nullable();
            $table->text('response_body_excerpt')->nullable();  // truncated body / [marker] for SSRF/quota outcomes
            $table->integer('response_time_ms')->nullable();
            $table->string('signature', 255)->nullable();       // the HMAC actually sent (support/debug repro)

            $table->timestampsTz();

            // Composite-FK target uniqueness lives on webhook_endpoints (tenant_id, id).
            $table->foreign(['tenant_id', 'webhook_endpoint_id'], 'webhook_deliveries_endpoint_fk')
                ->references(['tenant_id', 'id'])->on('webhook_endpoints')->cascadeOnDelete();

            // Idempotency: one delivery per (endpoint, event). tenant_id-leading is unnecessary here since
            // event_id is globally unique per event, but the endpoint scopes it to the tenant transitively.
            $table->unique(['webhook_endpoint_id', 'event_id'], 'webhook_deliveries_endpoint_event_unique');
        });

        // Pin the status + event_type vocabularies at the database, from the enums so they cannot drift.
        $statuses = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            WebhookDeliveryStatus::values()
        ));
        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check '
            ."CHECK (status IN ({$statuses}))"
        );

        $eventTypes = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            DomainEventType::values()
        ));
        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_event_type_check '
            ."CHECK (event_type IN ({$eventTypes}))"
        );

        // Partial index for the retry sweep: it scans only rows awaiting a re-attempt. Not expressible via
        // Blueprint's ->index(), so the raw-DDL idiom (the submissions_draft_expiry_idx precedent).
        DB::statement(
            'CREATE INDEX webhook_deliveries_retry_idx '
            .'ON webhook_deliveries (tenant_id, next_retry_at) '
            ."WHERE status = 'failed'"
        );

        withTenantIsolation('webhook_deliveries'); // strict — mutated post-insert (see docblock)
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
