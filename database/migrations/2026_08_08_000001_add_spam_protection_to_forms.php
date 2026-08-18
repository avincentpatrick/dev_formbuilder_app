<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment I8b — per-form spam protection on the guest runtime, closing the last unbuilt acceptance
 * criterion of PRD Feature #3: *"Per-form, configurable rate limiting / bot-challenge (e.g., CAPTCHA) is
 * available to curb spam submissions"* (docs/PRD.md:186), and the bot-flooding row in
 * docs/security-threat-model.md §4.
 *
 * Two columns, two mechanisms, and they are deliberately not one control:
 *
 *   · `bot_challenge` — a proof-of-work challenge the respondent's browser must solve before a submission
 *     is accepted. Costs the ATTACKER, which is what bounds the low-and-slow distributed case that rate
 *     limiting structurally cannot see (many IPs, many tokens, nobody individually fast).
 *   · `guest_rate_limit_per_minute` — a per-IP ceiling FOR THIS FORM, layered on top of the deployment-wide
 *     `throttle:guest` limiters. Bounds VELOCITY from one source.
 *
 * The threat model draws exactly that division of labour, which is why both exist rather than one.
 *
 * ⚠️ BOTH DEFAULTS REPRODUCE TODAY'S BEHAVIOUR EXACTLY, and that is a committed constraint rather than a
 * convenience. security-threat-model.md §4 requires the challenge be *"not enabled by default, since many
 * M&E/field-collection use cases run on trusted enumerator devices where a CAPTCHA would be actively
 * harmful UX"* — a form filled by a health worker on a shared tablet at the end of a long shift must not
 * acquire a puzzle because someone else's form was being spammed.
 *
 * ⚠️ NOT NAMED `require_captcha`, which is what the threat model suggested in passing. Nothing squiggly
 * ships: there is no image, no audio and no puzzle, so the name would guarantee a support ticket asking
 * where the letters are. A boolean would also foreclose the only future this seam needs — a tenant
 * bringing their own third-party keys becomes an enum case, where a boolean would need a second column on
 * a live table. The suggested name was never claimed in docs/data-dictionary.md, so nothing depends on it.
 *
 * No RLS re-emit: `forms` already carries strict RLS and its policies are row predicates, not column
 * lists (the 2026_07_23_000010 / 2026_07_25_000001 / 2026_07_30_000002 precedent). Alter-only, so the
 * migration linter skips this file — its green is vacuous here, not evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            // Width and style mirror `schedule_state` (2026_07_25_000001) — a short backed-enum string
            // rather than a Postgres enum type, so adding a case later is a code change, not a migration.
            $table->string('bot_challenge', 20)->default('off')->after('save_and_resume');

            // NULL means "no per-form ceiling", which is NOT the same as 0 and must stay distinguishable:
            // 0 would mean "accept nothing". smallInteger because the request caps it at 600.
            $table->smallInteger('guest_rate_limit_per_minute')->nullable()->after('bot_challenge');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn(['bot_challenge', 'guest_rate_limit_per_minute']);
        });
    }
};
