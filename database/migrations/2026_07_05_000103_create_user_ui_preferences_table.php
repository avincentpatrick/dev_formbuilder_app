<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user UI preferences (data-dictionary §19, PRD Feature #9). Belongs to a PERSON, not a tenant —
 * the same user keeps their theme across every tenant membership — so it has NO tenant_id and uses
 * the "belongs to me" RLS shape (keyed on app.current_user_id), which B1 applies + fuzz-tests.
 *
 * Minimal in B1 (storage + RLS only): the theme-toggle UI that reads/writes it lands with the app
 * shell in Increment C. More preference columns (accent, text-size, dyslexia font) are added then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ui_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('theme_mode', 10)->default('system'); // system | light | dark
            $table->timestampsTz();
        });

        withTenantIsolation('user_ui_preferences', 'belongs_to_user', ['column' => 'user_id']);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ui_preferences');
    }
};
