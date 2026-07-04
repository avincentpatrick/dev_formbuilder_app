<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Throwaway Increment-A harness for the NULLABLE-GLOBAL tenant shape (ADR-0002 §D2 named exception):
 * a NULL tenant_id means "platform-provided, visible to every tenant". See App\Models\GlobalProbe.
 * Removed when the real adopters (field_library, form_templates, settings) land.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_probes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // NULL ⇒ global row, readable by every tenant
            $table->string('note');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        withTenantIsolation('global_probes', 'nullable_global');
    }

    public function down(): void
    {
        Schema::dropIfExists('global_probes');
    }
};
