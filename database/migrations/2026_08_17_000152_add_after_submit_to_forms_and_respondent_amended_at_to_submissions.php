<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a respondent may do after Submit — M248 (`D232`, NHETE recommendation 6a).
 *
 * `forms.after_submit` is the form's "After submitting" setting: `another` (the thank-you page offers "Submit another
 * response", which every form has always done — the default, so no existing form changes), `amend` (the respondent may
 * change the response they sent until the form stops accepting responses) or `once` (nothing more from that device).
 * Backed by `App\Enums\AfterSubmit`; the CHECK says what the value may be, as `bot_challenge`'s cast does for its own.
 *
 * `submissions.respondent_amended_at` is when the respondent last changed their own response through the amend link,
 * NULL when they never did. The change itself is an `audits` row; this is the one fact staff see on the response.
 *
 * No new policy: `forms` and `submissions` carry strict RLS and policies are row predicates, so a new column is covered.
 *
 * ⛔ ADDITIVE ONLY (`D137`): one column with a constant default (a metadata-only change on PostgreSQL 11+, no rewrite)
 * and one nullable column; nothing rewritten, no row touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->string('after_submit', 10)->default('another')->after('save_and_resume');
        });

        DB::statement(
            'ALTER TABLE forms ADD CONSTRAINT forms_after_submit_chk '
            ."CHECK (after_submit IN ('another', 'amend', 'once'))"
        );

        Schema::table('submissions', function (Blueprint $table): void {
            $table->timestampTz('respondent_amended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropColumn('respondent_amended_at');
        });

        DB::statement('ALTER TABLE forms DROP CONSTRAINT IF EXISTS forms_after_submit_chk');

        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn('after_submit');
        });
    }
};
