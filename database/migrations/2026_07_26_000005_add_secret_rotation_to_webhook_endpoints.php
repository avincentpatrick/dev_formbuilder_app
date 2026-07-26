<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H13b — dual-secret rotation grace (webhook-integration-design.md §5, technical-architecture.md
 * §7.2). Adds the second, transitional signing secret to `webhook_endpoints`. On rotation the current
 * `secret` moves to `secret_previous` and a fresh `secret` is minted; during the bounded grace window
 * (`secret_previous_expires_at`) every delivery is signed with BOTH so a receiver mid-rotation on its own
 * side can verify with either. `secret_previous` is `text` for the same reason as `secret` — the model's
 * `encrypted` cast overflows `varchar(255)`. Both null when no rotation is in flight.
 *
 * No RLS re-emit: `webhook_endpoints` already carries strict RLS and its policies are row predicates.
 * Alter-only, so the migration linter skips it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->text('secret_previous')->nullable()->after('secret');
            $table->timestampTz('secret_previous_expires_at')->nullable()->after('secret_previous');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropColumn(['secret_previous', 'secret_previous_expires_at']);
        });
    }
};
