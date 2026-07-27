<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's OAuth grant on a tenant's third-party workspace (data-dictionary §24, ADR-0009, H15a).
 * Strictly tenant-scoped, ordinary writes → the plain strict RLS shape.
 *
 * `access_token`/`refresh_token` are TEXT, not varchar — the model's `encrypted` cast inflates the value to
 * roughly 4/3 of (plaintext + 60 bytes of iv/mac framing), which a varchar(255) cannot hold for a real OAuth
 * token (the `webhook_endpoints.secret` precedent, `2026_07_26_000002:38-41`). ADR-0009 §D1: they are
 * `$hidden` on the model and returned by NO api resource or presenter in any form, not even masked — unlike
 * a webhook secret, a connector token has no user-facing consumer.
 *
 * The partial unique `(tenant_id, provider, external_account_id) WHERE deleted_at IS NULL` makes re-connecting
 * the same workspace an UPDATE of the live row rather than a second grant, while leaving disconnected
 * (soft-deleted) history in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('provider', 30);                       // App\Enums\ConnectorProviderKey
            $table->string('external_account_id', 255);           // e.g. a Slack team id — the provider's own handle
            $table->string('external_account_label', 255);        // e.g. the workspace name, for display

            $table->jsonb('scopes')->default('[]');               // granted scopes, for display + re-consent detection

            // Encrypted at rest via the model cast (ADR-0009 §D1). TEXT for the reason in the docblock.
            $table->text('access_token');
            $table->text('refresh_token')->nullable();            // absent for providers with non-expiring grants
            $table->timestampTz('token_expires_at')->nullable();  // NULL = never expires ⇒ the refresh sweep skips it

            $table->string('status', 20)->default(ConnectionStatus::Active->value);
            $table->timestampTz('last_refreshed_at')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->string('last_error', 255)->nullable();        // the provider's error code, never a token

            $table->foreignUuid('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Composite-FK target (Postgres requires a UNIQUE on the referenced column list); used by
            // connection_subscriptions' composite FK back to this table.
            $table->unique(['tenant_id', 'id'], 'connections_tenant_id_id_unique');

            $table->index(['tenant_id', 'status'], 'connections_tenant_status_idx');
        });

        // Pin the provider + status vocabularies at the database, from the enums so they cannot drift.
        foreach ([
            'connections_provider_check' => ['provider', ConnectorProviderKey::values()],
            'connections_status_check' => ['status', ConnectionStatus::values()],
        ] as $constraint => [$column, $cases]) {
            $literals = implode(', ', array_map(
                static fn (string $value): string => "'".$value."'",
                $cases
            ));
            DB::statement(
                "ALTER TABLE connections ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$literals}))"
            );
        }

        // One LIVE grant per (tenant, provider, workspace) — re-connecting updates it in place. Partial, so
        // soft-deleted history never collides; not expressible via Blueprint's ->unique(), hence raw DDL
        // (the webhook_deliveries_retry_idx precedent).
        DB::statement(
            'CREATE UNIQUE INDEX connections_tenant_provider_account_unique '
            .'ON connections (tenant_id, provider, external_account_id) '
            .'WHERE deleted_at IS NULL'
        );

        withTenantIsolation('connections'); // strict
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
