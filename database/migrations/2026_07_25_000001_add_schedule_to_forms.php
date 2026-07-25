<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Increment H12a — scheduled forms (H-map row 233). Adds the optional open/close window + a response cap to
 * the durable form record. `opens_at`/`closes_at` are `timestamptz` (absolute UTC instants); every enforcement
 * check compares them against `now()`, so the window is unambiguous regardless of viewer clock. `timezone` is
 * IANA authoring/display metadata ONLY — H12b's builder round-trips the author's intended wall-clock zone; it
 * is never consulted server-side. `max_responses` (null = unlimited) is enforced by a transactional
 * COUNT-under-RLS at finalize, never a running aggregate. `schedule_state` is the once-only event-emission key
 * the state-flip sweep advances monotonically (scheduled → open → closed) so `form.opened`/`form.closed` each
 * fire exactly once; it is NOT read by enforcement (which computes acceptance live). Null when the form has no
 * schedule, so the sweep skips it.
 *
 * No RLS re-emit: `forms` already carries strict RLS and policies are row predicates (same as
 * 2026_07_23_000010_add_save_and_resume_to_forms). Alter-only, so the migration linter skips it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->timestampTz('opens_at')->nullable()->after('archived_at');
            $table->timestampTz('closes_at')->nullable()->after('opens_at');
            $table->string('timezone', 64)->default('UTC')->after('closes_at');
            $table->integer('max_responses')->nullable()->after('timezone');
            $table->string('schedule_state', 20)->nullable()->after('max_responses');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table): void {
            $table->dropColumn(['opens_at', 'closes_at', 'timezone', 'max_responses', 'schedule_state']);
        });
    }
};
