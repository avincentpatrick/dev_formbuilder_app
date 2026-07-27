<?php

declare(strict_types=1);

use App\Enums\ConnectorSubscriptionStatus;
use App\Models\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One "send these events to this destination" rule on a {@see Connection} (data-dictionary §25,
 * ADR-0009, H15a) — the `webhook_endpoints` analogue for native connectors. Strictly tenant-scoped,
 * ordinary writes → the plain strict RLS shape.
 *
 * Credentials live on the CONNECTION; routing lives here. That split is what lets one OAuth grant feed many
 * destinations (a Slack workspace, three channels) without duplicating — or re-refreshing — a token per rule.
 *
 * `form_id` is nullable: NULL = tenant-wide, non-null = scoped to one form. Both FKs are COMPOSITE
 * `(tenant_id, …)` (ADR-0002 §D5): PostgreSQL runs referential actions BYPASSING RLS, so a single-column FK
 * could let one tenant's delete cascade into another tenant's rows. MATCH SIMPLE means a NULL `form_id`
 * skips its constraint entirely, so tenant-wide subscriptions are free.
 *
 * `config` is the per-provider destination payload (Slack: `{channel_id, channel_name}`) — deliberately
 * jsonb rather than provider-specific columns, since H16's Sheets/Airtable destinations are shaped nothing
 * like a channel id. It carries no credential; the adapter validates its own shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Composite FKs below (not foreignUuid()->constrained()).
            $table->uuid('connection_id');
            $table->uuid('form_id')->nullable();

            $table->string('name', 150);
            $table->jsonb('event_types')->default('[]');       // App\Enums\DomainEventType values
            $table->jsonb('config')->default('{}');            // provider-specific destination (no credentials)
            $table->string('status', 20)->default(ConnectorSubscriptionStatus::Active->value);
            $table->integer('consecutive_failure_count')->default(0); // drives the circuit breaker
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Composite-FK target — webhook_deliveries points back here (the shared outbound ledger, …000003).
            $table->unique(['tenant_id', 'id'], 'connection_subscriptions_tenant_id_id_unique');

            $table->foreign(['tenant_id', 'connection_id'], 'connection_subscriptions_connection_fk')
                ->references(['tenant_id', 'id'])->on('connections')->cascadeOnDelete();

            // cascadeOnDelete mirrors webhook_endpoints: a form-scoped rule dies with its form on a hard
            // delete rather than silently becoming tenant-wide. Forms soft-delete, so this only fires on a
            // true teardown.
            $table->foreign(['tenant_id', 'form_id'], 'connection_subscriptions_form_fk')
                ->references(['tenant_id', 'id'])->on('forms')->cascadeOnDelete();

            $table->index(['tenant_id', 'status'], 'connection_subscriptions_tenant_status_idx');
            $table->index(['tenant_id', 'connection_id'], 'connection_subscriptions_tenant_connection_idx');
        });

        // Pin the status vocabulary at the database, from the enum so the two cannot drift.
        $statuses = implode(', ', array_map(
            static fn (string $value): string => "'".$value."'",
            ConnectorSubscriptionStatus::values()
        ));
        DB::statement(
            'ALTER TABLE connection_subscriptions ADD CONSTRAINT connection_subscriptions_status_check '
            ."CHECK (status IN ({$statuses}))"
        );

        withTenantIsolation('connection_subscriptions'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_subscriptions');
    }
};
