<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long the thank-you screen waits before it moves a respondent on (M138, `R-df7f4b62`; `D91` amends `D76`).
 *
 * `D76` fixed it at 20 seconds, the time WCAG 2.2.1 gives a person to stop a move; the user's staging smoke test found
 * that too long, and `D91` lets the form builder choose 5, 10, 20 or 30. 20 stays the default, so every existing form
 * keeps today's behaviour, and the settings say plainly that less than 20 gives respondents less time than WCAG asks.
 *
 * ON `forms`, beside `redirect_url` and `redirect_form_id` (`2026_08_17_000118`), for their reason: it is a live setting
 * the author changes without a republish.
 *
 * ⛔ A CHECK, NOT JUST A FORM REQUEST RULE. The four values are the whole contract the respondent's page is built for
 * (it accepts nothing else and falls back to 20), so the database refuses any other number however it is written.
 *
 * No `withTenantIsolation()` here: `forms` already carries strict RLS, and adding a column does not weaken a row
 * predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->smallInteger('redirect_delay_seconds')->default(20);
        });

        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_redirect_delay_seconds_check '
            .'CHECK (redirect_delay_seconds IN (5, 10, 20, 30))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_redirect_delay_seconds_check');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn('redirect_delay_seconds');
        });
    }
};
