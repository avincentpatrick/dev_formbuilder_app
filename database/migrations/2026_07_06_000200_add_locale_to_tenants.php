<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the locale columns the Data Dictionary §1 specifies for `tenants` but that Increment B1's initial
 * table did not yet carry. `forms.default_locale` is copied from `tenants.default_locale` at form
 * creation (data-dictionary §2), so this must land before Increment D's FormService reads it — without
 * it, every form-create would read a missing column. `tenants` is the central, RLS-exempt table, so no
 * isolation call is needed (and the linter skips this alter-only migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('default_locale', 10)->default('en');
            $table->jsonb('supported_locales')->default('["en"]');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['default_locale', 'supported_locales']);
        });
    }
};
