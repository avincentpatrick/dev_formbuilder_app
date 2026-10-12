<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a form's public link takes a filled paper copy, and the two limits on it (M165, `R-7173c02c`, `D106`).
 *
 * `allow_guest_ocr` is the second switch `D106` names: the public link offers "Upload a filled paper copy" only
 * where it AND the form's Scanning switch (`allow_ocr_single`) are both on. Off by default, so every existing
 * form keeps today's behaviour.
 *
 * The two limits are the spend guard the user chose (2026-10-12): nothing meters the reading provider's pages per
 * workspace yet (`R-e5bd776f`), so a public upload is bounded per form instead.
 *   - `guest_ocr_window_minutes` — one device may send a few uploads per this many minutes. 10 by default, and the
 *     user's answer was that it can be set SHORTER, so the CHECK allows 1, 2, 5 or 10 and nothing longer.
 *   - `guest_ocr_daily_cap` — at most this many guest uploads per form in any 24 hours. 20 by default.
 *
 * ⛔ ADDITIVE ONLY. New columns with defaults; no existing row is rewritten or deleted (the user's rule for the
 * testing server).
 *
 * No `withTenantIsolation()` here: `forms` already carries strict RLS, and adding a column does not weaken a row
 * predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->boolean('allow_guest_ocr')->default(false);
            $table->smallInteger('guest_ocr_window_minutes')->default(10);
            $table->smallInteger('guest_ocr_daily_cap')->default(20);
        });

        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_guest_ocr_window_minutes_check '
            .'CHECK (guest_ocr_window_minutes IN (1, 2, 5, 10))'
        );
        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_guest_ocr_daily_cap_check '
            .'CHECK (guest_ocr_daily_cap BETWEEN 1 AND 100)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_guest_ocr_daily_cap_check');
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_guest_ocr_window_minutes_check');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn(['allow_guest_ocr', 'guest_ocr_window_minutes', 'guest_ocr_daily_cap']);
        });
    }
};
