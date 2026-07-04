<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Throwaway Increment-A harness for a STRICT tenant-scoped table (see App\Models\Probe). Gives the
 * cross-tenant fuzz pack a real RLS-protected table. Removed when real domain tables land.
 *
 * Ends with withTenantIsolation() — the one call every tenant-scoped migration must make, and the
 * one the migration linter enforces (ADR-0002 §D2/§D6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('note');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // tenant_id leads every composite index on a tenant-scoped table (ADR-0002 §D1).
            $table->index(['tenant_id', 'created_at']);
        });

        withTenantIsolation('probes');
    }

    public function down(): void
    {
        // Dropping the table drops its RLS policies with it.
        Schema::dropIfExists('probes');
    }
};
