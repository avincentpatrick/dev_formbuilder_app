<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-2 personalization columns (data-dictionary §19, PRD Feature #9, design-system-reference.md
 * §2.9) — Increment G11.
 *
 * The B1 migration (2026_07_05_000103) deliberately shipped `theme_mode` only and its docblock said
 * the rest "are added then [Increment C]" — they never were. PROGRESS.md's claim that these columns
 * already existed (and that G11 was therefore not a schema increment) was simply wrong: `accent` was a
 * hardcoded PHP literal in User::uiTheme() that had never touched the database.
 *
 * No RLS change is needed. `user_ui_preferences` already carries the "belongs to me" policy set
 * attached by withTenantIsolation(..., 'belongs_to_user', ['column' => 'user_id']), and PostgreSQL RLS
 * policies are ROW-level — they gate which rows are visible/writable, not which columns exist — so
 * adding columns to a protected table requires no policy edit. (The migration linter keys on
 * Schema::create with a tenant_id, so this ALTER is outside its scope by design, not by omission.)
 *
 * `accent_token` is nullable with NO CHECK constraint, by the explicit §19 decision that a CHECK
 * enumerating design-system tokens would create a cross-document coupling requiring a schema migration
 * every time the design system adds an accent. The whitelist is App\Enums\AccentToken instead.
 * NULL = the product default (Blueprint), so "never expressed an opinion" and "explicitly chose the
 * default" are the same state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_ui_preferences', function (Blueprint $table): void {
            // NULL = product default (Blueprint). Whitelisted in the application layer, not by CHECK.
            $table->string('accent_token', 30)->nullable();
            $table->string('font_size_scale', 15)->default('standard'); // standard | large | extra_large
            $table->boolean('use_dyslexia_friendly_font')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_ui_preferences', function (Blueprint $table): void {
            $table->dropColumn(['accent_token', 'font_size_scale', 'use_dyslexia_friendly_font']);
        });
    }
};
