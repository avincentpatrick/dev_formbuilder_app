<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H10 — the resume cursor. The UX contract (docs/ux/form-filling-ux-flow.md §5.2) restores the
 * respondent's EXACT current step on resume; the H9a draft substrate stored answers + completeness but no
 * position, so a cross-device resume could only guess. This nullable column carries the SPA's
 * `current_step_key` (a `form_sections` key, or the synthetic lead-step sentinel) through the draft-save and
 * back on the resume-read, so a resumed session lands where the respondent left off.
 *
 * Single-page forms DO set it, contrary to this docblock's original claim (corrected in Increment H21b, Doc #27
 * §5.3): the SPA seeds `currentStepKey` from the first visible step in both presentation modes and sends the
 * column unconditionally. It is simply unused on resume there. No RLS re-emit: `submissions` already
 * carries strict RLS and policies are row predicates — the same reasoning as 2026_07_23_000006. Alter-only, so
 * the migration linter skips it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->string('draft_current_step')->nullable()->after('draft_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropColumn('draft_current_step');
        });
    }
};
